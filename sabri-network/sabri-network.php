<?php
/**
 * Plugin Name: Sabri Network and Messages
 * Plugin URI: https://www.sabrihomeopathy.com/
 * Description: Canonical File-17 relationship, messaging, Sabri Meet, call, private update and abuse-reporting system for the Sabri Social Homeopathy Platform.
 * Version: 2.0.0
 * Author: Sabri Homeopathy
 * Text Domain: sabri-network
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

define('SN_VERSION', '2.0.0');
define('SN_FILE', __FILE__);
define('SN_DIR', plugin_dir_path(__FILE__));
define('SN_URL', plugin_dir_url(__FILE__));

require_once SN_DIR . 'includes/class-sn-db.php';
require_once SN_DIR . 'includes/class-sn-safety.php';
require_once SN_DIR . 'includes/class-sn-policy.php';
require_once SN_DIR . 'includes/class-sn-private-files.php';
require_once SN_DIR . 'includes/class-sn-privacy.php';
require_once SN_DIR . 'includes/class-sn-activator.php';
require_once SN_DIR . 'includes/class-sn-auth.php';
require_once SN_DIR . 'includes/class-sn-relationships.php';
require_once SN_DIR . 'includes/class-sn-admin.php';
require_once SN_DIR . 'includes/class-sn-rest.php';
require_once SN_DIR . 'includes/class-sn-ajax.php';
require_once SN_DIR . 'includes/class-sn-shortcode.php';
require_once SN_DIR . 'includes/class-sn-meet.php';

register_activation_hook(__FILE__, ['SN_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['SN_Activator', 'deactivate']);

final class Sabri_Network {
    private static ?Sabri_Network $instance = null;

    public static function instance(): Sabri_Network {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'init']);
        add_action('template_redirect', [$this, 'disable_network_cache'], 0);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('rest_api_init', ['SN_REST', 'register_routes']);
        add_action('admin_menu', ['SN_Admin', 'register_menu']);
        add_action('admin_init', ['SN_Admin', 'register_settings']);
        add_action('wp_enqueue_scripts', ['SN_Shortcode', 'register_assets'], 5);
        add_action('wp_enqueue_scripts', ['SN_Shortcode', 'enqueue_if_network'], 20);
        add_shortcode('sabri_network', ['SN_Shortcode', 'render']);
        add_filter('sn_network_profile_action_state', ['SN_Relationships', 'filter_profile_action_state'], 10, 3);

        SN_Ajax::register();
        SN_Privacy::register();
        SN_Private_Files::register();
        SN_Meet::register();

        add_filter('query_vars', [$this, 'query_vars']);
        add_filter('template_include', [$this, 'safe_template'], 99);
        add_filter('redirect_canonical', [$this, 'disable_safe_canonical'], 10, 2);
        add_filter('the_content', [$this, 'force_network_content'], 9999);
        add_action('sn_cleanup_hourly', ['SN_DB', 'cleanup_expired']);
        add_action('sn_network_retry_private_delete', ['SN_Private_Files', 'retry_delete_bytes']);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('sabri-network', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function init(): void {
        SN_DB::maybe_upgrade();
        SN_Activator::ensure_cleanup_schedule();
        add_rewrite_tag('%sn_network_app%', '1');
        add_rewrite_rule('^network-safe/?$', 'index.php?sn_network_app=1', 'top');

        if ((string) get_option('sn_plugin_version', '') !== SN_VERSION) {
            SN_Activator::retire_legacy_secrets();
            SN_DB::install();
            SN_Private_Files::ensure_storage();
            SN_Activator::ensure_network_page(true);
            update_option('sn_plugin_version', SN_VERSION, false);
            flush_rewrite_rules(false);
        }

        /** File 20 consumes this route contract; File 17 does not inject a second global menu. */
        do_action('sn_network_relationship_contract_registered', [
            'owner' => 'file-17',
            'version' => SN_VERSION,
            'state_route' => rest_url('sabri-network/v2/users/{user_id}/relationship'),
            'state_method' => 'GET',
            'follow_route' => rest_url('sabri-network/v2/users/{user_id}/follow'),
            'follow_method' => 'POST',
            'contact_route' => rest_url('sabri-network/v2/contacts'),
            'contact_request_route' => rest_url('sabri-network/v2/contacts'),
            'contact_request_method' => 'POST',
            'contact_decision_route' => rest_url('sabri-network/v2/contacts/{request_id}'),
            'contact_decision_method' => 'POST',
            'block_route' => rest_url('sabri-network/v2/block'),
            'block_method' => 'POST',
            'conversation_route' => rest_url('sabri-network/v2/conversations'),
            'conversation_method' => 'POST',
        ]);

        do_action('sn_network_route_registered', [
            'key' => 'network',
            'label' => (string) get_option('sn_menu_label', 'Network'),
            'url' => SN_Activator::network_url(),
            'owner' => 'file-17',
            'version' => SN_VERSION,
        ]);
    }

    public function query_vars(array $vars): array {
        $vars[] = 'sn_network_app';
        return $vars;
    }

    public function disable_safe_canonical($redirect_url, $requested_url) {
        return ((int) get_query_var('sn_network_app') === 1 || isset($_GET['sn-network-safe'])) ? false : $redirect_url;
    }

    public function safe_template(string $template): string {
        if ((int) get_query_var('sn_network_app') === 1 || isset($_GET['sn-network-safe'])) {
            status_header(200);
            return SN_DIR . 'templates/network-standalone.php';
        }
        return $template;
    }

    public function force_network_content(string $content): string {
        $page_id = (int) get_option('sn_network_page_id');
        if ($page_id && SN_Activator::is_owned_page($page_id) && is_page($page_id) && in_the_loop() && is_main_query()) {
            return do_shortcode('[sabri_network]');
        }
        return $content;
    }

    public function disable_network_cache(): void {
        $page_id = (int) get_option('sn_network_page_id');
        $is_network = ($page_id && SN_Activator::is_owned_page($page_id) && is_page($page_id)) || (int) get_query_var('sn_network_app') === 1 || isset($_GET['sn-network-safe']);
        if (!$is_network) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, noarchive', true);
        header('X-Content-Type-Options: nosniff', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('X-LiteSpeed-Cache-Control: no-cache', true);
        do_action('litespeed_control_set_nocache', 'Sabri Network is an authenticated dynamic page.');
    }

    public function admin_notices(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'toplevel_page_sabri-network') {
            return;
        }
        $page_id = (int) get_option('sn_network_page_id');
        $page_ok = $page_id && SN_Activator::is_owned_page($page_id) && get_post_status($page_id) === 'publish';
        if (!$page_ok) {
            echo '<div class="notice notice-error"><p><strong>Sabri Network:</strong> The owned Network page is missing. Open <a href="' . esc_url(admin_url('admin.php?page=sabri-network')) . '">Network</a> and run repair.</p></div>';
        }
    }
}

Sabri_Network::instance();
