<?php
/** Safe presentation/read contracts for the File-17 two-plan completion layer. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Two_Plan_Presentation {
    private const LIMIT = 100;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes'], 1450);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_messages_assets'], 35);
    }

    public static function enqueue_messages_assets(): void {
        $page_id = (int) get_option('sn_messages_page_id');
        $standalone = (int) get_query_var('sn_messages_app') === 1 && (string) get_query_var('sn_messages_mode') !== 'settings';
        $is_messages = ($page_id > 0 && SN_Messages::is_owned_page($page_id) && is_page($page_id)) || $standalone;
        if (!$is_messages) return;
        $css = SN_DIR . 'assets/css/two-plan-ui.css';
        $js = SN_DIR . 'assets/js/two-plan-ui.js';
        wp_register_style('sabri-network-two-plan-ui', SN_URL . 'assets/css/two-plan-ui.css', ['sabri-messages'], is_file($css) ? (string) filemtime($css) : SN_VERSION);
        wp_register_script('sabri-network-two-plan-ui', SN_URL . 'assets/js/two-plan-ui.js', ['sabri-messages'], is_file($js) ? (string) filemtime($js) : SN_VERSION, true);
        wp_enqueue_style('sabri-network-two-plan-ui');
        wp_enqueue_script('sabri-network-two-plan-ui');
    }

    public static function register_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/messages/starred', [
            'methods' => 'GET', 'callback' => [self::class, 'starred_messages'], 'permission_callback' => $access,
        ]);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/structured', [
            'methods' => 'GET', 'callback' => [self::class, 'structured_message'], 'permission_callback' => $access,
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/community-artifacts/(?P<artifact>\d+)/responses', [
            'methods' => 'GET', 'callback' => [self::class, 'artifact_responses'], 'permission_callback' => $access,
        ]);
    }

    public static function starred_messages(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $viewer = get_current_user_id();
        $limit = min(self::LIMIT, max(1, absint($request->get_param('limit')) ?: 50));
        $before = absint($request->get_param('before'));
        $where = $before > 0 ? $wpdb->prepare(' AND s.id<%d', $before) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT s.id star_id,s.created_at starred_at,m.* FROM ' . SN_DB::table('message_stars') . ' s INNER JOIN ' . SN_DB::table('messages') . ' m ON m.id=s.message_id INNER JOIN ' . SN_DB::table('members') . ' cm ON cm.conversation_id=m.conversation_id AND cm.user_id=%d AND cm.left_at IS NULL WHERE s.user_id=%d' . $where . ' ORDER BY s.id DESC LIMIT %d',
            $viewer, $viewer, $limit
        ));
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($row->deleted_at || SN_Message_Operations::is_hidden($viewer, (int) $row->id)) continue;
            $plain = SN_Message_Body::decrypt_row($row);
            $items[] = [
                'star_id' => (int) $row->star_id,
                'message_id' => (int) $row->id,
                'conversation_id' => (int) $row->conversation_id,
                'sender' => SN_Auth::public_user((int) $row->sender_id),
                'body' => is_wp_error($plain) ? '' : (string) $plain,
                'message_type' => (string) $row->message_type,
                'created_at' => (string) $row->created_at,
                'starred_at' => (string) $row->starred_at,
            ];
        }
        return rest_ensure_response(['items' => $items, 'next_before' => $items ? (int) end($items)['star_id'] : null]);
    }

    public static function structured_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $viewer = get_current_user_id();
        $id = absint($request['id']);
        $message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        if (!$message || !SN_DB::is_member((int) $message->conversation_id, $viewer) || $message->deleted_at || SN_Message_Operations::is_hidden($viewer, $id)) return self::not_found();
        $meta = json_decode((string) ($message->metadata ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $structured = [
            'message_id' => $id,
            'message_type' => (string) $message->message_type,
            'expires_at' => isset($meta['expires_at']) ? (string) $meta['expires_at'] : null,
            'voice_note' => isset($meta['voice_note']) && is_array($meta['voice_note']) ? self::safe_voice_note($meta['voice_note']) : null,
            'poll' => null,
            'checklist' => null,
        ];
        if ((string) $message->message_type === 'poll' && isset($meta['poll']) && is_array($meta['poll'])) {
            $options = array_values(array_map('strval', (array) ($meta['poll']['options'] ?? [])));
            $counts = array_fill(0, count($options), 0);
            foreach ($wpdb->get_results($wpdb->prepare('SELECT option_index,COUNT(*) total FROM ' . SN_DB::table('poll_votes') . ' WHERE message_id=%d GROUP BY option_index', $id)) ?: [] as $row) {
                $index = (int) $row->option_index;
                if (array_key_exists($index, $counts)) $counts[$index] = (int) $row->total;
            }
            $viewer_vote = $wpdb->get_var($wpdb->prepare('SELECT option_index FROM ' . SN_DB::table('poll_votes') . ' WHERE message_id=%d AND user_id=%d', $id, $viewer));
            $structured['poll'] = [
                'question' => (string) ($meta['poll']['question'] ?? ''),
                'options' => $options,
                'counts' => $counts,
                'viewer_vote' => $viewer_vote === null ? null : (int) $viewer_vote,
                'single_choice' => true,
                'clinical_decision_substitute' => false,
            ];
        }
        if ((string) $message->message_type === 'checklist' && isset($meta['checklist']) && is_array($meta['checklist'])) {
            $items = [];
            foreach ((array) ($meta['checklist']['items'] ?? []) as $index => $item) {
                if (!is_array($item)) continue;
                $items[] = [
                    'index' => (int) $index,
                    'label' => (string) ($item['label'] ?? ''),
                    'done' => (bool) ($item['done'] ?? false),
                    'by' => absint($item['by'] ?? 0),
                    'at' => (string) ($item['at'] ?? ''),
                ];
            }
            $structured['checklist'] = ['title' => (string) ($meta['checklist']['title'] ?? ''), 'items' => $items, 'clinical_decision_substitute' => false];
        }
        return rest_ensure_response($structured);
    }

    public static function artifact_responses(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $viewer = get_current_user_id();
        $space_id = absint($request['id']);
        $artifact_id = absint($request['artifact']);
        $space = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('spaces') . ' WHERE id=%d', $space_id));
        if (!$space || !self::can_view_space_content($space, $viewer)) return self::not_found();
        $artifact = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . SN_DB::table('community_artifacts') . " WHERE id=%d AND space_id=%d AND status IN ('active','closed')", $artifact_id, $space_id));
        if (!$artifact) return self::not_found();
        $after = absint($request->get_param('after'));
        $limit = min(self::LIMIT, max(1, absint($request->get_param('limit')) ?: 50));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SN_DB::table('community_responses') . " WHERE artifact_id=%d AND status='active' AND id>%d ORDER BY id ASC LIMIT %d", $artifact_id, $after, $limit));
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $plain = SN_Communication_Crypto::decrypt((string) $row->body_cipher, 'community-response|' . $artifact_id . '|' . (int) $row->user_id);
            $items[] = [
                'id' => (int) $row->id,
                'artifact_id' => $artifact_id,
                'user' => SN_Auth::public_user((int) $row->user_id),
                'body' => is_wp_error($plain) ? '' : (string) $plain,
                'body_unavailable' => is_wp_error($plain),
                'metadata' => json_decode((string) $row->metadata, true) ?: [],
                'created_at' => (string) $row->created_at,
            ];
        }
        return rest_ensure_response(['items' => $items, 'next_after' => count($items) === $limit ? (int) end($items)['id'] : null]);
    }

    private static function can_view_space_content(object $space, int $viewer): bool {
        global $wpdb;
        if (in_array((string) $space->state, ['closed', 'deletion_requested'], true)) return false;
        if ((string) $space->visibility === 'public') return true;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . SN_DB::table('space_members') . " WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1", (int) $space->id, $viewer));
    }

    private static function safe_voice_note(array $meta): array {
        return [
            'playback_speeds' => array_values(array_map('floatval', (array) ($meta['playback_speeds'] ?? [1]))),
            'transcript_available' => (bool) ($meta['transcript_available'] ?? false),
            'transcript' => !empty($meta['transcript_available']) ? (string) ($meta['transcript'] ?? '') : '',
        ];
    }

    private static function not_found(): WP_Error {
        return new WP_Error('sn_not_found', 'The requested communication object is unavailable.', ['status' => 404]);
    }
}
