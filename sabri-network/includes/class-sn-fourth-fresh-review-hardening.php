<?php
/** Fourth fresh review-cycle cross-cutting authorization hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Review_Hardening {
    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'authorize_before_side_effects'], -29999, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 1990);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/owner', [
            'methods' => 'POST',
            'callback' => [self::class, 'transfer_conversation_owner'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function authorize_before_side_effects($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $result;
        if (str_starts_with($route, '/sabri-network/v2/admin/')) {
            $admin = SN_REST::admin_access();
            if (is_wp_error($admin) || $admin !== true) return is_wp_error($admin) ? $admin : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
        }
        $actor = get_current_user_id();
        $space = self::authorize_space_mutation($route, $request, $actor);
        if (is_wp_error($space)) return $space;
        return $result;
    }

    public static function transfer_conversation_owner(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        $target = absint($request->get_param('user_id'));
        $action_id = absint($request->get_param('high_risk_action_id'));
        if ($target <= 0 || $target === $actor || !get_user_by('id', $target) || SN_Policy::is_suspended($target) || !SN_Policy::has_verified_adult_age($target)) {
            return new WP_Error('owner_ineligible', 'Select an active adult conversation member.', ['status' => 403]);
        }
        $lock = 'sn:f17:conversation:' . substr(hash('sha256', (string) $conversation), 0, 32);
        $held = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, 5));
        if ($held !== 1) return new WP_Error('conversation_busy', 'This conversation is changing. Try again.', ['status' => 409]);
        try {
            $conversations = SN_DB::table('conversations');
            $members = SN_DB::table('members');
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE id=%d FOR UPDATE", $conversation));
                if (!$c || (string) $c->type === 'direct' || (string) $c->status !== 'active') throw new DomainException('invalid_conversation');
                if ((int) $c->owner_id !== $actor) throw new UnexpectedValueException('forbidden');
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $members WHERE conversation_id=%d AND user_id IN (%d,%d) FOR UPDATE", $conversation, $actor, $target));
                $map = [];
                foreach ($rows as $row) if ($row->left_at === null) $map[(int) $row->user_id] = $row;
                if (!isset($map[$actor]) || (string) $map[$actor]->role !== 'owner') throw new UnexpectedValueException('forbidden');
                if (!isset($map[$target])) throw new DomainException('member_required');
                if (SN_Policy::is_suspended($target) || !SN_Policy::has_verified_adult_age($target)) throw new UnexpectedValueException('owner_ineligible');

                $payload = ['conversation_id' => $conversation, 'current_owner_id' => $actor, 'new_owner_id' => $target];
                $claim = SN_High_Risk::claim($action_id, $actor, 'conversation_ownership_transfer', $payload);
                if (is_wp_error($claim)) { $wpdb->query('ROLLBACK'); return $claim; }
                $now = current_time('mysql', true);
                if ($wpdb->query($wpdb->prepare("UPDATE $members SET role='moderator' WHERE id=%d AND role='owner'", (int) $map[$actor]->id)) !== 1) throw new RuntimeException('old_owner_update_failed');
                if ($wpdb->query($wpdb->prepare("UPDATE $members SET role='owner' WHERE id=%d", (int) $map[$target]->id)) !== 1) throw new RuntimeException('new_owner_update_failed');
                if ($wpdb->query($wpdb->prepare("UPDATE $conversations SET owner_id=%d,updated_at=%s WHERE id=%d AND owner_id=%d", $target, $now, $conversation, $actor)) !== 1) throw new RuntimeException('conversation_owner_update_failed');
                $event = SN_Outbox::enqueue('conversation.ownership_transferred', 'conversation', $conversation, ['conversation_id' => $conversation, 'former_owner_id' => $actor, 'new_owner_id' => $target], 'conversation.ownership_transferred:' . $conversation . ':' . $target . ':' . $now);
                if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                $completed = SN_High_Risk::complete($action_id, $actor, (string) $claim['claim_token'], ['conversation_id' => $conversation, 'new_owner_id' => $target]);
                if (is_wp_error($completed)) throw new RuntimeException($completed->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('owner_commit_failed');
                SN_DB::audit('conversation_owner_transferred', 'conversation', $conversation, 'success', ['from' => $actor, 'to' => $target, 'high_risk_action_id' => $action_id], $actor);
                do_action('sn_network_event_queued', $event, 'conversation.ownership_transferred');
                $forward = new WP_REST_Request('GET', '/sabri-network/v2/conversations/' . $conversation);
                $forward->set_url_params(['id' => $conversation]);
                return SN_REST::get_conversation($forward);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                return match ($e->getMessage()) {
                    'invalid_conversation' => new WP_Error('invalid_conversation', 'Ownership cannot be transferred for this conversation.', ['status' => 400]),
                    'forbidden' => new WP_Error('forbidden', 'Only the current conversation owner may execute an approved ownership transfer.', ['status' => 403]),
                    'member_required' => new WP_Error('member_required', 'The new owner must be an active conversation member.', ['status' => 409]),
                    'owner_ineligible' => new WP_Error('owner_ineligible', 'The selected member must remain an active verified adult.', ['status' => 403]),
                    default => self::database_error(),
                };
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function authorize_space_mutation(string $route, WP_REST_Request $request, int $actor): bool|WP_Error {
        global $wpdb;
        if (!str_contains($route, '/spaces/') && !str_contains($route, '/space-invites/')) return true;
        if ($actor <= 0) return self::not_found();
        $spaces = SN_DB::table('spaces'); $members = SN_DB::table('space_members'); $invites = SN_DB::table('space_invites');
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
        $role = self::space_role($space_id, $actor, $members); $member = $role !== '';
        if (preg_match('#^/sabri-network/v2/spaces/\d+/join$#', $route)) return ($member || in_array((string) $space->visibility, ['public', 'discoverable_private'], true)) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/leave$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/community-artifacts$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/community-artifacts/\d+/respond$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:bans|community-artifacts/\d+/moderate)$#', $route)) return in_array($role, ['owner', 'administrator', 'moderator'], true) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:join-requests/\d+|invites|members/\d+|community-settings)$#', $route)) return in_array($role, ['owner', 'administrator'], true) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:lifecycle)$#', $route) || preg_match('#^/sabri-network/v2/spaces/\d+$#', $route)) return in_array($role, ['owner', 'administrator'], true) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/transfer$#', $route)) {
            if (current_user_can('manage_options')) return true;
            return (bool) apply_filters('sn_network_can_execute_space_transfer', false, $actor, $space) ? true : self::not_found();
        }
        return true;
    }
    private static function space_role(int $space_id, int $actor, string $members): string { global $wpdb; return (string) $wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1", $space_id, $actor)); }
    private static function database_error(): WP_Error { return new WP_Error('database_error', 'The Network request could not be completed safely.', ['status' => 500]); }
    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested communication object is unavailable.', ['status' => 404]); }
}
