<?php
/**
 * Cross-cutting contract firewall for File-17 completion routes.
 *
 * This is not a parallel backend. It enforces canonical invariants before the
 * 2.1.0 completion/Future-24 handlers run: caller-supplied idempotency on
 * state-changing routes, and metadata-only discovery for private spaces.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Two_Plan_Contract_Firewall {
    private const SCHEMA_VERSION = '1.0.0';
    private const CACHE_TTL = 7 * DAY_IN_SECONDS;

    /** @var array<string,bool> */
    private const MUTATING_ROUTE_PATTERNS = [
        '#^/sabri-network/v2/message-requests$#' => true,
        '#^/sabri-network/v2/message-requests/\d+$#' => true,
        '#^/sabri-network/v2/messages/\d+/forward$#' => true,
        '#^/sabri-network/v2/conversations/\d+/scheduled-messages$#' => true,
        '#^/sabri-network/v2/scheduled-messages/\d+$#' => true,
        '#^/sabri-network/v2/conversations/\d+/polls$#' => true,
        '#^/sabri-network/v2/messages/\d+/poll-vote$#' => true,
        '#^/sabri-network/v2/conversations/\d+/checklists$#' => true,
        '#^/sabri-network/v2/messages/\d+/checklist-items/\d+$#' => true,
        '#^/sabri-network/v2/messages/\d+/expiry$#' => true,
        '#^/sabri-network/v2/conversations/\d+/voice-notes$#' => true,
        '#^/sabri-network/v2/updates$#' => true,
        '#^/sabri-network/v2/updates/\d+/view$#' => true,
        '#^/sabri-network/v2/spaces/\d+/community-settings$#' => true,
        '#^/sabri-network/v2/spaces/\d+/community-artifacts$#' => true,
        '#^/sabri-network/v2/spaces/\d+/community-artifacts/\d+/respond$#' => true,
        '#^/sabri-network/v2/spaces/\d+/community-artifacts/\d+/moderate$#' => true,

        // Founder-approved Future Communication Superset — every user-driven
        // state mutation below reuses the same canonical encrypted idempotency ledger.
        '#^/sabri-network/v2/future/e2ee-policy$#' => true,
        '#^/sabri-network/v2/future/device-keys$#' => true,
        '#^/sabri-network/v2/future/device-keys/[A-Za-z0-9._:-]+$#' => true,
        '#^/sabri-network/v2/future/conversation-locks/\d+$#' => true,
        '#^/sabri-network/v2/future/team-inbox/\d+$#' => true,
        '#^/sabri-network/v2/future/team-inbox/\d+/handoff$#' => true,
        '#^/sabri-network/v2/future/team-inbox/\d+/notes$#' => true,
        '#^/sabri-network/v2/future/reminders$#' => true,
        '#^/sabri-network/v2/future/reminders/\d+$#' => true,
        '#^/sabri-network/v2/future/templates$#' => true,
        '#^/sabri-network/v2/future/templates/\d+$#' => true,
        '#^/sabri-network/v2/future/conversations/bulk$#' => true,
        '#^/sabri-network/v2/future/conversations/bulk/\d+$#' => true,
        '#^/sabri-network/v2/future/smart-views$#' => true,
        '#^/sabri-network/v2/future/community-invites$#' => true,
        '#^/sabri-network/v2/future/community-invites/redeem$#' => true,
        '#^/sabri-network/v2/future/community-invites/\d+/revoke$#' => true,
        '#^/sabri-network/v2/future/temporary-memberships$#' => true,
        '#^/sabri-network/v2/future/mentorships$#' => true,
        '#^/sabri-network/v2/future/mentorships/\d+$#' => true,
        '#^/sabri-network/v2/future/mentorships/\d+/end$#' => true,
        '#^/sabri-network/v2/future/citations$#' => true,
        '#^/sabri-network/v2/future/case-discussions$#' => true,
        '#^/sabri-network/v2/calls/\d+/lobby$#' => true,
        '#^/sabri-network/v2/calls/\d+/hand-raise$#' => true,
        '#^/sabri-network/v2/calls/\d+/speaker-queue$#' => true,
        '#^/sabri-network/v2/calls/\d+/breakouts$#' => true,
        '#^/sabri-network/v2/calls/\d+/breakouts/move$#' => true,
        '#^/sabri-network/v2/calls/\d+/breakouts/close$#' => true,
        '#^/sabri-network/v2/calls/\d+/cohosts$#' => true,
        '#^/sabri-network/v2/calls/\d+/host-transfer$#' => true,
        '#^/sabri-network/v2/calls/\d+/host-transfer/confirm$#' => true,
        '#^/sabri-network/v2/calls/\d+/host-takeover$#' => true,
        '#^/sabri-network/v2/calls/\d+/network-quality$#' => true,
        '#^/sabri-network/v2/future/ai-assistant$#' => true,
        '#^/sabri-network/v2/future/semantic-search$#' => true,
        '#^/sabri-network/v2/future/semantic-search/consent$#' => true,
        '#^/sabri-network/v2/future/interop$#' => true,
        '#^/sabri-network/v2/future/interop/\d+$#' => true,
        '#^/sabri-network/v2/future/interop/\d+/outbound$#' => true,
        '#^/sabri-network/v2/future/records/\d+$#' => true,
    ];

    public static function register(): void {
        add_action('init', [self::class, 'maybe_upgrade'], 24);
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 8, 3);
        add_filter('rest_post_dispatch', [self::class, 'post_dispatch'], 1200, 3);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_two_plan_firewall_schema_version', '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE ".self::table()." (
            scope_key CHAR(64) NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            method VARCHAR(10) NOT NULL,
            route_hash CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'processing',
            response_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_cipher LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (scope_key),
            KEY actor_state (actor_id,state,updated_at),
            KEY updated_at (updated_at)
        ) $charset;");
        update_option('sn_two_plan_firewall_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        if (!self::requires_idempotency($request)) return null;
        $actor = get_current_user_id();
        if ($actor <= 0) return null;

        // rest_pre_dispatch runs before the route permission callback. Never create
        // an idempotency reservation for a suspended/denied identity merely because
        // WordPress has a logged-in session.
        $access = SN_Policy::access();
        if (is_wp_error($access)) return $access;
        if ($access !== true) return new WP_Error('network_access_denied', 'Network access is not permitted for this account.', ['status' => 403]);

        $raw_key = trim((string) $request->get_header('Idempotency-Key'));
        if ($raw_key === '') $raw_key = trim((string) $request->get_param('client_id'));
        $raw_key = strtolower($raw_key);
        if ($raw_key === '') return new WP_Error('sn_idempotency_key_required', 'A caller-supplied Idempotency-Key is required for this mutation.', ['status' => 400]);
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $raw_key)) return new WP_Error('sn_idempotency_key_invalid', 'Idempotency-Key must be 8–64 safe characters.', ['status' => 400]);

        $request->set_param('client_id', $raw_key);
        $route = $request->get_route();
        $request_hash = self::request_hash($request);
        $scope_key = hash('sha256', $actor.'|'.$request->get_method().'|'.$route.'|'.$raw_key);
        $route_hash = hash('sha256', $request->get_method().'|'.$route);
        $now = current_time('mysql', true);

        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE scope_key=%s', $scope_key));
        if ($existing) return self::existing_result($existing, $request_hash, $scope_key);

        $inserted = $wpdb->insert(self::table(), [
            'scope_key' => $scope_key,
            'actor_id' => $actor,
            'method' => $request->get_method(),
            'route_hash' => $route_hash,
            'request_hash' => $request_hash,
            'state' => 'processing',
            'response_code' => 0,
            'response_cipher' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false) {
            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE scope_key=%s', $scope_key));
            return $race ? self::existing_result($race, $request_hash, $scope_key) : new WP_Error('sn_idempotency_reservation_failed', 'The mutation could not reserve an idempotency record.', ['status' => 503]);
        }

        $request->set_param('_sn_two_plan_scope_key', $scope_key);
        $request->set_param('_sn_two_plan_request_hash', $request_hash);
        return null;
    }

    public static function post_dispatch($response, WP_REST_Server $server, WP_REST_Request $request) {
        $response = self::minimize_discoverable_private($response, $request);
        $scope_key = (string) $request->get_param('_sn_two_plan_scope_key');
        if ($scope_key === '') return $response;

        global $wpdb;
        $code = self::response_code($response);
        if ($code >= 200 && $code < 300) {
            $data = self::response_data($response);
            $json = wp_json_encode($data);
            if (!is_string($json)) {
                $wpdb->delete(self::table(), ['scope_key' => $scope_key], ['%s']);
                return $response;
            }
            $cipher = SN_Communication_Crypto::encrypt($json, 'two-plan-idempotency|'.$scope_key);
            if (is_wp_error($cipher)) {
                SN_DB::audit('idempotency_response_cache_failed', 'two_plan_idempotency', 0, 'failure', ['scope_hash' => hash('sha256', $scope_key)], get_current_user_id());
                return $response;
            }
            $wpdb->update(self::table(), [
                'state' => 'complete',
                'response_code' => $code,
                'response_cipher' => $cipher,
                'updated_at' => current_time('mysql', true),
            ], ['scope_key' => $scope_key]);
        } else {
            $wpdb->delete(self::table(), ['scope_key' => $scope_key], ['%s']);
        }
        return $response;
    }

    private static function existing_result(object $existing, string $request_hash, string $scope_key) {
        if (!hash_equals((string) $existing->request_hash, $request_hash)) return new WP_Error('sn_idempotency_key_reused', 'The same Idempotency-Key cannot be reused with a different request.', ['status' => 409]);
        if ((string) $existing->state !== 'complete') return new WP_Error('sn_idempotency_in_progress', 'A request with this Idempotency-Key is already processing or requires reconciliation.', ['status' => 409]);
        $plain = SN_Communication_Crypto::decrypt((string) $existing->response_cipher, 'two-plan-idempotency|'.$scope_key);
        if (is_wp_error($plain)) return new WP_Error('sn_idempotency_replay_unavailable', 'The prior result cannot be replayed safely.', ['status' => 503]);
        $data = json_decode((string) $plain, true);
        if (!is_array($data)) return new WP_Error('sn_idempotency_replay_invalid', 'The prior result cannot be replayed safely.', ['status' => 503]);
        return new WP_REST_Response($data, max(200, min(299, (int) $existing->response_code)));
    }

    private static function minimize_discoverable_private($response, WP_REST_Request $request) {
        if ($request->get_method() !== 'GET' || !preg_match('#^/sabri-network/v2/spaces/(\d+)/community-artifacts$#', $request->get_route(), $match)) return $response;
        $space_id = (int) $match[1];
        $viewer = get_current_user_id();
        global $wpdb;
        $space = $wpdb->get_row($wpdb->prepare('SELECT id,visibility FROM '.SN_DB::table('spaces').' WHERE id=%d', $space_id));
        if (!$space || (string) $space->visibility !== 'discoverable_private') return $response;
        $member = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1", $space_id, $viewer));
        if ($member > 0) return $response;

        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as &$item) {
                    if (!is_array($item)) continue;
                    $item['body'] = '';
                    $item['body_withheld'] = true;
                }
                unset($item);
                $response->set_data($data);
            }
        }
        return $response;
    }

    private static function requires_idempotency(WP_REST_Request $request): bool {
        if (!in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return false;
        foreach (array_keys(self::MUTATING_ROUTE_PATTERNS) as $pattern) if (preg_match($pattern, $request->get_route())) return true;
        return false;
    }

    private static function request_hash(WP_REST_Request $request): string {
        $params = $request->get_params();
        unset($params['client_id'], $params['_sn_two_plan_scope_key'], $params['_sn_two_plan_request_hash']);
        $files = [];
        foreach ($request->get_file_params() as $key => $file) {
            if (!is_array($file)) continue;
            $files[$key] = [
                'name' => sanitize_file_name((string) ($file['name'] ?? '')),
                'size' => (int) ($file['size'] ?? 0),
                'type' => sanitize_text_field((string) ($file['type'] ?? '')),
                'error' => (int) ($file['error'] ?? 0),
                'sha256' => (string) (($params['_sn_uploaded_file_hashes'][$key] ?? '')),
            ];
        }
        unset($params['_sn_uploaded_file_hashes']);
        self::ksort_recursive($params);
        self::ksort_recursive($files);
        return hash('sha256', (string) wp_json_encode(['params' => $params, 'files' => $files]));
    }

    private static function ksort_recursive(array &$value): void {
        ksort($value);
        foreach ($value as &$item) if (is_array($item)) self::ksort_recursive($item);
        unset($item);
    }

    private static function response_code($response): int {
        if ($response instanceof WP_REST_Response) return (int) $response->get_status();
        if ($response instanceof WP_Error) {
            $data = $response->get_error_data();
            return is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        }
        return 200;
    }

    private static function response_data($response) {
        if ($response instanceof WP_REST_Response) return $response->get_data();
        if ($response instanceof WP_Error) return ['code' => $response->get_error_code(), 'message' => $response->get_error_message()];
        return $response;
    }

    public static function cleanup(): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::CACHE_TTL);
        $wpdb->query($wpdb->prepare("DELETE FROM ".self::table()." WHERE state='complete' AND updated_at<%s LIMIT 500", $cutoff));
    }

    public static function register_eraser(array $erasers): array {
        $erasers['sabri-network-two-plan-idempotency'] = [
            'eraser_friendly_name' => 'Sabri communication request cache',
            'callback' => [self::class, 'privacy_erase'],
        ];
        return $erasers;
    }

    public static function privacy_erase(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        $removed = false;
        if ($page === 1) {
            $count = $wpdb->delete(self::table(), ['actor_id' => (int) $user->ID], ['%d']);
            $removed = is_int($count) && $count > 0;
        }
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sn_two_plan_idempotency';
    }
}
