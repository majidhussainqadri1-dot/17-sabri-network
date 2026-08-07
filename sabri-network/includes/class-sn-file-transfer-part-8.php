<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_8 {
    public static function erase_personal_data(string $email, int $page = 1): array {
        global $wpdb; $user = get_user_by('email', $email); if (!$user) { return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true]; }
        $sent = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND status NOT IN (\'revoked\',\'expired\',\'rejected\')', $user->ID));
        foreach ($sent as $id) { self::delete_chunks((int) $id); }
        $now = current_time('mysql', true); $wpdb->query($wpdb->prepare('UPDATE ' . self::sessions_table() . ' SET status=\'revoked\',revoked_at=COALESCE(revoked_at,%s),version=version+1,updated_at=%s WHERE sender_id=%d', $now, $now, $user->ID));
        $wpdb->query($wpdb->prepare('UPDATE ' . self::recipients_table() . ' SET state=\'erased\',revoked_at=COALESCE(revoked_at,%s),updated_at=%s WHERE user_id=%d', $now, $now, $user->ID));
        return ['items_removed' => true, 'items_retained' => true, 'messages' => ['Minimum integrity, legal-hold and abuse evidence may be retained under the approved retention policy.'], 'done' => true];
    }


    private static function sessions_table(): string { return SN_DB::table('transfer_sessions'); }

    private static function chunks_table(): string { return SN_DB::table('transfer_chunks'); }

    private static function recipients_table(): string { return SN_DB::table('transfer_recipients'); }

}
