<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_3 {

    public static function upload_chunk(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $row = self::session((string) $request['public_id']); $user_id = get_current_user_id();
        if (!$row || (int) $row->sender_id !== $user_id) { return self::not_found(); }
        $policy = self::revalidate($row, $user_id, true); if (is_wp_error($policy)) { return $policy; }
        if ((string) $row->status !== 'uploading' || strtotime((string) $row->expires_at) < time()) { return new WP_Error('transfer_not_uploadable', 'This transfer session is no longer accepting chunks.', ['status' => 409]); }
        $index = absint($request['index']); if ($index >= (int) $row->total_chunks) { return new WP_Error('invalid_chunk_index', 'The transfer chunk index is invalid.', ['status' => 400]); }
        $body = $request->get_body(); $bytes = strlen($body);
        $expected_bytes = $index === (int) $row->total_chunks - 1 ? (int) $row->total_bytes - ((int) $row->chunk_bytes * $index) : (int) $row->chunk_bytes;
        if ($bytes !== $expected_bytes || $bytes < 1 || $bytes > self::MAX_CHUNK_BYTES) { return new WP_Error('invalid_chunk_size', 'The transfer chunk size does not match the approved plan.', ['status' => 400]); }
        $sha = hash('sha256', $body); $declared = strtolower(trim((string) $request->get_header('x-chunk-sha256')));
        if ($declared === '' || !preg_match('/^[a-f0-9]{64}$/', $declared) || !hash_equals($declared, $sha)) { return new WP_Error('chunk_integrity_failed', 'The transfer chunk checksum did not match.', ['status' => 422]); }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::chunks_table() . ' WHERE transfer_id=%d AND chunk_index=%d', (int) $row->id, $index));
        if ($existing) {
            return hash_equals((string) $existing->sha256, $sha) && (int) $existing->byte_count === $bytes
                ? rest_ensure_response(['accepted' => true, 'duplicate' => true, 'index' => $index])
                : new WP_Error('chunk_idempotency_conflict', 'This chunk index was already used for different bytes.', ['status' => 409]);
        }
        $storage_key = $row->public_id . '/' . str_pad((string) $index, 6, '0', STR_PAD_LEFT) . '.snc';
        $written = SN_Communication_Crypto::write_encrypted_file(self::storage_root() . '/' . $storage_key, $body, self::chunk_context($row, $index));
        if (is_wp_error($written)) { return $written; }
        $now = current_time('mysql', true); $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->insert(self::chunks_table(), ['transfer_id' => (int) $row->id, 'chunk_index' => $index, 'byte_count' => $bytes, 'sha256' => $sha, 'storage_key' => $storage_key, 'created_at' => $now]) === false) { throw new RuntimeException('chunk_row_failed'); }
            $updated = $wpdb->query($wpdb->prepare('UPDATE ' . self::sessions_table() . ' SET received_chunks=received_chunks+1,received_bytes=received_bytes+%d,version=version+1,updated_at=%s WHERE id=%d AND status=\'uploading\'', $bytes, $now, (int) $row->id));
            if ($updated !== 1) { throw new RuntimeException('chunk_counter_failed'); }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK'); @unlink(self::storage_root() . '/' . $storage_key);
            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::chunks_table() . ' WHERE transfer_id=%d AND chunk_index=%d', (int) $row->id, $index));
            if ($race && hash_equals((string) $race->sha256, $sha)) { return rest_ensure_response(['accepted' => true, 'duplicate' => true, 'index' => $index]); }
            return new WP_Error('chunk_store_failed', 'The encrypted transfer chunk could not be committed.', ['status' => 500]);
        }
        return rest_ensure_response(['accepted' => true, 'index' => $index, 'sha256' => $sha, 'received_bytes' => (int) $row->received_bytes + $bytes]);
    }

}
