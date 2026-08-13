<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_4 {

    public static function export_personal_data(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return ['data' => [], 'done' => true];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT public_id,encrypted_payload,version,created_at,updated_at FROM ' . self::drafts_table() . ' WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT 100 OFFSET %d', $user->ID, max(0, ($page - 1) * 100)));
        $data = [];
        foreach ($rows ?: [] as $row) {
            $payload = [];
            $cipher = base64_decode((string) $row->encrypted_payload, true);
            if (is_string($cipher) && $cipher !== '') {
                $plain = SN_Communication_Crypto::decrypt($cipher, 'smail-draft|' . $row->public_id . '|' . $user->ID);
                if (!is_wp_error($plain)) {
                    $decoded = json_decode((string) $plain, true);
                    if (is_array($decoded)) $payload = $decoded;
                }
            }
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
        return ['data' => $data, 'done' => count($rows ?: []) < 100];
    }

    public static function register_eraser(array $erasers): array {
        $erasers['sabri-network-smail'] = ['eraser_friendly_name' => 'Sabri Smail drafts and mailbox state', 'callback' => [self::class, 'erase_personal_data']]; return $erasers;
    }

    public static function erase_personal_data(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];

        $uid = (int) $user->ID;
        $empty_hash = hash_hmac('sha256', '', wp_salt('auth') . '|sn-sm-draft-blind-v1');
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            $state_delete = $wpdb->delete(self::states_table(), ['user_id' => $uid], ['%d']);
            if ($state_delete === false) throw new RuntimeException('smail_state_erase_failed');
            $draft_update = $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::drafts_table() . ' SET encrypted_payload=%s,payload_hash=%s,deleted_at=%s,updated_at=%s WHERE owner_id=%d AND deleted_at IS NULL',
                '',
                $empty_hash,
                $now,
                $now,
                $uid
            ));
            if ($draft_update === false) throw new RuntimeException('smail_draft_erase_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('smail_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('smail_privacy_erase_failed', 'user', $uid, 'failure', ['reason' => $e->getMessage(), 'privacy_page' => max(1, $page)], $uid);
            return [
                'items_removed' => false,
                'items_retained' => true,
                'messages' => ['Smail privacy erasure could not be committed safely and will remain pending for retry.'],
                'done' => false,
            ];
        }

        return [
            'items_removed' => ($state_delete > 0 || $draft_update > 0),
            'items_retained' => true,
            'messages' => ['Canonical messages remain subject to File-17 conversation retention, legal hold and participant rights.'],
            'done' => true,
        ];
    }

    private static function format_smail(object $row): array { return ['id' => (int) $row->id, 'message_id' => (int) $row->message_id, 'conversation_id' => (int) $row->conversation_id, 'subject' => (string) $row->subject, 'created_at' => (string) $row->created_at]; }
    private static function messages_table(): string { return SN_DB::table('smail_messages'); }
    private static function states_table(): string { return SN_DB::table('smail_states'); }
    private static function drafts_table(): string { return SN_DB::table('smail_drafts'); }
}
