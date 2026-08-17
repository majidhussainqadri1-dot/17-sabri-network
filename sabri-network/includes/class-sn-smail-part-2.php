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
        $draft_id = sanitize_text_field((string) $request->get_param('draft_id'));
        $draft_version = absint($request->get_param('draft_version'));
        if ($draft_id !== '') {
            $draft = self::draft_row($draft_id, $sender_id);
            if (!$draft) return new WP_Error('draft_not_found', 'The Smail draft is unavailable.', ['status'=>404]);
            if ($draft_version <= 0 || $draft_version !== (int)$draft->version) return new WP_Error('draft_conflict', 'The Smail draft changed on another device. Reload and retry.', ['status'=>409]);
        }

        $client_key = hash('sha256', $sender_id . '|' . $client_id);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE client_key=%s', $client_key));
        if ($existing) {
            $cleanup = $draft_id === '' ? true : self::trash_draft_exact($draft_id, $sender_id, $draft_version);
            return rest_ensure_response(['smail' => self::format_smail($existing), 'duplicate' => true, 'draft_cleanup_pending' => !$cleanup]);
        }

        // The Smail idempotency key reserves/reuses the same canonical conversation,
        // including multi-recipient mail after an interrupted projection attempt.
        $conversation_id = SN_Central_Plan_Hardening::resolve_smail_conversation($sender_id, $recipients, $subject, $client_key);
        if (is_wp_error($conversation_id)) { return $conversation_id; }
        $conversation_id = (int) $conversation_id;
        if (!$conversation_id) { return new WP_Error('smail_conversation_failed', 'The Smail conversation could not be resolved.', ['status' => 500]); }

        // Smail is a mailbox projection over the canonical File-17 message. Do not
        // bypass the atomic search/outbox/encryption path by calling SN_REST directly.
        $message_request = new WP_REST_Request('POST', '/sabri-network/v2/conversations/' . $conversation_id . '/messages');
        $message_request->set_url_params(['id'=>$conversation_id]);
        $message_request->set_param('id', $conversation_id);
        $message_request->set_param('body', $body);
        $message_request->set_param('message_type', 'text');
        $message_request->set_param('client_id', 'smail:' . substr($client_key, 0, 40));
        $message_response = SN_Message_Integrity::send_message($message_request);
        if (is_wp_error($message_response)) { return $message_response; }
        $message_data = $message_response->get_data();
        $message_id = (int) ($message_data['message']['id'] ?? 0);
        if (!$message_id) { return new WP_Error('smail_message_failed', 'The canonical message could not be created.', ['status' => 500]); }

        $now = current_time('mysql', true);
        $smail_id = 0;
        $event = null;
        if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('smail_projection_failed','The canonical message was created but the mailbox transaction could not start.',['status'=>500]);
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
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('smail_projection_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('smail_projection_failed', 'message', $message_id, 'failure', ['conversation_id' => $conversation_id,'reason'=>$e->getMessage()]);
            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE client_key=%s', $client_key));
            if ($race) {
                $expected_states = count(array_unique(array_merge([$sender_id],$recipients)));
                $state_count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::states_table().' WHERE smail_message_id=%d',(int)$race->id));
                if ($state_count === $expected_states) {
                    $cleanup = $draft_id === '' ? true : self::trash_draft_exact($draft_id,$sender_id,$draft_version);
                    return rest_ensure_response(['smail'=>self::format_smail($race),'message'=>$message_data['message']??null,'duplicate'=>true,'commit_reconciled'=>true,'draft_cleanup_pending'=>!$cleanup]);
                }
            }
            return new WP_Error('smail_projection_failed', 'The canonical message was created but its Smail mailbox projection could not be completed.', ['status' => 500]);
        }
        foreach ($recipients as $recipient_id) {
            SN_DB::add_notification($recipient_id, 'smail_received', 'New Smail message', '', 'smail', $smail_id);
        }
        if ($event !== null) do_action('sn_network_event_queued',$event,'smail.sent');
        SN_DB::audit('smail_sent', 'smail', $smail_id, 'success', ['conversation_id' => $conversation_id, 'recipients' => count($recipients)]);
        $cleanup = $draft_id === '' ? true : self::trash_draft_exact($draft_id, $sender_id, $draft_version);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE id=%d', $smail_id));
        return rest_ensure_response(['smail' => self::format_smail($row), 'message' => $message_data['message'] ?? null, 'draft_cleanup_pending'=>!$cleanup]);
    }

    private static function trash_draft_exact(string $public_id,int $owner_id,int $expected_version): bool {
        global $wpdb;
        if($public_id===''||$expected_version<=0)return false;
        $now=current_time('mysql',true);
        return $wpdb->query($wpdb->prepare(
            'UPDATE '.self::drafts_table().' SET deleted_at=%s,encrypted_payload=%s,payload_hash=%s,version=version+1,updated_at=%s WHERE public_id=%s AND owner_id=%d AND version=%d AND deleted_at IS NULL',
            $now,'',hash_hmac('sha256','',wp_salt('auth').'|sn-sm-draft-blind-v1'),$now,sanitize_text_field($public_id),$owner_id,$expected_version
        ))===1;
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
