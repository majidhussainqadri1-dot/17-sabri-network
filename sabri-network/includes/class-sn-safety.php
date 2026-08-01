<?php
defined('ABSPATH') || exit;

/**
 * Abuse-reporting, legal-hold, retention and privacy-minimization controls.
 *
 * File 17 owns the native report record. File 24 may consume assurance evidence
 * through hooks, but does not replace these enforcement controls.
 */
final class SN_Safety {
    private const DEFAULT_RETENTION_DAYS = 365;
    private const HIGH_RISK_RETENTION_DAYS = 730;
    private const ANONYMIZED_DELETE_DAYS = 180;
    private const RETENTION_LOCK = 'sn_report_retention_lock';

    public static function valid_uuid(string $value): bool {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($value));
    }

    public static function target_key(int $reported_user_id, int $conversation_id, int $message_id): string {
        if ($message_id > 0) {
            return hash('sha256', 'message:' . $message_id);
        }
        if ($conversation_id > 0) {
            return hash('sha256', 'conversation:' . $conversation_id);
        }
        return hash('sha256', 'user:' . max(0, $reported_user_id));
    }

    public static function evidence_hash(array $evidence): string {
        $canonical = self::canonicalize_evidence($evidence);
        return hash('sha256', (string) wp_json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function evidence_is_intact(array $evidence, string $expected_hash): bool {
        return preg_match('/^[0-9a-f]{64}$/i', $expected_hash) === 1
            && hash_equals(strtolower($expected_hash), self::evidence_hash($evidence));
    }

    public static function retention_until(string $category, ?string $created_at = null): string {
        $high_risk = ['child_safety', 'threat', 'fraud', 'stolen_account', 'malware'];
        $days = in_array($category, $high_risk, true) ? self::HIGH_RISK_RETENTION_DAYS : self::DEFAULT_RETENTION_DAYS;
        $days = (int) apply_filters('sn_network_report_retention_days', $days, $category);
        $days = min(3650, max(30, $days));
        $base = $created_at ? strtotime($created_at . ' UTC') : time();
        if ($base === false) {
            $base = time();
        }
        return gmdate('Y-m-d H:i:s', $base + $days * DAY_IN_SECONDS);
    }

    public static function allowed_statuses(): array {
        return ['open', 'reviewing', 'actioned', 'dismissed', 'closed', 'expired'];
    }

    public static function can_transition_status(string $from, string $to): bool {
        $transitions = [
            'open' => ['open', 'reviewing', 'actioned', 'dismissed', 'closed'],
            'reviewing' => ['reviewing', 'actioned', 'dismissed', 'closed'],
            'actioned' => ['actioned', 'reviewing', 'closed'],
            'dismissed' => ['dismissed', 'reviewing', 'closed'],
            'closed' => ['closed', 'reviewing'],
            'expired' => ['expired'],
        ];
        return isset($transitions[$from]) && in_array($to, $transitions[$from], true);
    }

    public static function legal_hold_release_authorized(int $administrator_id, object $report): bool {
        if ($administrator_id <= 0 || !(bool) $report->legal_hold) {
            return false;
        }
        return (bool) apply_filters('sn_network_legal_hold_release_authorized', false, $administrator_id, $report);
    }

    public static function migrate_reports(): void {
        global $wpdb;
        $table = SN_DB::table('reports');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        for ($batch = 0; $batch < 20; $batch++) {
            $rows = $wpdb->get_results(
                "SELECT id,reported_user_id,conversation_id,message_id,category,evidence,created_at,target_key,retention_until,evidence_hash FROM $table WHERE target_key='' OR retention_until IS NULL OR evidence_hash='' ORDER BY id ASC LIMIT 250" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->evidence, true);
                $evidence = is_array($decoded) ? $decoded : [];
                $wpdb->update($table, [
                    'target_key' => self::target_key((int) $row->reported_user_id, (int) $row->conversation_id, (int) $row->message_id),
                    'retention_until' => self::retention_until((string) $row->category, (string) $row->created_at),
                    'evidence_hash' => self::evidence_hash($evidence),
                    'version' => 1,
                ], ['id' => (int) $row->id], ['%s', '%s', '%s', '%d'], ['%d']);
            }
            if (count($rows) < 250) {
                break;
            }
        }
    }

    public static function purge_expired_reports(int $limit = 200): array {
        global $wpdb;
        $limit = min(500, max(1, $limit));
        $token = self::acquire_retention_lock();
        if ($token === '') {
            return ['anonymized' => 0, 'deleted' => 0, 'locked' => true];
        }

        $table = SN_DB::table('reports');
        $now = current_time('mysql', true);
        $anonymized = 0;
        $deleted = 0;
        try {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE legal_hold=0 AND anonymized_at IS NULL AND retention_until IS NOT NULL AND retention_until<=%s ORDER BY retention_until ASC,id ASC LIMIT %d",
                $now,
                $limit
            )));
            foreach ($ids as $id) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET reporter_id=0,reported_user_id=0,conversation_id=0,message_id=0,client_uuid=NULL,target_key=%s,details='',evidence='[]',evidence_hash=%s,status='expired',decision_reason='',decision_by=0,decision_at=NULL,appeal_status='none',appeal_reason='',appealed_at=NULL,appeal_decided_by=0,appeal_decision_reason='',appeal_decided_at=NULL,anonymized_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND legal_hold=0 AND anonymized_at IS NULL",
                    hash('sha256', 'expired-report:' . $id),
                    hash('sha256', '[]'),
                    $now,
                    $now,
                    $id
                ));
                if ($updated === 1) {
                    $anonymized++;
                    SN_DB::audit('report_retention_anonymized', 'report', $id, 'success', [], 0);
                }
            }

            $delete_before = gmdate('Y-m-d H:i:s', time() - self::ANONYMIZED_DELETE_DAYS * DAY_IN_SECONDS);
            $delete_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE legal_hold=0 AND status='expired' AND anonymized_at IS NOT NULL AND anonymized_at<=%s ORDER BY anonymized_at ASC,id ASC LIMIT %d",
                $delete_before,
                $limit
            )));
            if ($delete_ids) {
                $placeholders = implode(',', array_fill(0, count($delete_ids), '%d'));
                $result = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($placeholders) AND legal_hold=0 AND status='expired'", ...$delete_ids));
                if ($result !== false) {
                    $deleted = (int) $result;
                }
            }
        } finally {
            self::release_retention_lock($token);
        }

        return ['anonymized' => $anonymized, 'deleted' => $deleted, 'locked' => false];
    }

    public static function erase_user_report_data(int $user_id): array {
        global $wpdb;
        $table = SN_DB::table('reports');
        $now = current_time('mysql', true);
        $empty_hash = self::evidence_hash([]);

        $retained_submitted = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE reporter_id=%d AND legal_hold=1",
            $user_id
        ));
        $retained_reported = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE reported_user_id=%d AND legal_hold=1",
            $user_id
        ));

        $wpdb->query('START TRANSACTION');
        try {
            $held_reporter_updates = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET reporter_id=0,client_uuid=NULL,updated_at=%s,version=version+1 WHERE reporter_id=%d AND legal_hold=1",
                $now,
                $user_id
            ));
            if ($held_reporter_updates === false) {
                throw new RuntimeException('held_reporter_minimization_failed');
            }

            $redacted_reporter_updates = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET reporter_id=0,client_uuid=NULL,details='',evidence='[]',evidence_hash=%s,updated_at=%s,version=version+1 WHERE reporter_id=%d AND legal_hold=0",
                $empty_hash,
                $now,
                $user_id
            ));
            if ($redacted_reporter_updates === false) {
                throw new RuntimeException('reporter_redaction_failed');
            }

            $target_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,conversation_id,message_id,target_key FROM $table WHERE reported_user_id=%d AND legal_hold=0 FOR UPDATE",
                $user_id
            ));
            if (!is_array($target_rows)) {
                throw new RuntimeException('reported_user_query_failed');
            }
            $redacted_reported_updates = 0;
            foreach ($target_rows as $target_row) {
                $target_key = ((int) $target_row->conversation_id === 0 && (int) $target_row->message_id === 0)
                    ? hash('sha256', 'erased-user-report:' . (int) $target_row->id)
                    : (string) $target_row->target_key;
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET reported_user_id=0,target_key=%s,appeal_reason='',appealed_at=NULL,appeal_decision_reason='',appeal_decided_by=0,appeal_decided_at=NULL,decision_reason='',decision_by=0,decision_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND reported_user_id=%d AND legal_hold=0",
                    $target_key,
                    $now,
                    (int) $target_row->id,
                    $user_id
                ));
                if ($updated === false) {
                    throw new RuntimeException('reported_user_redaction_failed');
                }
                $redacted_reported_updates += (int) $updated;
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('report_privacy_erasure_failed', 'user', $user_id, 'failed', ['reason' => $e->getMessage()], 0);
            return [
                'redacted' => 0,
                'retained' => $retained_submitted + $retained_reported,
                'held_reporter_minimized' => 0,
                'failed' => true,
            ];
        }

        return [
            'redacted' => (int) $redacted_reporter_updates + $redacted_reported_updates,
            'retained' => $retained_submitted + $retained_reported,
            'held_reporter_minimized' => (int) $held_reporter_updates,
            'failed' => false,
        ];
    }

    public static function operational_summary(): array {
        global $wpdb;
        $table = SN_DB::table('reports');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['open' => 0, 'reviewing' => 0, 'legal_holds' => 0, 'retention_due' => 0];
        }
        $now = current_time('mysql', true);
        return [
            'open' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='open'"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'reviewing' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='reviewing'"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'legal_holds' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE legal_hold=1"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'retention_due' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE legal_hold=0 AND anonymized_at IS NULL AND retention_until IS NOT NULL AND retention_until<=%s", $now)),
        ];
    }

    private static function canonicalize_evidence(array $value): array {
        if (array_is_list($value)) {
            return array_map(static function ($item) {
                return is_array($item) ? self::canonicalize_evidence($item) : $item;
            }, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize_evidence($item);
            }
        }
        return $value;
    }

    private static function acquire_retention_lock(): string {
        global $wpdb;
        $token = wp_generate_uuid4();
        $expires = time() + 10 * MINUTE_IN_SECONDS;
        $value = $token . '|' . $expires;
        if (add_option(self::RETENTION_LOCK, $value, '', false)) {
            return $token;
        }

        $stored = (string) get_option(self::RETENTION_LOCK, '');
        $parts = explode('|', $stored, 2);
        $expired_or_malformed = !isset($parts[1])
            || !ctype_digit($parts[1])
            || (int) $parts[1] <= time();
        if (!$expired_or_malformed) {
            return '';
        }

        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => $value],
            ['option_name' => self::RETENTION_LOCK, 'option_value' => $stored],
            ['%s'],
            ['%s', '%s']
        );
        if ($updated === 1) {
            wp_cache_delete(self::RETENTION_LOCK, 'options');
            return $token;
        }
        return '';
    }

    private static function release_retention_lock(string $token): void {
        global $wpdb;
        $stored = (string) get_option(self::RETENTION_LOCK, '');
        if (!str_starts_with($stored, $token . '|')) {
            return;
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            ['option_name' => self::RETENTION_LOCK, 'option_value' => $stored],
            ['%s', '%s']
        );
        if ($deleted === 1) {
            wp_cache_delete(self::RETENTION_LOCK, 'options');
        }
    }
}
