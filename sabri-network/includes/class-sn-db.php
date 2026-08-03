<?php
defined('ABSPATH') || exit;

/** Canonical persistence and bounded operational helpers for File 17. */
final class SN_DB {
    public const DB_VERSION = '2.0.4';

    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'sn_' . sanitize_key($name);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_db_version', '') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = [];

        $sql[] = "CREATE TABLE " . self::table('conversations') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL DEFAULT 'direct',
            title VARCHAR(191) NOT NULL DEFAULT '',
            slug VARCHAR(191) NOT NULL DEFAULT '',
            direct_key CHAR(64) NULL DEFAULT NULL,
            owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            description TEXT NULL,
            avatar_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            privacy VARCHAR(20) NOT NULL DEFAULT 'private',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            settings LONGTEXT NULL,
            last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY direct_key (direct_key),
            KEY type_status (type,status),
            KEY owner_id (owner_id),
            KEY parent_id (parent_id),
            KEY updated_at (updated_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('members') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'member',
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_muted TINYINT(1) NOT NULL DEFAULT 0,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            joined_at DATETIME NOT NULL,
            left_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY conversation_user (conversation_id,user_id),
            KEY user_active (user_id,left_at),
            KEY conversation_active (conversation_id,left_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('messages') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message_type VARCHAR(24) NOT NULL DEFAULT 'text',
            body LONGTEXT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            attachment_source VARCHAR(20) NOT NULL DEFAULT 'none',
            reply_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
            idempotency_key CHAR(64) NULL DEFAULT NULL,
            metadata LONGTEXT NULL,
            edited_at DATETIME NULL,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY conversation_id (conversation_id,id),
            KEY sender_id (sender_id),
            KEY created_at (created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('reactions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            reaction VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_user (message_id,user_id),
            KEY message_id (message_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('contacts') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            contact_user_id BIGINT UNSIGNED NOT NULL,
            pair_key CHAR(64) NOT NULL,
            requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            alias VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pair_key (pair_key),
            KEY user_status (user_id,status),
            KEY contact_status (contact_user_id,status),
            KEY requested_by (requested_by)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('follows') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            follower_id BIGINT UNSIGNED NOT NULL,
            followed_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY follower_followed (follower_id,followed_id),
            KEY follower_status (follower_id,status,id),
            KEY followed_status (followed_id,status,id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('updates') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            body LONGTEXT NULL,
            media_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            media_source VARCHAR(20) NOT NULL DEFAULT 'none',
            media_type VARCHAR(20) NOT NULL DEFAULT 'text',
            privacy VARCHAR(20) NOT NULL DEFAULT 'contacts',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('update_views') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            update_id BIGINT UNSIGNED NOT NULL,
            viewer_id BIGINT UNSIGNED NOT NULL,
            viewed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY update_viewer (update_id,viewer_id),
            KEY viewer_id (viewer_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('calls') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            initiator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            call_type VARCHAR(10) NOT NULL DEFAULT 'audio',
            status VARCHAR(20) NOT NULL DEFAULT 'ringing',
            room_key VARCHAR(64) NOT NULL,
            active_key CHAR(64) NULL DEFAULT NULL,
            metadata LONGTEXT NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY active_key (active_key),
            KEY conversation_status (conversation_id,status),
            KEY initiator_id (initiator_id),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('call_members') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            call_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'invited',
            joined_at DATETIME NULL,
            left_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY call_user (call_id,user_id),
            KEY user_status (user_id,status)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('signals') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            call_id BIGINT UNSIGNED NOT NULL,
            from_user_id BIGINT UNSIGNED NOT NULL,
            to_user_id BIGINT UNSIGNED NOT NULL,
            signal_type VARCHAR(20) NOT NULL,
            payload LONGTEXT NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY call_to (call_id,to_user_id,consumed_at),
            KEY created_at (created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('presence') . " (
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'offline',
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            KEY status_expires (status,expires_at),
            KEY last_seen_at (last_seen_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('typing') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY conversation_user (conversation_id,user_id),
            KEY conversation_expires (conversation_id,expires_at),
            KEY expires_at (expires_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('notifications') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(191) NOT NULL,
            body TEXT NULL,
            entity_type VARCHAR(40) NOT NULL DEFAULT '',
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_read (user_id,is_read),
            KEY created_at (created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('blocks') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            blocked_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_blocked (user_id,blocked_user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('reports') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reporter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reported_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            client_uuid CHAR(36) NULL DEFAULT NULL,
            target_key CHAR(64) NOT NULL DEFAULT '',
            category VARCHAR(40) NOT NULL,
            details TEXT NULL,
            evidence LONGTEXT NULL,
            evidence_hash CHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            legal_hold TINYINT(1) NOT NULL DEFAULT 0,
            decision_reason TEXT NULL,
            decision_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            decision_at DATETIME NULL,
            appeal_status VARCHAR(20) NOT NULL DEFAULT 'none',
            appeal_reason TEXT NULL,
            appealed_at DATETIME NULL,
            appeal_decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            appeal_decision_reason TEXT NULL,
            appeal_decided_at DATETIME NULL,
            retention_until DATETIME NULL,
            anonymized_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY reporter_client (reporter_id,client_uuid),
            KEY reporter_id (reporter_id),
            KEY reported_user_id (reported_user_id),
            KEY target_created (target_key,created_at),
            KEY status_updated (status,updated_at),
            KEY appeal_queue (appeal_status,appealed_at),
            KEY retention_queue (legal_hold,anonymized_at,retention_until)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('attachments') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_id BIGINT UNSIGNED NOT NULL,
            storage_key VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(191) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL,
            scan_status VARCHAR(20) NOT NULL DEFAULT 'validated',
            created_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY storage_key (storage_key),
            KEY owner_id (owner_id),
            KEY sha256 (sha256)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('rate_limits') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bucket VARCHAR(64) NOT NULL,
            subject_hash CHAR(64) NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY bucket_subject (bucket,subject_hash),
            KEY expires_at (expires_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('audit_log') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(80) NOT NULL,
            object_type VARCHAR(40) NOT NULL DEFAULT '',
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            outcome VARCHAR(20) NOT NULL DEFAULT 'success',
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY actor_created (actor_id,created_at),
            KEY object_lookup (object_type,object_id),
            KEY action_created (action,created_at)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::migrate_contacts();
        self::backfill_direct_keys();
        self::backfill_active_call_keys();
        self::drop_legacy_otp_table();
        if (class_exists('SN_Safety')) {
            SN_Safety::migrate_reports();
        }
        update_option('sn_db_version', self::DB_VERSION, false);
    }

    public static function direct_key(int $a, int $b): string {
        $ids = [$a, $b];
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(':', $ids));
    }

    public static function contact_pair_key(int $a, int $b): string {
        return self::direct_key($a, $b);
    }

    public static function is_member(int $conversation_id, int $user_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('members') . ' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL',
            $conversation_id,
            $user_id
        ));
    }

    public static function member_role(int $conversation_id, int $user_id): string {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT role FROM ' . self::table('members') . ' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL',
            $conversation_id,
            $user_id
        ));
    }

    public static function member_preferences(int $conversation_id, int $user_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT is_muted,is_archived FROM ' . self::table('members') . ' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL',
            $conversation_id,
            $user_id
        ));
        return [
            'muted' => $row ? (bool) $row->is_muted : false,
            'archived' => $row ? (bool) $row->is_archived : false,
        ];
    }

    public static function is_conversation_muted(int $conversation_id, int $user_id): bool {
        return (bool) (self::member_preferences($conversation_id, $user_id)['muted'] ?? false);
    }

    public static function share_active_conversation(int $a, int $b): bool {
        global $wpdb;
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }
        $members = self::table('members');
        $conversations = self::table('conversations');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT m1.conversation_id FROM $members m1
             INNER JOIN $members m2 ON m2.conversation_id=m1.conversation_id AND m2.user_id=%d AND m2.left_at IS NULL
             INNER JOIN $conversations c ON c.id=m1.conversation_id AND c.status='active'
             WHERE m1.user_id=%d AND m1.left_at IS NULL LIMIT 1",
            $b,
            $a
        ));
    }

    public static function contact_record(int $a, int $b): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('contacts') . ' WHERE pair_key=%s LIMIT 1',
            self::contact_pair_key($a, $b)
        ));
        return is_object($row) ? $row : null;
    }

    public static function are_contacts(int $a, int $b): bool {
        $row = self::contact_record($a, $b);
        return $row && (string) $row->status === 'accepted';
    }

    public static function follow_record(int $follower_id, int $followed_id): ?object {
        global $wpdb;
        if ($follower_id <= 0 || $followed_id <= 0 || $follower_id === $followed_id) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('follows') . ' WHERE follower_id=%d AND followed_id=%d LIMIT 1',
            $follower_id,
            $followed_id
        ));
        return is_object($row) ? $row : null;
    }

    public static function is_following(int $follower_id, int $followed_id): bool {
        $row = self::follow_record($follower_id, $followed_id);
        return $row && (string) $row->status === 'active';
    }

    public static function follow_counts(int $user_id): array {
        global $wpdb;
        if ($user_id <= 0) {
            return ['followers' => 0, 'following' => 0];
        }
        $table = self::table('follows');
        return [
            'followers' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE followed_id=%d AND status='active'", $user_id)),
            'following' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE follower_id=%d AND status='active'", $user_id)),
        ];
    }

    public static function is_blocked(int $a, int $b): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('blocks') . ' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1',
            $a,
            $b,
            $b,
            $a
        ));
    }

    public static function add_notification(int $user_id, string $type, string $title, string $body = '', string $entity_type = '', int $entity_id = 0): void {
        $event = [
            'user_id' => $user_id,
            'type' => sanitize_key($type),
            'title' => sanitize_text_field($title),
            'body' => sanitize_text_field($body),
            'entity_type' => sanitize_key($entity_type),
            'entity_id' => $entity_id,
            'created_at' => current_time('mysql', true),
        ];
        $muted = $entity_type === 'conversation' && $entity_id > 0 && self::is_conversation_muted($entity_id, $user_id);
        $event['muted'] = $muted;
        if ((bool) apply_filters('sn_network_notification_handled', false, $event)) {
            return;
        }
        if ($muted && in_array($event['type'], ['message_received'], true)) {
            return;
        }
        global $wpdb;
        unset($event['muted']);
        $wpdb->insert(self::table('notifications'), $event + ['is_read' => 0]);
    }

    public static function consume_rate_limit(string $bucket, string $subject, int $limit, int $window_seconds): bool {
        $handled = apply_filters('sn_network_rate_limit_result', null, $bucket, $subject, $limit, $window_seconds);
        if (is_bool($handled)) {
            return $handled;
        }
        global $wpdb;
        $table = self::table('rate_limits');
        $bucket = sanitize_key($bucket);
        $limit = max(1, $limit);
        $window_seconds = max(1, $window_seconds);
        $subject_hash = hash_hmac('sha256', $subject, wp_salt('nonce'));
        $now_ts = time();
        $now = gmdate('Y-m-d H:i:s', $now_ts);
        $expires = gmdate('Y-m-d H:i:s', $now_ts + $window_seconds);

        // Create the counter once with zero hits. Concurrent creators are collapsed by
        // the unique bucket/subject key instead of replacing and resetting each other.
        $created = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (bucket,subject_hash,hits,window_started_at,expires_at) VALUES (%s,%s,0,%s,%s)",
            $bucket,
            $subject_hash,
            $now,
            $expires
        ));
        if ($created === false) {
            return false;
        }

        // One conditional UPDATE either starts a new expired window at hit 1 or
        // increments an active window below its ceiling. No read/replace race exists.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET
                hits=IF(expires_at<=%s,1,hits+1),
                window_started_at=IF(expires_at<=%s,%s,window_started_at),
                expires_at=IF(expires_at<=%s,%s,expires_at)
             WHERE bucket=%s AND subject_hash=%s AND (expires_at<=%s OR hits<%d)",
            $now,
            $now,
            $now,
            $now,
            $expires,
            $bucket,
            $subject_hash,
            $now,
            $limit
        ));
        return $updated === 1;
    }

    public static function audit(string $action, string $object_type = '', int $object_id = 0, string $outcome = 'success', array $context = [], ?int $actor_id = null): void {
        global $wpdb;
        $actor_id = $actor_id === null ? get_current_user_id() : max(0, $actor_id);
        $safe_context = [];
        foreach (array_slice($context, 0, 30, true) as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key !== '' && (is_scalar($value) || $value === null)) {
                $safe_context[$key] = is_string($value) ? mb_substr(sanitize_text_field($value), 0, 500) : $value;
            }
        }
        $wpdb->insert(self::table('audit_log'), [
            'actor_id' => $actor_id,
            'action' => sanitize_key($action),
            'object_type' => sanitize_key($object_type),
            'object_id' => $object_id,
            'outcome' => sanitize_key($outcome),
            'context' => wp_json_encode($safe_context),
            'created_at' => current_time('mysql', true),
        ]);
    }

    public static function user_can_access_attachment(int $attachment_id, int $user_id): bool {
        global $wpdb;
        if ($attachment_id <= 0 || $user_id <= 0) {
            return false;
        }
        $attachment = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (!$attachment) {
            return false;
        }
        $message_access = $wpdb->get_var($wpdb->prepare(
            'SELECT m.id FROM ' . self::table('messages') . ' m INNER JOIN ' . self::table('members') . ' cm ON cm.conversation_id=m.conversation_id AND cm.user_id=%d AND cm.left_at IS NULL WHERE m.attachment_id=%d AND m.attachment_source=%s AND m.deleted_at IS NULL LIMIT 1',
            $user_id,
            $attachment_id,
            'private'
        ));
        if ($message_access) {
            return true;
        }
        $updates = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table('updates') . ' WHERE media_id=%d AND media_source=%s AND expires_at>%s',
            $attachment_id,
            'private',
            current_time('mysql', true)
        ));
        foreach ($updates as $update) {
            if ((int) $update->user_id === $user_id) {
                return true;
            }
            if ((string) $update->privacy === 'public' && SN_Policy::has_verified_adult_age((int) $update->user_id)) {
                return true;
            }
            if ((string) $update->privacy === 'contacts' && self::are_contacts($user_id, (int) $update->user_id) && !self::is_blocked($user_id, (int) $update->user_id)) {
                return true;
            }
        }
        return false;
    }

    public static function private_attachment_is_referenced(int $attachment_id): bool {
        global $wpdb;
        if ($attachment_id <= 0) {
            return false;
        }
        $message_reference = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('messages') . ' WHERE attachment_id=%d AND attachment_source=%s AND deleted_at IS NULL LIMIT 1',
            $attachment_id,
            'private'
        ));
        if ($message_reference) {
            return true;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('updates') . ' WHERE media_id=%d AND media_source=%s LIMIT 1',
            $attachment_id,
            'private'
        ));
    }

    public static function cleanup_expired(): void {
        global $wpdb;
        $now = current_time('mysql', true);
        for ($batch = 0; $batch < 20; $batch++) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,media_id,media_source FROM ' . self::table('updates') . ' WHERE expires_at<%s ORDER BY id ASC LIMIT 500', $now));
            if (!$rows) {
                break;
            }
            $ids = [];
            $private_attachment_ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) $row->id;
                if ((string) $row->media_source === 'private' && (int) $row->media_id) {
                    $private_attachment_ids[] = (int) $row->media_id;
                }
            }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query('START TRANSACTION');
            try {
                $views_deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('update_views') . " WHERE update_id IN ($placeholders)", ...$ids));
                $updates_deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('updates') . " WHERE id IN ($placeholders)", ...$ids));
                if ($views_deleted === false || $updates_deleted === false) {
                    throw new RuntimeException('expired_update_delete_failed');
                }
                $wpdb->query('COMMIT');
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                self::audit('expired_update_cleanup_failed', 'update', 0, 'failure', ['batch' => $batch]);
                break;
            }

            // Delete bytes only after canonical update records are gone, and never while
            // another live message or update still references the same private object.
            if (class_exists('SN_Private_Files')) {
                foreach (array_values(array_unique($private_attachment_ids)) as $attachment_id) {
                    if (!self::private_attachment_is_referenced($attachment_id)) {
                        SN_Private_Files::delete($attachment_id, 0, true);
                    }
                }
            }
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('signals') . ' WHERE created_at<%s OR consumed_at IS NOT NULL AND consumed_at<%s', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('typing') . ' WHERE expires_at<%s', $now));
        $wpdb->query($wpdb->prepare("UPDATE " . self::table('presence') . " SET status='offline',updated_at=%s WHERE status<>'offline' AND expires_at<%s", $now, $now));
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::table('presence') . " WHERE status='offline' AND last_seen_at<%s", gmdate('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS)));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('rate_limits') . ' WHERE expires_at<%s', $now));
        $stale_calls = array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::table('calls') . " WHERE (status='ringing' AND created_at<%s) OR (status='active' AND COALESCE(started_at,created_at)<%s) LIMIT 500",
            gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS),
            gmdate('Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS)
        )));
        if ($stale_calls) {
            $placeholders = implode(',', array_fill(0, count($stale_calls), '%d'));
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table('calls') . " SET status='ended',active_key=NULL,ended_at=%s WHERE id IN ($placeholders)", $now, ...$stale_calls));
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table('call_members') . " SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id IN ($placeholders) AND status IN ('invited','joined')", $now, ...$stale_calls));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('signals') . " WHERE call_id IN ($placeholders)", ...$stale_calls));
        }
        if (class_exists('SN_Safety')) {
            SN_Safety::purge_expired_reports();
        }
    }

    private static function migrate_contacts(): void {
        global $wpdb;
        $table = self::table('contacts');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $rows = $wpdb->get_results("SELECT * FROM $table WHERE pair_key IS NULL OR pair_key='' ORDER BY id ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($rows as $row) {
            $pair = self::contact_pair_key((int) $row->user_id, (int) $row->contact_user_id);
            $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE pair_key=%s AND id<>%d", $pair, (int) $row->id));
            if ($existing) {
                $wpdb->delete($table, ['id' => (int) $row->id], ['%d']);
                continue;
            }
            $wpdb->update($table, [
                'pair_key' => $pair,
                'requested_by' => (int) $row->user_id,
                'updated_at' => $row->created_at ?: current_time('mysql', true),
            ], ['id' => (int) $row->id]);
        }
    }

    private static function backfill_direct_keys(): void {
        global $wpdb;
        $conversations = self::table('conversations');
        $members = self::table('members');
        $rows = $wpdb->get_results("SELECT id FROM $conversations WHERE type='direct' AND (direct_key IS NULL OR direct_key='') ORDER BY id ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($rows as $row) {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $members WHERE conversation_id=%d ORDER BY user_id ASC LIMIT 3", (int) $row->id)));
            if (count($ids) !== 2) {
                $wpdb->update($conversations, ['status' => 'archived'], ['id' => (int) $row->id]);
                continue;
            }
            $key = self::direct_key($ids[0], $ids[1]);
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $conversations WHERE direct_key=%s AND id<>%d", $key, (int) $row->id));
            if (!$exists) {
                $wpdb->update($conversations, ['direct_key' => $key], ['id' => (int) $row->id]);
            } else {
                $wpdb->update($conversations, ['status' => 'archived'], ['id' => (int) $row->id]);
            }
        }
    }


    private static function backfill_active_call_keys(): void {
        global $wpdb;
        $table = self::table('calls');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $wpdb->query("UPDATE $table SET active_key=NULL WHERE active_key IS NOT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results("SELECT id,conversation_id,status FROM $table WHERE status IN ('ringing','active') ORDER BY conversation_id ASC,id DESC"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $seen = [];
        $now = current_time('mysql', true);
        foreach ($rows as $row) {
            $conversation_id = (int) $row->conversation_id;
            if (!isset($seen[$conversation_id])) {
                $keyed = $wpdb->update($table, ['active_key' => hash('sha256', 'conversation:' . $conversation_id)], ['id' => (int) $row->id]);
                if ($keyed !== false) {
                    $seen[$conversation_id] = true;
                    continue;
                }
            }
            $call_id = (int) $row->id;
            $wpdb->update($table, ['status' => 'ended', 'active_key' => null, 'ended_at' => $now], ['id' => $call_id]);
            $wpdb->query($wpdb->prepare("UPDATE " . self::table('call_members') . " SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id=%d AND status IN ('invited','joined')", $now, $call_id));
            $wpdb->delete(self::table('signals'), ['call_id' => $call_id], ['%d']);
        }
    }

    private static function drop_legacy_otp_table(): void {
        global $wpdb;
        $legacy = $wpdb->prefix . 'sn_phone_otps';
        $wpdb->query("DROP TABLE IF EXISTS `$legacy`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
    }
}
