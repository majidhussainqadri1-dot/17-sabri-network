<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_3 {

    public static function get_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $row = self::draft_row((string) $request['public_id'], get_current_user_id());
        if (!$row) { return new WP_Error('draft_not_found', 'The Smail draft is unavailable.', ['status' => 404]); }
        $plain = SN_Communication_Crypto::decrypt(base64_decode((string) $row->encrypted_payload, true) ?: '', 'smail-draft|' . $row->public_id . '|' . $row->owner_id);
        if (is_wp_error($plain)) { return $plain; }
        $payload = json_decode($plain, true);
        return rest_ensure_response(['draft' => ['id' => (string) $row->public_id, 'version' => (int) $row->version, 'payload' => is_array($payload) ? $payload : [], 'updated_at' => (string) $row->updated_at]]);
    }


    public static function save_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $owner_id = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('smail_draft', (string) $owner_id, 240, HOUR_IN_SECONDS)) {
            return new WP_Error('draft_rate_limited', 'Too many draft changes were made. Try again later.', ['status' => 429]);
        }
        $public_id = sanitize_text_field((string) ($request['public_id'] ?: $request->get_param('id')));
        $row = $public_id ? self::draft_row($public_id, $owner_id) : null;
        if ($public_id && !$row) { return new WP_Error('draft_not_found', 'The Smail draft is unavailable.', ['status' => 404]); }
        $expected = absint($request->get_param('version'));
        if ($row && $expected && $expected !== (int) $row->version) { return new WP_Error('draft_conflict', 'The Smail draft changed on another device.', ['status' => 409]); }
        $payload = [
            'recipient_ids' => array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('recipient_ids'))))),
            'subject' => mb_substr(sanitize_text_field((string) $request->get_param('subject')), 0, self::MAX_SUBJECT),
            'body' => mb_substr(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))), 0, 10000),
        ];
        $json = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $public_id = $row ? (string) $row->public_id : wp_generate_uuid4();
        $cipher = SN_Communication_Crypto::encrypt($json, 'smail-draft|' . $public_id . '|' . $owner_id);
        if (is_wp_error($cipher)) { return $cipher; }
        $now = current_time('mysql', true);
        if ($row) {
            $next = (int) $row->version + 1;
            $updated = $wpdb->query($wpdb->prepare('UPDATE ' . self::drafts_table() . ' SET encrypted_payload=%s,payload_hash=%s,version=%d,updated_at=%s WHERE id=%d AND version=%d', base64_encode($cipher), hash('sha256', $json), $next, $now, (int) $row->id, (int) $row->version));
            if ($updated !== 1) { return new WP_Error('draft_conflict', 'The Smail draft changed on another device.', ['status' => 409]); }
            $version = $next;
        } else {
            if ($wpdb->insert(self::drafts_table(), ['owner_id' => $owner_id, 'public_id' => $public_id, 'encrypted_payload' => base64_encode($cipher), 'payload_hash' => hash('sha256', $json), 'version' => 1, 'created_at' => $now, 'updated_at' => $now]) === false) {
                return new WP_Error('draft_save_failed', 'The Smail draft could not be saved.', ['status' => 500]);
            }
            $version = 1;
        }
        return rest_ensure_response(['draft' => ['id' => $public_id, 'version' => $version, 'updated_at' => $now]]);
    }


    public static function delete_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::trash_draft_by_public_id((string) $request['public_id'], get_current_user_id())
            ? rest_ensure_response(['deleted' => true])
            : new WP_Error('draft_not_found', 'The Smail draft is unavailable.', ['status' => 404]);
    }


    private static function trash_draft_by_public_id(string $public_id, int $owner_id): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('UPDATE ' . self::drafts_table() . ' SET deleted_at=%s,encrypted_payload=%s,payload_hash=%s,updated_at=%s WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL', current_time('mysql', true), '', hash('sha256', ''), current_time('mysql', true), sanitize_text_field($public_id), $owner_id)) === 1;
    }


    private static function draft_row(string $public_id, int $owner_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::drafts_table() . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL', sanitize_text_field($public_id), $owner_id));
        return $row ?: null;
    }


    public static function health(): WP_REST_Response {
        global $wpdb;
        $missing = [];
        foreach ([self::messages_table(), self::states_table(), self::drafts_table()] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { $missing[] = $table; }
        }
        return rest_ensure_response(['ok' => !$missing, 'schema_version' => self::SCHEMA_VERSION, 'missing_tables' => $missing, 'mailboxes' => 7]);
    }


    public static function register_assets(): void {
        wp_register_style('sabri-smail', SN_URL . 'assets/css/smail.css', [], SN_VERSION);
        wp_register_script('sabri-smail', SN_URL . 'assets/js/smail.js', [], SN_VERSION, true);
    }


    public static function render(): string {
        self::register_assets(); wp_enqueue_style('sabri-smail'); wp_enqueue_script('sabri-smail');
        $destination = self::url();
        wp_localize_script('sabri-smail', 'SN_SMAIL_CONFIG', [
            'restUrl' => esc_url_raw(rest_url('sabri-network/v2/smail/')), 'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'ready' => SN_Policy::access() === true, 'loginUrl' => esc_url_raw((string) apply_filters('sn_network_login_url', wp_login_url($destination), $destination)),
            'messagesUrl' => esc_url_raw(SN_Messages::messages_url()), 'transferUrl' => esc_url_raw(SN_File_Transfer::url()),
        ]);
        ob_start(); include SN_DIR . 'templates/smail-app.php'; return (string) ob_get_clean();
    }


    public static function force_content(string $content): string {
        if (!in_the_loop() || !is_main_query() || get_queried_object_id() !== (int) get_option('sn_smail_page_id')) { return $content; }
        return do_shortcode('[sabri_smail]');
    }


    public static function disable_cache(): void {
        if (get_queried_object_id() !== (int) get_option('sn_smail_page_id')) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers(); header('X-Robots-Tag: noindex, noarchive', true); header('X-Content-Type-Options: nosniff', true);
    }


    public static function ensure_page(bool $repair): int {
        $id = (int) get_option('sn_smail_page_id'); $page = $id ? get_post($id) : null;
        if ($page instanceof WP_Post && (string) get_post_meta($id, self::PAGE_OWNER_META, true) === 'smail') {
            if ($repair || !has_shortcode((string) $page->post_content, 'sabri_smail')) { wp_update_post(['ID' => $id, 'post_title' => 'Smail', 'post_content' => '[sabri_smail]', 'post_status' => 'publish']); }
            return $id;
        }
        $candidate = get_page_by_path('smail', OBJECT, 'page');
        if ($candidate instanceof WP_Post && (string) get_post_meta((int) $candidate->ID, self::PAGE_OWNER_META, true) !== 'smail') { return 0; }
        $created = $candidate instanceof WP_Post ? (int) $candidate->ID : wp_insert_post(['post_title' => 'Smail', 'post_name' => 'smail', 'post_content' => '[sabri_smail]', 'post_status' => 'publish', 'post_type' => 'page', 'comment_status' => 'closed'], true);
        if (is_wp_error($created)) { return 0; }
        $id = (int) $created;
        if ($id > 0) { update_post_meta($id, self::PAGE_OWNER_META, 'smail'); update_option('sn_smail_page_id', $id, false); }
        return $id;
    }


    public static function url(): string {
        $id = (int) get_option('sn_smail_page_id'); $url = $id ? get_permalink($id) : false;
        return $url ? (string) $url : home_url('/smail/');
    }


    public static function register_exporter(array $exporters): array {
        $exporters['sabri-network-smail'] = ['exporter_friendly_name' => 'Sabri Smail', 'callback' => [self::class, 'export_personal_data']]; return $exporters;
    }

}
