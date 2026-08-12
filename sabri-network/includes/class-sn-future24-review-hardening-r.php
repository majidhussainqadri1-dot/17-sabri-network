<?php
/** Review round 45 — temporary membership revoke/renew and promotion-safe expiry. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_R {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes'], 2110);
        // Run before Future-Superset cleanup so a later permanent promotion is not expired by an old temporary lease.
        add_action('sn_cleanup_hourly', [self::class, 'preflight_expiry'], -1);
    }

    public static function routes(): void {
        register_rest_route('sabri-network/v2', '/future/temporary-memberships/(?P<id>\d+)/renew', [
            'methods' => 'POST', 'callback' => [self::class, 'renew'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
        register_rest_route('sabri-network/v2', '/future/temporary-memberships/(?P<id>\d+)/revoke', [
            'methods' => 'POST', 'callback' => [self::class, 'revoke'], 'permission_callback' => [SN_REST::class, 'access'],
        ]);
    }

    public static function renew(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $id = absint($request['id']);
        $row = self::record($id);
        if (!$row) return self::not_found();
        $space = (int) $row->scope_id;
        if (!self::space_manager($space, $actor)) return self::error('forbidden', 'Space management permission is required.', 403);
        $data = self::decode($row);
        if (is_wp_error($data)) return $data;
        $user = absint($data['user_id'] ?? $row->owner_id);
        if ($user <= 0 || SN_Policy::is_suspended($user) || SN_DB::is_blocked($actor, $user)) return self::error('sn_temp_member_no_longer_eligible', 'Temporary membership is no longer eligible for renewal.', 409);
        if (SN_Policy::age_state($user) !== 'adult'
            && !(bool) apply_filters('sn_network_guardian_communication_approved', false, $user, $actor, 'temporary_space_membership_renew')) {
            return self::error('sn_guardian_approval_required', 'Current guardian approval is required.', 403);
        }
        if (!(bool) apply_filters('sn_network_space_temporary_membership_allowed', true, $space, $user, $actor)) return self::error('sn_temp_member_policy_denied', 'Temporary membership is not permitted by current space policy.', 403);

        $member = self::space_member($space, $user);
        if (!self::matches_temporary_lease($member, $data)) return self::error('sn_temp_member_promoted', 'The account no longer has the original temporary membership lease.', 409);

        $until = self::future_time((string) $request->get_param('expires_at'), 90 * DAY_IN_SECONDS);
        if (is_wp_error($until)) return $until;
        $expected = absint($request->get_param('expected_version'));
        if ($expected > 0 && $expected !== (int) $row->version) return self::error('sn_temp_member_stale', 'Temporary membership changed before renewal.', 409);
        $data['renewed_by'] = $actor;
        $data['renewed_at'] = current_time('mysql', true);
        $cipher = self::encode($row, $data);
        if (is_wp_error($cipher)) return $cipher;
        $ok = $wpdb->update($wpdb->prefix . 'sn_future_records', [
            'payload_cipher' => $cipher, 'expires_at' => $until, 'updated_at' => current_time('mysql', true), 'version' => (int) $row->version + 1,
        ], ['id' => $id, 'state' => 'active', 'version' => (int) $row->version]);
        if ($ok !== 1) return self::error('sn_temp_member_stale', 'Temporary membership changed before renewal.', 409);
        SN_DB::audit('future_temporary_membership_renewed', 'space', $space, 'success', ['record_id' => $id, 'user_id' => $user, 'expires_at' => $until], $actor);
        return rest_ensure_response(['id' => $id, 'state' => 'active', 'expires_at' => $until, 'version' => (int) $row->version + 1]);
    }

    public static function revoke(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $id = absint($request['id']);
        $wpdb->query('START TRANSACTION');
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-13' AND state='active' LIMIT 1 FOR UPDATE", $id));
            if (!$row) { $wpdb->query('ROLLBACK'); return self::not_found(); }
            $space = (int) $row->scope_id;
            if (!self::space_manager($space, $actor)) { $wpdb->query('ROLLBACK'); return self::error('forbidden', 'Space management permission is required.', 403); }
            $data = self::decode($row);
            if (is_wp_error($data)) throw new RuntimeException('decode');
            $user = absint($data['user_id'] ?? $row->owner_id);
            $member = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('space_members') . ' WHERE space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE', $space, $user));
            $removed = false;
            if (self::matches_temporary_lease($member, $data)) {
                $now = current_time('mysql', true);
                $ok = $wpdb->update(SN_DB::table('space_members'), ['status' => 'revoked', 'left_at' => $now, 'updated_at' => $now, 'version' => (int) $member->version + 1], ['id' => (int) $member->id, 'version' => (int) $member->version]);
                if ($ok !== 1) throw new RuntimeException('member_conflict');
                $conversation = (int) $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('spaces') . ' WHERE id=%d', $space));
                if ($conversation > 0) {
                    $wpdb->update(SN_DB::table('members'), ['left_at' => $now], ['conversation_id' => $conversation, 'user_id' => $user, 'left_at' => null]);
                }
                $removed = true;
            }
            $state = $removed ? 'revoked' : 'superseded';
            $ok = $wpdb->update($wpdb->prefix . 'sn_future_records', ['state' => $state, 'updated_at' => current_time('mysql', true), 'version' => (int) $row->version + 1], ['id' => $id, 'state' => 'active', 'version' => (int) $row->version]);
            if ($ok !== 1) throw new RuntimeException('record_conflict');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('commit');
            SN_DB::audit('future_temporary_membership_revoked', 'space', $space, 'success', ['record_id' => $id, 'user_id' => $user, 'membership_removed' => $removed, 'state' => $state], $actor);
            return rest_ensure_response(['id' => $id, 'state' => $state, 'membership_removed' => $removed]);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return self::error('sn_temp_member_revoke_failed', 'Temporary membership could not be revoked safely.', 409);
        }
    }

    public static function preflight_expiry(): void {
        global $wpdb;
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE feature_id='F17-FUT-13' AND state='active' AND expires_at IS NOT NULL AND expires_at<=%s ORDER BY id ASC LIMIT 300", $now));
        foreach (is_array($rows) ? $rows : [] as $row) {
            $data = self::decode($row);
            if (is_wp_error($data)) continue;
            $space = (int) $row->scope_id;
            $user = absint($data['user_id'] ?? $row->owner_id);
            $member = self::space_member($space, $user);
            if (!$member || self::matches_temporary_lease($member, $data)) continue;
            // Membership truth changed since grant (for example promotion to a permanent role).
            // Retire only the temporary lease record; never evict the newer canonical membership.
            $ok = $wpdb->update($wpdb->prefix . 'sn_future_records', ['state' => 'superseded', 'updated_at' => $now, 'version' => (int) $row->version + 1], ['id' => (int) $row->id, 'state' => 'active', 'version' => (int) $row->version]);
            if ($ok === 1) SN_DB::audit('future_temporary_membership_superseded', 'space', $space, 'success', ['record_id' => (int) $row->id, 'user_id' => $user], 0);
        }
    }

    private static function record(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-13' AND state='active' LIMIT 1", $id));
        return is_object($row) ? $row : null;
    }
    private static function space_member(int $space, int $user): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('space_members') . ' WHERE space_id=%d AND user_id=%d LIMIT 1', $space, $user));
        return is_object($row) ? $row : null;
    }
    private static function matches_temporary_lease(?object $member, array $data): bool {
        if (!$member || (string) $member->status !== 'active' || !empty($member->left_at)) return false;
        $role = sanitize_key((string) ($data['role'] ?? 'observer'));
        $grantor = absint($data['granted_by'] ?? 0);
        return (string) $member->role === $role && ($grantor <= 0 || (int) $member->approved_by === $grantor)
            && !(bool) apply_filters('sn_network_space_member_is_permanent', false, (int) $member->space_id, (int) $member->user_id, $member, $data);
    }
    private static function space_manager(int $space, int $user): bool {
        global $wpdb;
        $role = (string) $wpdb->get_var($wpdb->prepare("SELECT role FROM " . SN_DB::table('space_members') . " WHERE space_id=%d AND user_id=%d AND status='active' AND left_at IS NULL", $space, $user));
        return in_array($role, ['owner', 'administrator', 'moderator'], true) || current_user_can('manage_options');
    }
    private static function future_time(string $value, int $max): string|WP_Error {
        $ts = strtotime($value);
        return (!$ts || $ts <= time() || $ts > time() + $max) ? self::error('sn_future_time_invalid', 'Choose a valid future time within the permitted window.', 400) : gmdate('Y-m-d H:i:s', $ts);
    }
    private static function decode(object $row): array|WP_Error {
        $plain = SN_Communication_Crypto::decrypt((string) $row->payload_cipher, 'future-record|' . (string) $row->feature_id . '|' . (int) $row->owner_id . '|' . (string) $row->scope_type . '|' . (int) $row->scope_id);
        if (is_wp_error($plain)) return $plain;
        $data = json_decode($plain, true);
        return is_array($data) ? $data : self::error('sn_future_record_invalid', 'Advanced communication data is invalid.', 500);
    }
    private static function encode(object $row, array $data): string|WP_Error {
        return SN_Communication_Crypto::encrypt((string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'future-record|' . (string) $row->feature_id . '|' . (int) $row->owner_id . '|' . (string) $row->scope_type . '|' . (int) $row->scope_id);
    }
    private static function not_found(): WP_Error { return self::error('not_found', 'Requested communication object is unavailable.', 404); }
    private static function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, ['status' => $status]); }
}
