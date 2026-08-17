<?php
/** Fourth fresh review-cycle cross-cutting authorization hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Review_Hardening {
    public static function register(): void {
        // SN_Runtime_Boundary_Policy runs at -30000. This second-stage gate still
        // runs before every File-17 side-effecting pre-dispatch hook (priority 3+).
        add_filter('rest_pre_dispatch', [self::class, 'authorize_before_side_effects'], -29999, 3);
    }

    public static function authorize_before_side_effects($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $result;

        if (str_starts_with($route, '/sabri-network/v2/admin/')) {
            $admin = SN_REST::admin_access();
            if (is_wp_error($admin) || $admin !== true) {
                return is_wp_error($admin) ? $admin : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
            }
        }

        $actor = get_current_user_id();
        $space = self::authorize_space_mutation($route, $request, $actor);
        if (is_wp_error($space)) return $space;
        return $result;
    }

    private static function authorize_space_mutation(string $route, WP_REST_Request $request, int $actor): bool|WP_Error {
        global $wpdb;
        if (!str_contains($route, '/spaces/') && !str_contains($route, '/space-invites/')) return true;
        if ($actor <= 0) return self::not_found();
        $spaces = SN_DB::table('spaces');
        $members = SN_DB::table('space_members');
        $invites = SN_DB::table('space_invites');

        if (preg_match('#^/sabri-network/v2/space-invites/(\d+)$#', $route, $match)) {
            $invite = $wpdb->get_row($wpdb->prepare("SELECT id,space_id,inviter_id,invitee_id,status FROM $invites WHERE id=%d", (int) $match[1]));
            if (!$invite || (string) $invite->status !== 'pending') return self::not_found();
            $decision = sanitize_key((string) $request->get_param('decision'));
            if ($decision === 'cancel') {
                if ((int) $invite->inviter_id === $actor) return true;
                $role = self::space_role((int) $invite->space_id, $actor, $members);
                return in_array($role, ['owner', 'administrator'], true) ? true : self::not_found();
            }
            return (int) $invite->invitee_id === $actor ? true : self::not_found();
        }

        if (!preg_match('#^/sabri-network/v2/spaces/(\d+)(?:/|$)#', $route, $match)) return true;
        $space_id = (int) $match[1];
        $space = $wpdb->get_row($wpdb->prepare("SELECT id,owner_user_id,visibility,state FROM $spaces WHERE id=%d", $space_id));
        if (!$space) return self::not_found();
        $role = self::space_role($space_id, $actor, $members);
        $member = $role !== '';

        if (preg_match('#^/sabri-network/v2/spaces/\d+/join$#', $route)) {
            return ($member || in_array((string) $space->visibility, ['public', 'discoverable_private'], true)) ? true : self::not_found();
        }
        if (preg_match('#^/sabri-network/v2/spaces/\d+/leave$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/community-artifacts$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/community-artifacts/\d+/respond$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:bans|community-artifacts/\d+/moderate)$#', $route)) {
            return in_array($role, ['owner', 'administrator', 'moderator'], true) ? true : self::not_found();
        }
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:join-requests/\d+|invites|members/\d+|community-settings)$#', $route)) {
            return in_array($role, ['owner', 'administrator'], true) ? true : self::not_found();
        }
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:lifecycle)$#', $route) || preg_match('#^/sabri-network/v2/spaces/\d+$#', $route)) {
            return in_array($role, ['owner', 'administrator'], true) ? true : self::not_found();
        }
        if (preg_match('#^/sabri-network/v2/spaces/\d+/transfer$#', $route)) {
            if (current_user_can('manage_options')) return true;
            return (bool) apply_filters('sn_network_can_execute_space_transfer', false, $actor, $space) ? true : self::not_found();
        }
        return true;
    }

    private static function space_role(int $space_id, int $actor, string $members): string {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1", $space_id, $actor));
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested communication object is unavailable.', ['status' => 404]);
    }
}
