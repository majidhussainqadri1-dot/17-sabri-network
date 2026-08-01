<?php
defined('ABSPATH') || exit;

/** Central server-side policy for every File-17 action. */
final class SN_Policy {
    public static function access(): true|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('authentication_required', 'Sign in through the platform account system to use Network.', ['status' => 401]);
        }
        $user_id = get_current_user_id();
        if (!$user_id || !get_user_by('id', $user_id)) {
            return new WP_Error('identity_unavailable', 'The authenticated identity is unavailable.', ['status' => 401]);
        }
        if (!self::identity_authority_available()) {
            return new WP_Error('identity_authority_unavailable', 'The platform identity authority is unavailable. Network actions are temporarily disabled.', ['status' => 503]);
        }
        if (self::is_suspended($user_id)) {
            return new WP_Error('account_restricted', 'Network access is unavailable for this account.', ['status' => 403]);
        }
        $allowed = apply_filters('sn_network_user_can_access', true, $user_id);
        return $allowed === true ? true : (is_wp_error($allowed) ? $allowed : new WP_Error('network_access_denied', 'Network access is not permitted for this account.', ['status' => 403]));
    }


    public static function identity_authority_available(): bool {
        $known = class_exists('Sabri_Membership_Core')
            || class_exists('Sabri\Membership\Core')
            || function_exists('sabri_membership_core');
        return (bool) apply_filters('sn_network_identity_authority_available', $known);
    }

    public static function is_suspended(int $user_id): bool {
        $filtered = apply_filters('sn_network_user_is_suspended', null, $user_id);
        if (is_bool($filtered)) {
            return $filtered;
        }
        return (bool) get_user_meta($user_id, 'sn_account_suspended', true)
            || in_array((string) get_user_meta($user_id, 'sn_account_status', true), ['suspended', 'blocked', 'deleted'], true);
    }

    public static function age_state(int $user_id): string {
        $state = apply_filters('sn_network_user_age_state', null, $user_id);
        if (is_string($state) && in_array($state, ['adult', 'minor', 'unknown'], true)) {
            return $state;
        }

        $filtered = apply_filters('sn_network_user_is_minor', null, $user_id);
        if (is_bool($filtered)) {
            return $filtered ? 'minor' : 'adult';
        }

        $dob = trim((string) get_user_meta($user_id, 'sn_date_of_birth', true));
        if ($dob === '') {
            $dob = trim((string) get_user_meta($user_id, 'date_of_birth', true));
        }
        if ($dob === '') {
            return 'unknown';
        }

        $date = substr($dob, 0, 10);
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$birth || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) || $birth->format('Y-m-d') !== $date) {
            return 'unknown';
        }
        $today = new DateTimeImmutable('today');
        if ($birth > $today) {
            return 'unknown';
        }
        return $birth->diff($today)->y < 18 ? 'minor' : 'adult';
    }

    public static function is_minor(int $user_id): bool {
        return self::age_state($user_id) === 'minor';
    }

    public static function has_guardian_consent(int $user_id): bool {
        $filtered = apply_filters('sn_network_guardian_consent_valid', null, $user_id);
        if (is_bool($filtered)) {
            return $filtered;
        }
        return (bool) get_user_meta($user_id, 'sn_guardian_consent_verified', true);
    }

    public static function can_contact(int $actor_id, int $target_id, string $context): true|WP_Error {
        if (!$actor_id || !$target_id || $actor_id === $target_id || !get_user_by('id', $target_id)) {
            return new WP_Error('invalid_contact', 'Select a valid Network member.', ['status' => 400]);
        }
        if (self::is_suspended($actor_id) || self::is_suspended($target_id)) {
            return new WP_Error('contact_unavailable', 'This Network member is unavailable.', ['status' => 403]);
        }
        if (SN_DB::is_blocked($actor_id, $target_id)) {
            return new WP_Error('blocked', 'This Network connection is unavailable.', ['status' => 403]);
        }
        $actor_age_state = self::age_state($actor_id);
        $target_age_state = self::age_state($target_id);
        if (($actor_age_state === 'unknown' || $target_age_state === 'unknown')
            && !(bool) apply_filters('sn_network_unknown_age_contact_allowed', false, $actor_id, $target_id, $context)) {
            return new WP_Error('age_verification_required', 'Verified age information is required before this contact can be used.', ['status' => 403]);
        }
        $actor_is_minor = $actor_age_state === 'minor';
        $target_is_minor = $target_age_state === 'minor';
        if (($actor_is_minor && !self::has_guardian_consent($actor_id)) || ($target_is_minor && !self::has_guardian_consent($target_id))) {
            return new WP_Error('guardian_consent_required', 'Verified guardian consent is required for this contact.', ['status' => 403]);
        }
        if (($actor_is_minor || $target_is_minor) && !(bool) apply_filters('sn_network_minor_contact_allowed', false, $actor_id, $target_id, $context)) {
            return new WP_Error('minor_contact_restricted', 'This contact is not approved under the minor-safety policy.', ['status' => 403]);
        }

        $privacy = self::privacy_for($target_id);
        $privacy_key = match ($context) {
            'call' => 'calls',
            'group' => 'groups',
            default => 'messages',
        };
        $visibility = (string) ($privacy[$privacy_key] ?? 'contacts');
        $contacts = SN_DB::are_contacts($actor_id, $target_id);
        if ($context === 'request') {
            if ($visibility === 'nobody') {
                return new WP_Error('contact_requests_disabled', 'This member is not accepting contact requests.', ['status' => 403]);
            }
            return true;
        }
        if (!$contacts) {
            return new WP_Error('contact_required', 'An accepted contact relationship is required.', ['status' => 403]);
        }
        if ($visibility === 'nobody') {
            return new WP_Error('contact_action_disabled', 'This member has disabled this contact method.', ['status' => 403]);
        }
        return true;
    }

    public static function can_follow(int $actor_id, int $target_id): true|WP_Error {
        if (!$actor_id || !$target_id || $actor_id === $target_id || !get_user_by('id', $target_id)) {
            return new WP_Error('invalid_follow', 'Select a valid Network member.', ['status' => 400]);
        }
        if (self::is_suspended($actor_id) || self::is_suspended($target_id)) {
            return new WP_Error('follow_unavailable', 'This Network member is unavailable.', ['status' => 403]);
        }
        if (SN_DB::is_blocked($actor_id, $target_id)) {
            return new WP_Error('blocked', 'This Network connection is unavailable.', ['status' => 403]);
        }
        $actor_age = self::age_state($actor_id);
        $target_age = self::age_state($target_id);
        if (($actor_age === 'unknown' || $target_age === 'unknown')
            && !(bool) apply_filters('sn_network_unknown_age_follow_allowed', false, $actor_id, $target_id)) {
            return new WP_Error('age_verification_required', 'Verified age information is required before following this member.', ['status' => 403]);
        }
        if (($actor_age === 'minor' && !self::has_guardian_consent($actor_id))
            || ($target_age === 'minor' && !self::has_guardian_consent($target_id))) {
            return new WP_Error('guardian_consent_required', 'Verified guardian consent is required for this follow relationship.', ['status' => 403]);
        }
        if (($actor_age === 'minor' || $target_age === 'minor')
            && !(bool) apply_filters('sn_network_minor_follow_allowed', false, $actor_id, $target_id)) {
            return new WP_Error('minor_follow_restricted', 'This follow relationship is not approved under the minor-safety policy.', ['status' => 403]);
        }
        $visibility = (string) (self::privacy_for($target_id)['follows'] ?? 'everyone');
        if (!in_array($visibility, ['everyone', 'contacts', 'nobody'], true)) {
            $visibility = 'contacts';
        }
        if ($visibility === 'nobody') {
            return new WP_Error('follows_disabled', 'This member is not accepting followers.', ['status' => 403]);
        }
        return true;
    }

    public static function follow_initial_status(int $actor_id, int $target_id): string {
        $visibility = (string) (self::privacy_for($target_id)['follows'] ?? 'everyone');
        return $visibility === 'everyone' || SN_DB::are_contacts($actor_id, $target_id) ? 'active' : 'pending';
    }

    public static function can_create_conversation(int $user_id, string $type): bool {
        if ($type === 'direct') {
            return true;
        }
        $map = [
            'group' => 'sn_network_create_group',
            'community' => 'sn_network_create_community',
            'channel' => 'sn_network_create_channel',
        ];
        if (!isset($map[$type]) || self::age_state($user_id) !== 'adult' || self::is_suspended($user_id)) {
            return false;
        }
        return (bool) apply_filters('sn_network_can_create_conversation', user_can($user_id, $map[$type]), $user_id, $type);
    }

    public static function can_publish_public_update(int $user_id): bool {
        if (self::age_state($user_id) !== 'adult' || self::is_suspended($user_id)) {
            return false;
        }
        return (bool) apply_filters('sn_network_can_publish_public_update', user_can($user_id, 'sn_network_publish_public_update'), $user_id);
    }

    public static function can_use_group_calls(int $user_id, int $conversation_id): bool {
        return (bool) apply_filters('sn_network_sfu_available', false, $user_id, $conversation_id)
            && (bool) apply_filters('sn_network_can_use_group_calls', user_can($user_id, 'sn_network_group_call'), $user_id, $conversation_id);
    }

    public static function can_post_to_conversation(object $conversation, int $user_id): true|WP_Error {
        $role = SN_DB::member_role((int) $conversation->id, $user_id);
        if ($role === '') {
            return new WP_Error('conversation_membership_required', 'An active conversation membership is required.', ['status' => 403]);
        }
        if ((string) $conversation->type === 'channel' && !in_array($role, ['owner', 'moderator'], true)) {
            $allowed = (bool) apply_filters('sn_network_channel_member_can_post', false, $user_id, $conversation, $role);
            if (!$allowed) {
                return new WP_Error('channel_posting_restricted', 'Only channel administrators may publish messages here.', ['status' => 403]);
            }
        }
        return true;
    }

    public static function can_view_presence(int $viewer_id, int $target_id): bool {
        if ($viewer_id <= 0 || $target_id <= 0) {
            return false;
        }
        if ($viewer_id === $target_id) {
            return true;
        }
        if (self::is_suspended($target_id) || SN_DB::is_blocked($viewer_id, $target_id)) {
            return false;
        }
        $contacts = SN_DB::are_contacts($viewer_id, $target_id);
        $shared = SN_DB::share_active_conversation($viewer_id, $target_id);
        if (!$contacts && !$shared) {
            return false;
        }
        if (self::is_minor($target_id) && !$contacts) {
            return false;
        }
        $visibility = (string) (self::privacy_for($target_id)['last_seen'] ?? 'contacts');
        $allowed = $visibility === 'everyone' || ($visibility === 'contacts' && $contacts);
        return (bool) apply_filters('sn_network_can_view_presence', $allowed, $viewer_id, $target_id, $visibility);
    }

    public static function can_edit_message(object $message, int $user_id): bool {
        if ((int) $message->sender_id !== $user_id || $message->deleted_at) {
            return false;
        }
        $window = max(0, (int) apply_filters('sn_network_message_edit_window', 15 * MINUTE_IN_SECONDS, $message, $user_id));
        return time() <= strtotime((string) $message->created_at . ' UTC') + $window;
    }

    public static function can_delete_message(object $message, int $user_id): bool {
        if ((int) $message->sender_id !== $user_id || $message->deleted_at) {
            return false;
        }
        $window = max(0, (int) apply_filters('sn_network_message_delete_window', HOUR_IN_SECONDS, $message, $user_id));
        return time() <= strtotime((string) $message->created_at . ' UTC') + $window;
    }

    public static function sanitize_reaction(string $reaction): string {
        $reaction = trim(wp_unslash($reaction));
        $allowed = (array) apply_filters('sn_network_allowed_reactions', ['👍', '❤️', '😂', '😮', '😢', '🙏']);
        return in_array($reaction, $allowed, true) ? $reaction : '';
    }

    public static function consume_rate_limit(string $bucket, string $subject, int $limit, int $window_seconds): bool {
        return SN_DB::consume_rate_limit($bucket, $subject, $limit, $window_seconds);
    }

    public static function privacy_for(int $user_id): array {
        $defaults = [
            'phone_visibility' => 'contacts',
            'last_seen' => 'contacts',
            'profile_photo' => 'everyone',
            'groups' => 'contacts',
            'calls' => 'contacts',
            'messages' => 'contacts',
            'updates' => 'contacts',
            'follows' => 'everyone',
        ];
        $stored = (array) get_user_meta($user_id, 'sn_privacy', true);
        $privacy = array_merge($defaults, array_intersect_key($stored, $defaults));
        if (self::is_minor($user_id)) {
            foreach (['phone_visibility', 'last_seen', 'groups', 'calls', 'messages', 'updates'] as $key) {
                $privacy[$key] = 'contacts';
            }
        }
        return $privacy;
    }
}
