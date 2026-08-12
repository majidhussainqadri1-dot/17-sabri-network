<?php
/** Review round 43 — race-safe, replay-idempotent QR invitation redemption. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_Q {
    public static function register(): void {
        // Hardening A owns QR v2. Override only redemption with stricter transactional truth.
        add_action('rest_api_init', [self::class, 'routes'], 2000);
    }

    public static function routes(): void {
        register_rest_route('sabri-network/v2', '/future/community-invites/redeem', [
            'methods' => 'POST',
            'callback' => [self::class, 'redeem_qr_invite'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function redeem_qr_invite(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user = get_current_user_id();
        $token = trim((string) $request->get_param('token'));
        $claims = SN_Communication_Crypto::verify($token, 'future-space-invite-v2');
        if (is_wp_error($claims) || ($claims['typ'] ?? '') !== 'f17-space-invite-v2') {
            return self::error('sn_invite_invalid', 'Invitation is invalid or expired.', 403);
        }

        $space = absint($claims['space_id'] ?? 0);
        $issuer = absint($claims['iss'] ?? 0);
        $nonce = (string) ($claims['nonce'] ?? '');
        if ($space <= 0 || $issuer <= 0 || $nonce === '' || SN_Policy::is_suspended($user) || SN_DB::is_blocked($user, $issuer)) {
            return self::error('sn_invite_ineligible', 'Invitation cannot be used by this account.', 403);
        }
        if (SN_Policy::age_state($user) !== 'adult'
            && !(bool) apply_filters('sn_network_guardian_communication_approved', false, $user, $issuer, 'community_invite')) {
            return self::error('sn_guardian_approval_required', 'Guardian approval is required.', 403);
        }

        $records = $wpdb->prefix . 'sn_future_records';
        $hash = hash('sha256', $token);
        $invite_client = hash('sha256', 'qr-v2:' . $hash);
        $now = current_time('mysql', true);

        $wpdb->query('START TRANSACTION');
        try {
            $invite = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $records WHERE feature_id='F17-FUT-12' AND scope_type='space' AND scope_id=%d AND owner_id=%d AND client_key=%s AND state='active' LIMIT 1 FOR UPDATE",
                $space,
                $issuer,
                $invite_client
            ));
            if (!$invite) throw new RuntimeException('unavailable');
            if (!empty($invite->expires_at) && (string) $invite->expires_at <= $now) throw new RuntimeException('expired');

            $payload = self::decode_record($invite);
            if (is_wp_error($payload)
                || !hash_equals((string) ($payload['token_hash'] ?? ''), $hash)
                || !hash_equals((string) ($payload['nonce'] ?? ''), $nonce)) {
                throw new RuntimeException('invalid');
            }

            // Lock current authority/membership truth so a concurrent revoke/ban cannot be overwritten.
            $space_row = $wpdb->get_row($wpdb->prepare(
                'SELECT id,conversation_id,state FROM ' . SN_DB::table('spaces') . ' WHERE id=%d FOR UPDATE',
                $space
            ));
            if (!$space_row || !in_array((string) $space_row->state, ['active', 'restricted'], true)) throw new RuntimeException('space_unavailable');

            $issuer_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . SN_DB::table('space_members') . " WHERE space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE",
                $space,
                $issuer
            ));
            if (!$issuer_row
                || (string) $issuer_row->status !== 'active'
                || !empty($issuer_row->left_at)
                || !in_array((string) $issuer_row->role, ['owner', 'administrator', 'moderator'], true)) {
                throw new RuntimeException('issuer_revoked');
            }

            $member = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . SN_DB::table('space_members') . ' WHERE space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',
                $space,
                $user
            ));
            if ($member && in_array((string) $member->status, ['banned', 'blocked'], true)) throw new RuntimeException('member_blocked');
            if (!(bool) apply_filters('sn_network_space_capacity_allows', true, $space, $user)) throw new RuntimeException('space_full');

            // Exact same user+invite redemption is idempotent and does not consume another use.
            $redeem_client = hash('sha256', 'qr-redemption:' . (int) $invite->id . ':' . $user);
            $already = $wpdb->get_row($wpdb->prepare("SELECT id FROM $records WHERE client_key=%s LIMIT 1 FOR UPDATE", $redeem_client));
            if ($already) {
                $wpdb->query('COMMIT');
                return rest_ensure_response(['space_id' => $space, 'joined' => true, 'duplicate' => true]);
            }

            $count = max(0, (int) ($payload['use_count'] ?? 0));
            $max = max(1, (int) ($payload['max_uses'] ?? 1));
            if ($count >= $max) throw new RuntimeException('exhausted');

            $role = self::enum((string) ($claims['role'] ?? 'member'), ['member', 'observer'], 'member');
            self::activate_member_locked($space_row, $member, $space, $user, $role, $issuer, $now);

            $payload['use_count'] = $count + 1;
            $state = $payload['use_count'] >= $max ? 'redeemed' : 'active';
            $cipher = self::encode_record($invite, $payload);
            if (is_wp_error($cipher)) throw new RuntimeException($cipher->get_error_code());
            $changed = $wpdb->update($records, [
                'payload_cipher' => $cipher,
                'state' => $state,
                'updated_at' => $now,
                'version' => (int) $invite->version + 1,
            ], [
                'id' => (int) $invite->id,
                'state' => 'active',
                'version' => (int) $invite->version,
            ]);
            if ($changed !== 1) throw new RuntimeException('conflict');

            $receipt_payload = wp_json_encode(['invite_id' => (int) $invite->id, 'user_id' => $user, 'redeemed_at' => $now]);
            $receipt_cipher = SN_Communication_Crypto::encrypt((string) $receipt_payload, 'future-record|F17-FUT-12|' . $user . '|space|' . $space);
            if (is_wp_error($receipt_cipher)) throw new RuntimeException($receipt_cipher->get_error_code());
            $inserted = $wpdb->insert($records, [
                'feature_id' => 'F17-FUT-12',
                'owner_id' => $user,
                'scope_type' => 'space',
                'scope_id' => $space,
                'state' => 'redeemed_user',
                'payload_cipher' => $receipt_cipher,
                'client_key' => $redeem_client,
                'expires_at' => $invite->expires_at,
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ]);
            if ($inserted === false) throw new RuntimeException('receipt_failed');

            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('commit_failed');
            SN_DB::audit('future_qr_invite_redeemed', 'space', $space, 'success', [
                'invite_id' => (int) $invite->id,
                'remaining' => max(0, $max - $payload['use_count']),
                'replay_safe' => true,
            ], $user);
            return rest_ensure_response([
                'space_id' => $space,
                'joined' => true,
                'remaining_uses' => max(0, $max - $payload['use_count']),
                'duplicate' => false,
            ]);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            $reason = $error->getMessage();
            $status = in_array($reason, ['space_full', 'conflict'], true) ? 409 : (in_array($reason, ['member_blocked'], true) ? 403 : 410);
            return self::error('sn_invite_' . sanitize_key($reason), 'Invitation is no longer available.', $status);
        }
    }

    private static function activate_member_locked(object $space_row, ?object $member, int $space, int $user, string $role, int $issuer, string $now): void {
        global $wpdb;
        $data = ['role' => $role, 'status' => 'active', 'approved_by' => $issuer, 'left_at' => null, 'updated_at' => $now];
        if ($member) {
            $data['version'] = (int) $member->version + 1;
            $ok = $wpdb->update(SN_DB::table('space_members'), $data, ['id' => (int) $member->id, 'version' => (int) $member->version]);
            if ($ok !== 1 && ((string) $member->status !== 'active' || !empty($member->left_at) || (string) $member->role !== $role)) throw new RuntimeException('member_conflict');
        } else {
            $ok = $wpdb->insert(SN_DB::table('space_members'), [
                'space_id' => $space, 'user_id' => $user, 'joined_at' => $now, 'created_at' => $now,
            ] + $data);
            if ($ok === false) throw new RuntimeException('member_write_failed');
        }

        $conversation_id = (int) $space_row->conversation_id;
        if ($conversation_id <= 0) return;
        $conversation_member = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',
            $conversation_id,
            $user
        ));
        $conversation_data = ['role' => 'member', 'left_at' => null, 'joined_at' => $now];
        $ok = $conversation_member
            ? $wpdb->update(SN_DB::table('members'), $conversation_data, ['id' => (int) $conversation_member->id])
            : $wpdb->insert(SN_DB::table('members'), ['conversation_id' => $conversation_id, 'user_id' => $user] + $conversation_data);
        if ($ok === false) throw new RuntimeException('conversation_member_write_failed');
    }

    private static function decode_record(object $record): array|WP_Error {
        $plain = SN_Communication_Crypto::decrypt((string) $record->payload_cipher, 'future-record|' . (string) $record->feature_id . '|' . (int) $record->owner_id . '|' . (string) $record->scope_type . '|' . (int) $record->scope_id);
        if (is_wp_error($plain)) return $plain;
        $data = json_decode($plain, true);
        return is_array($data) ? $data : self::error('sn_future_record_invalid', 'Advanced communication data is invalid.', 500);
    }

    private static function encode_record(object $record, array $data): string|WP_Error {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return SN_Communication_Crypto::encrypt((string) $json, 'future-record|' . (string) $record->feature_id . '|' . (int) $record->owner_id . '|' . (string) $record->scope_type . '|' . (int) $record->scope_id);
    }

    private static function enum(string $value, array $allowed, string $default): string {
        $value = sanitize_key($value);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
