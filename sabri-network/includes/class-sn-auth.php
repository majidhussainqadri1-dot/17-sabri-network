<?php
defined('ABSPATH') || exit;

/** Read-only identity projection. Account creation/authentication remains File 00/File 02. */
final class SN_Auth {
    public static function normalize_phone(string $raw): string|WP_Error {
        $raw = trim(wp_unslash($raw));
        $raw = preg_replace('/[^\d+]/', '', $raw);
        if ($raw === '') {
            return new WP_Error('invalid_phone', 'Enter a valid mobile number.', ['status' => 400]);
        }
        if (!str_starts_with($raw, '+')) {
            $country = preg_replace('/\D/', '', (string) get_option('sn_default_country_code', '+92'));
            $digits = ltrim($raw, '0');
            $raw = '+' . $country . $digits;
        }
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $raw)) {
            return new WP_Error('invalid_phone', 'Enter a valid international mobile number.', ['status' => 400]);
        }
        return $raw;
    }

    public static function user_by_phone(string $phone): ?WP_User {
        $phone = self::normalize_phone($phone);
        if (is_wp_error($phone)) {
            return null;
        }
        $users = get_users([
            'number' => 1,
            'meta_key' => 'sn_phone_e164',
            'meta_value' => $phone,
            'fields' => 'all',
            'count_total' => false,
        ]);
        return $users ? $users[0] : null;
    }

    public static function public_user(int $user_id, bool $self = false): array {
        $user = get_user_by('id', $user_id);
        if (!$user || SN_Policy::is_suspended($user_id)) {
            return [];
        }
        $viewer_id = get_current_user_id();
        $privacy = SN_Policy::privacy_for($user_id);
        $phone = (string) get_user_meta($user_id, 'sn_phone_e164', true);
        $can_see_phone = $self || self::can_view_phone($viewer_id, $user_id, $privacy);
        $avatar_visibility = (string) ($privacy['profile_photo'] ?? 'everyone');
        $can_see_avatar = $self || $avatar_visibility === 'everyone' || ($avatar_visibility === 'contacts' && SN_DB::are_contacts($viewer_id, $user_id));
        $projection = [
            'id' => $user_id,
            'name' => sanitize_text_field($user->display_name),
            'avatar' => $can_see_avatar ? get_avatar_url($user_id, ['size' => 192]) : SN_URL . 'assets/network-default-avatar.svg',
            'phone' => $can_see_phone ? $phone : '',
            'phone_masked' => $can_see_phone && $phone ? self::mask_phone($phone) : '',
            'verified' => (bool) apply_filters('sn_network_user_verified', (bool) get_user_meta($user_id, 'sn_phone_verified', true), $user_id),
            'about' => mb_substr(sanitize_textarea_field((string) get_user_meta($user_id, 'sn_about', true)), 0, 500),
            'role_label' => sanitize_text_field((string) apply_filters('sn_network_user_role_label', get_user_meta($user_id, 'sn_role_label', true), $user_id)),
        ];
        $filtered = (array) apply_filters('sn_network_public_user_projection', $projection, $user_id, $viewer_id, $self);
        $avatar = esc_url_raw((string) ($filtered['avatar'] ?? $projection['avatar']), ['http', 'https']);
        return [
            'id' => $user_id,
            'name' => mb_substr(sanitize_text_field((string) ($filtered['name'] ?? $projection['name'])), 0, 191),
            'avatar' => $avatar ?: SN_URL . 'assets/network-default-avatar.svg',
            'phone' => $can_see_phone ? mb_substr(sanitize_text_field((string) ($filtered['phone'] ?? $projection['phone'])), 0, 32) : '',
            'phone_masked' => $can_see_phone ? mb_substr(sanitize_text_field((string) ($filtered['phone_masked'] ?? $projection['phone_masked'])), 0, 32) : '',
            'verified' => (bool) ($filtered['verified'] ?? $projection['verified']),
            'about' => mb_substr(sanitize_textarea_field((string) ($filtered['about'] ?? $projection['about'])), 0, 500),
            'role_label' => mb_substr(sanitize_text_field((string) ($filtered['role_label'] ?? $projection['role_label'])), 0, 100),
        ];
    }

    public static function can_view_phone(int $viewer_id, int $target_id, array $privacy = []): bool {
        if ($viewer_id === $target_id && $viewer_id > 0) {
            return true;
        }
        if (SN_Policy::age_state($target_id) !== 'adult') {
            return false;
        }
        $visibility = (string) ($privacy['phone_visibility'] ?? 'contacts');
        if ($visibility === 'everyone') {
            return true;
        }
        return $viewer_id > 0 && $visibility === 'contacts' && SN_DB::are_contacts($viewer_id, $target_id) && !SN_DB::is_blocked($viewer_id, $target_id);
    }

    public static function ice_servers(int $user_id, int $conversation_id = 0): array {
        if ($user_id <= 0 || ($conversation_id > 0 && !SN_DB::is_member($conversation_id, $user_id))) {
            return [];
        }
        $servers = [];
        $stun = preg_split('/\r\n|\r|\n/', (string) get_option('sn_stun_urls', ''));
        $stun = array_values(array_filter(array_map('trim', (array) $stun), static fn($url) => preg_match('/^(stun|stuns):[^\s]+$/i', $url)));
        if ($stun) {
            $servers[] = ['urls' => count($stun) === 1 ? $stun[0] : $stun];
        }
        $turn = apply_filters('sn_network_ephemeral_turn_credentials', [], $user_id, $conversation_id);
        if (is_array($turn) && !empty($turn['urls']) && !empty($turn['username']) && !empty($turn['credential']) && !empty($turn['expires_at'])) {
            $expires = is_numeric($turn['expires_at']) ? (int) $turn['expires_at'] : strtotime((string) $turn['expires_at']);
            if ($expires > time() + 30) {
                $servers[] = [
                    'urls' => $turn['urls'],
                    'username' => sanitize_text_field((string) $turn['username']),
                    'credential' => (string) $turn['credential'],
                ];
            }
        }
        return $servers;
    }

    public static function mask_phone(string $phone): string {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 6) {
            return '••••';
        }
        return '+' . substr($digits, 0, 2) . str_repeat('•', max(4, strlen($digits) - 6)) . substr($digits, -4);
    }
}
