<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_4 {

    public static function export_personal_data(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return ['data' => [], 'done' => true];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT public_id,encrypted_payload,version,created_at,updated_at FROM ' . self::drafts_table() . ' WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT 100 OFFSET %d', $user->ID, max(0, ($page - 1) * 100)));
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            SN_DB::audit('smail_privacy_export_read_failed', 'user', (int) $user->ID, 'failure', ['reason' => (string) $wpdb->last_error], (int) $user->ID);
            return ['data' => [], 'done' => false];
        }
        $data = [];
        foreach ($rows as $row) {
            $payload = [];
            $cipher = base64_decode((string) $row->encrypted_payload, true);
            if (!is_string($cipher) || $cipher === '') {
                SN_DB::audit('smail_privacy_export_cipher_invalid', 'user', (int) $user->ID, 'failure', ['draft_hash' => hash('sha256', (string) $row->public_id)], (int) $user->ID);
                return ['data' => [], 'done' => false];
            }
            $plain = SN_Communication_Crypto::decrypt($cipher, 'smail-draft|' . $row->public_id . '|' . $user->ID);
            if (is_wp_error($plain)) {
                SN_DB::audit('smail_privacy_export_decrypt_failed', 'user', (int) $user->ID, 'failure', ['draft_hash' => hash('sha256', (string) $row->public_id), 'reason' => sanitize_key($plain->get_error_code())], (int) $user->ID);
                return ['data' => [], 'done' => false];
            }
            $decoded = json_decode((string) $plain, true);
            if (!is_array($decoded)) {
                SN_DB::audit('smail_privacy_export_payload_invalid', 'user', (int) $user->ID, 'failure', ['draft_hash' => hash('sha256', (string) $row->public_id)], (int) $user->ID);
                return ['data' => [], 'done' => false];
            }
            $payload = $decoded;
            $data[] = [
                'group_id' => 'sabri-smail', 'group_label' => 'Smail', 'item_id' => 'draft-' . $row->public_id,
                'data' => [
                    ['name' => 'Draft identifier', 'value' => $row->public_id],
                    ['name' => 'Recipients', 'value' => implode(', ', array_map('absint', (array) ($payload['recipient_ids'] ?? [])))],
                    ['name' => 'Subject', 'value' => (string) ($payload['subject'] ?? '')],
                    ['name' => 'Body', 'value' => (string) ($payload['body'] ?? '')],
                    ['name' => 'Version', 'value' => $row->version],
                    ['name' => 'Created', 'value' => $row->created_at],
                    ['name' => 'Updated', 'value' => $row->updated_at],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < 100];
    }

    public static function register_eraser(array $erasers): array {
        $erasers['sabri-network-smail'] = ['eraser_friendly_name' => 'Sabri Smail drafts and mailbox state', 'callback' => [self::class, 'erase_personal_data']]; return $erasers;
    }

    public static function erase_personal_data(string $email, int $page = 1): array {
        global $wpdb; $user = get_user_by('email', $email);
        if (!$user) return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        $deleted = $wpdb->delete(self::states_table(), ['user_id' => $user->ID], ['%d']);
        if ($deleted === false) {
            SN_DB::audit('smail_privacy_state_erasure_failed', 'user', (int) $user->ID, 'failure', ['reason' => (string) $wpdb->last_error], (int) $user->ID);
            return ['items_removed' => false, 'items_retained' => true, 'messages' => ['Smail mailbox-state erasure must be retried.'], 'done' => false];
        }
        $empty_hash = hash_hmac('sha256', '', wp_salt('auth') . '|sn-sm-draft-blind-v1');
        $updated = $wpdb->query($wpdb->prepare('UPDATE ' . self::drafts_table() . ' SET encrypted_payload=%s,payload_hash=%s,deleted_at=%s,updated_at=%s WHERE owner_id=%d AND deleted_at IS NULL', '', $empty_hash, current_time('mysql', true), current_time('mysql', true), $user->ID));
        if ($updated === false) {
            SN_DB::audit('smail_privacy_draft_erasure_failed', 'user', (int) $user->ID, 'failure', ['reason' => (string) $wpdb->last_error], (int) $user->ID);
            return ['items_removed' => $deleted > 0, 'items_retained' => true, 'messages' => ['Smail draft erasure must be retried.'], 'done' => false];
        }
        return ['items_removed' => ($deleted > 0 || $updated > 0), 'items_retained' => true, 'messages' => ['Canonical messages remain subject to File-17 conversation retention, legal hold and participant rights.'], 'done' => true];
    }

    private static function format_smail(object $row): array { return ['id' => (int) $row->id, 'message_id' => (int) $row->message_id, 'conversation_id' => (int) $row->conversation_id, 'subject' => (string) $row->subject, 'created_at' => (string) $row->created_at]; }
    private static function messages_table(): string { return SN_DB::table('smail_messages'); }
    private static function states_table(): string { return SN_DB::table('smail_states'); }
    private static function drafts_table(): string { return SN_DB::table('smail_drafts'); }
}
