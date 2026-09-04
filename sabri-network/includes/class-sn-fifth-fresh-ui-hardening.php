<?php
/** Fifth fresh review: exact brand and keyboard-accessibility asset governance. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fifth_Fresh_UI_Hardening {
    public static function register(): void {
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 4);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 1000);
    }

    public static function register_assets(): void {
        $css = SN_DIR . 'assets/css/brand-green-overrides.css';
        $js = SN_DIR . 'assets/js/fifth-fresh-ui.js';
        wp_register_style('sn-file17-brand-green', SN_URL . 'assets/css/brand-green-overrides.css', [], is_file($css) ? (string)filemtime($css) : SN_VERSION);
        wp_register_script('sn-file17-ui-hardening', SN_URL . 'assets/js/fifth-fresh-ui.js', [], is_file($js) ? (string)filemtime($js) : SN_VERSION, true);
    }

    public static function enqueue_assets(): void {
        if (!self::is_file17_surface()) return;
        wp_enqueue_style('sn-file17-brand-green');
        wp_enqueue_script('sn-file17-ui-hardening');
    }

    private static function is_file17_surface(): bool {
        $page = get_queried_object_id();
        $owned_pages = array_filter(array_map('absint', [
            get_option('sn_network_page_id', 0),
            get_option('sn_messages_page_id', 0),
            get_option('sn_communication_settings_page_id', 0),
            get_option('sn_smail_page_id', 0),
            get_option('sn_file_transfer_page_id', 0),
        ]));
        if ($page > 0 && in_array($page, $owned_pages, true)) return true;
        if ((int)get_query_var('sn_network_app') === 1) return true;
        if ((int)get_query_var('sn_messages_app') === 1) return true;
        if ((string)get_query_var('sn_meet_app') !== '') return true;
        return false;
    }
}
