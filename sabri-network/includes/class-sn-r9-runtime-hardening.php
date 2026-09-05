<?php
/** Next fresh Round 9: transaction, Future-erasure and scheduler-recovery truth. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_R9_Runtime_Hardening {
    private const DEVICE_KEY_BATCH = 100;
    private const BULK_RECOVERY_BATCH = 200;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 3500);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_future_eraser'], 9700);
        remove_action('sn_cleanup_hourly', [SN_Future24_Review_Hardening_O::class, 'bulk_job_preflight'], 0);
        add_action('sn_cleanup_hourly', [self::class, 'bulk_job_preflight'], 0);
    }

    public static function override_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/hand-raise', [
            'methods'=>'POST',
            'callback'=>static fn(WP_REST_Request $request) => self::guard(
                static fn() => SN_Future24_Review_Hardening_D::hand_raise($request),
                'speaker_hand_raise'
            ),
            'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/speaker-queue', [
            ['methods'=>'GET','callback'=>[SN_Future24_Review_Hardening_D::class,'speaker_queue'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>static fn(WP_REST_Request $request) => self::guard(
                static fn() => SN_Future24_Review_Hardening_D::manage_speaker_queue($request),
                'speaker_queue_manage'
            ),'permission_callback'=>$access],
        ], true);
        register_rest_route('sabri-network/v2', '/future/templates/(?P<id>\d+)', [
            ['methods'=>'POST','callback'=>static fn(WP_REST_Request $request) => self::guard(
                static fn() => SN_Future24_Review_Hardening_N::update_template($request),
                'template_update'
            ),'permission_callback'=>$access],
            ['methods'=>'DELETE','callback'=>static fn(WP_REST_Request $request) => self::guard(
                static fn() => SN_Future24_Review_Hardening_N::delete_template($request),
                'template_delete'
            ),'permission_callback'=>$access],
        ], true);
    }

    private static function guard(callable $callback, string $operation): WP_REST_Response|WP_Error {
        global $wpdb;
        $original = $wpdb;
        $wpdb = new SN_R6_WPDB_Guard($original);
        try {
            $result = $callback();
            return $result instanceof WP_REST_Response || is_wp_error($result)
                ? $result
                : rest_ensure_response($result);
        } catch (Throwable $e) {
            SN_DB::audit('future_transaction_failed','system',0,'failure',[
                'operation'=>$operation,
                'reason'=>$e->getMessage(),
            ],get_current_user_id());
            return new WP_Error(
                'sn_future_transaction_failed',
                'The advanced communication transaction could not start or complete safely. Retry the request.',
                ['status'=>503]
            );
        } finally {
            $wpdb = $original;
        }
    }

    public static function override_future_eraser(array $erasers): array {
        if (isset($erasers['sabri-network-future'])) {
            $erasers['sabri-network-future']['callback'] = [self::class, 'erase_future'];
        }
        return $erasers;
    }

    /** Preserve the sixth-cycle eraser and restore user-owned device-key erasure. */
    public static function erase_future(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;

        $base = SN_Sixth_Fresh_Privacy_Hardening::erase_future($email, $page);
        if (!is_array($base)) return self::retry('Future-capability erasure returned an invalid result and must be retried.');
        if (($base['done'] ?? false) !== true) return $base;

        $table = $wpdb->prefix . 'sn_future_device_keys';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id=%d ORDER BY id ASC LIMIT %d",
            $uid,
            self::DEVICE_KEY_BATCH
        ));
        if (!is_array($ids)) return self::retry('Device-key privacy erasure could not be read safely and must be retried.');
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if ($ids) {
            if ($wpdb->query('START TRANSACTION') === false) {
                return self::retry('Device-key privacy erasure could not start and must be retried.');
            }
            try {
                foreach ($ids as $id) {
                    $changed = $wpdb->delete($table, ['id'=>$id,'user_id'=>$uid], ['%d','%d']);
                    if ($changed !== 1) throw new RuntimeException('device_key_delete_failed');
                }
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('device_key_delete_commit_failed');
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                SN_DB::audit('future_device_key_privacy_erase_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);
                return self::retry('Device-key privacy erasure could not be committed and must be retried.');
            }
        }

        $wpdb->last_error = '';
        $more_keys_raw = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE user_id=%d LIMIT 1", $uid));
        if ($wpdb->last_error !== '') return self::retry('Device-key privacy completion could not be verified safely.');
        $more_keys = (bool)$more_keys_raw;
        $wpdb->last_error = '';
        $key_log_raw = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sn_future_key_log WHERE user_id=%d',
            $uid
        ));
        if ($wpdb->last_error !== '') return self::retry('Key-transparency retained-data truth could not be verified safely.');
        $key_log_count = (int)$key_log_raw;
        $messages = is_array($base['messages'] ?? null) ? $base['messages'] : [];
        if ($key_log_count > 0) {
            $messages[] = 'Append-only key-transparency integrity entries were retained so the security ledger cannot be rewritten by unilateral erasure.';
        }
        return [
            'items_removed'=>(bool)($base['items_removed'] ?? false) || !empty($ids),
            'items_retained'=>(bool)($base['items_retained'] ?? false) || $more_keys || $key_log_count > 0,
            'messages'=>array_values(array_unique($messages)),
            'done'=>!$more_keys,
        ];
    }

    /** Checked replacement for the Future bulk-job recovery hook. */
    public static function bulk_job_preflight(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sn_future_records';
        $now = current_time('mysql', true);
        $stale = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
        $batch = self::BULK_RECOVERY_BATCH;
        $queries = [
            $wpdb->prepare("UPDATE $table SET state='expired',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='queued' AND expires_at IS NOT NULL AND expires_at<=%s LIMIT $batch", $now, $now),
            $wpdb->prepare("UPDATE $table SET state='expired',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='processing' AND updated_at<%s AND expires_at IS NOT NULL AND expires_at<=%s LIMIT $batch", $now, $stale, $now),
            $wpdb->prepare("UPDATE $table SET state='queued',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='processing' AND updated_at<%s AND (expires_at IS NULL OR expires_at>%s) LIMIT $batch", $now, $stale, $now),
        ];
        foreach ($queries as $index => $query) {
            if ($wpdb->query($query) === false) {
                SN_DB::audit('future_bulk_recovery_failed','system',0,'failure',[
                    'stage'=>$index + 1,
                    'query_hash'=>substr(hash('sha256', (string)$query), 0, 16),
                ],0);
                return;
            }
        }
    }

    private static function retry(string $message): array {
        return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];
    }

    private static function done(): array {
        return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    }
}
