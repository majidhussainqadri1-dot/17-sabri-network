<?php
/** Additional runtime invariants for the File-17 two-plan completion paths. */
declare(strict_types=1);
defined('ABSPATH') || exit;

require_once SN_DIR . 'includes/class-sn-future-superset.php';

final class SN_Two_Plan_Runtime_Hardening {
    private const PROCESSING_LEASE_SECONDS = 900;

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 6, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 1600);
        add_action('sn_cleanup_hourly', [self::class, 'recover_stale_scheduled'], 5);
        SN_Future_Superset::register();
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();

        // This hook is global to the WordPress REST server. File 17 must never
        // inspect, hash or reject another plugin's upload/request payload.
        if (!str_starts_with($route, '/sabri-network/v2/')) return null;

        $method = $request->get_method();

        if ($method === 'POST' && preg_match('#^/sabri-network/v2/messages/\d+/translate$#', $route)) {
            $actor = get_current_user_id();
            if ($actor > 0 && !SN_Policy::consume_rate_limit('message_translate', (string) $actor, 60, HOUR_IN_SECONDS)) {
                return new WP_Error('sn_translation_rate_limited', 'Too many translation requests were made.', ['status' => 429]);
            }
        }

        if ($method === 'POST' && $route === '/sabri-network/v2/updates' && sanitize_key((string) $request->get_param('privacy')) === 'public') {
            return new WP_Error('sn_public_temporary_update_forbidden', 'Temporary updates are limited to private/contact/group audiences and do not replace public publishing.', ['status' => 400]);
        }

        $file_params = $request->get_file_params();
        if ($file_params) {
            // rest_pre_dispatch precedes the route permission callback. Avoid
            // hashing attacker-controlled uploads for unauthenticated, suspended
            // or otherwise denied identities.
            $access = SN_Policy::access();
            if (is_wp_error($access)) return $access;
            if ($access !== true) return new WP_Error('network_access_denied', 'Network access is not permitted for this account.', ['status' => 403]);
        }

        $hashes = [];
        foreach ($file_params as $key => $file) {
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return new WP_Error('sn_upload_incomplete', 'The uploaded file is incomplete.', ['status' => 400]);
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) return new WP_Error('sn_upload_hash_unavailable', 'The uploaded file cannot be verified safely.', ['status' => 503]);
            $digest = hash_file('sha256', $tmp);
            if (!is_string($digest) || strlen($digest) !== 64) return new WP_Error('sn_upload_hash_unavailable', 'The uploaded file cannot be verified safely.', ['status' => 503]);
            $hashes[(string) $key] = $digest;
        }
        if ($hashes) {
            ksort($hashes);
            $request->set_param('_sn_uploaded_file_hashes', $hashes);
        }
        return null;
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/scheduled-messages/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'cancel_scheduled_idempotently'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function cancel_scheduled_idempotently(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $id = absint($request['id']);
        $table = SN_DB::table('scheduled_messages');
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $id));
        if (!$row || (int) $row->sender_id !== $actor) return self::not_found();
        if ((string) $row->status === 'cancelled') return rest_ensure_response(['id' => $id, 'status' => 'cancelled', 'duplicate' => true]);
        if (!in_array((string) $row->status, ['pending', 'failed'], true)) return new WP_Error('sn_schedule_not_cancellable', 'This scheduled message can no longer be cancelled.', ['status' => 409]);
        $updated = $wpdb->query($wpdb->prepare("UPDATE $table SET status='cancelled',updated_at=%s WHERE id=%d AND sender_id=%d AND status IN ('pending','failed')", current_time('mysql', true), $id, $actor));
        if ($updated === false) return new WP_Error('sn_schedule_cancel_failed', 'The scheduled message could not be cancelled safely.', ['status' => 500]);
        if ($updated === 0) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT status FROM ' . $table . ' WHERE id=%d AND sender_id=%d', $id, $actor));
            if ($fresh && (string) $fresh->status === 'cancelled') return rest_ensure_response(['id' => $id, 'status' => 'cancelled', 'duplicate' => true]);
            return new WP_Error('sn_schedule_state_changed', 'The scheduled message changed state before cancellation.', ['status' => 409]);
        }
        SN_DB::audit('scheduled_message_cancelled', 'scheduled_message', $id, 'success', [], $actor);
        return rest_ensure_response(['id' => $id, 'status' => 'cancelled', 'duplicate' => false]);
    }

    public static function recover_stale_scheduled(): void {
        global $wpdb;
        $table = SN_DB::table('scheduled_messages');
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::PROCESSING_LEASE_SECONDS);
        $wpdb->query($wpdb->prepare("UPDATE $table SET status='failed',last_error='processing_lease_expired',updated_at=%s WHERE status='processing' AND updated_at<%s LIMIT 100", current_time('mysql', true), $cutoff));
    }

    private static function not_found(): WP_Error {
        return new WP_Error('sn_not_found', 'The requested communication object is unavailable.', ['status' => 404]);
    }
}
