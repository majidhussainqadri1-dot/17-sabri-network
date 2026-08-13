<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_4 {

    public static function finalize(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $row = self::session((string) $request['public_id']); $sender_id = get_current_user_id();
        if (!$row || (int) $row->sender_id !== $sender_id) { return self::not_found(); }
        $policy = self::revalidate($row, $sender_id, true); if (is_wp_error($policy)) { return $policy; }
        if ((string) $row->status === 'ready') { return rest_ensure_response(['transfer' => self::format($row, $sender_id), 'duplicate' => true]); }
        if (!in_array((string) $row->status, ['uploading', 'quarantined'], true)) { return new WP_Error('transfer_not_finalizable', 'This transfer is not in an uploadable or quarantined state.', ['status' => 409]); }
        $chunks = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::chunks_table() . ' WHERE transfer_id=%d ORDER BY chunk_index ASC', (int) $row->id));
        if (count($chunks ?: []) !== (int) $row->total_chunks) { return new WP_Error('transfer_incomplete', 'All approved chunks must be uploaded before finalization.', ['status' => 409]); }
        $hash = hash_init('sha256'); $first = '';
        foreach ($chunks as $position => $chunk) {
            if ((int) $chunk->chunk_index !== $position) { return new WP_Error('transfer_chunk_gap', 'The transfer chunk sequence is incomplete.', ['status' => 409]); }
            $path = self::existing_storage_path((string) $chunk->storage_key);
            if (is_wp_error($path)) { return $path; }
            $plain = SN_Communication_Crypto::read_encrypted_file($path, self::chunk_context($row, (int) $chunk->chunk_index));
            if (is_wp_error($plain)) { return $plain; }
            if (!hash_equals((string) $chunk->sha256, hash('sha256', $plain)) || strlen($plain) !== (int) $chunk->byte_count) { return self::reject_corrupt($row, 'chunk_revalidation_failed'); }
            if ($position === 0) { $first = substr($plain, 0, 1048576); }
            hash_update($hash, $plain);
        }
        $actual = hash_final($hash);
        if ((string) $row->expected_sha256 !== '' && !hash_equals((string) $row->expected_sha256, $actual)) { return self::reject_corrupt($row, 'file_checksum_mismatch'); }
        $mime = self::detect_mime($first, (string) $row->safe_name);
        if (!self::allowed_type((string) $row->safe_name, $mime)) { return self::reject_corrupt($row, 'file_type_not_allowed'); }
        if (self::looks_like_archive((string) $row->safe_name, $mime)) {
            $archive = self::inspect_archive($row, $chunks); if (is_wp_error($archive)) { return self::reject_corrupt($row, $archive->get_error_code()); }
        }

        $scan_paths = [];
        $materialize = static function () use ($row, &$scan_paths): string|WP_Error {
            $path = self::materialize_for_scan((int) $row->id);
            if (is_string($path) && $path !== '') { $scan_paths[] = $path; }
            return $path;
        };
        $cleanup = static function () use (&$scan_paths): void {
            foreach (array_unique($scan_paths) as $path) {
                if (is_string($path) && $path !== '') { @unlink($path); }
            }
            $scan_paths = [];
        };
        try {
            $scan = apply_filters('sn_network_transfer_scan_result', null, [
                'transfer_id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'sender_id' => $sender_id,
                'name' => (string) $row->safe_name, 'bytes' => (int) $row->total_bytes, 'sha256' => $actual,
                'mime' => $mime, 'materialize_callback' => $materialize, 'cleanup_callback' => $cleanup,
            ]);
        } finally {
            $cleanup();
        }
        $scan_status = is_array($scan) ? sanitize_key((string) ($scan['status'] ?? '')) : sanitize_key((string) $scan);
        if (!in_array($scan_status, ['clean', 'malware', 'rejected'], true)) {
            $wpdb->update(self::sessions_table(), ['actual_sha256' => $actual, 'detected_mime' => $mime, 'status' => 'quarantined', 'scan_status' => 'scanner_unavailable', 'failure_code' => 'scanner_required', 'version' => (int) $row->version + 1, 'updated_at' => current_time('mysql', true)], ['id' => (int) $row->id]);
            SN_DB::audit('file_transfer_quarantined', 'file_transfer', (int) $row->id, 'failure', ['reason' => 'scanner_required']);
            return new WP_Error('transfer_quarantined', 'The file is encrypted and quarantined until the approved malware scanner clears it.', ['status' => 503, 'transfer' => self::format(self::session((string) $row->public_id), $sender_id)]);
        }
        if ($scan_status !== 'clean') { return self::reject_corrupt($row, 'malware_or_policy_rejected'); }
        $now = current_time('mysql', true); $retention = (string) $row->retention_until;
        $wpdb->query('START TRANSACTION');
        try {
            $updated = $wpdb->query($wpdb->prepare('UPDATE ' . self::sessions_table() . ' SET actual_sha256=%s,detected_mime=%s,status=\'ready\',scan_status=\'clean\',failure_code=\'\',completed_at=%s,expires_at=%s,version=version+1,updated_at=%s WHERE id=%d AND status IN (\'uploading\',\'quarantined\')', $actual, $mime, $now, $retention, $now, (int) $row->id));
            if ($updated !== 1) { throw new RuntimeException('transfer_finalize_race'); }
            $recipient_projection = $wpdb->query($wpdb->prepare('UPDATE ' . self::recipients_table() . ' SET state=\'ready\',updated_at=%s WHERE transfer_id=%d AND revoked_at IS NULL', $now, (int) $row->id));
            if ($recipient_projection === false) { throw new RuntimeException('transfer_recipient_ready_failed'); }
            $event = SN_Outbox::enqueue('file-transfer.ready', 'file_transfer', (int) $row->id, ['transfer_id' => (int) $row->id, 'sender_id' => $sender_id, 'bytes' => (int) $row->total_bytes, 'sha256' => $actual], 'file-transfer-ready-' . $row->id);
            if (is_wp_error($event)) { throw new RuntimeException('transfer_ready_event_failed'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('transfer_finalize_commit_failed'); }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $fresh = self::session((string) $row->public_id);
            if ($fresh && (string) $fresh->status === 'ready' && hash_equals((string) $fresh->actual_sha256, $actual)) {
                return rest_ensure_response(['transfer' => self::format($fresh, $sender_id), 'duplicate' => true, 'commit_reconciled' => true]);
            }
            return new WP_Error('transfer_finalize_failed', 'The clean transfer could not be finalized.', ['status' => 500]);
        }
        $recipient_ids = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . self::recipients_table() . ' WHERE transfer_id=%d AND revoked_at IS NULL', (int) $row->id));
        foreach ($recipient_ids as $recipient_id) { SN_DB::add_notification((int) $recipient_id, 'file_transfer_ready', 'A private file is ready', '', 'file_transfer', (int) $row->id); }
        $wpdb->query($wpdb->prepare('UPDATE ' . self::recipients_table() . ' SET notified_at=%s,updated_at=%s WHERE transfer_id=%d AND revoked_at IS NULL', $now, $now, (int) $row->id));
        SN_DB::audit('file_transfer_ready', 'file_transfer', (int) $row->id, 'success', ['bytes' => (int) $row->total_bytes]);
        return rest_ensure_response(['transfer' => self::format(self::session((string) $row->public_id), $sender_id)]);
    }

    public static function list_transfers(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $user_id = get_current_user_id(); $box = sanitize_key((string) $request->get_param('box')) === 'sent' ? 'sent' : 'inbox';
        if ($box === 'sent') { $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE sender_id=%d ORDER BY id DESC LIMIT 100', $user_id)); }
        else { $rows = $wpdb->get_results($wpdb->prepare('SELECT s.* FROM ' . self::sessions_table() . ' s INNER JOIN ' . self::recipients_table() . ' r ON r.transfer_id=s.id AND r.user_id=%d AND r.revoked_at IS NULL ORDER BY s.id DESC LIMIT 100', $user_id)); }
        return rest_ensure_response(['box' => $box, 'transfers' => array_map(fn($r): array => self::format($r, $user_id), $rows ?: [])]);
    }

    public static function status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $row = self::session((string) $request['public_id']); $user_id = get_current_user_id();
        if (!$row || !self::can_access($row, $user_id)) { return self::not_found(); }
        $policy = self::revalidate($row, $user_id, (int) $row->sender_id === $user_id); if (is_wp_error($policy)) { return $policy; }
        return rest_ensure_response(['transfer' => self::format($row, $user_id)]);
    }

    public static function grant(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $row = self::session((string) $request['public_id']); $user_id = get_current_user_id();
        if (!$row || !self::can_access($row, $user_id)) { return self::not_found(); }
        $policy = self::revalidate($row, $user_id, (int) $row->sender_id === $user_id); if (is_wp_error($policy)) { return $policy; }
        if ((string) $row->status !== 'ready' || (string) $row->scan_status !== 'clean' || $row->revoked_at || strtotime((string) $row->expires_at) <= time()) { return new WP_Error('transfer_not_ready', 'The private transfer is not available for download.', ['status' => 409]); }
        $exp = time() + self::GRANT_TTL;
        $token = SN_Communication_Crypto::sign(['transfer' => (string) $row->public_id, 'user' => $user_id, 'version' => (int) $row->version, 'exp' => $exp], 'file-transfer-download');
        $url = add_query_arg(['sn_file17_transfer_download' => (string) $row->public_id, 'grant' => $token], home_url('/'));
        return rest_ensure_response(['url' => esc_url_raw($url), 'expires_at' => gmdate('c', $exp)]);
    }

}
