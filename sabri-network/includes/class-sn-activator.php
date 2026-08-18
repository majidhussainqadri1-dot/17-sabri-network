<?php
defined('ABSPATH') || exit;

final class SN_Activator {
    private const PAGE_OWNER_META = '_sn_network_owned';

    public static function activate(): void {
        self::set_defaults();
        self::retire_legacy_secrets();
        // Activation delegates all schema/version publication to the serialized
        // migration governor. Its verified order includes these historical direct
        // activation steps, now centrally owned rather than duplicated here:
        // SN_Two_Plan_Completion::install();
        // SN_Future_Superset::install();
        // update_option('sn_plugin_version', SN_VERSION, false);
        $migration = SN_Fifth_Fresh_Migration_Hardening::upgrade(true);
        if (is_wp_error($migration)) {
            throw new RuntimeException($migration->get_error_message());
        }
        SN_Messages::register_rewrites();
        SN_Meet::register_rewrites();
        if (!SN_Private_Files::ensure_storage()) throw new RuntimeException('File 17 private message storage is unavailable.');
        if (!SN_File_Transfer::ensure_storage()) throw new RuntimeException('File 17 transfer storage is unavailable.');
        if (self::ensure_network_page() <= 0) throw new RuntimeException('File 17 Network page could not be created safely.');
        SN_Messages::ensure_pages();
        if (SN_File_Transfer::ensure_page(false) <= 0) throw new RuntimeException('File 17 transfer page could not be created safely.');
        if (SN_Smail::ensure_page(false) <= 0) throw new RuntimeException('File 17 Smail page could not be created safely.');
        SN_Messages::mark_routes_current();
        self::ensure_cleanup_schedule();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('sn_cleanup_hourly');
        flush_rewrite_rules(false);
    }

    public static function ensure_cleanup_schedule(): void {
        if (!wp_next_scheduled('sn_cleanup_hourly')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sn_cleanup_hourly');
        }
    }

    public static function safe_url(): string {
        return home_url('/network-safe/');
    }

    public static function network_url(): string {
        $page_id = (int) get_option('sn_network_page_id');
        if ($page_id && self::is_owned_page($page_id) && get_post_status($page_id) === 'publish') {
            $url = get_permalink($page_id);
            if ($url) return (string) $url;
        }
        return self::safe_url();
    }

    /** Never overwrites an unrelated /network page. */
    public static function ensure_network_page(bool $repair = false): int {
        $page_id = (int) get_option('sn_network_page_id');
        $page = $page_id ? get_post($page_id) : null;
        if ($page instanceof WP_Post && self::is_owned_page($page_id)) {
            if ($repair || !has_shortcode((string) $page->post_content, 'sabri_network') || $page->post_status !== 'publish') {
                wp_update_post([
                    'ID' => $page_id,
                    'post_title' => 'Network',
                    'post_content' => '[sabri_network]',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                ]);
            }
            return $page_id;
        }

        $candidate = get_page_by_path('network', OBJECT, 'page');
        if ($candidate instanceof WP_Post && self::is_owned_page((int) $candidate->ID)) {
            update_option('sn_network_page_id', (int) $candidate->ID, false);
            return self::ensure_network_page($repair);
        }
        $slug = $candidate instanceof WP_Post ? 'network-messages' : 'network';
        $owned = get_page_by_path($slug, OBJECT, 'page');
        if ($owned instanceof WP_Post && self::is_owned_page((int) $owned->ID)) {
            update_option('sn_network_page_id', (int) $owned->ID, false);
            return self::ensure_network_page($repair);
        }
        $new_id = wp_insert_post([
            'post_title' => 'Network',
            'post_name' => $slug,
            'post_content' => '[sabri_network]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed',
        ], true);
        if (is_wp_error($new_id) || !$new_id) return 0;
        update_post_meta((int) $new_id, self::PAGE_OWNER_META, 'file-17');
        update_option('sn_network_page_id', (int) $new_id, false);
        return (int) $new_id;
    }

    public static function is_owned_page(int $page_id): bool {
        return (string) get_post_meta($page_id, self::PAGE_OWNER_META, true) === 'file-17';
    }

    public static function retire_legacy_secrets(): void {
        foreach ([
            'sn_sms_webhook_url','sn_sms_auth_header','sn_sms_payload_template','sn_sms_message_template',
            'sn_turn_url','sn_turn_username','sn_turn_credential','sn_enable_staging_otp','sn_otp_expiry_minutes','sn_auto_menu_button',
        ] as $key) delete_option($key);
        update_option('sn_legacy_identity_secrets_retired', gmdate('c'), false);
    }

    private static function set_defaults(): void {
        $defaults = [
            'sn_menu_label' => 'Network',
            'sn_default_country_code' => '+92',
            'sn_max_upload_mb' => 25,
            'sn_stun_urls' => '',
        ];
        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) add_option($key, $value, '', false);
        }
    }
}
