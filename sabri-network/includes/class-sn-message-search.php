<?php
defined('ABSPATH') || exit;

/** Authorized hashed-token message search with signed snapshot/context cursors. */
final class SN_Message_Search {
    private const SCHEMA_VERSION = '1.0.0';
    private const MAX_QUERY_CHARS = 160;
    private const MAX_TERMS = 8;
    private const MAX_INDEX_TERMS = 128;
    private const MAX_RESULTS = 50;
    private const MAX_SCAN = 500;
    private const MAX_CONTEXT = 25;
    private const CURSOR_TTL = 900;
    private const BACKFILL_BATCH = 100;
    private const REBUILDING_OPTION = 'sn_message_search_epoch_rebuilding';
    private const REBUILD_ERROR_OPTION = 'sn_message_search_epoch_error';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes'], 30);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 35);
        add_action('sn_cleanup_hourly', [self::class, 'backfill']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_token (message_id,token_hash),
            KEY conversation_token_message (conversation_id,token_hash,message_id),
            KEY sender_message (sender_id,message_id)
        ) $charset;");
        update_option('sn_message_search_schema_version', self::SCHEMA_VERSION, false);
        add_option('sn_message_search_backfill_after', 0, '', false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_message_search_schema_version', '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function register_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/search', [
            'methods' => 'GET', 'callback' => [self::class, 'search'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/search/context', [
            'methods' => 'GET', 'callback' => [self::class, 'context'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/admin/message-search-health', [
            'methods' => 'GET', 'callback' => [self::class, 'health'], 'permission_callback' => [SN_REST::class, 'admin_access'],
        ]);
        register_rest_route('sabri-network/v2', '/admin/message-search/rebuild', [
            'methods' => 'POST', 'callback' => [self::class, 'rebuild'], 'permission_callback' => [SN_REST::class, 'admin_access'],
        ]);
    }

    public static function enqueue_assets(): void {
        if (!is_user_logged_in() || !self::is_messages_surface()) return;
        wp_enqueue_style('sn-message-search', SN_URL . 'assets/css/message-search.css', ['sabri-messages'], SN_VERSION);
        wp_enqueue_script('sn-message-search', SN_URL . 'assets/js/message-search.js', ['sabri-messages'], SN_VERSION, true);
        wp_localize_script('sn-message-search', 'SNMessageSearch', [
            'root' => esc_url_raw(rest_url('sabri-network/v2/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'maxQuery' => self::MAX_QUERY_CHARS,
        ]);
    }

    public static function index_message(int $message_id): bool|WP_Error {
        global $wpdb;
        $message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $message_id));
        if (!$message) return new WP_Error('message_not_found', 'The message is unavailable.');
        $table = self::table();
        if (!self::indexable($message)) {
            return $wpdb->delete($table, ['message_id' => $message_id], ['%d']) === false
                ? new WP_Error('search_index_delete_failed', 'The previous search index could not be removed.') : true;
        }
        $plain = SN_Message_Body::decrypt_row($message);
        if (is_wp_error($plain)) return new WP_Error('search_index_decrypt_failed', 'The private message could not be indexed safely.');
        $terms = array_slice(self::terms($plain, self::MAX_INDEX_TERMS), 0, self::MAX_INDEX_TERMS);
        $hashes = array_values(array_unique(array_map([self::class, 'token_hash'], $terms)));
        $now = current_time('mysql', true);

        // Preserve the last known-good derived index until every desired token has
        // been inserted. A transient decrypt/write failure must not erase a valid index.
        foreach ($hashes as $hash) {
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (message_id,conversation_id,sender_id,token_hash,created_at) VALUES (%d,%d,%d,%s,%s)",
                $message_id, (int) $message->conversation_id, (int) $message->sender_id, $hash, $now
            ));
            if ($ok === false) return new WP_Error('search_index_write_failed', 'The message search index could not be written.');
        }
        if (!$hashes) {
            if ($wpdb->delete($table, ['message_id' => $message_id], ['%d']) === false) return new WP_Error('search_index_delete_failed', 'The previous search index could not be removed.');
            return true;
        }
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $params = array_merge([$message_id], $hashes);
        $sql = $wpdb->prepare("DELETE FROM $table WHERE message_id=%d AND token_hash NOT IN ($placeholders)", ...$params);
        if ($wpdb->query($sql) === false) return new WP_Error('search_index_reconcile_failed', 'The previous message search tokens could not be reconciled safely.');
        return true;
    }

    public static function remove_message(int $message_id): bool|WP_Error {
        global $wpdb;
        return $wpdb->delete(self::table(), ['message_id' => $message_id], ['%d']) === false
            ? new WP_Error('search_index_delete_failed', 'The message search index could not be removed.') : true;
    }

    public static function search(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $viewer_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $viewer_id)) return self::not_found();
        if (!SN_Policy::consume_rate_limit('message_search', (string) $viewer_id, 120, MINUTE_IN_SECONDS)) return new WP_Error('rate_limited', 'Too many search requests.', ['status' => 429]);

        $query = trim(sanitize_text_field(wp_unslash((string) $request->get_param('q'))));
        if (mb_strlen($query) < 2 || mb_strlen($query) > self::MAX_QUERY_CHARS) return new WP_Error('invalid_search_query', 'Enter between 2 and 160 characters.', ['status' => 400]);
        $terms = array_slice(self::terms($query, self::MAX_TERMS), 0, self::MAX_TERMS);
        if (!$terms) return new WP_Error('invalid_search_query', 'The search query has no searchable terms.', ['status' => 400]);

        $filters = self::filters($request);
        if (is_wp_error($filters)) return $filters;
        $filter_hash = hash('sha256', wp_json_encode([$conversation_id, array_map([self::class, 'token_hash'], $terms), $filters]));
        $limit = min(self::MAX_RESULTS, max(1, absint($request->get_param('limit')) ?: 25));
        $snapshot = 0;
        $before = PHP_INT_MAX;
        $cursor = trim((string) $request->get_param('cursor'));
        if ($cursor !== '') {
            $state = self::decode_cursor($cursor, 'search', $viewer_id, $conversation_id, $filter_hash);
            if (is_wp_error($state)) return $state;
            $snapshot = (int) $state['snapshot'];
            $before = (int) $state['before'];
        } else {
            $snapshot_raw = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id),0) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));
            if ($wpdb->last_error !== '') return new WP_Error('search_snapshot_unavailable', 'Message search snapshot could not be read safely.', ['status'=>503]);
            $snapshot = (int) $snapshot_raw;
        }
        if ($snapshot <= 0) return rest_ensure_response(['results' => [], 'next_cursor' => null, 'snapshot' => 0]);

        $hashes = array_values(array_unique(array_map([self::class, 'token_hash'], $terms)));
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $messages = SN_DB::table('messages');
        $tokens = self::table();
        $params = array_merge($hashes, [$conversation_id, $snapshot, $before]);
        $where = "t.token_hash IN ($placeholders) AND m.conversation_id=%d AND m.id<=%d AND m.id<%d AND m.deleted_at IS NULL";
        if ($filters['sender_id'] > 0) { $where .= ' AND m.sender_id=%d'; $params[] = $filters['sender_id']; }
        if ($filters['message_type'] !== '') { $where .= ' AND m.message_type=%s'; $params[] = $filters['message_type']; }
        if ($filters['from'] !== '') { $where .= ' AND m.created_at>=%s'; $params[] = $filters['from']; }
        if ($filters['to'] !== '') { $where .= ' AND m.created_at<=%s'; $params[] = $filters['to']; }
        $params[] = count($hashes);
        $params[] = min(self::MAX_SCAN, $limit + 1);
        $sql = $wpdb->prepare(
            "SELECT m.* FROM $messages m INNER JOIN $tokens t ON t.message_id=m.id WHERE $where GROUP BY m.id HAVING COUNT(DISTINCT t.token_hash)>=%d ORDER BY m.id DESC LIMIT %d",
            ...$params
        );
        $rows = $wpdb->get_results($sql);
        if (!is_array($rows)) return new WP_Error('search_unavailable', 'Message search is temporarily unavailable.', ['status' => 500]);
        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);
        $page_tail = $rows ? (int) end($rows)->id : 0;
        $rows = array_values(array_filter($rows, static fn(object $row): bool => self::indexable($row) && !SN_Message_Operations::is_hidden($viewer_id, (int) $row->id)));
        $items = array_map(fn(object $row): array => self::format_message($row, $viewer_id, $snapshot), $rows);
        $next = $has_more && $page_tail > 0 ? self::encode_cursor('search', [
            'viewer' => $viewer_id, 'conversation' => $conversation_id, 'filter' => $filter_hash,
            'snapshot' => $snapshot, 'before' => $page_tail,
        ]) : null;
        SN_DB::audit('message_search_executed', 'conversation', $conversation_id, 'success', [
            'filter_hash' => $filter_hash, 'term_count' => count($hashes), 'result_count' => count($items), 'snapshot' => $snapshot,
        ], $viewer_id);
        return rest_ensure_response(['results' => $items, 'next_cursor' => $next, 'snapshot' => $snapshot]);
    }

    public static function context(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $viewer_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $viewer_id)) return self::not_found();
        if (!SN_Policy::consume_rate_limit('message_search_context', (string) $viewer_id, 120, MINUTE_IN_SECONDS)) return new WP_Error('rate_limited', 'Too many context requests.', ['status' => 429]);
        $cursor = trim((string) $request->get_param('cursor'));
        $state = self::decode_cursor($cursor, 'context', $viewer_id, $conversation_id, '');
        if (is_wp_error($state)) return $state;
        $target_id = (int) $state['target'];
        $snapshot = (int) $state['snapshot'];
        $messages = SN_DB::table('messages');
        $target = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d AND conversation_id=%d AND id<=%d AND deleted_at IS NULL", $target_id, $conversation_id, $snapshot));
        if ($wpdb->last_error !== '') return new WP_Error('search_context_unavailable', 'Message search context could not be read safely.', ['status'=>503]);
        if (!$target || !self::indexable($target) || SN_Message_Operations::is_hidden($viewer_id, $target_id)) return self::not_found();
        $before_rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $messages WHERE conversation_id=%d AND id<%d AND id<=%d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT));
        if (!is_array($before_rows) || $wpdb->last_error !== '') return new WP_Error('search_context_unavailable', 'Message search context could not be read safely.', ['status'=>503]);
        $before = array_reverse($before_rows);
        $after = $wpdb->get_results($wpdb->prepare("SELECT * FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT));
        if (!is_array($after) || $wpdb->last_error !== '') return new WP_Error('search_context_unavailable', 'Message search context could not be read safely.', ['status'=>503]);
        $rows = array_values(array_filter(array_merge($before, [$target], $after), static fn(object $row): bool => self::indexable($row) && !SN_Message_Operations::is_hidden($viewer_id, (int) $row->id)));
        return rest_ensure_response(['target_id' => $target_id, 'snapshot' => $snapshot, 'messages' => array_map(fn(object $row): array => self::format_message($row, $viewer_id, $snapshot), $rows)]);
    }

    public static function context_cursor(int $viewer_id, int $conversation_id, int $target_id, int $snapshot): string {
        return self::encode_cursor('context', ['viewer' => $viewer_id, 'conversation' => $conversation_id, 'target' => $target_id, 'snapshot' => $snapshot]);
    }

    public static function backfill(): bool|WP_Error {
        global $wpdb;
        $after = max(0, (int) get_option('sn_message_search_backfill_after', 0));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id>%d ORDER BY id ASC LIMIT %d', $after, self::BACKFILL_BATCH));
        if (!is_array($rows)) return self::backfill_failure(new WP_Error('search_backfill_query_failed', 'The message search backfill could not read its next batch.'));
        if (!$rows) { update_option('sn_message_search_backfill_after', 0, false); return true; }
        foreach ($rows as $row) {
            $indexed = self::index_message((int) $row->id);
            if (is_wp_error($indexed)) return self::backfill_failure($indexed, (int) $row->id);
            $after = (int) $row->id;
        }
        update_option('sn_message_search_backfill_after', $after, false);
        return true;
    }

    private static function backfill_failure(WP_Error $error, int $message_id = 0): WP_Error {
        if ((bool) get_option(self::REBUILDING_OPTION, false)) update_option(self::REBUILD_ERROR_OPTION, $error->get_error_code(), false);
        if (class_exists('SN_DB')) SN_DB::audit('message_search_backfill_failed', 'message_search', $message_id, 'failure', ['reason' => $error->get_error_code(), 'cursor' => (int) get_option('sn_message_search_backfill_after', 0)], 0);
        return $error;
    }

    public static function cleanup(): void {
        global $wpdb;
        $tokens = self::table(); $messages = SN_DB::table('messages');
        $ids = $wpdb->get_col("SELECT t.id FROM $tokens t LEFT JOIN $messages m ON m.id=t.message_id WHERE m.id IS NULL OR m.deleted_at IS NOT NULL ORDER BY t.id ASC LIMIT 1000");
        if (!is_array($ids) || $wpdb->last_error !== '') {
            if (class_exists('SN_DB')) SN_DB::audit('message_search_cleanup_read_failed', 'message_search', 0, 'failure', ['reason'=>(string)$wpdb->last_error], 0);
            return;
        }
        if ($ids && $wpdb->query('DELETE FROM ' . $tokens . ' WHERE id IN (' . implode(',', array_map('absint', $ids)) . ')') === false) {
            if (class_exists('SN_DB')) SN_DB::audit('message_search_cleanup_delete_failed', 'message_search', 0, 'failure', ['reason'=>(string)$wpdb->last_error], 0);
        }
    }

    public static function health(): WP_REST_Response {
        global $wpdb;
        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        $rebuilding = (bool) get_option(self::REBUILDING_OPTION, false);
        $error = (string) get_option(self::REBUILD_ERROR_OPTION, '');
        return rest_ensure_response(['ok' => $exists && !$rebuilding && $error === '' && (string) get_option('sn_message_search_schema_version', '') === self::SCHEMA_VERSION, 'table' => $exists, 'schema_version' => (string) get_option('sn_message_search_schema_version', ''), 'tokens' => $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") : 0, 'backfill_after' => (int) get_option('sn_message_search_backfill_after', 0), 'rebuilding' => $rebuilding, 'error' => $error, 'time' => gmdate('c')]);
    }

    public static function rebuild(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        if ($request->get_param('confirm') !== true) return new WP_Error('confirmation_required', 'Exact boolean confirmation is required.', ['status' => 400]);
        if (!SN_Policy::consume_rate_limit('message_search_rebuild', (string) get_current_user_id(), 3, DAY_IN_SECONDS)) return new WP_Error('rate_limited', 'Too many rebuild requests.', ['status' => 429]);
        if ($wpdb->query('TRUNCATE TABLE ' . self::table()) === false) return new WP_Error('search_rebuild_failed', 'The search index could not be reset.', ['status' => 500]);
        update_option('sn_message_search_backfill_after', 0, false);
        update_option(self::REBUILDING_OPTION, true, false);
        delete_option(self::REBUILD_ERROR_OPTION);
        SN_DB::audit('message_search_rebuild_started', 'message_search', 0, 'success', [], get_current_user_id());
        $backfill = self::backfill();
        if (is_wp_error($backfill)) {
            update_option(self::REBUILD_ERROR_OPTION, $backfill->get_error_code(), false);
            SN_DB::audit('message_search_rebuild_failed', 'message_search', 0, 'failure', ['reason' => $backfill->get_error_code()], get_current_user_id());
            return new WP_Error('search_rebuild_backfill_failed', 'The search index rebuild stopped safely and will remain unavailable until it can be retried.', ['status' => 503]);
        }
        if (class_exists('SN_Runtime_Boundary_Policy')) SN_Runtime_Boundary_Policy::finish_search_rebuild();
        return rest_ensure_response(['rebuild_started' => true, 'backfill_after' => (int) get_option('sn_message_search_backfill_after', 0), 'rebuilding' => (bool) get_option(self::REBUILDING_OPTION, false)]);
    }

    private static function filters(WP_REST_Request $request): array|WP_Error {
        $type = sanitize_key((string) $request->get_param('type'));
        if ($type !== '' && !in_array($type, ['text','image','video','audio','document'], true)) return new WP_Error('invalid_message_type', 'The message type filter is invalid.', ['status' => 400]);
        $from = self::date_bound((string) $request->get_param('from'), false);
        $to = self::date_bound((string) $request->get_param('to'), true);
        if (is_wp_error($from)) return $from;
        if (is_wp_error($to)) return $to;
        if ($from !== '' && $to !== '' && strcmp($from, $to) > 0) return new WP_Error('invalid_date_range', 'The date range is inverted.', ['status' => 400]);
        return ['sender_id' => absint($request->get_param('sender_id')), 'message_type' => $type, 'from' => $from, 'to' => $to];
    }

    private static function date_bound(string $value, bool $end): string|WP_Error {
        $value = trim($value);
        if ($value === '') return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) return new WP_Error('invalid_date', 'Use a valid YYYY-MM-DD date.', ['status' => 400]);
        return $date->setTime($end ? 23 : 0, $end ? 59 : 0, $end ? 59 : 0)->format('Y-m-d H:i:s');
    }

    private static function terms(string $text, int $limit): array {
        $text = mb_strtolower(wp_strip_all_tags($text), 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $part = mb_substr($part, 0, 64, 'UTF-8');
            if (mb_strlen($part, 'UTF-8') < 2) continue;
            $terms[$part] = true;
            if (count($terms) >= $limit) break;
        }
        return array_keys($terms);
    }

    private static function token_hash(string $term): string { return hash_hmac('sha256', $term, wp_salt('auth') . '|sn-message-search-v1'); }

    private static function indexable(object $message): bool {
        if (!empty($message->deleted_at)) return false;
        $metadata = json_decode((string) ($message->metadata ?? ''), true);
        $state = is_array($metadata) ? sanitize_key((string) ($metadata['state'] ?? 'sent')) : 'sent';
        return !in_array($state, ['quarantined','moderation_removed','removed','unsent','expired','rejected'], true);
    }

    private static function format_message(object $row, int $viewer_id, int $snapshot): array {
        $sender = (int) $row->sender_id ? SN_Auth::public_user((int) $row->sender_id) : [];
        if (!$sender) $sender = ['id' => 0, 'name' => 'Unavailable account', 'avatar' => SN_URL . 'assets/network-default-avatar.svg'];
        $attachment = null;
        if ((int) $row->attachment_id > 0 && (string) $row->attachment_source === 'private') $attachment = SN_Private_Files::formatted((int) $row->attachment_id, $viewer_id);
        $plain = SN_Message_Body::decrypt_row($row);
        $unavailable = is_wp_error($plain);
        return [
            'id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id, 'sender' => $sender,
            'message_type' => (string) $row->message_type, 'body' => $unavailable ? '' : (string) $plain, 'body_unavailable' => $unavailable, 'attachment' => $attachment,
            'reply_to' => (int) $row->reply_to, 'edited' => (bool) $row->edited_at, 'deleted' => false,
            'created_at' => (string) $row->created_at,
            'context_cursor' => self::context_cursor($viewer_id, (int) $row->conversation_id, (int) $row->id, $snapshot),
        ];
    }

    private static function encode_cursor(string $purpose, array $payload): string {
        $payload['purpose'] = $purpose; $payload['exp'] = time() + self::CURSOR_TTL; $payload['v'] = 1;
        $json = (string) wp_json_encode($payload);
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, wp_salt('nonce') . '|sn-search-cursor-v1');
    }

    private static function decode_cursor(string $cursor, string $purpose, int $viewer, int $conversation, string $filter): array|WP_Error {
        if ($cursor === '' || strlen($cursor) > 2048 || !str_contains($cursor, '.')) return new WP_Error('invalid_search_cursor', 'The search cursor is invalid.', ['status' => 400]);
        [$body, $sig] = explode('.', $cursor, 2);
        $expected = hash_hmac('sha256', $body, wp_salt('nonce') . '|sn-search-cursor-v1');
        if (!hash_equals($expected, $sig)) return new WP_Error('invalid_search_cursor', 'The search cursor signature is invalid.', ['status' => 400]);
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['purpose'] ?? '') !== $purpose || (int) ($data['viewer'] ?? 0) !== $viewer || (int) ($data['conversation'] ?? 0) !== $conversation || (int) ($data['exp'] ?? 0) < time()) return new WP_Error('expired_search_cursor', 'The search cursor expired or does not belong to this request.', ['status' => 409]);
        if ($filter !== '' && !hash_equals($filter, (string) ($data['filter'] ?? ''))) return new WP_Error('search_cursor_scope_mismatch', 'The search cursor does not match these filters.', ['status' => 409]);
        return $data;
    }

    private static function is_messages_surface(): bool {
        if ((int) get_query_var('sn_messages_app') === 1) return true;
        foreach (['sn_messages_page_id','sn_communication_settings_page_id'] as $key) {
            $id = (int) get_option($key, 0);
            if ($id > 0 && is_page($id)) return true;
        }
        return false;
    }

    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested conversation or message is unavailable.', ['status' => 404]); }
    private static function table(): string { return SN_DB::table('message_search_tokens'); }
}