<?php
/**
 * Corrective layer for the 17-Aug-2026 fresh twenty-round File-17 review.
 *
 * Each method is added only after the corresponding review round is complete.
 * Later rounds extend this single layer instead of creating stacked one-off patches.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Next20_Corrections {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes'], 2100);
    }

    public static function routes(): void {
        // Round 02: external interoperability may never turn manage_options into
        // blanket access to a private conversation. The actor must be an active
        // conversation manager before delegating to the existing governed bridge.
        register_rest_route('sabri-network/v2', '/future/interop', [
            ['methods' => 'GET', 'callback' => [self::class, 'interop_list'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'interop_create'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/future/interop/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'interop_revoke'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/future/interop/(?P<id>\d+)/outbound', [
            'methods' => 'POST', 'callback' => [self::class, 'interop_outbound'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function interop_create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request->get_param('conversation_id'));
        if (!self::conversation_manager($conversation, get_current_user_id())) {
            return self::forbidden();
        }
        return SN_Future24_Review_Hardening_H::create_bridge($request);
    }

    public static function interop_list(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request->get_param('conversation_id'));
        if (!self::conversation_manager($conversation, get_current_user_id())) {
            return self::forbidden();
        }
        return SN_Future24_Review_Hardening_H::list_bridges($request);
    }

    public static function interop_revoke(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = self::bridge_conversation(absint($request['id']));
        if ($conversation <= 0 || !self::conversation_manager($conversation, get_current_user_id())) {
            return self::not_found();
        }
        return SN_Future24_Review_Hardening_H::revoke_bridge($request);
    }

    public static function interop_outbound(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = self::bridge_conversation(absint($request['id']));
        if ($conversation <= 0 || !self::conversation_manager($conversation, get_current_user_id())) {
            return self::not_found();
        }
        return SN_Future24_Review_Hardening_H::outbound($request);
    }

    private static function bridge_conversation(int $bridge_id): int {
        global $wpdb;
        if ($bridge_id <= 0) return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT scope_id FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-24' AND scope_type='conversation' AND state='active' LIMIT 1",
            $bridge_id
        ));
    }

    private static function conversation_manager(int $conversation, int $user): bool {
        return $conversation > 0
            && $user > 0
            && SN_DB::is_member($conversation, $user)
            && in_array(SN_DB::member_role($conversation, $user), ['owner', 'moderator'], true);
    }

    private static function forbidden(): WP_Error {
        return new WP_Error('forbidden', 'Current conversation management authority is required.', ['status' => 403]);
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'Requested communication object is unavailable.', ['status' => 404]);
    }
}
