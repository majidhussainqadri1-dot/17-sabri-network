<?php
/** Review rounds 16+ — private smart-view execution and related hardened read projections. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_A {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes'], 1900);
    }

    public static function routes(): void {
        register_rest_route('sabri-network/v2', '/future/smart-views/(?P<id>\d+)/results', [
            'methods' => 'GET',
            'callback' => [self::class, 'smart_view_results'],
            'permission_callback' => [SN_REST::class, 'access'],
        ]);
    }

    public static function smart_view_results(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::records_table() . " WHERE id=%d AND feature_id='F17-FUT-11' AND owner_id=%d AND state='active' LIMIT 1",
            absint($request['id']),
            $user_id
        ));
        if (!$record) return self::not_found();
        $payload = self::decode($record);
        if (is_wp_error($payload)) return $payload;
        $criteria = is_array($payload['criteria'] ?? null) ? $payload['criteria'] : [];

        $where = ['m.user_id=%d', 'm.left_at IS NULL', "c.status='active'"];
        $args = [$user_id];
        if (array_key_exists('muted', $criteria)) { $where[] = 'm.is_muted=%d'; $args[] = rest_sanitize_boolean($criteria['muted']) ? 1 : 0; }
        if (array_key_exists('archived', $criteria)) { $where[] = 'm.is_archived=%d'; $args[] = rest_sanitize_boolean($criteria['archived']) ? 1 : 0; }
        if (!empty($criteria['unread'])) $where[] = 'c.last_message_id>m.last_read_message_id';
        $type = sanitize_key((string)($criteria['conversation_type'] ?? ''));
        if ($type !== '') { if (!in_array($type, ['direct','group','channel','community'], true)) return new WP_Error('sn_smart_view_type_invalid', 'Saved view contains an unsupported conversation type.', ['status'=>409]); $where[] = 'c.type=%s'; $args[] = $type; }
        $days = absint($criteria['days'] ?? 0);
        if ($days > 0) { $days = min(3650, $days); $where[] = 'c.updated_at>=%s'; $args[] = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS); }
        if (!empty($criteria['has_files'])) $where[] = 'EXISTS (SELECT 1 FROM ' . SN_DB::table('messages') . ' mx WHERE mx.conversation_id=c.id AND mx.deleted_at IS NULL AND mx.attachment_id>0)';

        $sql = 'SELECT c.id,c.type,c.title,c.updated_at,c.last_message_id,m.last_read_message_id,m.is_muted,m.is_archived FROM ' . SN_DB::table('members') . ' m INNER JOIN ' . SN_DB::table('conversations') . ' c ON c.id=m.conversation_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.updated_at DESC,c.id DESC LIMIT 100';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        $items = [];
        $need_verified = !empty($criteria['from_verified']);
        if ($need_verified && !has_filter('sn_network_user_verified')) return new WP_Error('sn_smart_view_verification_provider_unavailable', 'Verified-account filtering is temporarily unavailable.', ['status'=>503]);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $conversation_id = (int)$row->id;
            if (!SN_DB::is_member($conversation_id, $user_id)) continue;
            if ($need_verified) {
                $peers = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL LIMIT 50', $conversation_id, $user_id)));
                $verified = false;
                foreach ($peers as $peer_id) if ((bool)apply_filters('sn_network_user_verified', false, $peer_id, $user_id, 'smart_view')) { $verified = true; break; }
                if (!$verified) continue;
            }
            $items[] = [
                'conversation_id'=>$conversation_id,
                'type'=>(string)$row->type,
                'title'=>(string)$row->title,
                'updated_at'=>(string)$row->updated_at,
                'unread'=>(int)$row->last_message_id>(int)$row->last_read_message_id,
                'muted'=>(bool)$row->is_muted,
                'archived'=>(bool)$row->is_archived,
            ];
        }
        return rest_ensure_response(['view_id'=>(int)$record->id,'name'=>(string)($payload['name']??''),'items'=>$items,'revalidated'=>true,'global_search_owner'=>'file-26','private_message_corpus_exported'=>false]);
    }

    private static function records_table(): string { global $wpdb; return $wpdb->prefix . 'sn_future_records'; }
    private static function decode(object $record): array|WP_Error {
        $plain = SN_Communication_Crypto::decrypt((string)$record->payload_cipher, 'future-record|' . (string)$record->feature_id . '|' . (int)$record->owner_id . '|' . (string)$record->scope_type . '|' . (int)$record->scope_id);
        if (is_wp_error($plain)) return $plain;
        $data = json_decode($plain, true);
        return is_array($data) ? $data : new WP_Error('sn_future_record_invalid', 'Advanced communication data is invalid.', ['status'=>500]);
    }
    private static function not_found(): WP_Error { return new WP_Error('not_found', 'Requested communication object is unavailable.', ['status'=>404]); }
}
