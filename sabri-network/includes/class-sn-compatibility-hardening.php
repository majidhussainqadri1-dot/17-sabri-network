<?php
/** Compatibility and corrective bridges that preserve one canonical File-17 backend. */
declare(strict_types=1);
defined('ABSPATH') || exit;

require_once SN_DIR . 'includes/class-sn-two-plan-completion.php';
require_once SN_DIR . 'includes/class-sn-two-plan-contract-firewall.php';
require_once SN_DIR . 'includes/class-sn-two-plan-presentation.php';

final class SN_Compatibility_Hardening {
    private const MAX_FORWARD_BODY = 10000;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 1200);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'override_privacy_exporter'], 1200);
        SN_Two_Plan_Completion::register();
        SN_Two_Plan_Contract_Firewall::register();
        SN_Two_Plan_Presentation::register();
    }

    public static function override_privacy_exporter(array $exporters): array {
        if (isset($exporters['sabri-network'])) {
            $exporters['sabri-network']['callback'] = [self::class, 'privacy_export'];
        }
        return $exporters;
    }

    public static function privacy_export(string $email_address, int $page = 1): array {
        global $wpdb;
        $result = SN_Privacy::export($email_address, $page);
        if (!isset($result['data']) || !is_array($result['data'])) return $result;
        foreach ($result['data'] as &$item) {
            if (($item['group_id'] ?? '') !== 'sabri-network-messages' || !preg_match('/^message-(\d+)$/', (string) ($item['item_id'] ?? ''), $match)) continue;
            $message_id = (int) $match[1];
            $row = $wpdb->get_row($wpdb->prepare('SELECT id,conversation_id,sender_id,body,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $message_id));
            if (!$row) continue;
            $plain = SN_Message_Body::decrypt_row($row);
            foreach ((array) ($item['data'] ?? []) as &$field) {
                if (($field['name'] ?? '') === __('Message', 'sabri-network')) {
                    $field['value'] = is_wp_error($plain) ? __('[encrypted message unavailable]', 'sabri-network') : (string) $plain;
                }
            }
            unset($field);
        }
        unset($item);
        return $result;
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/presence', [
            ['methods' => 'GET', 'callback' => [self::class, 'legacy_get_presence'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'legacy_heartbeat'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/forward', [
            'methods' => 'POST', 'callback' => [self::class, 'secure_forward_message'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function secure_forward_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $source_id = absint($request['id']);
        $actor = get_current_user_id();
        $target_id = absint($request->get_param('conversation_id'));
        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $source_id));
        if (!$source || !empty($source->deleted_at) || !SN_DB::is_member((int) $source->conversation_id, $actor) || !SN_DB::is_member($target_id, $actor)) return self::not_found();
        $target = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $target_id));
        if (!$target) return self::not_found();
        $post = SN_Policy::can_post_to_conversation($target, $actor);
        if (is_wp_error($post)) return $post;
        if ((string) $target->type === 'direct') {
            $peer = (int) $wpdb->get_var($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1', $target_id, $actor));
            if ($peer <= 0) return self::not_found();
            $contact = SN_Policy::can_contact($actor, $peer, 'message');
            if (is_wp_error($contact)) return $contact;
        }
        if (!SN_Policy::consume_rate_limit('message_forward', (string) $actor, 60, MINUTE_IN_SECONDS)) return new WP_Error('sn_forward_rate_limited', 'Too many forwards were requested.', ['status' => 429]);
        if ((int) $source->attachment_id > 0 && (string) $source->attachment_source === 'private') return new WP_Error('sn_forward_private_attachment_requires_resend', 'Private attachments must be re-uploaded rather than reused across audiences.', ['status' => 409]);
        $plain = SN_Message_Body::decrypt_row($source);
        if (is_wp_error($plain)) return $plain;
        $plain = mb_substr((string) $plain, 0, self::MAX_FORWARD_BODY);
        if ($plain === '') return new WP_Error('sn_forward_empty', 'There is no permitted content to forward.', ['status' => 409]);
        $stored = SN_Message_Body::encrypt($plain, $target_id, $actor);
        if (is_wp_error($stored)) return $stored;
        $client = strtolower(trim((string) $request->get_param('client_id'))) ?: wp_generate_uuid4();
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) return new WP_Error('sn_forward_client_id_invalid', 'A valid idempotency key is required.', ['status' => 400]);
        $idem = hash('sha256', $actor . ':' . $target_id . ':forward:' . $source_id . ':' . $client);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idem));
        if ($existing) return rest_ensure_response(['message_id' => (int) $existing->id, 'duplicate' => true]);
        $shared = self::target_audience_is_source_authorized((int) $source->conversation_id, $target_id);
        $source_hash = hash_hmac('sha256', $source_id . '|' . (int) $source->conversation_id . '|' . (string) $source->created_at, wp_salt('auth') . '|sn-forward-source-v1');
        $metadata = ['forwarded' => true, 'source_scope_hash' => $source_hash];
        if ($shared) $metadata['source_message_id'] = $source_id;
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            $space = SN_Spaces::assert_post_allowed_in_transaction($target_id, $actor);
            if (is_wp_error($space)) { $wpdb->query('ROLLBACK'); return $space; }
            if ($wpdb->insert(SN_DB::table('messages'), ['conversation_id' => $target_id, 'sender_id' => $actor, 'message_type' => 'text', 'body' => $stored, 'attachment_id' => 0, 'attachment_source' => 'none', 'reply_to' => 0, 'idempotency_key' => $idem, 'metadata' => (string) wp_json_encode($metadata), 'created_at' => $now]) === false) throw new RuntimeException('forward_insert_failed');
            $new_id = (int) $wpdb->insert_id;
            if ($wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('conversations') . ' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d', $new_id, $now, $target_id)) === false) throw new RuntimeException('forward_pointer_failed');
            SN_Spaces::mark_posted_for_conversation($target_id, $actor, $now);
            $indexed = SN_Message_Search::index_message($new_id);
            if (is_wp_error($indexed)) throw new RuntimeException($indexed->get_error_code());
            $event = SN_Outbox::enqueue('message.forwarded', 'message', $new_id, ['message_id' => $new_id, 'conversation_id' => $target_id, 'sender_id' => $actor, 'source_scope_hash' => $source_hash, 'source_visible' => $shared], 'message.forwarded:' . $new_id);
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_forwarded', 'message', $new_id, 'success', ['target_conversation_id' => $target_id, 'source_scope_hash' => $source_hash, 'attachment_reused' => false], $actor);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('forward_commit_failed');
            do_action('sn_network_event_queued', $event, 'message.forwarded');
            return new WP_REST_Response(['message_id' => $new_id, 'source_visible' => $shared, 'private_attachment_forwarded' => false], 201);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $race = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idem));
            if ($race) return rest_ensure_response(['message_id' => (int) $race->id, 'duplicate' => true]);
            SN_DB::audit('message_forward_failed', 'message', $source_id, 'failure', ['target_conversation_id' => $target_id, 'reason' => $e->getMessage()], $actor);
            return new WP_Error('sn_forward_failed', 'The forward could not be committed safely.', ['status' => 500]);
        }
    }

    public static function legacy_heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, ['online', 'away', 'offline'], true)) $status = 'online';
        $forward = new WP_REST_Request('POST', '/sabri-network/v2/presence/devices/heartbeat');
        $forward->set_param('device_id', self::legacy_device_id($user_id));
        $forward->set_param('state', $status);
        $forward->set_param('ttl', $status === 'offline' ? 30 : 90);
        $forward->set_param('label', 'Compatibility web session');
        $forward->set_param('capabilities', ['realtime']);
        $response = SN_Presence_Devices::heartbeat($forward);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        return rest_ensure_response(['presence' => ['user_id' => $user_id, 'status' => $status, 'last_seen_at' => current_time('mysql', true), 'expires_at' => (string) ($data['expires_at'] ?? '')], 'compatibility_only' => true, 'canonical_owner' => 'presence_devices']);
    }

    public static function legacy_get_presence(WP_REST_Request $request): WP_REST_Response {
        $raw = $request->get_param('user_ids');
        if (is_string($raw)) $raw = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $raw)))), 0, 100);
        $presence = [];
        foreach ($ids as $target_id) {
            $forward = new WP_REST_Request('GET', '/sabri-network/v2/presence/users/' . $target_id);
            $forward->set_param('user_id', $target_id);
            $result = SN_Presence_Devices::aggregate($forward);
            if (is_wp_error($result)) continue;
            $data = $result->get_data();
            $presence[] = ['user_id' => (int) ($data['user_id'] ?? $target_id), 'status' => (string) ($data['state'] ?? 'offline'), 'last_seen_at' => $data['last_seen_at'] ?? null];
        }
        return rest_ensure_response(['presence' => $presence, 'compatibility_only' => true, 'canonical_owner' => 'presence_devices']);
    }

    private static function target_audience_is_source_authorized(int $source_id, int $target_id): bool {
        global $wpdb;
        $targets = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL', $target_id)));
        if (!$targets) return false;
        foreach ($targets as $user_id) if (!SN_DB::is_member($source_id, $user_id)) return false;
        return true;
    }

    private static function legacy_device_id(int $user_id): string {
        $session = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
        $material = $session !== '' ? $session : ('user:' . $user_id);
        return 'legacy-web-' . substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 32);
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested object is unavailable.', ['status' => 404]);
    }
}
