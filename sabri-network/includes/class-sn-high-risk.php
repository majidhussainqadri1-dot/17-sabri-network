<?php
/** Step-up and dual-control governance for high-risk File-17 changes. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_High_Risk {
    private const SCHEMA_VERSION = '1.0.0';
    private const GRANT_TTL = 10 * MINUTE_IN_SECONDS;
    private const ACTION_TTL = DAY_IN_SECONDS;
    private const EXECUTION_STALE_SECONDS = 10 * MINUTE_IN_SECONDS;
    private const MAX_PAYLOAD_BYTES = 8192;
    private const TYPES = [
        'space_ownership_transfer', 'conversation_ownership_transfer', 'space_emergency_recovery', 'space_destructive_purge',
        'legal_hold_release', 'provider_configuration', 'provider_key_rotation',
        'mass_moderation', 'retention_override',
    ];

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $grants = self::grants_table();
        $actions = self::actions_table();
        dbDelta("CREATE TABLE $grants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            grant_uuid CHAR(36) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            purpose VARCHAR(80) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY grant_uuid (grant_uuid),
            UNIQUE KEY token_hash (token_hash),
            KEY user_status (user_id,status,expires_at)
        ) $charset;");
        dbDelta("CREATE TABLE $actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_uuid CHAR(36) NOT NULL,
            action_type VARCHAR(80) NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            approver_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            executor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payload_json LONGTEXT NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            result_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'requested',
            reason VARCHAR(500) NOT NULL DEFAULT '',
            step_up_grant_id BIGINT UNSIGNED NOT NULL,
            claim_token_hash CHAR(64) NULL,
            expires_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            executing_at DATETIME NULL,
            executed_at DATETIME NULL,
            released_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY action_uuid (action_uuid),
            KEY status_expiry (status,expires_at),
            KEY requester_status (requester_id,status),
            KEY executor_status (executor_id,status)
        ) $charset;");
        update_option('sn_high_risk_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_high_risk_schema_version', '') !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    public static function register_routes(): void {
        register_rest_route('sabri-network/v2', '/security/step-up', [
            'methods' => 'POST', 'callback' => [self::class, 'issue_step_up'],
            'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/admin/high-risk-actions', [
            ['methods' => 'GET', 'callback' => [self::class, 'list_actions'], 'permission_callback' => [SN_REST::class, 'admin_access']],
            ['methods' => 'POST', 'callback' => [self::class, 'request_action'], 'permission_callback' => [SN_REST::class, 'admin_access']],
        ]);
        register_rest_route('sabri-network/v2', '/admin/high-risk-actions/(?P<id>\d+)/decision', [
            'methods' => 'POST', 'callback' => [self::class, 'decide_action'],
            'permission_callback' => [SN_REST::class, 'admin_access'],
        ]);
    }

    public static function issue_step_up(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $purpose = sanitize_key((string) $request->get_param('purpose'));
        if (!in_array($purpose, self::TYPES, true)) {
            return self::error('sn_step_up_purpose_invalid', 'Select an approved high-risk purpose.', 400);
        }
        $verified = apply_filters('sn_network_step_up_verified', false, $user_id, $purpose, $request);
        if ($verified !== true) {
            return self::error('sn_step_up_required', 'Recent strong authentication is required.', 403);
        }
        if (!SN_Policy::consume_rate_limit('high_risk_step_up', (string) $user_id, 5, HOUR_IN_SECONDS)) {
            return self::error('sn_step_up_rate_limited', 'Too many step-up requests.', 429);
        }
        $raw = wp_generate_uuid4() . '.' . wp_generate_password(48, false, false);
        $hash = hash_hmac('sha256', $raw, wp_salt('auth'));
        $now = self::now();
        $expires = gmdate('Y-m-d H:i:s', time() + self::GRANT_TTL);
        $ok = $wpdb->insert(self::grants_table(), [
            'grant_uuid' => wp_generate_uuid4(), 'user_id' => $user_id, 'purpose' => $purpose,
            'token_hash' => $hash, 'status' => 'active', 'expires_at' => $expires,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($ok === false) return self::error('sn_step_up_create_failed', 'The step-up grant could not be created.', 500);
        SN_DB::audit('high_risk_step_up_issued', 'high_risk_grant', (int) $wpdb->insert_id, 'success', ['purpose' => $purpose, 'expires_at' => $expires], $user_id);
        return new WP_REST_Response(['step_up_token' => $raw, 'purpose' => $purpose, 'expires_at' => $expires], 201);
    }

    public static function request_action(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $requester = get_current_user_id();
        $type = sanitize_key((string) $request->get_param('action_type'));
        if (!in_array($type, self::TYPES, true)) return self::error('sn_high_risk_type_invalid', 'The high-risk action type is not allowed.', 400);
        $payload = $request->get_param('payload');
        $payload = is_array($payload) ? self::sanitize_payload($payload) : [];
        $encoded = self::canonical_json($payload);
        if ($encoded === '' || strlen($encoded) > self::MAX_PAYLOAD_BYTES) return self::error('sn_high_risk_payload_invalid', 'The action scope is invalid or too large.', 400);
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $grant = self::consume_grant((string) $request->get_param('step_up_token'), $requester, $type);
            if (is_wp_error($grant)) {
                $wpdb->query('ROLLBACK');
                return $grant;
            }
            $now = self::now();
            $expires = gmdate('Y-m-d H:i:s', time() + self::ACTION_TTL);
            $ok = $wpdb->insert(self::actions_table(), [
                'action_uuid' => wp_generate_uuid4(), 'action_type' => $type, 'requester_id' => $requester,
                'payload_json' => $encoded, 'payload_hash' => hash('sha256', $encoded),
                'status' => 'requested', 'reason' => self::bounded_text((string) $request->get_param('reason'), 500),
                'step_up_grant_id' => (int) $grant->id, 'expires_at' => $expires,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($ok === false) throw new RuntimeException('high_risk_action_insert_failed');
            $id = (int) $wpdb->insert_id;
            SN_DB::audit('high_risk_action_requested', 'high_risk_action', $id, 'success', ['action_type' => $type, 'payload_hash' => hash('sha256', $encoded)], $requester);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('high_risk_action_commit_failed');
            return new WP_REST_Response(['id' => $id, 'status' => 'requested', 'version' => 1, 'expires_at' => $expires], 201);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('high_risk_action_request_failed', 'high_risk_action', 0, 'failure', ['action_type' => $type, 'reason' => $e->getMessage()], $requester);
            return self::error('sn_high_risk_request_failed', 'The high-risk request could not be stored atomically.', 500);
        }
    }

    public static function decide_action(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $approver = get_current_user_id();
        $decision = sanitize_key((string) $request->get_param('decision'));
        if (!in_array($decision, ['approve', 'reject'], true)) return self::error('sn_high_risk_decision_invalid', 'Select approve or reject.', 400);
        $row = self::action($id);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('high_risk_decision_read_failed');
        if (!$row) return self::error('sn_high_risk_not_found', 'The action is unavailable.', 404);
        if ((int) $row->requester_id === $approver) return self::error('sn_high_risk_separation_required', 'The requester cannot approve this action.', 409);
        if ((string) $row->status !== 'requested' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_high_risk_not_pending', 'The action is no longer awaiting approval.', 409);
        $expected = absint($request->get_param('version'));
        if ($expected !== (int) $row->version) return self::error('sn_high_risk_version_conflict', 'The action changed. Reload and retry.', 409);
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $now = self::now();
        $data = ['status' => $status, 'approver_id' => $approver, 'updated_at' => $now, 'version' => $expected + 1];
        if ($status === 'approved') $data['approved_at'] = $now;
        $changed = $wpdb->update(self::actions_table(), $data, ['id' => $id, 'status' => 'requested', 'version' => $expected]);
        if ($changed === false) return self::storage_error('high_risk_decision_write_failed');
        if ($changed !== 1) return self::error('sn_high_risk_decision_conflict', 'A concurrent decision was detected.', 409);
        SN_DB::audit('high_risk_action_' . $status, 'high_risk_action', $id, 'success', ['action_type' => (string) $row->action_type], $approver);
        return rest_ensure_response(['id' => $id, 'status' => $status, 'version' => $expected + 1]);
    }

    /** Caller owns the surrounding transaction. */
    public static function claim(int $action_id, int $executor_id, string $type, array $payload): array|WP_Error {
        global $wpdb;
        $row = self::action($action_id, true);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('high_risk_claim_read_failed');
        if (!$row || (string) $row->action_type !== $type) return self::error('sn_high_risk_scope_mismatch', 'The approved action does not match this operation.', 403);
        if ((string) $row->status !== 'approved' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_high_risk_not_approved', 'A current approved action is required.', 403);
        if (in_array($executor_id, [(int) $row->requester_id, (int) $row->approver_id], true)) return self::error('sn_high_risk_executor_separation', 'A distinct executor is required.', 409);
        $encoded = self::canonical_json(self::sanitize_payload($payload));
        if ($encoded === '' || !hash_equals((string) $row->payload_hash, hash('sha256', $encoded))) return self::error('sn_high_risk_payload_mismatch', 'The approved scope does not match this operation.', 409);
        $raw_claim = wp_generate_uuid4() . '.' . wp_generate_password(32, false, false);
        $claim_hash = hash_hmac('sha256', $raw_claim, wp_salt('auth'));
        $now = self::now();
        $changed = $wpdb->update(self::actions_table(), [
            'status' => 'executing', 'executor_id' => $executor_id, 'claim_token_hash' => $claim_hash,
            'executing_at' => $now, 'updated_at' => $now, 'version' => (int) $row->version + 1,
        ], ['id' => $action_id, 'status' => 'approved', 'version' => (int) $row->version]);
        if ($changed === false) return self::storage_error('high_risk_claim_write_failed');
        if ($changed !== 1) return self::error('sn_high_risk_claim_conflict', 'The approved action was claimed concurrently.', 409);
        return ['action_id' => $action_id, 'claim_token' => $raw_claim, 'version' => (int) $row->version + 1];
    }

    /** Caller owns the surrounding transaction. */
    public static function complete(int $action_id, int $executor_id, string $claim_token, array $result = [], string $final_status = 'executed'): bool|WP_Error {
        global $wpdb;
        if (!in_array($final_status, ['executed', 'released'], true)) return self::error('sn_high_risk_final_state_invalid', 'The completion state is invalid.', 400);
        $row = self::action($action_id, true);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('high_risk_completion_read_failed');
        if (!$row || (string) $row->status !== 'executing' || (int) $row->executor_id !== $executor_id) return self::error('sn_high_risk_execution_lost', 'The action execution claim is unavailable.', 409);
        $hash = hash_hmac('sha256', $claim_token, wp_salt('auth'));
        if (!(string) $row->claim_token_hash || !hash_equals((string) $row->claim_token_hash, $hash)) return self::error('sn_high_risk_claim_invalid', 'The action execution claim is invalid.', 403);
        $encoded = self::canonical_json(self::sanitize_payload($result));
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) return self::error('sn_high_risk_result_too_large', 'The completion evidence is too large.', 400);
        $now = self::now();
        $data = ['status' => $final_status, 'result_json' => $encoded, 'claim_token_hash' => null, 'updated_at' => $now, 'version' => (int) $row->version + 1];
        $data[$final_status === 'released' ? 'released_at' : 'executed_at'] = $now;
        $changed = $wpdb->update(self::actions_table(), $data, ['id' => $action_id, 'status' => 'executing', 'version' => (int) $row->version]);
        if ($changed === false) return self::storage_error('high_risk_completion_write_failed');
        return $changed === 1 ? true : self::error('sn_high_risk_completion_conflict', 'The action completion conflicted with another update.', 409);
    }

    public static function list_actions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $status = sanitize_key((string) $request->get_param('status'));
        $limit = max(1, min(100, absint($request->get_param('limit')) ?: 50));
        $where = $status !== '' ? $wpdb->prepare(' WHERE status=%s', $status) : '';
        $rows = $wpdb->get_results("SELECT id,action_uuid,action_type,requester_id,approver_id,executor_id,payload_hash,status,reason,expires_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM " . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
        if (($wpdb->last_error ?? '') !== '' || !is_array($rows)) return self::storage_error('high_risk_list_read_failed');
        return rest_ensure_response(['items' => $rows]);
    }

    public static function cleanup(): void {
        global $wpdb;
        $now = self::now();
        $stale = gmdate('Y-m-d H:i:s', time() - self::EXECUTION_STALE_SECONDS);
        $expired_grants=$wpdb->query($wpdb->prepare("UPDATE " . self::grants_table() . " SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at<=%s LIMIT 500", $now, $now));
        if($expired_grants===false)SN_DB::audit('high_risk_cleanup_grants_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
        $expired_actions=$wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='expired',updated_at=%s,version=version+1 WHERE status IN ('requested','approved') AND expires_at<=%s LIMIT 500", $now, $now));
        if($expired_actions===false)SN_DB::audit('high_risk_cleanup_actions_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
        $recovered=$wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='approved',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND expires_at>%s LIMIT 100", $now, $stale, $now));
        if($recovered===false)SN_DB::audit('high_risk_cleanup_recovery_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
    }

    private static function consume_grant(string $raw, int $user_id, string $purpose): stdClass|WP_Error {
        global $wpdb;
        $raw = trim(wp_unslash($raw));
        if (strlen($raw) < 40 || strlen($raw) > 160) return self::error('sn_step_up_token_invalid', 'A valid one-time step-up token is required.', 403);
        $hash = hash_hmac('sha256', $raw, wp_salt('auth'));
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::grants_table() . ' WHERE token_hash=%s AND user_id=%d AND purpose=%s LIMIT 1 FOR UPDATE', $hash, $user_id, $purpose));
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('step_up_grant_read_failed');
        if (!$row || (string) $row->status !== 'active' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_step_up_token_expired', 'The step-up token is invalid or expired.', 403);
        $now = self::now();
        $changed = $wpdb->update(self::grants_table(), ['status' => 'consumed', 'consumed_at' => $now, 'updated_at' => $now, 'version' => (int) $row->version + 1], ['id' => (int) $row->id, 'status' => 'active', 'version' => (int) $row->version]);
        if ($changed === false) return self::storage_error('step_up_grant_write_failed');
        return $changed === 1 ? $row : self::error('sn_step_up_token_replayed', 'The one-time step-up token was already used.', 409);
    }

    private static function action(int $id, bool $lock = false): ?object {
        global $wpdb;
        $sql = $wpdb->prepare('SELECT * FROM ' . self::actions_table() . ' WHERE id=%d' . ($lock ? ' FOR UPDATE' : ''), $id);
        return $wpdb->get_row($sql) ?: null;
    }

    private static function sanitize_payload(array $payload): array {
        $blocked = ['password','secret','token','credential','authorization','cookie','ice','sdp','candidate','message_body','body','content','storage_path'];
        $clean = [];
        foreach (array_slice($payload, 0, 50, true) as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || in_array($key, $blocked, true)) continue;
            if (is_array($value)) $clean[$key] = self::sanitize_payload($value);
            elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) $clean[$key] = $value;
            else $clean[$key] = self::bounded_text((string) $value, 500);
        }
        ksort($clean);
        return $clean;
    }

    private static function canonical_json(array $payload): string {
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    private static function bounded_text(string $value, int $max): string { return mb_substr(sanitize_textarea_field(wp_unslash($value)), 0, $max); }
    private static function now(): string { return current_time('mysql', true); }
    private static function grants_table(): string { return SN_DB::table('step_up_grants'); }
    private static function actions_table(): string { return SN_DB::table('high_risk_actions'); }
    private static function storage_error(string $reason): WP_Error { SN_DB::audit($reason,'high_risk',0,'failure',[],get_current_user_id()); return self::error('sn_high_risk_storage_unavailable','High-risk governance state could not be verified safely. Retry later.',503); }
    private static function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, ['status' => $status]); }
}
