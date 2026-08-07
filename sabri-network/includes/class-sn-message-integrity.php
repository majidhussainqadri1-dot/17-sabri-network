<?php
defined('ABSPATH') || exit;

/** Atomic message/search/event mutations layered over the canonical File-17 routes. */
final class SN_Message_Integrity {
    private const MAX_MESSAGE_CHARS = 10000;
    private const MAX_RECEIPT_RANGE = 500;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 999);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/messages', [
            ['methods' => 'GET', 'callback' => [SN_REST::class, 'get_messages'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'send_message'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'edit_message'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_message'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/receipts', [
            ['methods' => 'GET', 'callback' => [SN_Messages::class, 'get_receipts'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'record_receipt'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
    }

    public static function send_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        $conversation = self::conversation_row($conversation_id);
        if (!$conversation || !SN_DB::is_member($conversation_id, $user_id)) return self::not_found();
        $post_policy = SN_Policy::can_post_to_conversation($conversation, $user_id);
        if (is_wp_error($post_policy)) return $post_policy;
        $contact = self::conversation_contact_check($conversation, $conversation_id, $user_id, 'message');
        if (is_wp_error($contact)) return $contact;
        if (!SN_Policy::consume_rate_limit('message_send', (string) $user_id, 120, MINUTE_IN_SECONDS)) return new WP_Error('rate_limited', 'Too many message requests.', ['status' => 429]);

        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if (mb_strlen($body) > self::MAX_MESSAGE_CHARS) return new WP_Error('message_too_long', 'The message is longer than the permitted limit.', ['status' => 413]);
        $message_type = sanitize_key((string) $request->get_param('message_type')) ?: 'text';
        if (!in_array($message_type, ['text','image','video','audio','document'], true)) $message_type = 'text';
        $reply_to = absint($request->get_param('reply_to'));
        if ($reply_to && !self::message_in_conversation($reply_to, $conversation_id)) return new WP_Error('invalid_reply', 'The replied-to message is unavailable.', ['status' => 400]);
        $client_id = strtolower(trim((string) $request->get_param('client_id'))) ?: wp_generate_uuid4();
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client_id)) return new WP_Error('invalid_client_id', 'A valid message idempotency key is required.', ['status' => 400]);
        $idempotency_key = hash('sha256', $user_id . ':' . $conversation_id . ':' . $client_id);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idempotency_key));
        if ($existing) return self::finalize_existing_message($existing, $user_id, true);

        $attachment = null;
        $files = $request->get_file_params();
        if (!empty($files['attachment']) && is_array($files['attachment'])) {
            // File bytes and their ledger row are created before the transaction so a later rollback can delete them safely.
            $attachment = SN_Private_Files::create_from_upload($files['attachment'], $user_id);
            if (is_wp_error($attachment)) return $attachment;
            $message_type = (string) $attachment['type'];
        }
        if ($body === '' && !$attachment) return new WP_Error('empty_message', 'Write a message or attach a file.', ['status' => 400]);
        if (!$attachment) $message_type = 'text';

        $stored_body = SN_Message_Body::encrypt($body, $conversation_id, $user_id);
        if (is_wp_error($stored_body)) {
            if ($attachment) SN_Private_Files::delete((int) $attachment['id'], $user_id);
            return $stored_body;
        }
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->insert(SN_DB::table('messages'), [
                'conversation_id' => $conversation_id, 'sender_id' => $user_id, 'message_type' => $message_type,
                'body' => $stored_body, 'attachment_id' => $attachment ? (int) $attachment['id'] : 0,
                'attachment_source' => $attachment ? 'private' : 'none', 'reply_to' => $reply_to,
                'idempotency_key' => $idempotency_key, 'metadata' => '{}', 'created_at' => $now,
            ]);
            if ($inserted === false) throw new RuntimeException('message_insert_failed');
            $message_id = (int) $wpdb->insert_id;
            if ($wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('conversations') . ' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d', $message_id, $now, $conversation_id)) === false) throw new RuntimeException('message_pointer_failed');
            foreach (self::recipient_ids($conversation_id, $user_id) as $recipient_id) SN_DB::add_notification($recipient_id, 'message_received', 'New Network message', '', 'conversation', $conversation_id);
            $indexed = SN_Message_Search::index_message($message_id);
            if (is_wp_error($indexed)) throw new RuntimeException($indexed->get_error_code());
            $event_id = SN_Outbox::enqueue('message.sent', 'message', $message_id, [
                'message_id' => $message_id, 'conversation_id' => $conversation_id, 'sender_id' => $user_id,
                'message_type' => $message_type,
                'created_at' => $now,
            ], 'message.sent:' . $message_id);
            if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_code());
            SN_DB::audit('message_sent', 'message', $message_id, 'success', ['conversation_id' => $conversation_id, 'type' => $message_type], $user_id);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_commit_failed');
            do_action('sn_network_event_queued', $event_id, 'message.sent');
            return rest_ensure_response(['message' => self::format_message(self::message_row($message_id), $user_id)]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($attachment) SN_Private_Files::delete((int) $attachment['id'], $user_id);
            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idempotency_key));
            if ($race) return self::finalize_existing_message($race, $user_id, true);
            SN_DB::audit('message_atomic_send_failed', 'conversation', $conversation_id, 'failure', ['reason' => $e->getMessage()], $user_id);
            return new WP_Error('message_atomic_send_failed', 'The message could not be committed with its search and delivery records.', ['status' => 500]);
        }
    }

    public static function edit_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $message = self::message_row($id);
        $user_id = get_current_user_id();
        if (!$message || !SN_DB::is_member((int) $message->conversation_id, $user_id)) return self::not_found();
        if (!SN_Policy::can_edit_message($message, $user_id)) return new WP_Error('edit_forbidden', 'This message can no longer be edited.', ['status' => 403]);
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if ($body === '' || mb_strlen($body) > self::MAX_MESSAGE_CHARS) return new WP_Error('invalid_message', 'Enter a valid message within the permitted length.', ['status' => 400]);
        $stored_body = SN_Message_Body::encrypt($body, (int) $message->conversation_id, (int) $message->sender_id);
        if (is_wp_error($stored_body)) return $stored_body;
        $wpdb->query('START TRANSACTION');
        try {
            $edited_at = current_time('mysql', true);
            if ($wpdb->update(SN_DB::table('messages'), ['body' => $stored_body, 'edited_at' => $edited_at], ['id' => $id]) === false) throw new RuntimeException('message_update_failed');
            $indexed = SN_Message_Search::index_message($id);
            if (is_wp_error($indexed)) throw new RuntimeException($indexed->get_error_code());
            $event_id = SN_Outbox::enqueue('message.edited', 'message', $id, [
                'message_id' => $id, 'conversation_id' => (int) $message->conversation_id,
                'sender_id' => $user_id, 'revision_hash' => hash('sha256', $body),
            ], 'message.edited:' . $id . ':' . hash('sha256', $body));
            if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_code());
            SN_DB::audit('message_edited', 'message', $id, 'success', ['conversation_id' => (int) $message->conversation_id], $user_id);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_edit_commit_failed');
            do_action('sn_network_event_queued', $event_id, 'message.edited');
            return rest_ensure_response(['message' => self::format_message(self::message_row($id), $user_id)]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_atomic_edit_failed', 'message', $id, 'failure', ['reason' => $e->getMessage()], $user_id);
            return new WP_Error('message_atomic_edit_failed', 'The message edit could not be committed.', ['status' => 500]);
        }
    }

    public static function delete_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $message = self::message_row($id);
        $user_id = get_current_user_id();
        if (!$message || !SN_DB::is_member((int) $message->conversation_id, $user_id)) return self::not_found();
        if (!SN_Policy::can_delete_message($message, $user_id)) return new WP_Error('delete_forbidden', 'This message can no longer be deleted.', ['status' => 403]);
        $attachment_id = (string) $message->attachment_source === 'private' ? (int) $message->attachment_id : 0;
        $wpdb->query('START TRANSACTION');
        try {
            $deleted_at = current_time('mysql', true);
            if ($wpdb->update(SN_DB::table('messages'), ['body' => '', 'attachment_id' => 0, 'attachment_source' => 'erased', 'deleted_at' => $deleted_at], ['id' => $id]) === false) throw new RuntimeException('message_delete_failed');
            if ($wpdb->delete(SN_DB::table('reactions'), ['message_id' => $id], ['%d']) === false) throw new RuntimeException('message_reaction_delete_failed');
            $removed = SN_Message_Search::remove_message($id);
            if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
            $event_id = SN_Outbox::enqueue('message.deleted', 'message', $id, [
                'message_id' => $id, 'conversation_id' => (int) $message->conversation_id,
                'sender_id' => (int) $message->sender_id, 'deleted_by' => $user_id, 'deleted_at' => $deleted_at,
            ], 'message.deleted:' . $id);
            if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_code());
            SN_DB::audit('message_deleted', 'message', $id, 'success', ['conversation_id' => (int) $message->conversation_id], $user_id);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_delete_commit_failed');
            if ($attachment_id > 0) SN_Private_Files::delete($attachment_id, $user_id);
            do_action('sn_network_event_queued', $event_id, 'message.deleted');
            return rest_ensure_response(['deleted' => true]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_atomic_delete_failed', 'message', $id, 'failure', ['reason' => $e->getMessage()], $user_id);
            return new WP_Error('message_atomic_delete_failed', 'The message deletion could not be committed.', ['status' => 500]);
        }
    }

    public static function record_receipt(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id)) return self::not_found();
        if (!SN_Policy::consume_rate_limit('message_receipt', (string) $user_id, 600, MINUTE_IN_SECONDS)) return new WP_Error('rate_limited', 'Too many receipt updates.', ['status' => 429]);
        $requested_id = absint($request->get_param('message_id'));
        $state = sanitize_key((string) $request->get_param('state'));
        $device_key = self::device_key((string) $request->get_param('device_id'), $user_id);
        if (is_wp_error($device_key)) return $device_key;
        if (!in_array($state, ['delivered','read'], true) || $requested_id <= 0) return new WP_Error('invalid_receipt', 'A valid message and receipt state are required.', ['status' => 400]);
        $messages = SN_DB::table('messages');
        $target = $wpdb->get_row($wpdb->prepare("SELECT id,sender_id,deleted_at FROM $messages WHERE id=%d AND conversation_id=%d", $requested_id, $conversation_id));
        if (!$target || $target->deleted_at) return self::not_found();
        if ((int) $target->sender_id === $user_id) return new WP_Error('own_message_receipt', 'A sender cannot record their own recipient receipt.', ['status' => 409]);
        $table = SN_DB::table('message_receipts');
        $column = $state === 'read' ? 'read_at' : 'delivered_at';
        $through = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(message_id),0) FROM $table WHERE conversation_id=%d AND user_id=%d AND device_key=%s AND $column IS NOT NULL", $conversation_id, $user_id, $device_key));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $conversation_id, $through, $requested_id, $user_id, self::MAX_RECEIPT_RANGE));
        if (!is_array($rows)) return new WP_Error('database_error', 'The receipt range could not be read.', ['status' => 500]);
        $recorded = 0; $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                $row_id = (int) $row->id;
                $sql = $state === 'read'
                    ? $wpdb->prepare("INSERT INTO $table (message_id,conversation_id,user_id,device_key,delivered_at,read_at,updated_at) VALUES (%d,%d,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE delivered_at=COALESCE(delivered_at,VALUES(delivered_at)),read_at=COALESCE(read_at,VALUES(read_at)),updated_at=VALUES(updated_at)", $row_id, $conversation_id, $user_id, $device_key, $now, $now, $now)
                    : $wpdb->prepare("INSERT INTO $table (message_id,conversation_id,user_id,device_key,delivered_at,read_at,updated_at) VALUES (%d,%d,%d,%s,%s,NULL,%s) ON DUPLICATE KEY UPDATE delivered_at=COALESCE(delivered_at,VALUES(delivered_at)),updated_at=VALUES(updated_at)", $row_id, $conversation_id, $user_id, $device_key, $now, $now);
                if ($wpdb->query($sql) === false) throw new RuntimeException('receipt_write_failed');
                $through = max($through, $row_id); $recorded++;
            }
            if ($state === 'read' && $through > 0 && $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('members') . ' SET last_read_message_id=GREATEST(last_read_message_id,%d) WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL', $through, $conversation_id, $user_id)) === false) throw new RuntimeException('read_pointer_failed');
            $event_id = SN_Outbox::enqueue('message.' . $state, 'conversation', $conversation_id, [
                'conversation_id' => $conversation_id, 'recipient_id' => $user_id,
                'through_message_id' => $through, 'requested_message_id' => $requested_id,
                'state' => $state,
            ], 'message.' . $state . ':' . $conversation_id . ':' . $user_id . ':' . $device_key . ':' . $requested_id . ':' . $through);
            if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_code());
            SN_DB::audit('message_receipt_recorded', 'conversation', $conversation_id, 'success', ['requested_message_id' => $requested_id, 'through_message_id' => $through, 'state' => $state, 'recorded' => $recorded], $user_id);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('receipt_commit_failed');
            do_action('sn_network_event_queued', $event_id, 'message.' . $state);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_receipt_failed', 'conversation', $conversation_id, 'failure', ['requested_message_id' => $requested_id, 'through_message_id' => $through, 'state' => $state, 'reason' => $e->getMessage()], $user_id);
            return new WP_Error('database_error', 'The receipt could not be committed.', ['status' => 500]);
        }
        $more = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $conversation_id, $through, $requested_id, $user_id));
        do_action('sn_network_message_receipt_recorded', $conversation_id, $through, $user_id, $state, $requested_id, $more);
        return rest_ensure_response(['state' => $state, 'recorded' => $recorded, 'requested_message_id' => $requested_id, 'through_message_id' => $through, 'more' => $more]);
    }

    private static function device_key(string $raw, int $user_id): string|WP_Error {
        $raw = strtolower(trim(wp_unslash($raw)));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{15,127}$/', $raw)) return new WP_Error('invalid_device_id', 'A valid bounded device identifier is required.', ['status' => 400]);
        return hash('sha256', $user_id . '|' . $raw . '|' . wp_salt('auth'));
    }

    private static function finalize_existing_message(object $message, int $user_id, bool $duplicate): WP_REST_Response|WP_Error {
        global $wpdb;
        $secured = SN_Message_Body::ensure_encrypted_row($message);
        if (is_wp_error($secured)) return $secured;
        $message = $secured;
        $wpdb->query('START TRANSACTION');
        try {
            $indexed = SN_Message_Search::index_message((int) $message->id);
            if (is_wp_error($indexed)) throw new RuntimeException($indexed->get_error_code());
            $event_id = SN_Outbox::enqueue('message.sent', 'message', (int) $message->id, [
                'message_id' => (int) $message->id,
                'conversation_id' => (int) $message->conversation_id,
                'sender_id' => (int) $message->sender_id,
                'message_type' => (string) $message->message_type,
                'created_at' => (string) $message->created_at,
            ], 'message.sent:' . (int) $message->id);
            if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_code());
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('duplicate_message_commit_failed');
            do_action('sn_network_event_queued', $event_id, 'message.sent');
            return rest_ensure_response(['message' => self::format_message($message, $user_id), 'duplicate' => $duplicate]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_duplicate_reconciliation_failed', 'message', (int) $message->id, 'failure', ['reason' => $e->getMessage()], $user_id);
            return new WP_Error('message_duplicate_reconciliation_failed', 'The existing message could not be reconciled with its search and delivery records.', ['status' => 500]);
        }
    }

    private static function conversation_row(int $conversation_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('conversations') . ' WHERE id=%d AND status=%s', $conversation_id, 'active')) ?: null;
    }

    private static function conversation_contact_check(object $conversation, int $conversation_id, int $actor_id, string $context): bool|WP_Error {
        $others = self::recipient_ids($conversation_id, $actor_id);
        if ((string) $conversation->type !== 'direct') {
            foreach ($others as $target_id) if (SN_DB::is_blocked($actor_id, $target_id)) return new WP_Error('blocked', 'A conversation member is unavailable.', ['status' => 403]);
            return true;
        }
        if (count($others) !== 1) return new WP_Error('invalid_direct_conversation', 'The direct conversation membership is invalid.', ['status' => 409]);
        return SN_Policy::can_contact($actor_id, $others[0], $context);
    }

    private static function message_in_conversation(int $message_id, int $conversation_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id=%d AND conversation_id=%d', $message_id, $conversation_id));
    }

    private static function reactions(int $message_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total FROM ' . SN_DB::table('reactions') . ' WHERE message_id=%d GROUP BY reaction ORDER BY reaction ASC', $message_id));
        return array_map(static fn(object $row): array => ['reaction' => (string) $row->reaction, 'count' => (int) $row->total], is_array($rows) ? $rows : []);
    }

    private static function response_data(WP_REST_Response $response): array { $data = $response->get_data(); return is_array($data) ? $data : []; }
    private static function message_row(int $id): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id)) ?: null; }
    private static function recipient_ids(int $conversation_id, int $sender_id): array { global $wpdb; return array_values(array_map('absint', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1000', $conversation_id, $sender_id)) ?: [])); }
    private static function format_message(?object $row, int $viewer_id): array {
        if (!$row) return [];
        $sender = SN_Auth::public_user((int) $row->sender_id) ?: ['id'=>0,'name'=>'Unavailable account','avatar'=>SN_URL.'assets/network-default-avatar.svg'];
        $attachment = !$row->deleted_at && (int) $row->attachment_id > 0 && (string) $row->attachment_source === 'private' ? SN_Private_Files::formatted((int) $row->attachment_id, $viewer_id) : null;
        $plain = $row->deleted_at ? '' : SN_Message_Body::decrypt_row($row);
        $unavailable = is_wp_error($plain);
        return ['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'sender'=>$sender,'message_type'=>(string)$row->message_type,'body'=>$unavailable?'':(string)$plain,'body_unavailable'=>$unavailable,'attachment'=>$attachment,'reply_to'=>(int)$row->reply_to,'reactions'=>self::reactions((int)$row->id),'edited'=>(bool)$row->edited_at,'deleted'=>(bool)$row->deleted_at,'created_at'=>(string)$row->created_at];
    }
    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested conversation or message is unavailable.', ['status' => 404]); }
}
