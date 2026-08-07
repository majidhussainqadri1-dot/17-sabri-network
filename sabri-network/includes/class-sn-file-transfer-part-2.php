<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_2 {

    public static function initiate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $sender_id = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('transfer_initiate', (string) $sender_id, 60, DAY_IN_SECONDS)) {
            return new WP_Error('transfer_rate_limited', 'The daily transfer initiation limit has been reached.', ['status' => 429]);
        }
        $name = sanitize_file_name((string) $request->get_param('name'));
        $original = mb_substr(sanitize_text_field((string) $request->get_param('name')), 0, 255);
        $total = (int) $request->get_param('size');
        if ($name === '' || $total < 1 || $total > self::MAX_FILE_BYTES) {
            return new WP_Error('invalid_transfer_file', 'Select a valid file not exceeding 1 GB.', ['status' => 413]);
        }
        $chunk_bytes = min(self::MAX_CHUNK_BYTES, max(self::MIN_CHUNK_BYTES, absint($request->get_param('chunk_size')) ?: self::DEFAULT_CHUNK_BYTES));
        $total_chunks = (int) ceil($total / $chunk_bytes);
        if ($total_chunks < 1 || $total_chunks > 1024) { return new WP_Error('invalid_chunk_plan', 'The proposed resumable chunk plan is invalid.', ['status' => 400]); }
        $recipients = self::resolve_recipients($request, $sender_id);
        if (is_wp_error($recipients)) { return $recipients; }
        $conversation_id = absint($request->get_param('conversation_id'));
        $client_id = strtolower(trim((string) $request->get_param('client_id'))); if ($client_id === '') { $client_id = wp_generate_uuid4(); }
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client_id)) { return new WP_Error('invalid_client_id', 'A valid transfer idempotency key is required.', ['status' => 400]); }
        $idempotency = hash('sha256', $sender_id . '|' . $client_id);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND idempotency_key=%s', $sender_id, $idempotency));
        if ($existing) { return rest_ensure_response(['transfer' => self::format($existing, $sender_id), 'duplicate' => true]); }
        $daily_limit = max(self::MAX_FILE_BYTES, (int) apply_filters('sn_network_daily_transfer_bytes', 3 * self::MAX_FILE_BYTES, $sender_id));
        $today = gmdate('Y-m-d 00:00:00');
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_bytes),0) FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND created_at>=%s AND status NOT IN (\'rejected\',\'revoked\',\'expired\')', $sender_id, $today));
        if ($used + $total > $daily_limit) { return new WP_Error('daily_transfer_volume_exceeded', 'The transparent daily transfer volume limit has been reached.', ['status' => 429]); }
        $expected = strtolower(trim((string) $request->get_param('sha256')));
        if ($expected !== '' && !preg_match('/^[a-f0-9]{64}$/', $expected)) { return new WP_Error('invalid_file_hash', 'The expected SHA-256 value is invalid.', ['status' => 400]); }
        $public_id = wp_generate_uuid4(); $now = current_time('mysql', true);
        $retention_days = min(365, max(1, (int) apply_filters('sn_network_transfer_retention_days', 30, $sender_id, $recipients)));
        $retention = gmdate('Y-m-d H:i:s', time() + $retention_days * DAY_IN_SECONDS);
        $upload_expiry = gmdate('Y-m-d H:i:s', time() + min(7, max(1, (int) get_option('sn_transfer_upload_expiry_days', 2))) * DAY_IN_SECONDS);
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->insert(self::sessions_table(), [
                'public_id' => $public_id, 'sender_id' => $sender_id, 'conversation_id' => $conversation_id,
                'original_name' => $original, 'safe_name' => $name, 'declared_mime' => mb_substr(sanitize_text_field((string) $request->get_param('mime')), 0, 191),
                'total_bytes' => $total, 'chunk_bytes' => $chunk_bytes, 'total_chunks' => $total_chunks,
                'expected_sha256' => $expected, 'status' => 'uploading', 'scan_status' => 'pending',
                'idempotency_key' => $idempotency, 'retention_until' => $retention, 'expires_at' => $upload_expiry,
                'created_at' => $now, 'updated_at' => $now,
            ]) === false) { throw new RuntimeException('transfer_session_failed'); }
            $transfer_id = (int) $wpdb->insert_id;
            foreach ($recipients as $recipient_id) {
                if ($wpdb->insert(self::recipients_table(), ['transfer_id' => $transfer_id, 'user_id' => $recipient_id, 'state' => 'pending', 'created_at' => $now, 'updated_at' => $now]) === false) { throw new RuntimeException('transfer_recipient_failed'); }
            }
            $event = SN_Outbox::enqueue('file-transfer.initiated', 'file_transfer', $transfer_id, ['transfer_id' => $transfer_id, 'sender_id' => $sender_id, 'recipient_count' => count($recipients), 'total_bytes' => $total], 'file-transfer-initiated-' . $transfer_id);
            if (is_wp_error($event)) { throw new RuntimeException('transfer_event_failed'); }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK'); return new WP_Error('transfer_initiation_failed', 'The private transfer session could not be created.', ['status' => 500]);
        }
        self::ensure_storage();
        SN_DB::audit('file_transfer_initiated', 'file_transfer', $transfer_id, 'success', ['recipients' => count($recipients), 'bytes' => $total]);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE id=%d', $transfer_id));
        return rest_ensure_response(['transfer' => self::format($row, $sender_id)]);
    }

}
