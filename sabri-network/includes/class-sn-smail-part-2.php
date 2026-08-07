<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_2 {

    public static function send(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $sender_id = get_current_user_id();
        $recipients = array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('recipient_ids')))));
        $recipients = array_values(array_diff($recipients, [$sender_id]));
        if (!$recipients || count($recipients) > self::MAX_RECIPIENTS) {
            return new WP_Error('invalid_recipients', 'Select between one and fifty permitted recipients.', ['status' => 400]);
        }
        $subject = mb_substr(sanitize_text_field((string) $request->get_param('subject')), 0, self::MAX_SUBJECT);
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if ($subject === '' || $body === '') {
            return new WP_Error('smail_content_required', 'A subject and message are required.', ['status' => 400]);
        }
        foreach ($recipients as $recipient_id) {
            $allowed = SN_Policy::can_contact($sender_id, $recipient_id, count($recipients) === 1 ? 'message' : 'group');
            if (is_wp_error($allowed)) { return $allowed; }
        }
        if (!SN_Policy::consume_rate_limit('smail_send', (string) $sender_id, 60, HOUR_IN_SECONDS)) {
            return new WP_Error('smail_rate_limited', 'Too many Smail messages were sent. Try again later.', ['status' => 429]);
        }
        $client_id = strtolower(trim((string) $request->get_param('client_id')));
        if ($client_id === '') { $client_id = wp_generate_uuid4(); }
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client_id)) {
            return new WP_Error('invalid_client_id', 'A valid Smail idempotency key is required.', ['status' => 400]);
        }
        $client_key = hash('sha256', $sender_id . '|' . $client_id);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE client_key=%s', $client_key));
        if ($existing) { return rest_ensure_response(['smail' => self::format_smail($existing), 'duplicate' => true]); }

        $conversation_request = new WP_REST_Request('POST', '/sabri-network/v2/conversations');
        if (count($recipients) === 1) {
            $conversation_request->set_param('type', 'direct');
            $conversation_request->set_param('user_id', $recipients[0]);
        } else {
            $conversation_request->set_param('type', 'group');
            $conversation_request->set_param('member_ids', $recipients);
            $conversation_request->set_param('title', 'Smail: ' . $subject);
            $conversation_request->set_param('privacy', 'private');
        }
        $conversation_response = SN_REST::create_conversation($conversation_request);
        if (is_wp_error($conversation_response)) { return $conversation_response; }
        $conversation_data = $conversation_response->get_data();
        $conversation_id = (int) ($conversation_data['conversation']['id'] ?? 0);
        if (!$conversation_id) { return new WP_Error('smail_conversation_failed', 'The Smail conversation could not be resolved.', ['status' => 500]); }

        $message_request = new WP_REST_Request('POST', '/sabri-network/v2/conversations/' . $conversation_id . '/messages');
        $message_request->set_param('id', $conversation_id);
        $message_request->set_param('body', $body);
        $message_request->set_param('message_type', 'text');
        $message_request->set_param('client_id', 'smail:' . substr($client_key, 0, 40));
        $message_response = SN_REST::send_message($message_request);
        if (is_wp_error($message_response)) { return $message_response; }
        $message_data = $message_response->get_data();
        $message_id = (int) ($message_data['message']['id'] ?? 0);
        if (!$message_id) { return new WP_Error('smail_message_failed', 'The canonical message could not be created.', ['status' => 500]); }

        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->insert(self::messages_table(), [
                'message_id' => $message_id, 'conversation_id' => $conversation_id, 'sender_id' => $sender_id,
                'subject' => $subject, 'client_key' => $client_key, 'created_at' => $now,
            ]);
            if ($inserted === false) { throw new RuntimeException('smail_projection_failed'); }
            $smail_id = (int) $wpdb->insert_id;
            foreach (array_values(array_unique(array_merge([$sender_id], $recipients))) as $user_id) {
                if ($wpdb->insert(self::states_table(), [
                    'smail_message_id' => $smail_id, 'user_id' => $user_id, 'updated_at' => $now,
                    'read_at' => $user_id === $sender_id ? $now : null,
                ]) === false) { throw new RuntimeException('smail_state_failed'); }
            }
            $event = SN_Outbox::enqueue('smail.sent', 'smail', $smail_id, [
                'smail_id' => $smail_id, 'conversation_id' => $conversation_id, 'message_id' => $message_id,
                'sender_id' => $sender_id, 'recipient_count' => count($recipients),
            ], 'smail-sent-' . $smail_id);
            if (is_wp_error($event)) { throw new RuntimeException('smail_event_failed'); }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('smail_projection_failed', 'message', $message_id, 'failure', ['conversation_id' => $conversation_id]);
            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE client_key=%s', $client_key));
            if ($race) { return rest_ensure_response(['smail' => self::format_smail($race), 'message' => $message_data['message'] ?? null, 'duplicate' => true]); }
            return new WP_Error('smail_projection_failed', 'The canonical message was created but its Smail mailbox projection could not be completed.', ['status' => 500]);
        }
        foreach ($recipients as $recipient_id) {
            SN_DB::add_notification($recipient_id, 'smail_received', 'New Smail message', '', 'smail', $smail_id);
        }
        SN_DB::audit('smail_sent', 'smail', $smail_id, 'success', ['conversation_id' => $conversation_id, 'recipients' => count($recipients)]);
        if ($draft_id = sanitize_text_field((string) $request->get_param('draft_id'))) { self::trash_draft_by_public_id($draft_id, $sender_id); }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE id=%d', $smail_id));
        return rest_ensure_response(['smail' => self::format_smail($row), 'message' => $message_data['message'] ?? null]);
    }


    public static function update_state(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']); $user_id = get_current_user_id();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::states_table() . ' WHERE smail_message_id=%d AND user_id=%d', $id, $user_id));
        if (!$row) { return new WP_Error('smail_not_found', 'The Smail item is unavailable.', ['status' => 404]); }
        $allowed = ['starred' => 'is_starred', 'archived' => 'is_archived', 'spam' => 'is_spam', 'trashed' => 'trashed_at', 'read' => 'read_at'];
        $field = sanitize_key((string) $request->get_param('field'));
        if (!isset($allowed[$field])) { return new WP_Error('invalid_smail_state', 'Select a valid Smail state.', ['status' => 400]); }
        $value = rest_sanitize_boolean($request->get_param('value'));
        $column = $allowed[$field]; $now = current_time('mysql', true);
        $data = ['updated_at' => $now, $column => in_array($column, ['trashed_at', 'read_at'], true) ? ($value ? $now : null) : ($value ? 1 : 0)];
        if ($wpdb->update(self::states_table(), $data, ['id' => (int) $row->id]) === false) {
            return new WP_Error('smail_state_failed', 'The Smail state could not be updated.', ['status' => 500]);
        }
        SN_DB::audit('smail_state_updated', 'smail', $id, 'success', ['field' => $field, 'value' => $value]);
        return rest_ensure_response(['updated' => true, 'field' => $field, 'value' => $value]);
    }


    public static function list_drafts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT public_id,version,created_at,updated_at FROM ' . self::drafts_table() . ' WHERE owner_id=%d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT %d', get_current_user_id(), self::MAX_DRAFTS));
        return rest_ensure_response(['drafts' => array_map(static fn($r): array => ['id' => (string) $r->public_id, 'version' => (int) $r->version, 'created_at' => (string) $r->created_at, 'updated_at' => (string) $r->updated_at], $rows ?: [])]);
    }

}
