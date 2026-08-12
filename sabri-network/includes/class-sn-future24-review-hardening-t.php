<?php
/** Review rounds 47 + 51 — enforce bounded retention by payload purge, with explicit case-discussion hold rechecks. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_T {
    public static function register(): void {
        // Run before the generic Future-Superset expiry state sweep at priority 10.
        add_action('sn_cleanup_hourly', [self::class, 'purge_case_discussions'], 3);
        add_action('sn_cleanup_hourly', [self::class, 'purge_quality_telemetry'], 4);
    }

    public static function purge_case_discussions(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_future_records';
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE feature_id='F17-FUT-16' AND state IN ('active','retention_hold') AND expires_at IS NOT NULL AND expires_at<=%s ORDER BY id ASC LIMIT 200",
            $now
        ));
        foreach (is_array($rows) ? $rows : [] as $row) {
            $hold = (bool) apply_filters('sn_network_case_discussion_hold', false, (int) $row->id, (int) $row->owner_id, (int) $row->scope_id);
            if ($hold) {
                $next = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
                $ok = $wpdb->update($table, [
                    'state' => 'retention_hold',
                    'expires_at' => $next,
                    'updated_at' => $now,
                    'version' => (int) $row->version + 1,
                ], ['id' => (int) $row->id, 'version' => (int) $row->version]);
                if ($ok === 1) SN_DB::audit('future_case_discussion_retention_hold', 'future_record', (int) $row->id, 'success', ['recheck_at' => $next], 0);
                continue;
            }
            self::purge_record($row, 'future_case_discussion_purged', $now);
        }
    }

    public static function purge_quality_telemetry(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_future_records';
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE feature_id='F17-FUT-21' AND state='active' AND expires_at IS NOT NULL AND expires_at<=%s ORDER BY id ASC LIMIT 500",
            $now
        ));
        foreach (is_array($rows) ? $rows : [] as $row) {
            self::purge_record($row, 'future_network_quality_purged', $now);
        }
    }

    private static function purge_record(object $row, string $audit_event, string $now): void {
        global $wpdb;
        $ok = $wpdb->update($wpdb->prefix . 'sn_future_records', [
            'payload_cipher' => null,
            'state' => 'expired',
            'updated_at' => $now,
            'version' => (int) $row->version + 1,
        ], ['id' => (int) $row->id, 'version' => (int) $row->version]);
        if ($ok === 1) SN_DB::audit($audit_event, 'future_record', (int) $row->id, 'success', ['payload_purged' => true], 0);
    }
}
