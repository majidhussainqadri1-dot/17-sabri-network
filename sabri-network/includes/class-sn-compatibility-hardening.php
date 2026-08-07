<?php
/** Compatibility-only bridges that preserve one canonical File-17 backend. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Compatibility_Hardening {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_legacy_presence'], 1200);
    }

    public static function override_legacy_presence(): void {
        register_rest_route('sabri-network/v2', '/presence', [
            ['methods' => 'GET', 'callback' => [self::class, 'legacy_get_presence'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'legacy_heartbeat'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
    }

    public static function legacy_heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, ['online', 'away', 'offline'], true)) {
            $status = 'online';
        }
        $forward = new WP_REST_Request('POST', '/sabri-network/v2/presence/devices/heartbeat');
        $forward->set_param('device_id', self::legacy_device_id($user_id));
        $forward->set_param('state', $status);
        $forward->set_param('ttl', $status === 'offline' ? 30 : 90);
        $forward->set_param('label', 'Compatibility web session');
        $forward->set_param('capabilities', ['realtime']);
        $response = SN_Presence_Devices::heartbeat($forward);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = $response->get_data();
        return rest_ensure_response([
            'presence' => [
                'user_id' => $user_id,
                'status' => $status,
                'last_seen_at' => current_time('mysql', true),
                'expires_at' => (string) ($data['expires_at'] ?? ''),
            ],
            'compatibility_only' => true,
            'canonical_owner' => 'presence_devices',
        ]);
    }

    public static function legacy_get_presence(WP_REST_Request $request): WP_REST_Response {
        $raw = $request->get_param('user_ids');
        if (is_string($raw)) {
            $raw = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $raw)))), 0, 100);
        $presence = [];
        foreach ($ids as $target_id) {
            $forward = new WP_REST_Request('GET', '/sabri-network/v2/presence/users/' . $target_id);
            $forward->set_param('user_id', $target_id);
            $result = SN_Presence_Devices::aggregate($forward);
            if (is_wp_error($result)) {
                continue;
            }
            $data = $result->get_data();
            $presence[] = [
                'user_id' => (int) ($data['user_id'] ?? $target_id),
                'status' => (string) ($data['state'] ?? 'offline'),
                'last_seen_at' => $data['last_seen_at'] ?? null,
            ];
        }
        return rest_ensure_response(['presence' => $presence, 'compatibility_only' => true, 'canonical_owner' => 'presence_devices']);
    }

    private static function legacy_device_id(int $user_id): string {
        $session = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
        $material = $session !== '' ? $session : ('user:' . $user_id);
        return 'legacy-web-' . substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 32);
    }
}