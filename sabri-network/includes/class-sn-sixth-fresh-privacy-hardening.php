<?php
/** Sixth fresh review plus later privacy recovery: progress-safe erasure and durable private-byte deletion retry. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Sixth_Fresh_Privacy_Hardening {
    private const BATCH = 100;
    private const VERSION_SCAN = 200;
    private const PRIVATE_DELETE_STALLED_AFTER = 5;

    public static function register(): void {
        // Replace only the Future-capability eraser after the fifth-cycle override;
        // the global privacy guard still wraps this callback at priority 9999.
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_eraser'], 9600);
        // SN_Private_Files owns the actual safe unlink at default priority. Run after
        // it and make sure a still-existing revoked object is never abandoned merely
        // because the initial bounded retry threshold was reached.
        add_action('sn_network_retry_private_delete', [self::class, 'ensure_private_byte_retry'], PHP_INT_MAX, 1);
    }

    public static function override_eraser(array $erasers): array {
        if (isset($erasers['sabri-network-future'])) {
            $erasers['sabri-network-future']['callback'] = [self::class, 'erase_future'];
        }
        return $erasers;
    }

    public static function erase_future(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int) $user->ID;
        $records = $wpdb->prefix . 'sn_future_records';
        $versions = $wpdb->prefix . 'sn_future_message_versions';
        $messages = SN_DB::table('messages');
        $removed = 0;
        $retained = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $records WHERE owner_id=%d AND feature_id IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')",
            $uid
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Future-capability erasure could not start.');
        try {
            foreach (is_array($rows) ? $rows : [] as $row) {
                $changed = $wpdb->update($records, [
                    'payload_cipher' => null,
                    'state' => 'erased',
                    'updated_at' => current_time('mysql', true),
                ], ['id'=>(int)$row->id,'owner_id'=>$uid], [null,'%s','%s'], ['%d','%d']);
                if ($changed !== 1) throw new RuntimeException('future_record_erase_conflict');
                $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('future_record_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::retry('Future-capability erasure could not be committed.');
        }

        $cursor_key = 'sn_privacy_future_version_cursor_' . $uid;
        $cursor = max(0, (int) get_option($cursor_key, 0));
        $scan = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.message_id FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d AND v.id>%d ORDER BY v.id ASC LIMIT %d",
            $uid,
            $cursor,
            self::VERSION_SCAN
        ));
        foreach (is_array($scan) ? $scan : [] as $version) {
            $vid = (int) $version->id;
            if ((bool) apply_filters('sn_network_message_version_hold', false, (int) $version->message_id, $uid)) {
                $retained++;
                update_option($cursor_key, $vid, false);
                continue;
            }
            $deleted = $wpdb->delete($versions, ['id'=>$vid], ['%d']);
            if ($deleted !== 1) {
                // Do not move the cursor past this row. A failed/ambiguous delete must
                // be retried rather than becoming permanently skipped personal data.
                return self::retry('Message-version privacy erasure must be retried.');
            }
            $removed++;
            update_option($cursor_key, $vid, false);
        }
        $more_versions = count(is_array($scan) ? $scan : []) === self::VERSION_SCAN;
        if (!$more_versions) delete_option($cursor_key);
        $more_records = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') LIMIT 1",
            $uid
        ));
        return [
            'items_removed'=>$removed>0,
            'items_retained'=>$retained>0,
            'messages'=>$retained>0 ? ['Governed key-transparency/interoperability or held integrity evidence was retained.'] : [],
            'done'=>!$more_records && !$more_versions,
        ];
    }

    /**
     * Persist the cleanup workflow after SN_Private_Files' initial retry budget.
     * This method never unlinks bytes itself; the canonical private-file owner keeps
     * path-containment and unlink authority. It only ensures another canonical retry
     * remains scheduled while a revoked object's bytes still exist.
     */
    public static function ensure_private_byte_retry(int $attachment_id): void {
        if ($attachment_id <= 0 || !class_exists('SN_Private_Files')) return;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,storage_key FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NOT NULL',
            $attachment_id
        ));
        if (!$row) return;

        $root = untrailingslashit(wp_normalize_path(SN_Private_Files::storage_dir()));
        $storage_key = ltrim(str_replace('\\', '/', (string) $row->storage_key), '/');
        $candidate = wp_normalize_path($root . '/' . $storage_key);
        if ($root === '' || ($candidate !== $root && !str_starts_with($candidate . '/', $root . '/'))) {
            SN_DB::audit('attachment_delete_retry_path_invalid', 'attachment', $attachment_id, 'failure', [
                'storage_key_hash'=>hash('sha256', (string)$row->storage_key),
            ], 0);
            return;
        }
        if (!is_file($candidate)) return;

        $attempts = max(1, (int) get_transient('sn_private_delete_retry_' . $attachment_id));
        if ($attempts >= self::PRIVATE_DELETE_STALLED_AFTER) {
            $notice_key = 'sn_private_delete_stalled_notice_' . $attachment_id;
            if (!get_transient($notice_key)) {
                SN_DB::audit('attachment_delete_stalled', 'attachment', $attachment_id, 'failure', [
                    'attempts'=>$attempts,
                    'storage_key_hash'=>hash('sha256', (string)$row->storage_key),
                ], 0);
                do_action('sn_network_private_bytes_delete_stalled', $attachment_id, hash('sha256', (string)$row->storage_key), $attempts);
                set_transient($notice_key, 1, DAY_IN_SECONDS);
            }
        }
        if (!wp_next_scheduled('sn_network_retry_private_delete', [$attachment_id])) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'sn_network_retry_private_delete', [$attachment_id]);
        }
    }

    private static function retry(string $message): array {
        return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];
    }

    private static function done(): array {
        return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    }
}
