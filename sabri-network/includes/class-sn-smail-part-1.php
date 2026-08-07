<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_1 {

    public static function register(): void {
        add_action('init', [self::class, 'init'], 6);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 5);
        add_action('template_redirect', [self::class, 'disable_cache'], 0);
        add_shortcode('sabri_smail', [self::class, 'render']);
        add_filter('the_content', [self::class, 'force_content'], 9997);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }


    public static function init(): void {
        self::maybe_upgrade();
        if (!(int) get_option('sn_smail_page_id')) {
            self::ensure_page(false);
        }
        do_action('sn_network_route_registered', [
            'key' => 'smail', 'label' => 'Smail', 'url' => self::url(), 'owner' => 'file-17', 'version' => SN_VERSION,
        ]);
        do_action('sn_network_smail_contract_registered', [
            'owner' => 'file-17', 'version' => '1.0.0',
            'mailboxes' => ['inbox', 'sent', 'drafts', 'starred', 'archive', 'spam', 'trash'],
            'message_truth' => 'sn_messages', 'notification_transport' => 'file-19',
            'rest_base' => rest_url('sabri-network/v2/smail/'), 'url' => self::url(),
        ]);
    }


    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $messages = self::messages_table();
        $states = self::states_table();
        $drafts = self::drafts_table();
        dbDelta("CREATE TABLE $messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(200) NOT NULL DEFAULT '',
            client_key CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_id (message_id),
            UNIQUE KEY client_key (client_key),
            KEY conversation_created (conversation_id,created_at),
            KEY sender_created (sender_id,created_at)
        ) $charset;");
        dbDelta("CREATE TABLE $states (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            smail_message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            is_starred TINYINT(1) NOT NULL DEFAULT 0,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            is_spam TINYINT(1) NOT NULL DEFAULT 0,
            trashed_at DATETIME NULL,
            read_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_user (smail_message_id,user_id),
            KEY user_mailbox (user_id,is_archived,is_spam,trashed_at),
            KEY user_starred (user_id,is_starred)
        ) $charset;");
        dbDelta("CREATE TABLE $drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_id BIGINT UNSIGNED NOT NULL,
            public_id CHAR(36) NOT NULL,
            encrypted_payload LONGTEXT NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            KEY owner_active (owner_id,deleted_at,updated_at)
        ) $charset;");
        update_option('sn_smail_schema_version', self::SCHEMA_VERSION, false);
    }


    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_smail_schema_version', '') !== self::SCHEMA_VERSION) {
            self::install();
        }
    }


    public static function register_routes(): void {
        register_rest_route('sabri-network/v2', '/smail/mailbox', [
            'methods' => 'GET', 'callback' => [self::class, 'mailbox'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/smail/send', [
            'methods' => 'POST', 'callback' => [self::class, 'send'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/smail/messages/(?P<id>\d+)/state', [
            'methods' => 'POST', 'callback' => [self::class, 'update_state'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/smail/drafts', [
            ['methods' => 'GET', 'callback' => [self::class, 'list_drafts'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'save_draft'], 'permission_callback' => [SN_REST::class, 'access']],
        ]);
        register_rest_route('sabri-network/v2', '/smail/drafts/(?P<public_id>[a-f0-9-]{36})', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_draft'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'save_draft'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_draft'], 'permission_callback' => [SN_REST::class, 'access']],
        ]);
        register_rest_route('sabri-network/v2', '/smail/health', [
            'methods' => 'GET', 'callback' => [self::class, 'health'], 'permission_callback' => [SN_REST::class, 'admin_access'],
        ]);
    }


    public static function mailbox(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $box = sanitize_key((string) $request->get_param('box')) ?: 'inbox';
        if ($box === 'drafts') {
            return self::list_drafts($request);
        }
        if (!in_array($box, ['inbox', 'sent', 'starred', 'archive', 'spam', 'trash'], true)) {
            return new WP_Error('invalid_mailbox', 'Select a valid Smail mailbox.', ['status' => 400]);
        }
        $limit = min(100, max(1, absint($request->get_param('limit')) ?: 30));
        $before = absint($request->get_param('before'));
        $sm = self::messages_table(); $st = self::states_table();
        $m = SN_DB::table('messages'); $cm = SN_DB::table('members');
        $where = ['cm.user_id=%d', 'cm.left_at IS NULL', 'msg.deleted_at IS NULL'];
        $args = [$user_id];
        if ($before) { $where[] = 'sm.id<%d'; $args[] = $before; }
        if ($box === 'sent') { $where[] = 'sm.sender_id=%d'; $args[] = $user_id; $where[] = 'state.trashed_at IS NULL'; }
        elseif ($box === 'starred') { $where[] = 'state.is_starred=1'; $where[] = 'state.trashed_at IS NULL'; }
        elseif ($box === 'archive') { $where[] = 'state.is_archived=1'; $where[] = 'state.trashed_at IS NULL'; }
        elseif ($box === 'spam') { $where[] = 'state.is_spam=1'; $where[] = 'state.trashed_at IS NULL'; }
        elseif ($box === 'trash') { $where[] = 'state.trashed_at IS NOT NULL'; }
        else { $where[] = 'sm.sender_id<>%d'; $args[] = $user_id; $where[] = 'state.is_archived=0'; $where[] = 'state.is_spam=0'; $where[] = 'state.trashed_at IS NULL'; }
        $args[] = $limit;
        $sql = "SELECT sm.*,msg.body,msg.message_type,msg.created_at message_created,state.is_starred,state.is_archived,state.is_spam,state.trashed_at,state.read_at
            FROM $sm sm INNER JOIN $m msg ON msg.id=sm.message_id
            INNER JOIN $cm cm ON cm.conversation_id=sm.conversation_id
            INNER JOIN $st state ON state.smail_message_id=sm.id AND state.user_id=cm.user_id
            WHERE " . implode(' AND ', $where) . " ORDER BY sm.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        $items = array_map(static function ($row) use ($user_id): array {
            return [
                'id' => (int) $row->id, 'message_id' => (int) $row->message_id,
                'conversation_id' => (int) $row->conversation_id, 'subject' => (string) $row->subject,
                'body' => (string) $row->body, 'sender' => SN_Auth::public_user((int) $row->sender_id),
                'is_sent' => (int) $row->sender_id === $user_id, 'starred' => (bool) $row->is_starred,
                'archived' => (bool) $row->is_archived, 'spam' => (bool) $row->is_spam,
                'trashed' => (bool) $row->trashed_at, 'read' => (bool) $row->read_at,
                'created_at' => (string) $row->message_created,
            ];
        }, $rows ?: []);
        return rest_ensure_response(['box' => $box, 'messages' => $items, 'next_before' => $items ? (int) end($items)['id'] : 0]);
    }

}
