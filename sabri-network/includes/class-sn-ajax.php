<?php
defined('ABSPATH') || exit;

/** Authenticated compatibility bridge; new clients use REST v2. */
final class SN_Ajax {
    public static function register(): void {
        add_action('wp_ajax_sn_network_status', [self::class, 'status']);
    }

    public static function status(): void {
        check_ajax_referer('sn_ajax', 'nonce');
        $access = SN_Policy::access();
        if (is_wp_error($access)) {
            wp_send_json_error(['code' => $access->get_error_code(), 'message' => $access->get_error_message()], (int) ($access->get_error_data()['status'] ?? 403));
        }
        wp_send_json_success([
            'version' => SN_VERSION,
            'rest_namespace' => 'sabri-network/v2',
            'user' => SN_Auth::public_user(get_current_user_id(), true),
        ]);
    }
}
