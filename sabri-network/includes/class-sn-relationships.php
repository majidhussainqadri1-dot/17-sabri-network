<?php
defined('ABSPATH') || exit;

/** Canonical asymmetric follow graph and File 25 profile-action contract. */
final class SN_Relationships {
    private const MAX_PAGE = 50;

    public static function state(int $viewer_id, int $target_id): array|WP_Error {
        if ($viewer_id <= 0 || $target_id <= 0 || $viewer_id === $target_id || !get_user_by('id', $target_id)) {
            return new WP_Error('invalid_relationship_target', 'Select a valid Network member.', ['status' => 400]);
        }
        if (SN_Policy::is_suspended($target_id)) {
            return new WP_Error('relationship_target_unavailable', 'This Network member is unavailable.', ['status' => 404]);
        }
        $blocked = SN_DB::is_blocked($viewer_id, $target_id);
        $contact = SN_DB::contact_record($viewer_id, $target_id);
        $follow = SN_DB::follow_record($viewer_id, $target_id);
        $reverse = SN_DB::follow_record($target_id, $viewer_id);
        $contact_state = 'none';
        if ($contact) {
            $contact_state = (string) $contact->status;
            if ($contact_state === 'pending') {
                $contact_state = (int) $contact->requested_by === $viewer_id ? 'requested' : 'incoming';
            }
        }
        $follow_state = $follow ? (string) $follow->status : 'none';
        $can_request_contact = !$blocked && !is_wp_error(SN_Policy::can_contact($viewer_id, $target_id, 'request'));
        $can_message = !$blocked && !is_wp_error(SN_Policy::can_contact($viewer_id, $target_id, 'message'));
        $can_follow = !$blocked && !is_wp_error(SN_Policy::can_follow($viewer_id, $target_id));
        return [
            'viewer_id' => $viewer_id,
            'target_id' => $target_id,
            'blocked' => $blocked,
            'contact' => ['id' => $contact ? (int) $contact->id : 0, 'state' => $contact_state],
            'follow' => ['id' => $follow ? (int) $follow->id : 0, 'state' => $follow_state, 'version' => $follow ? (int) $follow->version : 0],
            'followed_by_target' => $reverse && (string) $reverse->status === 'active',
            'actions' => [
                'connect' => $can_request_contact && in_array($contact_state, ['none', 'declined', 'removed'], true),
                'accept_contact' => !$blocked && $contact_state === 'incoming',
                'message' => $can_message,
                'follow' => $can_follow && in_array($follow_state, ['none', 'inactive', 'rejected'], true),
                'unfollow' => !$blocked && in_array($follow_state, ['active', 'pending'], true),
                'block' => !$blocked,
                'unblock' => $blocked,
            ],
        ];
    }

    public static function follow(int $follower_id, int $followed_id): array|WP_Error {
        return self::with_pair_lock($follower_id, $followed_id, function () use ($follower_id, $followed_id) {
            global $wpdb;
            $policy = SN_Policy::can_follow($follower_id, $followed_id);
            if (is_wp_error($policy)) return $policy;
            $status = SN_Policy::follow_initial_status($follower_id, $followed_id);
            $table = SN_DB::table('follows');
            $existing = SN_DB::follow_record($follower_id, $followed_id);
            if ($existing && in_array((string) $existing->status, ['active', 'pending'], true)) return self::project($existing, true);
            $now = current_time('mysql', true);
            $data = [
                'follower_id' => $follower_id, 'followed_id' => $followed_id, 'status' => $status,
                'version' => $existing ? (int) $existing->version + 1 : 1,
                'created_at' => $existing ? (string) $existing->created_at : $now,
                'updated_at' => $now, 'decided_at' => $status === 'active' ? $now : null,
            ];
            $ok = $existing
                ? $wpdb->update($table, $data, ['id' => (int) $existing->id, 'version' => (int) $existing->version])
                : $wpdb->insert($table, $data);
            if ($ok === false || ($existing && $ok !== 1)) {
                $race = SN_DB::follow_record($follower_id, $followed_id);
                if ($race && in_array((string) $race->status, ['active', 'pending'], true)) return self::project($race, true);
                return new WP_Error('follow_database_error', 'The follow relationship could not be saved.', ['status' => 500]);
            }
            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) return new WP_Error('follow_database_error', 'The follow relationship could not be confirmed.', ['status' => 500]);
            SN_DB::add_notification($followed_id, $status === 'active' ? 'new_follower' : 'follow_request', $status === 'active' ? 'New follower' : 'New follow request', '', 'follow', (int) $row->id);
            SN_DB::audit('follow_' . $status, 'follow', (int) $row->id, 'success', ['target_id' => $followed_id], $follower_id);
            do_action('sn_network_follow_changed', self::project($row), $follower_id);
            return self::project($row);
        });
    }

    public static function unfollow(int $follower_id, int $followed_id, int $expected_version = 0): array|WP_Error {
        return self::with_pair_lock($follower_id, $followed_id, function () use ($follower_id, $followed_id, $expected_version) {
            global $wpdb;
            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) {
                return ['id' => $row ? (int) $row->id : 0, 'status' => 'inactive', 'version' => $row ? (int) $row->version : 0, 'duplicate' => true];
            }
            if ($expected_version > 0 && (int) $row->version !== $expected_version) return new WP_Error('follow_version_conflict', 'The follow relationship changed before this request was saved.', ['status' => 409]);
            $now = current_time('mysql', true);
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE ' . SN_DB::table('follows') . " SET status='inactive',updated_at=%s,decided_at=%s,version=version+1 WHERE id=%d AND follower_id=%d AND version=%d",
                $now, $now, (int) $row->id, $follower_id, (int) $row->version
            ));
            if ($updated !== 1) return new WP_Error('follow_version_conflict', 'The follow relationship changed before this request was saved.', ['status' => 409]);
            SN_DB::audit('follow_inactive', 'follow', (int) $row->id, 'success', ['target_id' => $followed_id], $follower_id);
            return ['id' => (int) $row->id, 'status' => 'inactive', 'version' => (int) $row->version + 1];
        });
    }

    public static function decide(int $target_id, int $follow_id, string $decision, int $expected_version): array|WP_Error {
        global $wpdb;
        if (!in_array($decision, ['accept', 'reject'], true) || $expected_version <= 0) return new WP_Error('invalid_follow_decision', 'A valid follow decision and version are required.', ['status' => 400]);
        $probe = $wpdb->get_row($wpdb->prepare('SELECT follower_id,followed_id FROM ' . SN_DB::table('follows') . ' WHERE id=%d', $follow_id));
        if (!$probe || (int) $probe->followed_id !== $target_id) return new WP_Error('follow_request_not_found', 'This follow request is unavailable.', ['status' => 404]);
        return self::with_pair_lock((int) $probe->follower_id, $target_id, function () use ($target_id, $follow_id, $decision, $expected_version) {
            global $wpdb;
            $table = SN_DB::table('follows');
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $follow_id));
            if (!$row || (int) $row->followed_id !== $target_id || (string) $row->status !== 'pending') return new WP_Error('follow_request_not_found', 'This follow request is unavailable.', ['status' => 404]);
            if ((int) $row->version !== $expected_version) return new WP_Error('follow_version_conflict', 'The follow request changed before this decision was saved.', ['status' => 409]);
            if ($decision === 'accept') {
                $policy = SN_Policy::can_follow((int) $row->follower_id, $target_id);
                if (is_wp_error($policy)) return $policy;
            }
            $status = $decision === 'accept' ? 'active' : 'rejected';
            $now = current_time('mysql', true);
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status=%s,updated_at=%s,decided_at=%s,version=version+1 WHERE id=%d AND followed_id=%d AND status='pending' AND version=%d",
                $status, $now, $now, $follow_id, $target_id, $expected_version
            ));
            if ($updated !== 1) return new WP_Error('follow_version_conflict', 'The follow request changed before this decision was saved.', ['status' => 409]);
            SN_DB::add_notification((int) $row->follower_id, 'follow_' . $status, $status === 'active' ? 'Follow request accepted' : 'Follow request declined', '', 'follow', $follow_id);
            SN_DB::audit('follow_' . $status, 'follow', $follow_id, 'success', ['follower_id' => (int) $row->follower_id], $target_id);
            return ['id' => $follow_id, 'status' => $status, 'version' => $expected_version + 1];
        });
    }

    public static function lists(int $user_id, string $scope, int $limit, string $cursor = ''): array|WP_Error {
        $scope = in_array($scope, ['followers', 'following', 'requests', 'all'], true) ? $scope : 'all';
        $limit = min(self::MAX_PAGE, max(1, $limit));
        if ($scope === 'all') {
            $result = [];
            foreach (['followers', 'following', 'requests'] as $list_scope) {
                $list = self::query_list($user_id, $list_scope, $limit, '');
                if (is_wp_error($list)) return $list;
                $result[$list_scope] = $list;
            }
            return $result;
        }
        return self::query_list($user_id, $scope, $limit, $cursor);
    }

    private static function query_list(int $user_id, string $scope, int $limit, string $cursor): array|WP_Error {
        global $wpdb;
        $last_id = PHP_INT_MAX;
        if ($cursor !== '') {
            $decoded = self::decode_cursor($cursor, $user_id, $scope);
            if (is_wp_error($decoded)) return $decoded;
            $last_id = $decoded;
        }
        $table = SN_DB::table('follows');
        if ($scope === 'followers') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE followed_id=%d AND status='active' AND id<%d ORDER BY id DESC LIMIT %d", $user_id, $last_id, $limit + 1)); $other = 'follower_id';
        } elseif ($scope === 'following') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE follower_id=%d AND status='active' AND id<%d ORDER BY id DESC LIMIT %d", $user_id, $last_id, $limit + 1)); $other = 'followed_id';
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE followed_id=%d AND status='pending' AND id<%d ORDER BY id DESC LIMIT %d", $user_id, $last_id, $limit + 1)); $other = 'follower_id';
        }
        if (!is_array($rows)) return new WP_Error('follow_list_error', 'The follow list could not be loaded.', ['status' => 500]);
        $has_more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit); $items = [];
        foreach ($rows as $row) {
            $profile = SN_Auth::public_user((int) $row->{$other});
            if (!$profile || SN_DB::is_blocked($user_id, (int) $row->{$other})) continue;
            $items[] = self::project($row) + ['user' => $profile];
        }
        $next = $has_more && $rows ? self::encode_cursor((int) end($rows)->id, $user_id, $scope) : '';
        return ['items' => $items, 'next_cursor' => $next];
    }

    public static function filter_profile_action_state($state, int $viewer_id, int $target_id) {
        $result = self::state($viewer_id, $target_id);
        return is_wp_error($result) ? $state : $result;
    }

    public static function pair_lock_name(int $a, int $b): string {
        $ids = [min($a, $b), max($a, $b)];
        return 'sn:f17:relationship:' . substr(hash('sha256', $ids[0] . ':' . $ids[1]), 0, 32);
    }

    private static function with_pair_lock(int $a, int $b, callable $callback): array|WP_Error {
        global $wpdb;
        if ($a <= 0 || $b <= 0 || $a === $b) return new WP_Error('invalid_relationship_target', 'Select a valid Network member.', ['status' => 400]);
        $lock = self::pair_lock_name($a, $b);
        $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock));
        if ($acquired !== 1) return new WP_Error('relationship_busy', 'This relationship is changing. Try again.', ['status' => 409]);
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function project(object $row, bool $duplicate = false): array {
        return [
            'id' => (int) $row->id, 'follower_id' => (int) $row->follower_id, 'followed_id' => (int) $row->followed_id,
            'status' => (string) $row->status, 'version' => (int) $row->version, 'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at, 'duplicate' => $duplicate,
        ];
    }

    private static function encode_cursor(int $last_id, int $user_id, string $scope): string {
        $payload = wp_json_encode(['last_id' => $last_id, 'user_id' => $user_id, 'scope' => $scope]);
        $body = rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, wp_salt('nonce'));
    }

    private static function decode_cursor(string $cursor, int $user_id, string $scope): int|WP_Error {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], wp_salt('nonce')), $parts[1])) return new WP_Error('invalid_follow_cursor', 'The follow cursor is invalid.', ['status' => 400]);
        $body = strtr($parts[0], '-_', '+/');
        $remainder = strlen($body) % 4;
        if ($remainder !== 0) $body .= str_repeat('=', 4 - $remainder);
        $decoded = base64_decode($body, true); $data = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== $user_id || (string) ($data['scope'] ?? '') !== $scope || (int) ($data['last_id'] ?? 0) <= 0) return new WP_Error('invalid_follow_cursor', 'The follow cursor is invalid.', ['status' => 400]);
        return (int) $data['last_id'];
    }
}
