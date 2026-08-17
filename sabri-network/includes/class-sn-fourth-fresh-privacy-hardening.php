<?php
/** Fourth fresh cycle: native File-17 legal holds must govern every privacy eraser. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Privacy_Hardening {
    public static function register(): void {
        // SN_Privacy_Runtime_Hardening already guards every File-17 eraser through
        // this filter. Provide the missing native decision instead of depending on
        // an optional external module to discover File-17's own held evidence.
        add_filter('sn_network_retention_prevents_erasure', [self::class, 'native_legal_hold'], 20, 2);
    }

    public static function native_legal_hold(bool $retained, int $user_id): bool {
        if ($retained || $user_id <= 0) return $retained;
        global $wpdb;
        $reports = SN_DB::table('reports');
        $messages = SN_DB::table('messages');
        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT r.id
             FROM $reports r
             LEFT JOIN $messages m ON m.id=r.message_id
             WHERE r.legal_hold=1
               AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d)
             LIMIT 1",
            $user_id,
            $user_id,
            $user_id
        ));
        return $held > 0;
    }
}
