<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Realtime_Runtime_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 1960);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/presence/devices/heartbeat', ['methods'=>'POST','callback'=>[self::class,'heartbeat'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/presence/users/(?P<user_id>\d+)', ['methods'=>'GET','callback'=>[self::class,'aggregate'],'permission_callback'=>[SN_REST::class,'access']], true);
    }

    public static function heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = get_current_user_id();
        return self::with_locks([self::presence_lock($user)], static fn() => SN_Presence_Devices::heartbeat($request));
    }

    public static function aggregate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $viewer = get_current_user_id(); $target = absint($request['user_id']);
        $locks = [];
        if ($viewer > 0 && $target > 0 && $viewer !== $target) $locks[] = SN_Relationships::pair_lock_name($viewer, $target);
        return self::with_locks($locks, static function () use ($request, $viewer, $target) {
            if ($target <= 0 || !SN_Policy::can_view_presence($viewer, $target)) return new WP_Error('sn_presence_unavailable', 'Presence is unavailable.', ['status'=>404]);
            $response = SN_Presence_Devices::aggregate($request);
            if (is_wp_error($response)) return $response;
            if (!SN_Policy::can_view_presence($viewer, $target)) return new WP_Error('sn_presence_unavailable', 'Presence is unavailable.', ['status'=>404]);
            return $response;
        });
    }

    private static function presence_lock(int $user): string { return 'sn:f17:presence:' . substr(hash('sha256', (string) $user), 0, 32); }

    private static function with_locks(array $locks, callable $callback) {
        global $wpdb; $locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];
        try {
            foreach ($locks as $lock) {
                $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
                if($ok!==1)return new WP_Error('sn_realtime_busy','The realtime state is changing. Retry the request.',['status'=>409]);
                $held[]=$lock;
            }
            return $callback();
        } finally {
            foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
}
