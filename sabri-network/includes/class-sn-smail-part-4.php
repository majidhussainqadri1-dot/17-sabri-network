<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_4 {

    public static function export_personal_data(string $email, int $page = 1): array {
        global $wpdb; $user = get_user_by('email', $email); if (!$user) { return ['data' => [], 'done' => true]; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT public_id,version,created_at,updated_at FROM ' . self::drafts_table() . ' WHERE owner_id=%d AND deleted_at IS NULL LIMIT 100 OFFSET %d', $user->ID, max(0, ($page - 1) * 100)));
        $data = array_map(static fn($r): array => ['group_id' => 'sabri-smail', 'group_label' => 'Smail', 'item_id' => 'draft-' . $r->public_id, 'data' => [['name' => 'Draft identifier', 'value' => $r->public_id], ['name' => 'Version', 'value' => $r->version], ['name' => 'Updated', 'value' => $r->updated_at]]], $rows ?: []);
        return ['data' => $data, 'done' => count($rows ?: []) < 100];
    }


    public static function register_eraser(array $erasers): array {
        $erasers['sabri-network-smail'] = ['eraser_friendly_name' => 'Sabri Smail drafts and mailbox state', 'callback' => [self::class, 'erase_personal_data']]; return $erasers;
    }


    public static function erase_personal_data(string $email, int $page = 1): array {
        global $wpdb; $user = get_user_by('email', $email); if (!$user) { return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true]; }
        $wpdb->delete(self::states_table(), ['user_id' => $user->ID], ['%d']);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::drafts_table() . ' SET encrypted_payload=%s,payload_hash=%s,deleted_at=%s,updated_at=%s WHERE owner_id=%d AND deleted_at IS NULL', '', hash('sha256', ''), current_time('mysql', true), current_time('mysql', true), $user->ID));
        return ['items_removed' => true, 'items_retained' => true, 'messages' => ['Canonical messages remain subject to File-17 conversation retention, legal hold and participant rights.'], 'done' => true];
    }


    private static function format_smail(object $row): array { return ['id' => (int) $row->id, 'message_id' => (int) $row->message_id, 'conversation_id' => (int) $row->conversation_id, 'subject' => (string) $row->subject, 'created_at' => (string) $row->created_at]; }

    private static function messages_table(): string { return SN_DB::table('smail_messages'); }

    private static function states_table(): string { return SN_DB::table('smail_states'); }

    private static function drafts_table(): string { return SN_DB::table('smail_drafts'); }

}
