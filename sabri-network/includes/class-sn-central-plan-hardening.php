<?php
defined('ABSPATH') || exit;

/**
 * File-17 corrections required by the latest three central plans.
 *
 * - File 19 is the only notification-center/delivery owner; File 17 emits metadata-only facts.
 * - Legacy File-17 notification rows/routes remain compatibility-only and receive no new writes.
 * - Existing plaintext canonical message bodies are migrated to authenticated at-rest envelopes.
 * - Smail multi-recipient conversation creation is idempotent across interrupted retries.
 * - Forwarded message bodies are decrypted only in authorized memory and re-encrypted for the target.
 */
final class SN_Central_Plan_Hardening {
    private const VERSION = '1.0.0';
    private const MIGRATION_BATCH = 100;

    public static function register(): void {
        add_filter('sn_network_notification_handled', [self::class, 'route_notification_to_file19'], PHP_INT_MAX, 2);
        add_action('rest_api_init', [self::class, 'override_legacy_routes'], 2000);
        add_filter('rest_post_dispatch', [self::class, 'decrypt_file17_rest_bodies'], 50, 3);
        add_action('sn_cleanup_hourly', [self::class, 'migrate_message_bodies'], 5);
    }

    public static function maybe_upgrade(): void {
        self::migrate_message_bodies();
        update_option('sn_central_plan_hardening_version', self::VERSION, false);
    }

    /**
     * Give File 19 and any approved adapter the first opportunity to consume the event,
     * then always suppress File-17's historical local notification table fallback.
     */
    public static function route_notification_to_file19(bool $handled, array $event): bool {
        if ($handled) return true;

        $requested = [
            'producer' => 'file-17',
            'recipient_id' => absint($event['user_id'] ?? 0),
            'type' => sanitize_key((string) ($event['type'] ?? '')),
            'title' => sanitize_text_field((string) ($event['title'] ?? '')),
            'entity_type' => sanitize_key((string) ($event['entity_type'] ?? '')),
            'entity_id' => absint($event['entity_id'] ?? 0),
            'created_at' => sanitize_text_field((string) ($event['created_at'] ?? current_time('mysql', true))),
        ];
        $requested['idempotency_key'] = 'file17-notification:' . hash('sha256', implode('|', [
            (string)$requested['recipient_id'], (string)$requested['type'], (string)$requested['entity_type'],
            (string)$requested['entity_id'], (string)$requested['created_at'],
        ]));

        $ready = class_exists('SN_Seventh_Fresh_R13_Hardening')
            ? SN_Seventh_Fresh_R13_Hardening::file19_ready()
            : (has_action('sn_network_notification_requested') !== false && apply_filters('sn_network_file19_notification_adapter_ready', false) === true);
        if (!$ready) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_file19_unavailable', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'],
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
            return true; // File 17's deprecated local center must remain disabled.
        }

        try {
            do_action('sn_network_notification_requested', $requested);
            $ack = apply_filters('sn_network_notification_delivery_result', null, $requested);
            if (is_wp_error($ack) || $ack !== true) {
                if (class_exists('SN_DB')) SN_DB::audit('notification_file19_handoff_unacknowledged', $requested['entity_type'], $requested['entity_id'], 'failure', [
                    'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'],
                    'reason'=>is_wp_error($ack) ? $ack->get_error_code() : 'missing_explicit_ack',
                    'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
                ], 0);
                return true;
            }
            if (class_exists('SN_DB')) SN_DB::audit('notification_deferred_to_file19', $requested['entity_type'], $requested['entity_id'], 'success', [
                'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'],
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
        } catch (Throwable $error) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_file19_handoff_failed', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'], 'reason'=>$error->getMessage(),
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
        }
        return true;
    }

    /** File 17 no longer exposes a second notification center. */
    public static function override_legacy_routes(): void {
        register_rest_route('sabri-network/v2', '/notifications', [
            'methods' => 'GET',
            'callback' => [self::class, 'legacy_notifications'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/notifications/read', [
            'methods' => 'POST',
            'callback' => [self::class, 'legacy_notifications_read'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/forward', [
            'methods' => 'POST',
            'callback' => [self::class, 'forward_message'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function legacy_notifications(WP_REST_Request $request): WP_REST_Response {
        $projection = apply_filters('sn_network_file19_notification_projection', null, get_current_user_id(), $request);
        if (is_array($projection)) {
            $projection['owner'] = 'file-19';
            return rest_ensure_response($projection);
        }
        return rest_ensure_response([
            'notifications' => [],
            'owner' => 'file-19',
            'legacy_file17_center' => false,
        ]);
    }

    public static function legacy_notifications_read(WP_REST_Request $request): WP_REST_Response {
        do_action('sn_network_file19_notification_read_requested', get_current_user_id(), $request);
        return rest_ensure_response([
            'read' => false,
            'owner' => 'file-19',
            'legacy_file17_center' => false,
        ]);
    }

    /**
     * REST compatibility layer for legacy formatters that still read the canonical body column.
     * It decrypts only values that exactly match the referenced canonical message row.
     */
    public static function decrypt_file17_rest_bodies($response, WP_REST_Server $server, WP_REST_Request $request) {
        if (!str_starts_with($request->get_route(), '/sabri-network/v2/')) {
            return $response;
        }
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }
        $data = $response->get_data();
        if (is_array($data)) {
            $response->set_data(self::decrypt_payload($data));
        }
        return $response;
    }

    private static function decrypt_payload(array $payload): array {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::decrypt_payload($value);
            }
        }
        if (!isset($payload['body']) || !is_string($payload['body']) || !SN_Message_Body::is_encrypted($payload['body'])) {
            return $payload;
        }
        $message_id = absint($payload['message_id'] ?? $payload['id'] ?? 0);
        if ($message_id <= 0) {
            return $payload;
        }
        global $wpdb;
        static $cache = [];
        if (!array_key_exists($message_id, $cache)) {
            $cache[$message_id] = $wpdb->get_row($wpdb->prepare(
                'SELECT id,conversation_id,sender_id,body,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d',
                $message_id
            ));
        }
        $row = $cache[$message_id];
        if (!$row || !hash_equals((string) $row->body, $payload['body'])) {
            return $payload;
        }
        $plain = SN_Message_Body::decrypt_row($row);
        if (is_wp_error($plain)) {
            $payload['body'] = '';
            $payload['body_unavailable'] = true;
            return $payload;
        }
        $payload['body'] = $plain;
        return $payload;
    }

    /** Bounded, retry-safe plaintext-to-envelope migration for pre-2.0.2 message rows. */
    public static function migrate_message_bodies(): void {
        global $wpdb;
        if (!class_exists('SN_DB') || !class_exists('SN_Message_Body') || !class_exists('SN_Message_Search')) {
            return;
        }
        $table = SN_DB::table('messages');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE deleted_at IS NULL AND body<>'' AND body NOT LIKE %s ORDER BY id ASC LIMIT %d",
            $wpdb->esc_like(SN_Message_Body::PREFIX) . '%',
            self::MIGRATION_BATCH
        ));
        if (!is_array($rows) || !$rows) {
            return;
        }
        foreach ($rows as $row) {
            try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
                $secured = SN_Message_Body::ensure_encrypted_row($row);
                if (is_wp_error($secured)) {
                    throw new RuntimeException($secured->get_error_code());
                }
                $indexed = SN_Message_Search::index_message((int) $row->id);
                if (is_wp_error($indexed)) {
                    throw new RuntimeException($indexed->get_error_code());
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('message_encryption_commit_failed');
                }
                SN_DB::audit('message_body_encrypted_at_rest', 'message', (int) $row->id, 'success', [], 0);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                SN_DB::audit('message_body_encryption_migration_failed', 'message', (int) $row->id, 'failure', ['reason' => $e->getMessage()], 0);
                break;
            }
        }
    }

    /**
     * Resolve a Smail conversation without duplicate group creation after an interrupted retry.
     * Direct mail reuses the canonical direct-conversation resolver. Group mail reserves a
     * unique canonical conversation via the existing unique direct_key column.
     */
    public static function resolve_smail_conversation(int $sender_id, array $recipients, string $subject, string $client_key): int|WP_Error {
        global $wpdb;
        $recipients = array_values(array_unique(array_filter(array_map('absint', $recipients))));
        if (count($recipients) === 1) {
            $request = new WP_REST_Request('POST', '/sabri-network/v2/conversations');
            $request->set_param('type', 'direct');
            $request->set_param('user_id', $recipients[0]);
            $response = SN_REST::create_conversation($request);
            if (is_wp_error($response)) {
                return $response;
            }
            $data = $response->get_data();
            $id = absint($data['conversation']['id'] ?? 0);
            return $id > 0 ? $id : new WP_Error('smail_conversation_failed', 'The Smail conversation could not be resolved.', ['status' => 500]);
        }
        if (!SN_Policy::can_create_conversation($sender_id, 'group')) {
            return new WP_Error('conversation_type_forbidden', 'You cannot create this Smail group conversation.', ['status' => 403]);
        }
        if (!SN_Policy::consume_rate_limit('conversation_create', (string) $sender_id, 30, HOUR_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many conversation requests.', ['status' => 429]);
        }
        sort($recipients, SORT_NUMERIC);
        $recipient_hash = hash('sha256', implode(',', $recipients));
        $reservation = hash('sha256', 'smail-group|' . $sender_id . '|' . $client_key);
        $conversations = SN_DB::table('conversations');
        $members = SN_DB::table('members');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE direct_key=%s LIMIT 1", $reservation));
        if ($existing) {
            return self::validate_smail_group_reservation($existing, $sender_id, $recipients, $recipient_hash);
        }
        $now = current_time('mysql', true);
        $settings = (string) wp_json_encode(['purpose' => 'smail', 'recipient_hash' => $recipient_hash]);
        $conversation_id = 0;
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $ok = $wpdb->insert($conversations, [
                'type' => 'group',
                'title' => mb_substr('Smail: ' . $subject, 0, 191),
                'slug' => 'smail-' . substr($reservation, 0, 24),
                'direct_key' => $reservation,
                'owner_id' => $sender_id,
                'description' => '',
                'privacy' => 'private',
                'status' => 'active',
                'settings' => $settings,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($ok === false) {
                throw new RuntimeException('smail_conversation_insert_failed');
            }
            $conversation_id = (int) $wpdb->insert_id;
            foreach (array_values(array_unique(array_merge([$sender_id], $recipients))) as $member_id) {
                if ($wpdb->insert($members, [
                    'conversation_id' => $conversation_id,
                    'user_id' => $member_id,
                    'role' => $member_id === $sender_id ? 'owner' : 'member',
                    'joined_at' => $now,
                ]) === false) {
                    throw new RuntimeException('smail_member_insert_failed');
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('smail_conversation_commit_failed');
            }
            SN_DB::audit('smail_conversation_reserved', 'conversation', $conversation_id, 'success', ['recipient_count' => count($recipients)], $sender_id);
            return $conversation_id;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $race = $wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE direct_key=%s LIMIT 1", $reservation));
            if ($race) {
                return self::validate_smail_group_reservation($race, $sender_id, $recipients, $recipient_hash);
            }
            return new WP_Error('smail_conversation_failed', 'The Smail group conversation could not be created safely.', ['status' => 500]);
        }
    }

    private static function validate_smail_group_reservation(object $conversation, int $sender_id, array $recipients, string $recipient_hash): int|WP_Error {
        global $wpdb;
        if ((string) $conversation->type !== 'group' || (string) $conversation->status !== 'active' || (int) $conversation->owner_id !== $sender_id) {
            return new WP_Error('smail_idempotency_conflict', 'The Smail idempotency key conflicts with another conversation.', ['status' => 409]);
        }
        $settings = json_decode((string) $conversation->settings, true);
        if (!is_array($settings) || !hash_equals($recipient_hash, (string) ($settings['recipient_hash'] ?? ''))) {
            return new WP_Error('smail_idempotency_conflict', 'The Smail idempotency key was reused with different recipients.', ['status' => 409]);
        }
        $expected = array_values(array_unique(array_merge([$sender_id], $recipients)));
        sort($expected, SORT_NUMERIC);
        $actual = array_values(array_map('absint', $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL ORDER BY user_id ASC',
            (int) $conversation->id
        )) ?: []));
        sort($actual, SORT_NUMERIC);
        return $actual === $expected
            ? (int) $conversation->id
            : new WP_Error('smail_membership_conflict', 'The reserved Smail conversation membership changed.', ['status' => 409]);
    }

    /** Encrypted, audience-minimized forward path replacing the legacy plaintext copy. */
    public static function forward_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $source_id = absint($request['id']);
        $actor = get_current_user_id();
        $target_conversation = absint($request->get_param('conversation_id'));
        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $source_id));
        $target = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . SN_DB::table('conversations') . " WHERE id=%d AND status='active'", $target_conversation));
        if (!$source || !$target || !SN_DB::is_member((int) $source->conversation_id, $actor) || !SN_DB::is_member($target_conversation, $actor)) {
            return new WP_Error('not_found', 'The source or target conversation is unavailable.', ['status' => 404]);
        }
        if ($source->deleted_at) {
            return new WP_Error('sn_forward_source_unavailable', 'The source message is unavailable.', ['status' => 409]);
        }
        $policy = SN_Policy::can_post_to_conversation($target, $actor);
        if (is_wp_error($policy)) {
            return $policy;
        }
        if (!SN_Policy::consume_rate_limit('message_forward', (string) $actor, 60, MINUTE_IN_SECONDS)) {
            return new WP_Error('sn_forward_rate_limited', 'Too many forwards were requested.', ['status' => 429]);
        }
        $plain = SN_Message_Body::decrypt_row($source);
        if (is_wp_error($plain)) {
            return $plain;
        }
        $plain = mb_substr($plain, 0, 10000);
        if ((int) $source->attachment_id > 0 && (string) $source->attachment_source === 'private' && $plain === '') {
            return new WP_Error('sn_forward_private_attachment_requires_resend', 'Private attachments must be re-uploaded rather than reusing their identifier or bytes.', ['status' => 409]);
        }
        if ($plain === '') {
            return new WP_Error('sn_forward_empty', 'There is no permitted content to forward.', ['status' => 409]);
        }
        $client = strtolower(trim((string) $request->get_param('client_id'))) ?: wp_generate_uuid4();
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) {
            return new WP_Error('sn_forward_client_id_invalid', 'A valid idempotency key is required.', ['status' => 400]);
        }
        $idem = hash('sha256', $actor . ':' . $target_conversation . ':forward:' . $source_id . ':' . $client);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idem));
        if ($existing) {
            return rest_ensure_response(['message_id' => (int) $existing->id, 'duplicate' => true]);
        }
        $stored = SN_Message_Body::encrypt($plain, $target_conversation, $actor);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $metadata = (string) wp_json_encode([
            'forwarded' => true,
            'source_scope_hash' => hash('sha256', $source_id . '|' . (int) $source->conversation_id . '|' . (string) $source->created_at),
        ]);
        $now = current_time('mysql', true);
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $space_policy = SN_Spaces::assert_post_allowed_in_transaction($target_conversation, $actor);
            if (is_wp_error($space_policy)) {
                $wpdb->query('ROLLBACK');
                return $space_policy;
            }
            if ($wpdb->insert(SN_DB::table('messages'), [
                'conversation_id' => $target_conversation,
                'sender_id' => $actor,
                'message_type' => 'text',
                'body' => $stored,
                'attachment_id' => 0,
                'attachment_source' => 'none',
                'reply_to' => 0,
                'idempotency_key' => $idem,
                'metadata' => $metadata,
                'created_at' => $now,
            ]) === false) {
                throw new RuntimeException('forward_insert_failed');
            }
            $new_id = (int) $wpdb->insert_id;
            if ($wpdb->query($wpdb->prepare(
                'UPDATE ' . SN_DB::table('conversations') . ' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d',
                $new_id,
                $now,
                $target_conversation
            )) === false) {
                throw new RuntimeException('forward_pointer_failed');
            }
            SN_Spaces::mark_posted_for_conversation($target_conversation, $actor, $now);
            $indexed = SN_Message_Search::index_message($new_id);
            if (is_wp_error($indexed)) {
                throw new RuntimeException($indexed->get_error_code());
            }
            $sent_event = SN_Outbox::enqueue('message.sent', 'message', $new_id, [
                'message_id' => $new_id,
                'conversation_id' => $target_conversation,
                'sender_id' => $actor,
                'message_type' => 'text',
                'created_at' => $now,
            ], 'message.sent:' . $new_id);
            $forward_event = SN_Outbox::enqueue('message.forwarded', 'message', $new_id, [
                'message_id' => $new_id,
                'conversation_id' => $target_conversation,
                'sender_id' => $actor,
                'source_scope_hash' => hash('sha256', $source_id . '|' . (int) $source->conversation_id . '|' . (string) $source->created_at),
                'source_visible' => false,
            ], 'message.forwarded:' . $new_id);
            if (is_wp_error($sent_event) || is_wp_error($forward_event)) {
                throw new RuntimeException('forward_event_failed');
            }
            SN_DB::audit('message_forwarded', 'message', $new_id, 'success', ['target_conversation_id' => $target_conversation, 'source_visible' => false], $actor);
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('forward_commit_failed');
            }
            do_action('sn_network_event_queued', $sent_event, 'message.sent');
            do_action('sn_network_event_queued', $forward_event, 'message.forwarded');
            return new WP_REST_Response(['message_id' => $new_id, 'source_visible' => false, 'private_attachment_forwarded' => false], 201);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $race = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idem));
            return $race
                ? rest_ensure_response(['message_id' => (int) $race->id, 'duplicate' => true])
                : new WP_Error('sn_forward_failed', 'The forward could not be committed.', ['status' => 500]);
        }
    }
}
