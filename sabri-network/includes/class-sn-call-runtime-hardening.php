<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Corrective call/Meet boundary: stable relationship state, fail-closed provider issuance and post-commit confirmation. */
final class SN_Call_Runtime_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'lock_mutation'], 4, 3);
        add_filter('rest_post_dispatch', [self::class, 'verify_and_release'], 12, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 2050);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/media-credentials', [
            'methods' => 'POST', 'callback' => [self::class, 'issue_credentials'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function issue_credentials(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = get_current_user_id();
        $assertion = SN_Membership_Assertions::communication($user);
        if (is_wp_error($assertion)) return $assertion;
        if ($assertion['can_call'] !== true || $assertion['suspended'] === true) {
            return new WP_Error('sn_call_eligibility_denied', 'The current File 00 communication assertion does not permit calling.', ['status'=>403]);
        }
        $result = SN_Conference_Provider::issue_credentials($request);
        if (is_wp_error($result)) return $result;
        $fresh = SN_Membership_Assertions::communication($user);
        if (is_wp_error($fresh) || $fresh['can_call'] !== true || $fresh['suspended'] === true) {
            return new WP_Error('sn_call_eligibility_changed', 'Calling eligibility changed before credential delivery.', ['status'=>403]);
        }
        return $result;
    }

    public static function lock_mutation($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET','HEAD','OPTIONS'], true)) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        $locks = []; global $wpdb; $actor = get_current_user_id();

        if ($route === '/sabri-network/v2/meetings') {
            $locks[] = 'sn:f17:meet-host:' . substr(hash('sha256', (string)$actor), 0, 32);
            $conversation = absint($request->get_param('conversation_id'));
            if ($conversation > 0) $locks[] = self::conversation_lock($conversation);
        } elseif (preg_match('#^/sabri-network/v2/meetings/([A-Za-z0-9_-]{22,64})(?:/|$)#', $route, $m)) {
            $public = (string)$m[1];
            $locks[] = 'sn:f17:meet:' . substr(hash('sha256', $public), 0, 32);
            $meeting = $wpdb->get_row($wpdb->prepare("SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s", $public));
            if ($meeting) {
                if ((int)$meeting->conversation_id > 0) $locks[] = self::conversation_lock((int)$meeting->conversation_id);
                $targets = $request->get_param('user_ids');
                if (!is_array($targets)) $targets = [absint($request->get_param('user_id'))];
                foreach (array_slice(array_values(array_unique(array_filter(array_map('absint', $targets)))), 0, 100) as $target) {
                    if ($actor > 0 && $target > 0 && $actor !== $target) $locks[] = SN_Relationships::pair_lock_name($actor, $target);
                    if ((int)$meeting->host_id > 0 && $target > 0 && (int)$meeting->host_id !== $target) $locks[] = SN_Relationships::pair_lock_name((int)$meeting->host_id, $target);
                }
                if ($actor > 0 && (int)$meeting->host_id > 0 && $actor !== (int)$meeting->host_id) $locks[] = SN_Relationships::pair_lock_name($actor, (int)$meeting->host_id);
            }
        } elseif (preg_match('#^/sabri-network/v2/calls/(\d+)(?:/|$)#', $route, $m)) {
            $call = (int)$m[1];
            $locks[] = 'sn:f17:call:' . $call;
            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));
            if ($conversation > 0) $locks[] = self::conversation_lock($conversation);
        }

        if (!$locks) return $result;
        $locks = array_values(array_unique($locks)); sort($locks, SORT_STRING); $held=[];
        foreach ($locks as $lock) {
            $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
            if($ok!==1){self::release($held);return new WP_Error('sn_call_mutation_busy','The call or meeting is changing. Retry the request.',['status'=>409]);}
            $held[]=$lock;
        }
        $request->set_param('_sn_call_runtime_locks',$held);
        return $result;
    }

    public static function verify_and_release($response, WP_REST_Server $server, WP_REST_Request $request) {
        try {
            if (!($response instanceof WP_REST_Response) || $response->get_status() >= 400) return $response;
            $route=$request->get_route();$method=strtoupper($request->get_method());global $wpdb;
            if ($method==='POST' && $route==='/sabri-network/v2/meetings') {
                $data=$response->get_data();$public=(string)($data['meeting']['id']??'');$host=get_current_user_id();
                $row=$public!==''?$wpdb->get_row($wpdb->prepare("SELECT m.id,p.state,p.role FROM {$wpdb->prefix}sn_meet_meetings m INNER JOIN {$wpdb->prefix}sn_meet_participants p ON p.meeting_id=m.id AND p.user_id=%d WHERE m.public_id=%s",$host,$public)):null;
                if(!$row || (string)$row->role!=='host' || !in_array((string)$row->state,['admitted','joined'],true)){
                    SN_DB::audit('meet_commit_unconfirmed','meeting',0,'failure',['route'=>'create'],get_current_user_id());
                    return new WP_Error('sn_meet_commit_unconfirmed','The meeting transaction could not be confirmed. Retry safely with the same idempotency key.',['status'=>503]);
                }
            }
            return $response;
        } finally {
            $held=$request->get_param('_sn_call_runtime_locks');if(is_array($held)&&$held)self::release($held);$request->set_param('_sn_call_runtime_locks',[]);
        }
    }

    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:' . substr(hash('sha256',(string)$id),0,32); }
    private static function release(array $locks): void { global $wpdb; foreach(array_reverse($locks) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock)); }
}
