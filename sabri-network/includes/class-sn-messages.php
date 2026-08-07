<?php
defined('ABSPATH') || exit;

/**
 * Dedicated Messages and communication-settings surfaces plus native per-recipient
 * delivered/read receipt reconciliation for File 17.
 */
final class SN_Messages {
    private const PAGE_OWNER_META = '_sn_messages_owned';
    private const SCHEMA_VERSION = '1.0.0';
    private const ROUTE_VERSION = '1.0.0';
    private const MAX_RECEIPT_RANGE = 500;
    private const MAX_RECEIPT_SUMMARIES = 100;

    public static function register(): void {
        add_action('init', [self::class, 'init'], 5);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('template_redirect', [self::class, 'disable_cache'], 0);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_if_surface'], 21);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);

        add_shortcode('sabri_messages', [self::class, 'render_messages']);
        add_shortcode('sabri_communication_settings', [self::class, 'render_settings']);

        add_filter('query_vars', [self::class, 'query_vars']);
        add_filter('template_include', [self::class, 'template_include'], 98);
        add_filter('redirect_canonical', [self::class, 'disable_canonical'], 10, 2);
        add_filter('the_content', [self::class, 'force_content'], 9998);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function init(): void {
        self::maybe_upgrade();
        self::register_rewrites();

        if (!(int) get_option('sn_messages_page_id') || !(int) get_option('sn_communication_settings_page_id')) {
            self::ensure_pages(false);
        }
        $signature = self::route_signature();
        if ((string) get_option('sn_messages_route_signature', '') !== $signature) {
            update_option('sn_messages_route_signature', $signature, false);
            flush_rewrite_rules(false);
        }

        do_action('sn_network_route_registered', [
            'key' => 'messages',
            'label' => 'Messages',
            'url' => self::messages_url(),
            'owner' => 'file-17',
            'version' => SN_VERSION,
        ]);
        do_action('sn_network_route_registered', [
            'key' => 'communication-settings',
            'label' => 'Communication Settings',
            'url' => self::settings_url(),
            'owner' => 'file-17',
            'version' => SN_VERSION,
        ]);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::receipt_table();

        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            device_key CHAR(64) NOT NULL,
            delivered_at DATETIME NULL,
            read_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_user_device (message_id,user_id,device_key),
            KEY conversation_user_message (conversation_id,user_id,message_id),
            KEY message_state (message_id,delivered_at,read_at),
            KEY updated_at (updated_at)
        ) $charset;");

        update_option('sn_message_receipts_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_message_receipts_schema_version', '') !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    public static function register_rewrites(): void {
        add_rewrite_tag('%sn_messages_app%', '1');
        add_rewrite_tag('%sn_messages_mode%', '([^&]+)');
        add_rewrite_tag('%sn_conversation_id%', '([0-9]+)');
        $candidate = get_page_by_path('messages', OBJECT, 'page');
        if (!$candidate instanceof WP_Post || self::is_owned_page((int) $candidate->ID, 'messages')) {
            add_rewrite_rule('^messages/([1-9][0-9]*)/?$', 'index.php?sn_messages_app=1&sn_messages_mode=messages&sn_conversation_id=$matches[1]', 'top');
        }
        add_rewrite_rule('^messages-safe/?$', 'index.php?sn_messages_app=1&sn_messages_mode=messages', 'top');
        add_rewrite_rule('^communication-settings-safe/?$', 'index.php?sn_messages_app=1&sn_messages_mode=settings', 'top');
    }

    public static function mark_routes_current(): void {
        update_option('sn_messages_route_signature', self::route_signature(), false);
    }

    public static function query_vars(array $vars): array {
        $vars[] = 'sn_messages_app';
        $vars[] = 'sn_messages_mode';
        $vars[] = 'sn_conversation_id';
        return array_values(array_unique($vars));
    }

    public static function template_include(string $template): string {
        if ((int) get_query_var('sn_messages_app') !== 1) {
            return $template;
        }
        status_header(200);
        return SN_DIR . 'templates/messages-standalone.php';
    }

    public static function disable_canonical($redirect_url, $requested_url) {
        return (int) get_query_var('sn_messages_app') === 1 ? false : $redirect_url;
    }

    public static function register_assets(): void {
        $css = SN_DIR . 'assets/css/messages.css';
        $js = SN_DIR . 'assets/js/messages.js';
        wp_register_style('sabri-messages', SN_URL . 'assets/css/messages.css', [], is_file($css) ? (string) filemtime($css) : SN_VERSION);
        wp_register_script('sabri-messages', SN_URL . 'assets/js/messages.js', [], is_file($js) ? (string) filemtime($js) : SN_VERSION, true);
    }

    public static function enqueue_if_surface(): void {
        if (!self::is_surface()) {
            return;
        }
        wp_enqueue_style('sabri-messages');
        wp_enqueue_script('sabri-messages');
    }

    public static function render_messages(): string {
        return self::render('messages');
    }

    public static function render_settings(): string {
        return self::render('settings');
    }

    private static function render(string $mode): string {
        self::register_assets();
        wp_enqueue_style('sabri-messages');
        wp_enqueue_script('sabri-messages');

        $access = SN_Policy::access();
        $ready = $access === true;
        $destination = $mode === 'settings' ? self::settings_url() : self::messages_url(self::current_conversation_id());
        $login_url = (string) apply_filters('sn_network_login_url', wp_login_url($destination), $destination);

        wp_localize_script('sabri-messages', 'SN_MESSAGES_CONFIG', [
            'version' => SN_VERSION,
            'mode' => $mode,
            'restUrl' => esc_url_raw(rest_url('sabri-network/v2/')),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'isLoggedIn' => $ready,
            'currentUserId' => get_current_user_id(),
            'conversationId' => self::current_conversation_id(),
            'messagesUrl' => esc_url_raw(self::messages_url()),
            'conversationBaseUrl' => esc_url_raw(self::conversation_base_url()),
            'settingsUrl' => esc_url_raw(self::settings_url()),
            'networkUrl' => esc_url_raw(SN_Activator::network_url()),
            'loginUrl' => esc_url_raw($login_url),
            'maxUploadMb' => min(100, max(1, (int) get_option('sn_max_upload_mb', 25))),
            'deviceStorageKey' => 'sn_messages_device_v1',
            'strings' => [
                'requestFailed' => __('The Messages request could not be completed.', 'sabri-network'),
                'offline' => __('You appear to be offline.', 'sabri-network'),
                'empty' => __('No conversations are available yet.', 'sabri-network'),
                'selectConversation' => __('Select a conversation to begin.', 'sabri-network'),
            ],
        ]);

        ob_start();
        if ($mode === 'settings') {
            include SN_DIR . 'templates/communication-settings.php';
        } else {
            include SN_DIR . 'templates/messages-app.php';
        }
        return (string) ob_get_clean();
    }

    public static function force_content(string $content): string {
        if (!in_the_loop() || !is_main_query()) {
            return $content;
        }
        $page_id = get_queried_object_id();
        if ($page_id <= 0 || !self::is_owned_page($page_id)) {
            return $content;
        }
        $owner = (string) get_post_meta($page_id, self::PAGE_OWNER_META, true);
        return $owner === 'settings'
            ? do_shortcode('[sabri_communication_settings]')
            : do_shortcode('[sabri_messages]');
    }

    public static function disable_cache(): void {
        if (!self::is_surface()) {
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
        do_action('litespeed_control_set_nocache', 'Sabri Messages is an authenticated dynamic surface.');
    }

    public static function ensure_pages(bool $repair = false): array {
        return [
            'messages' => self::ensure_owned_page(
                'sn_messages_page_id',
                'messages',
                'sabri-messages',
                'Messages',
                '[sabri_messages]',
                'messages',
                $repair
            ),
            'settings' => self::ensure_owned_page(
                'sn_communication_settings_page_id',
                'communication-settings',
                'network-communication-settings',
                'Communication Settings',
                '[sabri_communication_settings]',
                'settings',
                $repair
            ),
        ];
    }

    private static function ensure_owned_page(
        string $option,
        string $preferred_slug,
        string $fallback_slug,
        string $title,
        string $shortcode,
        string $owner,
        bool $repair
    ): int {
        $page_id = (int) get_option($option);
        $page = $page_id ? get_post($page_id) : null;
        if ($page instanceof WP_Post && self::is_owned_page($page_id, $owner)) {
            if ($repair || !has_shortcode((string) $page->post_content, trim($shortcode, '[]')) || $page->post_status !== 'publish') {
                wp_update_post([
                    'ID' => $page_id,
                    'post_title' => $title,
                    'post_content' => $shortcode,
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                ]);
            }
            return $page_id;
        }

        $candidate = get_page_by_path($preferred_slug, OBJECT, 'page');
        if ($candidate instanceof WP_Post && self::is_owned_page((int) $candidate->ID, $owner)) {
            update_option($option, (int) $candidate->ID, false);
            return self::ensure_owned_page($option, $preferred_slug, $fallback_slug, $title, $shortcode, $owner, $repair);
        }

        $slug = $candidate instanceof WP_Post ? $fallback_slug : $preferred_slug;
        $fallback = get_page_by_path($slug, OBJECT, 'page');
        if ($fallback instanceof WP_Post && self::is_owned_page((int) $fallback->ID, $owner)) {
            update_option($option, (int) $fallback->ID, false);
            return self::ensure_owned_page($option, $preferred_slug, $fallback_slug, $title, $shortcode, $owner, $repair);
        }

        if ($fallback instanceof WP_Post) {
            update_option('sn_messages_page_conflict_' . sanitize_key($owner), (int) $fallback->ID, false);
            return 0;
        }

        $new_id = wp_insert_post([
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $shortcode,
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed',
        ], true);
        if (is_wp_error($new_id) || !$new_id) {
            return 0;
        }
        update_post_meta((int) $new_id, self::PAGE_OWNER_META, $owner);
        update_option($option, (int) $new_id, false);
        return (int) $new_id;
    }

    public static function messages_url(int $conversation_id = 0): string {
        $page_id = (int) get_option('sn_messages_page_id');
        $base = home_url('/messages-safe/');
        if ($page_id && self::is_owned_page($page_id, 'messages') && get_post_status($page_id) === 'publish') {
            $url = get_permalink($page_id);
            if ($url) {
                $base = (string) $url;
            }
        }
        if ($conversation_id <= 0) {
            return $base;
        }
        if (untrailingslashit($base) === untrailingslashit(home_url('/messages/'))) {
            return home_url('/messages/' . $conversation_id . '/');
        }
        return add_query_arg('conversation', $conversation_id, $base);
    }

    public static function conversation_base_url(): string {
        $base = self::messages_url();
        return untrailingslashit($base) === untrailingslashit(home_url('/messages/'))
            ? trailingslashit(home_url('/messages/'))
            : $base;
    }

    public static function settings_url(): string {
        $page_id = (int) get_option('sn_communication_settings_page_id');
        if ($page_id && self::is_owned_page($page_id, 'settings') && get_post_status($page_id) === 'publish') {
            $url = get_permalink($page_id);
            if ($url) {
                return (string) $url;
            }
        }
        return home_url('/communication-settings-safe/');
    }

    private static function route_signature(): string {
        $candidate = get_page_by_path('messages', OBJECT, 'page');
        $candidate_id = $candidate instanceof WP_Post ? (int) $candidate->ID : 0;
        $candidate_owner = $candidate_id > 0 ? (string) get_post_meta($candidate_id, self::PAGE_OWNER_META, true) : '';
        return hash('sha256', implode(':', [
            self::ROUTE_VERSION,
            $candidate_id,
            $candidate_owner,
            (int) get_option('sn_messages_page_id'),
            (int) get_option('sn_communication_settings_page_id'),
        ]));
    }

    private static function current_conversation_id(): int {
        $id = absint(get_query_var('sn_conversation_id'));
        if (!$id && isset($_GET['conversation'])) {
            $id = absint(wp_unslash($_GET['conversation']));
        }
        return $id;
    }

    private static function is_surface(): bool {
        if ((int) get_query_var('sn_messages_app') === 1) {
            return true;
        }
        $page_id = get_queried_object_id();
        return $page_id > 0 && self::is_owned_page($page_id);
    }

    private static function is_owned_page(int $page_id, string $owner = ''): bool {
        $value = (string) get_post_meta($page_id, self::PAGE_OWNER_META, true);
        return $owner === '' ? in_array($value, ['messages', 'settings'], true) : $value === $owner;
    }

    public static function register_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/receipts', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_receipts'],
                'permission_callback' => [self::class, 'access'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'record_receipt'],
                'permission_callback' => [self::class, 'access'],
            ],
        ]);
        register_rest_route('sabri-network/v2', '/admin/message-receipts-health', [
            'methods' => 'GET',
            'callback' => [self::class, 'health'],
            'permission_callback' => [self::class, 'admin_access'],
        ]);
    }

    public static function access(): bool|WP_Error {
        return SN_Policy::access();
    }

    public static function admin_access(): bool|WP_Error {
        $access = SN_Policy::access();
        if (is_wp_error($access)) {
            return $access;
        }
        return current_user_can('manage_options')
            ? true
            : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
    }

    public static function record_receipt(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        if (!SN_Policy::consume_rate_limit('message_receipt', (string) $user_id, 600, MINUTE_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many receipt updates. Please wait and try again.', ['status' => 429]);
        }

        $requested_message_id = absint($request->get_param('message_id'));
        $state = sanitize_key((string) $request->get_param('state'));
        $device_key = self::device_key((string) $request->get_param('device_id'), $user_id);
        if (is_wp_error($device_key)) {
            return $device_key;
        }
        if (!in_array($state, ['delivered', 'read'], true) || $requested_message_id <= 0) {
            return new WP_Error('invalid_receipt', 'A valid message and receipt state are required.', ['status' => 400]);
        }

        $messages = SN_DB::table('messages');
        $target = $wpdb->get_row($wpdb->prepare(
            "SELECT id,sender_id,deleted_at FROM $messages WHERE id=%d AND conversation_id=%d",
            $requested_message_id,
            $conversation_id
        ));
        if (!$target || $target->deleted_at) {
            return self::not_found();
        }
        if ((int) $target->sender_id === $user_id) {
            return new WP_Error('own_message_receipt', 'A sender cannot create a recipient receipt for the same message.', ['status' => 409]);
        }

        $table = self::receipt_table();
        $state_column = $state === 'read' ? 'read_at' : 'delivered_at';
        $device_through = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(message_id),0) FROM $table
             WHERE conversation_id=%d AND user_id=%d AND device_key=%s AND $state_column IS NOT NULL",
            $conversation_id,
            $user_id,
            $device_key
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $messages
             WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL
             ORDER BY id ASC LIMIT %d",
            $conversation_id,
            $device_through,
            $requested_message_id,
            $user_id,
            self::MAX_RECEIPT_RANGE
        ));
        if (!is_array($rows)) {
            return new WP_Error('database_error', 'The receipt range could not be read.', ['status' => 500]);
        }

        $now = current_time('mysql', true);
        $through_message_id = $device_through;
        $recorded = 0;
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                $row_id = (int) $row->id;
                if ($state === 'read') {
                    $sql = $wpdb->prepare(
                        "INSERT INTO $table (message_id,conversation_id,user_id,device_key,delivered_at,read_at,updated_at)
                         VALUES (%d,%d,%d,%s,%s,%s,%s)
                         ON DUPLICATE KEY UPDATE
                            delivered_at=COALESCE(delivered_at,VALUES(delivered_at)),
                            read_at=COALESCE(read_at,VALUES(read_at)),
                            updated_at=VALUES(updated_at)",
                        $row_id,
                        $conversation_id,
                        $user_id,
                        $device_key,
                        $now,
                        $now,
                        $now
                    );
                } else {
                    $sql = $wpdb->prepare(
                        "INSERT INTO $table (message_id,conversation_id,user_id,device_key,delivered_at,read_at,updated_at)
                         VALUES (%d,%d,%d,%s,%s,NULL,%s)
                         ON DUPLICATE KEY UPDATE
                            delivered_at=COALESCE(delivered_at,VALUES(delivered_at)),
                            updated_at=VALUES(updated_at)",
                        $row_id,
                        $conversation_id,
                        $user_id,
                        $device_key,
                        $now,
                        $now
                    );
                }
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException('receipt_write_failed');
                }
                $through_message_id = max($through_message_id, $row_id);
                $recorded++;
            }
            if ($state === 'read' && $through_message_id > 0) {
                $pointer = $wpdb->query($wpdb->prepare(
                    'UPDATE ' . SN_DB::table('members') . ' SET last_read_message_id=GREATEST(last_read_message_id,%d) WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL',
                    $through_message_id,
                    $conversation_id,
                    $user_id
                ));
                if ($pointer === false) {
                    throw new RuntimeException('read_pointer_failed');
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_receipt_failed', 'conversation', $conversation_id, 'failure', [
                'requested_message_id' => $requested_message_id,
                'through_message_id' => $through_message_id,
                'state' => $state,
                'reason' => $e->getMessage(),
            ], $user_id);
            return new WP_Error('database_error', 'The receipt could not be recorded.', ['status' => 500]);
        }

        $more = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $messages
             WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL
             ORDER BY id ASC LIMIT 1",
            $conversation_id,
            $through_message_id,
            $requested_message_id,
            $user_id
        ));
        SN_DB::audit('message_receipt_recorded', 'conversation', $conversation_id, 'success', [
            'requested_message_id' => $requested_message_id,
            'through_message_id' => $through_message_id,
            'state' => $state,
            'recorded' => $recorded,
            'more' => $more,
        ], $user_id);
        do_action('sn_network_message_receipt_recorded', $conversation_id, $through_message_id, $user_id, $state, $requested_message_id, $more);
        return rest_ensure_response([
            'state' => $state,
            'recorded' => $recorded,
            'requested_message_id' => $requested_message_id,
            'through_message_id' => $through_message_id,
            'more' => $more,
        ]);
    }

    public static function get_receipts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        if (!SN_Policy::consume_rate_limit('message_receipt_read', (string) $user_id, 240, MINUTE_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many receipt requests. Please wait and try again.', ['status' => 429]);
        }

        $after = absint($request->get_param('after'));
        $limit = min(self::MAX_RECEIPT_SUMMARIES, max(1, absint($request->get_param('limit')) ?: 50));
        $messages = SN_DB::table('messages');
        $members = SN_DB::table('members');
        $receipts = self::receipt_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id message_id,
                    GREATEST(0,(SELECT COUNT(*) FROM $members mm
                        WHERE mm.conversation_id=m.conversation_id
                          AND mm.user_id<>m.sender_id
                          AND mm.joined_at<=m.created_at
                          AND (mm.left_at IS NULL OR mm.left_at>m.created_at))) recipient_count,
                    COUNT(DISTINCT CASE WHEN r.delivered_at IS NOT NULL THEN r.user_id END) delivered_count,
                    COUNT(DISTINCT CASE WHEN r.read_at IS NOT NULL THEN r.user_id END) read_count
             FROM $messages m
             LEFT JOIN $receipts r ON r.message_id=m.id AND r.conversation_id=m.conversation_id
             WHERE m.conversation_id=%d AND m.sender_id=%d AND m.id>%d AND m.deleted_at IS NULL
             GROUP BY m.id,m.conversation_id,m.sender_id
             ORDER BY m.id ASC LIMIT %d",
            $conversation_id,
            $user_id,
            $after,
            $limit
        ));
        if (!is_array($rows)) {
            return new WP_Error('database_error', 'Receipt summaries are unavailable.', ['status' => 500]);
        }

        $items = [];
        foreach ($rows as $row) {
            $recipients = (int) $row->recipient_count;
            $delivered = min($recipients, (int) $row->delivered_count);
            $read = min($recipients, (int) $row->read_count);
            $state = 'sent';
            if ($recipients > 0 && $read >= $recipients) {
                $state = 'read';
            } elseif ($recipients > 0 && $delivered >= $recipients) {
                $state = 'delivered';
            } elseif ($read > 0 || $delivered > 0) {
                $state = 'partial';
            }
            $items[] = [
                'message_id' => (int) $row->message_id,
                'recipient_count' => $recipients,
                'delivered_count' => $delivered,
                'read_count' => $read,
                'state' => $state,
            ];
        }
        return rest_ensure_response(['receipts' => $items]);
    }

    public static function health(): WP_REST_Response {
        global $wpdb;
        $table = self::receipt_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        return rest_ensure_response([
            'ok' => $exists && (string) get_option('sn_message_receipts_schema_version', '') === self::SCHEMA_VERSION,
            'table' => $exists,
            'schema_version' => (string) get_option('sn_message_receipts_schema_version', ''),
            'messages_page_id' => (int) get_option('sn_messages_page_id'),
            'settings_page_id' => (int) get_option('sn_communication_settings_page_id'),
            'retention_days' => self::retention_days(),
            'time' => gmdate('c'),
        ]);
    }

    public static function cleanup(): void {
        global $wpdb;
        $receipts = self::receipt_table();
        $messages = SN_DB::table('messages');
        $deleted_cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $retention_cutoff = gmdate('Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT r.id FROM $receipts r LEFT JOIN $messages m ON m.id=r.message_id
             WHERE m.id IS NULL OR r.updated_at<%s OR (m.deleted_at IS NOT NULL AND m.deleted_at<%s)
             ORDER BY r.id ASC LIMIT 1000",
            $retention_cutoff,
            $deleted_cutoff
        ));
        $ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $receipts WHERE id IN ($placeholders)", ...$ids));
        SN_DB::audit('message_receipts_cleaned', 'message_receipt', 0, $deleted === false ? 'failure' : 'success', [
            'selected' => count($ids),
            'deleted' => $deleted === false ? 0 : (int) $deleted,
        ]);
    }

    public static function register_exporter(array $exporters): array {
        $exporters['sabri-network-message-receipts'] = [
            'exporter_friendly_name' => __('Sabri Network message receipts', 'sabri-network'),
            'callback' => [self::class, 'export_personal_data'],
        ];
        return $exporters;
    }

    public static function register_eraser(array $erasers): array {
        $erasers['sabri-network-message-receipts'] = [
            'eraser_friendly_name' => __('Sabri Network message receipts', 'sabri-network'),
            'callback' => [self::class, 'erase_personal_data'],
        ];
        return $erasers;
    }

    public static function export_personal_data(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $per_page = 200;
        $offset = max(0, ($page - 1) * $per_page);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id,message_id,conversation_id,delivered_at,read_at,updated_at FROM ' . self::receipt_table() . ' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            (int) $user->ID,
            $per_page,
            $offset
        ));
        $data = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $data[] = [
                'group_id' => 'sabri-network-message-receipts',
                'group_label' => __('Sabri Network message receipts', 'sabri-network'),
                'item_id' => 'receipt-' . (int) $row->id,
                'data' => [
                    ['name' => __('Conversation ID', 'sabri-network'), 'value' => (int) $row->conversation_id],
                    ['name' => __('Message ID', 'sabri-network'), 'value' => (int) $row->message_id],
                    ['name' => __('Delivered at', 'sabri-network'), 'value' => (string) $row->delivered_at],
                    ['name' => __('Read at', 'sabri-network'), 'value' => (string) $row->read_at],
                    ['name' => __('Updated at', 'sabri-network'), 'value' => (string) $row->updated_at],
                ],
            ];
        }
        return ['data' => $data, 'done' => count(is_array($rows) ? $rows : []) < $per_page];
    }

    public static function erase_personal_data(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::receipt_table() . ' WHERE user_id=%d ORDER BY id ASC LIMIT 500',
            (int) $user->ID
        ));
        $ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
        if (!$ids) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::receipt_table() . " WHERE id IN ($placeholders)", ...$ids));
        return [
            'items_removed' => $deleted !== false && $deleted > 0,
            'items_retained' => false,
            'messages' => $deleted === false ? [__('Some message receipts could not be erased.', 'sabri-network')] : [],
            'done' => count($ids) < 500,
        ];
    }

    private static function retention_days(): int {
        return min(1095, max(30, (int) apply_filters('sn_network_message_receipt_retention_days', 365)));
    }

    private static function device_key(string $device_id, int $user_id): string|WP_Error {
        $device_id = trim($device_id);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $device_id)) {
            return new WP_Error('invalid_device_id', 'A valid opaque device identifier is required.', ['status' => 400]);
        }
        return hash('sha256', $user_id . ':' . $device_id);
    }

    private static function receipt_table(): string {
        return SN_DB::table('message_receipts');
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested Messages item is unavailable.', ['status' => 404]);
    }
}
