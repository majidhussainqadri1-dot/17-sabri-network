<?php
/** Canonical runtime boundary enforcement for private storage, REST pre-dispatch and derived private-search state. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Runtime_Boundary_Policy {
    private const SEARCH_EPOCH_OPTION = 'sn_message_search_key_epoch';
    private const SEARCH_REBUILD_OPTION = 'sn_message_search_epoch_rebuilding';
    private const SEARCH_ERROR_OPTION = 'sn_message_search_epoch_error';
    private const SEARCH_CONTINUE_HOOK = 'sn_message_search_epoch_continue';

    public static function register(): void {
        add_filter('sn_network_private_storage_dir', [self::class, 'validate_private_storage_dir'], PHP_INT_MAX, 1);
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch_access_gate'], -30000, 3);
        add_filter('rest_pre_dispatch', [self::class, 'final_identity_gate'], PHP_INT_MAX, 3);
        add_action('init', [self::class, 'reconcile_search_epoch'], 11);
        add_action(self::SEARCH_CONTINUE_HOOK, [self::class, 'continue_search_rebuild']);
        add_action('sn_cleanup_hourly', [self::class, 'finish_search_rebuild'], 9999);
    }

    public static function validate_private_storage_dir(string $candidate): string {
        $candidate = trim($candidate);
        if ($candidate === '') return '';
        $resolved = self::resolve_candidate($candidate);
        if ($resolved === '') return '';
        foreach (self::public_roots() as $root) {
            if (self::same_or_within($resolved, $root) || self::same_or_within($root, $resolved)) {
                do_action('sn_network_private_storage_rejected', hash('sha256', $resolved), hash('sha256', $root));
                return '';
            }
        }
        return $candidate;
    }

    public static function pre_dispatch_access_gate($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;

        if (self::is_private_search_route($route) && (bool) get_option(self::SEARCH_REBUILD_OPTION, false)) {
            $access = SN_Policy::access();
            if (is_wp_error($access)) return $access;
            return new WP_Error('sn_message_search_rebuilding', 'Private message search is rebuilding after a key-epoch change. Retry shortly.', ['status' => 503]);
        }
        if ((string) get_option(self::SEARCH_ERROR_OPTION, '') !== '' && self::is_private_search_route($route)) {
            $access = SN_Policy::access();
            if (is_wp_error($access)) return $access;
            return new WP_Error('sn_message_search_key_epoch_failed', 'Private message search is unavailable until its derived index can be rebuilt safely.', ['status' => 503]);
        }

        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $result;
        $access = SN_Policy::access();
        if (is_wp_error($access)) return $access;

        if (str_starts_with($route, '/sabri-network/v2/admin/high-risk-actions')) {
            $admin = SN_REST::admin_access();
            if (is_wp_error($admin) || $admin !== true) {
                return is_wp_error($admin) ? $admin : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
            }
        }

        $actor = get_current_user_id();
        $conversation = self::conversation_from_route($route, $request);
        if ($conversation > 0 && !SN_DB::is_member($conversation, $actor)) {
            return new WP_Error('not_found', 'The requested communication object is unavailable.', ['status' => 404]);
        }

        // A space conversation is a projection of SN_Spaces membership/ownership.
        // It must never acquire an independent owner through the generic conversation route.
        if ($method === 'POST' && preg_match('#^/sabri-network/v2/conversations/(\d+)/owner$#', $route, $m)) {
            global $wpdb;
            $space_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . SN_DB::table('spaces') . ' WHERE conversation_id=%d LIMIT 1',
                (int) $m[1]
            ));
            if ($space_id > 0) {
                return new WP_Error('space_ownership_managed', 'Transfer ownership through the canonical File-17 space ownership workflow.', ['status' => 409]);
            }
        }
        return $result;
    }

    /** Final action-time identity check after all File-17 pre-dispatch locks and caches. */
    public static function final_identity_gate($result, WP_REST_Server $server, WP_REST_Request $request) {
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $result;

        // SN_Policy::access() clears the process-local File-00 assertion cache before
        // evaluating eligibility/suspension, so a state change during GET_LOCK or
        // idempotency processing cannot reach the mutation callback on stale truth.
        $access = SN_Policy::access();
        if (is_wp_error($access)) return $access;

        if (str_starts_with($route, '/sabri-network/v2/admin/high-risk-actions')) {
            $admin = SN_REST::admin_access();
            if (is_wp_error($admin) || $admin !== true) {
                return is_wp_error($admin) ? $admin : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
            }
        }
        return $result;
    }

    public static function reconcile_search_epoch(): void {
        if (!class_exists('SN_Message_Search') || !class_exists('SN_DB')) return;
        global $wpdb;
        $table = SN_DB::table('message_search_tokens');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
        $current = self::search_epoch();
        $stored = (string) get_option(self::SEARCH_EPOCH_OPTION, '');
        if ($stored !== $current) {
            if ($wpdb->query('TRUNCATE TABLE ' . $table) === false) {
                update_option(self::SEARCH_ERROR_OPTION, 'truncate_failed', false);
                update_option(self::SEARCH_REBUILD_OPTION, true, false);
                return;
            }
            update_option('sn_message_search_backfill_after', 0, false);
            update_option(self::SEARCH_EPOCH_OPTION, $current, false);
            update_option(self::SEARCH_REBUILD_OPTION, true, false);
            delete_option(self::SEARCH_ERROR_OPTION);
            SN_DB::audit('message_search_key_epoch_rebuild_started', 'message_search', 0, 'success', ['epoch' => substr($current, 0, 12)], 0);
            SN_Message_Search::backfill();
        }
        self::finish_search_rebuild();
    }

    public static function continue_search_rebuild(): void {
        if (!(bool) get_option(self::SEARCH_REBUILD_OPTION, false)) return;
        SN_Message_Search::backfill();
        self::finish_search_rebuild();
    }

    public static function finish_search_rebuild(): void {
        if (!(bool) get_option(self::SEARCH_REBUILD_OPTION, false) || !class_exists('SN_DB')) return;
        global $wpdb;
        $after = max(0, (int) get_option('sn_message_search_backfill_after', 0));
        $remaining = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SN_DB::table('messages') . ' WHERE id>%d LIMIT 1', $after));
        if ($remaining === 0) {
            update_option('sn_message_search_backfill_after', 0, false);
            update_option(self::SEARCH_REBUILD_OPTION, false, false);
            delete_option(self::SEARCH_ERROR_OPTION);
            SN_DB::audit('message_search_key_epoch_rebuild_completed', 'message_search', 0, 'success', ['epoch' => substr(self::search_epoch(), 0, 12)], 0);
            return;
        }
        if (!wp_next_scheduled(self::SEARCH_CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_CONTINUE_HOOK);
        }
    }

    private static function is_private_search_route(string $route): bool {
        return (bool) preg_match('#^/sabri-network/v2/conversations/\d+/search(?:/context)?$#', $route);
    }

    private static function conversation_from_route(string $route, WP_REST_Request $request): int {
        global $wpdb;
        if (preg_match('#^/sabri-network/v2/conversations/(\d+)(?:/|$)#', $route, $m)) return (int) $m[1];
        if (preg_match('#^/sabri-network/v2/future/team-inbox/(\d+)(?:/|$)#', $route, $m)) return (int) $m[1];
        if (preg_match('#^/sabri-network/v2/messages/(\d+)(?:/|$)#', $route, $m)) {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('messages') . ' WHERE id=%d', (int) $m[1]));
        }
        if (preg_match('#^/sabri-network/v2/calls/(\d+)(?:/|$)#', $route, $m)) {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', (int) $m[1]));
        }
        if ($route === '/sabri-network/v2/calls' || $route === '/sabri-network/v2/meetings') return absint($request->get_param('conversation_id'));
        if ($route === '/sabri-network/v2/future/interop') return absint($request->get_param('conversation_id'));
        return 0;
    }

    private static function search_epoch(): string {
        return hash('sha256', 'sn-message-search-v1|' . wp_salt('auth'));
    }

    private static function resolve_candidate(string $path): string {
        $real = realpath($path);
        if (is_string($real) && $real !== '') return self::normalize($real);
        $parent = realpath(dirname($path));
        if (!is_string($parent) || $parent === '') return '';
        return self::normalize($parent . DIRECTORY_SEPARATOR . basename($path));
    }

    private static function public_roots(): array {
        $roots = [ABSPATH];
        if (!function_exists('get_home_path')) {
            $file = ABSPATH . 'wp-admin/includes/file.php';
            if (is_file($file)) require_once $file;
        }
        if (function_exists('get_home_path')) $roots[] = (string) get_home_path();
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $roots[] = (string) wp_unslash($_SERVER['DOCUMENT_ROOT']);
        $roots = (array) apply_filters('sn_network_public_document_roots', $roots);
        $out = [];
        foreach ($roots as $root) {
            $real = realpath((string) $root);
            if (is_string($real) && $real !== '') $out[] = self::normalize($real);
        }
        return array_values(array_unique($out));
    }

    private static function same_or_within(string $path, string $root): bool {
        if ($path === $root) return true;
        return str_starts_with($path . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR);
    }

    private static function normalize(string $path): string {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
