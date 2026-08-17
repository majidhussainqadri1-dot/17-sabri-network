<?php
/** Fourth fresh cycle: meeting-object authorization and provider-token delivery hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Call_Hardening {
    private const MAX_MEDIA_TOKEN_TTL = 10 * MINUTE_IN_SECONDS;

    public static function register(): void {
        // This must run before SN_Call_Runtime_Hardening priority 4. It proves the
        // public meeting object and action scope before that later hook acquires
        // locks, refreshes File-00 assertions or reaches provider-adjacent work.
        add_filter('rest_pre_dispatch', [self::class, 'authorize_meeting_before_side_effects'], -29997, 3);
        add_filter('sn_network_meet_media_config', [self::class, 'validate_media_config'], PHP_INT_MAX, 4);
        add_filter('rest_post_dispatch', [self::class, 'revalidate_media_delivery'], 6, 3);
    }

    public static function authorize_meeting_before_side_effects($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null || strtoupper($request->get_method()) !== 'POST') return $result;
        $route = $request->get_route();
        if (!preg_match('#^/sabri-network/v2/meetings/([A-Za-z0-9_-]{22,64})/(invite|join|leave|heartbeat|moderate|signals(?:/ack)?)$#', $route, $match)) {
            return $result;
        }
        global $wpdb;
        $public = (string) $match[1];
        $action = (string) $match[2];
        $actor = get_current_user_id();
        $meeting = $wpdb->get_row($wpdb->prepare(
            "SELECT id,host_id,conversation_id,status,access_mode FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s",
            $public
        ));
        if (!$meeting || $actor <= 0) return self::not_found();
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT role,state FROM {$wpdb->prefix}sn_meet_participants WHERE meeting_id=%d AND user_id=%d",
            (int) $meeting->id,
            $actor
        ));

        if ($action === 'join') {
            $participant_allowed = $participant && !in_array((string) $participant->state, ['denied','removed'], true);
            $conversation_allowed = (string) $meeting->access_mode === 'conversation'
                && (int) $meeting->conversation_id > 0
                && SN_DB::is_member((int) $meeting->conversation_id, $actor);
            return ($participant_allowed || $conversation_allowed) ? $result : self::not_found();
        }
        if ($action === 'invite' || $action === 'moderate') {
            if (!$participant || !in_array((string) $participant->role, ['host','cohost'], true)) return self::not_found();
            if ((string) $participant->role === 'cohost' && !in_array((string) $participant->state, ['admitted','joined'], true)) return self::not_found();
            return $result;
        }
        if ($action === 'heartbeat' || str_starts_with($action, 'signals')) {
            return ($participant && in_array((string) $participant->state, ['admitted','joined'], true)) ? $result : self::not_found();
        }
        // Leave must remain available to invited/waiting/joined participants, including
        // newly restricted users, but not to unrelated authenticated accounts.
        return ($participant && !in_array((string) $participant->state, ['denied','removed'], true)) ? $result : self::not_found();
    }

    /**
     * The meeting adapter is intentionally provider-agnostic, but its output still
     * must obey File-17's short-lived-token and truthful-feature boundary.
     */
    public static function validate_media_config($value, object $meeting, int $user_id, object $session) {
        if (!is_array($value) || ($value['available'] ?? false) !== true) return $value;
        $expires = trim((string) ($value['expires_at'] ?? ''));
        $timestamp = strtotime($expires);
        if (!$timestamp || $timestamp <= time() || $timestamp > time() + self::MAX_MEDIA_TOKEN_TTL) {
            return ['available'=>false,'reason'=>'media_token_expiry_invalid','features'=>self::empty_features()];
        }
        $features = is_array($value['features'] ?? null) ? $value['features'] : [];
        // Recording remains unavailable in the canonical Sabri Meet control plane
        // until a separate governed recording lifecycle exists; an adapter cannot
        // turn a capability flag into an unsupported product/security claim.
        $features['recording'] = false;
        $value['features'] = $features;
        unset($value['e2ee'], $value['end_to_end_encryption']);
        return $value;
    }

    /** Never deliver a newly issued meeting-media token on a stale File-00 assertion. */
    public static function revalidate_media_delivery($response, WP_REST_Server $server, WP_REST_Request $request) {
        if (!($response instanceof WP_REST_Response) || $response->get_status() >= 400) return $response;
        if (strtoupper($request->get_method()) !== 'POST'
            || !preg_match('#^/sabri-network/v2/meetings/[A-Za-z0-9_-]{22,64}/join$#', $request->get_route())) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data) || !is_array($data['media'] ?? null) || ($data['media']['available'] ?? false) !== true) return $response;
        $actor = get_current_user_id();
        SN_Membership_Assertions::clear_cache($actor);
        $fresh = SN_Membership_Assertions::communication($actor);
        if (is_wp_error($fresh) || ($fresh['can_call'] ?? false) !== true || ($fresh['suspended'] ?? true) === true) {
            SN_DB::audit('meet_media_delivery_revoked', 'meeting', 0, 'failure', ['route'=>'join'], $actor);
            return rest_convert_error_to_response(new WP_Error('sn_call_eligibility_changed', 'Calling eligibility changed before media credential delivery.', ['status'=>403]));
        }
        return $response;
    }

    private static function empty_features(): array {
        return ['audio'=>false,'video'=>false,'screen_share'=>false,'captions'=>false,'recording'=>false];
    }
    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested meeting is unavailable.', ['status'=>404]);
    }
}
