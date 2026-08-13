<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Advisory-lock boundary for every File-17 space/community governance mutation. */
final class SN_Space_Runtime_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'lock_mutation'], 5, 3);
        add_filter('rest_post_dispatch', [self::class, 'release_mutation'], 11, 3);
    }

    public static function lock_mutation($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET','HEAD','OPTIONS'], true)) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        global $wpdb;
        $space_id = 0; $locks = [];
        if (preg_match('#^/sabri-network/v2/spaces/(\d+)(?:/|$)#', $route, $m)) {
            $space_id = (int) $m[1];
        } elseif (preg_match('#^/sabri-network/v2/space-invites/(\d+)$#', $route, $m)) {
            $space_id = (int) $wpdb->get_var($wpdb->prepare('SELECT space_id FROM ' . SN_DB::table('space_invites') . ' WHERE id=%d', (int) $m[1]));
        }
        if ($space_id <= 0) return $result;
        $locks[] = self::space_lock($space_id);

        $actor = get_current_user_id(); $target = 0;
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:join-requests|members)/(\d+)$#', $route, $m)) $target = (int) $m[1];
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:invites|bans)$#', $route)) $target = absint($request->get_param('user_id'));
        if (preg_match('#^/sabri-network/v2/space-invites/(\d+)$#', $route, $m)) {
            $invite = $wpdb->get_row($wpdb->prepare('SELECT inviter_id,invitee_id FROM ' . SN_DB::table('space_invites') . ' WHERE id=%d', (int) $m[1]));
            if ($invite) { $a = (int) $invite->inviter_id; $b = (int) $invite->invitee_id; if ($a > 0 && $b > 0 && $a !== $b) $locks[] = SN_Relationships::pair_lock_name($a, $b); }
        } elseif ($actor > 0 && $target > 0 && $actor !== $target) {
            $locks[] = SN_Relationships::pair_lock_name($actor, $target);
        }

        $locks = array_values(array_unique($locks)); sort($locks, SORT_STRING); $held = [];
        foreach ($locks as $lock) {
            $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT));
            if ($acquired !== 1) {
                self::release($held);
                return new WP_Error('sn_space_mutation_busy', 'This space or relationship is changing. Retry the request.', ['status' => 409]);
            }
            $held[] = $lock;
        }
        $request->set_param('_sn_space_runtime_locks', $held);
        return $result;
    }

    public static function release_mutation($response, WP_REST_Server $server, WP_REST_Request $request) {
        $held = $request->get_param('_sn_space_runtime_locks');
        if (is_array($held) && $held) { self::release($held); $request->set_param('_sn_space_runtime_locks', []); }
        return $response;
    }

    public static function space_lock(int $space_id): string {
        return 'sn:f17:space:' . substr(hash('sha256', (string) $space_id), 0, 32);
    }

    private static function release(array $locks): void {
        global $wpdb;
        foreach (array_reverse($locks) as $lock) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string) $lock));
    }
}
