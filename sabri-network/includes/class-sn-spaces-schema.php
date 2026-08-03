<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Schema {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $spaces = self::spaces_table();
        $members = self::members_table();
        $invites = self::invites_table();
        $requests = self::requests_table();
        $bans = self::bans_table();
        $audit = self::audit_table();
        dbDelta("CREATE TABLE $spaces (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(24) NOT NULL,
            subtype VARCHAR(40) NOT NULL DEFAULT '',
            slug VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            rules TEXT NULL,
            language VARCHAR(20) NOT NULL DEFAULT 'en-US',
            region VARCHAR(80) NOT NULL DEFAULT '',
            categories LONGTEXT NULL,
            visibility VARCHAR(24) NOT NULL DEFAULT 'invite_only',
            state VARCHAR(24) NOT NULL DEFAULT 'active',
            join_policy VARCHAR(20) NOT NULL DEFAULT 'request',
            posting_policy VARCHAR(20) NOT NULL DEFAULT 'members',
            history_policy VARCHAR(20) NOT NULL DEFAULT 'from_join',
            member_limit INT UNSIGNED NOT NULL DEFAULT 500,
            slow_mode_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            new_member_delay_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            invite_pause_until DATETIME NULL,
            anti_raid_until DATETIME NULL,
            media_pause_until DATETIME NULL,
            call_pause_until DATETIME NULL,
            locked_reason VARCHAR(500) NOT NULL DEFAULT '',
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            archived_at DATETIME NULL,
            closed_at DATETIME NULL,
            deletion_requested_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY slug (slug),
            KEY parent_type (parent_id,type),
            KEY discover (state,visibility,type,updated_at),
            KEY owner_user_id (owner_user_id),
            KEY conversation_id (conversation_id)
        ) $charset;");
        dbDelta("CREATE TABLE $members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            space_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(24) NOT NULL DEFAULT 'member',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            approved_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            joined_at DATETIME NOT NULL,
            left_at DATETIME NULL,
            last_post_at DATETIME NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY space_user (space_id,user_id),
            KEY user_active (user_id,status,space_id),
            KEY space_role (space_id,status,role)
        ) $charset;");
        dbDelta("CREATE TABLE $invites (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invite_uuid CHAR(36) NOT NULL,
            space_id BIGINT UNSIGNED NOT NULL,
            inviter_id BIGINT UNSIGNED NOT NULL,
            invitee_id BIGINT UNSIGNED NOT NULL,
            active_key CHAR(64) NULL DEFAULT NULL,
            role VARCHAR(24) NOT NULL DEFAULT 'member',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY invite_uuid (invite_uuid),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY active_key (active_key),
            KEY invitee_queue (invitee_id,status,expires_at),
            KEY space_queue (space_id,status,created_at)
        ) $charset;");
        dbDelta("CREATE TABLE $requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_uuid CHAR(36) NOT NULL,
            space_id BIGINT UNSIGNED NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            active_key CHAR(64) NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reason VARCHAR(500) NOT NULL DEFAULT '',
            decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            decided_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY request_uuid (request_uuid),
            UNIQUE KEY active_key (active_key),
            KEY space_queue (space_id,status,created_at)
        ) $charset;");
        dbDelta("CREATE TABLE $bans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            space_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            reason VARCHAR(500) NOT NULL DEFAULT '',
            banned_by BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY space_user (space_id,user_id),
            KEY active_expiry (status,expires_at)
        ) $charset;");
        dbDelta("CREATE TABLE $audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            space_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(40) NOT NULL DEFAULT '',
            target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            scope_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY space_created (space_id,created_at),
            KEY actor_created (actor_id,created_at)
        ) $charset;");
        update_option('sn_spaces_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_spaces_schema_version', '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function register_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/spaces', [
            ['methods'=>'GET','callback'=>[self::class,'list_spaces'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'create_space'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)', [
            ['methods'=>'GET','callback'=>[self::class,'get_space'],'permission_callback'=>$access],
            ['methods'=>'PATCH','callback'=>[self::class,'update_space'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/join', ['methods'=>'POST','callback'=>[self::class,'join_space'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/leave', ['methods'=>'POST','callback'=>[self::class,'leave_space'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/join-requests/(?P<user_id>\d+)', ['methods'=>'POST','callback'=>[self::class,'decide_join_request'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/invites', ['methods'=>'POST','callback'=>[self::class,'create_invite'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/space-invites/(?P<id>\d+)', ['methods'=>'POST','callback'=>[self::class,'decide_invite'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/members/(?P<user_id>\d+)', ['methods'=>'PATCH','callback'=>[self::class,'change_member'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/bans', ['methods'=>'POST','callback'=>[self::class,'change_ban'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/lifecycle', ['methods'=>'POST','callback'=>[self::class,'change_lifecycle'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/transfer', ['methods'=>'POST','callback'=>[self::class,'transfer_owner'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/governance', ['methods'=>'GET','callback'=>[self::class,'governance_log'],'permission_callback'=>$access]);
    }
}
