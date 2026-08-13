<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_1 {

    public static function register(): void {
        add_action('init', [self::class, 'init'], 7);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_brand_overrides'], 100);
        add_action('template_redirect', [self::class, 'maybe_download'], -10);
        add_action('template_redirect', [self::class, 'disable_cache'], 0);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_shortcode('sabri_file_transfer', [self::class, 'render']);
        add_filter('the_content', [self::class, 'force_content'], 9996);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function enqueue_brand_overrides(): void {
        if (!self::is_file17_surface()) { return; }
        $path = SN_DIR . 'assets/css/brand-green-overrides.css';
        if (!is_file($path)) { return; }
        wp_enqueue_style(
            'sabri-file17-green-brand',
            SN_URL . 'assets/css/brand-green-overrides.css',
            [],
            (string) filemtime($path)
        );
    }

    private static function is_file17_surface(): bool {
        $queried = get_queried_object_id();
        $network_id = (int) get_option('sn_network_page_id');
        if ($network_id > 0 && SN_Activator::is_owned_page($network_id) && $queried === $network_id) { return true; }

        foreach (['sn_messages_page_id', 'sn_communication_settings_page_id'] as $option) {
            $id = (int) get_option($option);
            if ($id > 0 && SN_Messages::is_owned_page($id) && $queried === $id) { return true; }
        }

        $smail_id = (int) get_option('sn_smail_page_id');
        if ($smail_id > 0 && (string) get_post_meta($smail_id, '_sn_file17_owned', true) === 'smail' && $queried === $smail_id) { return true; }

        $transfer_id = (int) get_option('sn_file_transfer_page_id');
        if ($transfer_id > 0 && self::is_owned_page($transfer_id) && $queried === $transfer_id) { return true; }

        return (int) get_query_var('sn_messages_app') === 1 || (int) get_query_var('sn_meet_app') === 1;
    }

    public static function init(): void {
        self::maybe_upgrade(); self::ensure_storage();
        if (!(int) get_option('sn_file_transfer_page_id')) { self::ensure_page(false); }
        do_action('sn_network_route_registered', ['key' => 'file-transfer', 'label' => 'File Transfer', 'url' => self::url(), 'owner' => 'file-17', 'version' => SN_VERSION]);
        do_action('sn_network_file_transfer_contract_registered', [
            'owner' => 'file-17', 'version' => '1.0.0', 'max_file_bytes' => self::MAX_FILE_BYTES,
            'verified_users_only' => true, 'resumable' => true, 'private_encrypted_chunks' => true,
            'scan_contract' => 'sn_network_transfer_scan_result', 'binary_processing_owner' => 'cf-04-after-activation',
            'notification_owner' => 'file-19', 'rest_base' => rest_url('sabri-network/v2/transfers/'), 'url' => self::url(),
        ]);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sessions = self::sessions_table(); $chunks = self::chunks_table(); $recipients = self::recipients_table();
        dbDelta("CREATE TABLE $sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            original_name VARCHAR(255) NOT NULL,
            safe_name VARCHAR(255) NOT NULL,
            declared_mime VARCHAR(191) NOT NULL DEFAULT '',
            detected_mime VARCHAR(191) NOT NULL DEFAULT '',
            total_bytes BIGINT UNSIGNED NOT NULL,
            chunk_bytes INT UNSIGNED NOT NULL,
            total_chunks INT UNSIGNED NOT NULL,
            received_chunks INT UNSIGNED NOT NULL DEFAULT 0,
            received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            expected_sha256 CHAR(64) NOT NULL DEFAULT '',
            actual_sha256 CHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL DEFAULT 'uploading',
            scan_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            failure_code VARCHAR(64) NOT NULL DEFAULT '',
            idempotency_key CHAR(64) NOT NULL,
            retention_until DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            completed_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY sender_idempotency (sender_id,idempotency_key),
            KEY sender_status (sender_id,status,created_at),
            KEY conversation_status (conversation_id,status),
            KEY expires_at (expires_at)
        ) $charset;");
        dbDelta("CREATE TABLE $chunks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transfer_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            byte_count INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            storage_key VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY transfer_chunk (transfer_id,chunk_index),
            KEY transfer_id (transfer_id)
        ) $charset;");
        dbDelta("CREATE TABLE $recipients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transfer_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            notified_at DATETIME NULL,
            first_accessed_at DATETIME NULL,
            downloaded_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY transfer_user (transfer_id,user_id),
            KEY user_state (user_id,state,updated_at)
        ) $charset;");
        update_option('sn_file_transfer_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_file_transfer_schema_version', '') !== self::SCHEMA_VERSION) { self::install(); }
    }

    public static function register_routes(): void {
        register_rest_route('sabri-network/v2', '/transfers', [
            ['methods' => 'GET', 'callback' => [self::class, 'list_transfers'], 'permission_callback' => [self::class, 'verified_access']],
            ['methods' => 'POST', 'callback' => [self::class, 'initiate'], 'permission_callback' => [self::class, 'verified_access']],
        ]);
        register_rest_route('sabri-network/v2', '/transfers/(?P<public_id>[a-f0-9-]{36})', [
            'methods' => 'GET', 'callback' => [self::class, 'status'], 'permission_callback' => [self::class, 'verified_access'],
        ]);
        register_rest_route('sabri-network/v2', '/transfers/(?P<public_id>[a-f0-9-]{36})/chunks/(?P<index>\d+)', [
            'methods' => 'PUT', 'callback' => [self::class, 'upload_chunk'], 'permission_callback' => [self::class, 'verified_access'],
        ]);
        register_rest_route('sabri-network/v2', '/transfers/(?P<public_id>[a-f0-9-]{36})/finalize', [
            'methods' => 'POST', 'callback' => [self::class, 'finalize'], 'permission_callback' => [self::class, 'verified_access'],
        ]);
        register_rest_route('sabri-network/v2', '/transfers/(?P<public_id>[a-f0-9-]{36})/grant', [
            'methods' => 'POST', 'callback' => [self::class, 'grant'], 'permission_callback' => [self::class, 'verified_access'],
        ]);
        register_rest_route('sabri-network/v2', '/transfers/(?P<public_id>[a-f0-9-]{36})/revoke', [
            'methods' => 'POST', 'callback' => [self::class, 'revoke'], 'permission_callback' => [self::class, 'verified_access'],
        ]);
        register_rest_route('sabri-network/v2', '/transfers/health', [
            'methods' => 'GET', 'callback' => [self::class, 'health'], 'permission_callback' => [SN_REST::class, 'admin_access'],
        ]);
    }

    public static function verified_access(): bool|WP_Error {
        $access = SN_Policy::access(); if (is_wp_error($access)) { return $access; }
        return self::is_verified_user(get_current_user_id()) ? true : new WP_Error('verified_account_required', 'A current verified platform account is required for file transfer.', ['status' => 403]);
    }

}
