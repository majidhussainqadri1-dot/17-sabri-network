<?php
defined('ABSPATH') || exit;

final class SN_Shortcode {
    public static function register_assets(): void {
        $css_version = is_file(SN_DIR . 'assets/css/network.css') ? (string) filemtime(SN_DIR . 'assets/css/network.css') : SN_VERSION;
        $js_version = is_file(SN_DIR . 'assets/js/network.js') ? (string) filemtime(SN_DIR . 'assets/js/network.js') : SN_VERSION;
        $two_plan_css = SN_DIR . 'assets/css/two-plan-ui.css';
        $two_plan_js = SN_DIR . 'assets/js/two-plan-ui.js';
        wp_register_style('sabri-network', SN_URL . 'assets/css/network.css', [], $css_version);
        wp_register_script('sabri-network', SN_URL . 'assets/js/network.js', [], $js_version, true);
        wp_register_style('sabri-network-two-plan-ui', SN_URL . 'assets/css/two-plan-ui.css', ['sabri-network'], is_file($two_plan_css) ? (string) filemtime($two_plan_css) : SN_VERSION);
        wp_register_script('sabri-network-two-plan-ui', SN_URL . 'assets/js/two-plan-ui.js', ['sabri-network'], is_file($two_plan_js) ? (string) filemtime($two_plan_js) : SN_VERSION, true);
    }

    public static function enqueue_if_network(): void {
        $page_id = (int) get_option('sn_network_page_id');
        $is_network = ($page_id && SN_Activator::is_owned_page($page_id) && is_page($page_id)) || (int) get_query_var('sn_network_app') === 1 || isset($_GET['sn-network-safe']);
        if ($is_network) {
            wp_enqueue_style('sabri-network');
            wp_enqueue_style('sabri-network-two-plan-ui');
            wp_enqueue_script('sabri-network');
            wp_enqueue_script('sabri-network-two-plan-ui');
        }
    }

    public static function render(): string {
        self::register_assets();
        wp_enqueue_style('sabri-network');
        wp_enqueue_style('sabri-network-two-plan-ui');
        wp_enqueue_script('sabri-network');
        wp_enqueue_script('sabri-network-two-plan-ui');

        $access = SN_Policy::access();
        $network_ready = $access === true;
        $login_url = wp_login_url(SN_Activator::network_url());
        $login_url = (string) apply_filters('sn_network_login_url', $login_url, SN_Activator::network_url());
        wp_localize_script('sabri-network', 'SN_CONFIG', [
            'version' => SN_VERSION,
            'restUrl' => esc_url_raw(rest_url('sabri-network/v2/')),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
            'ajaxNonce' => wp_create_nonce('sn_ajax'),
            'isLoggedIn' => $network_ready,
            'loginUrl' => esc_url_raw($login_url),
            'networkUrl' => esc_url_raw(SN_Activator::network_url()),
            'safeUrl' => esc_url_raw(SN_Activator::safe_url()),
            'maxUploadMb' => min(100, max(1, (int) get_option('sn_max_upload_mb', 25))),
            'strings' => [
                'requestFailed' => __('The Network request could not be completed.', 'sabri-network'),
                'offline' => __('You appear to be offline.', 'sabri-network'),
                'signIn' => __('Sign in to Network', 'sabri-network'),
            ],
        ]);

        ob_start();
        include SN_DIR . 'templates/network-app.php';
        return (string) ob_get_clean();
    }
}
