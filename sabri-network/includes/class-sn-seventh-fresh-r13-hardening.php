<?php
/** Seventh fresh review R13: realtime, receipt and notification-truth hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Seventh_Fresh_R13_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2400);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_erasers'], 2400);
    }

    public static function override_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/presence/devices/heartbeat', [
            'methods'=>'POST','callback'=>[self::class,'heartbeat'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/presence/devices/revoke', [
            'methods'=>'POST','callback'=>[self::class,'revoke_device'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/presence/users/(?P<user_id>\d+)', [
            'methods'=>'GET','callback'=>[self::class,'aggregate_presence'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/presence', [
            ['methods'=>'GET','callback'=>[self::class,'legacy_get_presence'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'legacy_heartbeat'],'permission_callback'=>$access],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/typing', [
            ['methods'=>'GET','callback'=>[self::class,'get_typing'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'set_typing'],'permission_callback'=>$access],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/receipts', [
            ['methods'=>'GET','callback'=>[self::class,'get_receipts'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'record_receipt'],'permission_callback'=>$access],
        ], true);
        register_rest_route('sabri-network/v2', '/admin/health', [
            'methods'=>'GET','callback'=>[self::class,'admin_health'],'permission_callback'=>[SN_REST::class,'admin_access'],
        ], true);
    }

    public static function override_erasers(array $erasers): array {
        if (isset($erasers['sabri-network-presence-devices'])) {
            $erasers['sabri-network-presence-devices']['callback'] = [self::class, 'erase_presence_devices'];
        }
        if (isset($erasers['sabri-network-message-receipts'])) {
            $erasers['sabri-network-message-receipts']['callback'] = [self::class, 'erase_message_receipts'];
        }
        return $erasers;
    }

    public static function heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $state = sanitize_key((string) $request->get_param('state'));
        if ($state !== '' && !in_array($state, ['online','away','dnd','offline'], true)) {
            return new WP_Error('sn_presence_state_invalid', 'Select online, away, dnd or offline.', ['status'=>400]);
        }
        if ($state === '') $request->set_param('state', 'online');
        $user = get_current_user_id();
        return self::with_locks([self::presence_lock($user)], static fn() => SN_Presence_Devices::heartbeat($request));
    }

    public static function revoke_device(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = get_current_user_id();
        return self::with_locks([self::presence_lock($user)], static fn() => SN_Presence_Devices::revoke($request));
    }

    public static function aggregate_presence(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $viewer = get_current_user_id();
        $target = absint($request['user_id']);
        if ($target <= 0) return self::presence_unavailable();
        $locks = $viewer > 0 && $viewer !== $target ? [SN_Relationships::pair_lock_name($viewer, $target)] : [];
        return self::with_locks($locks, static function () use ($request, $viewer, $target) {
            if (!SN_Policy::can_view_presence($viewer, $target)) return self::presence_unavailable();
            $response = SN_Presence_Devices::aggregate($request);
            if (is_wp_error($response)) return $response;
            if (!SN_Policy::can_view_presence($viewer, $target)) return self::presence_unavailable();
            return $response;
        });
    }

    public static function legacy_heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $status = sanitize_key((string) $request->get_param('status'));
        if ($status !== '' && !in_array($status, ['online','away','offline'], true)) {
            return new WP_Error('sn_presence_state_invalid', 'Select online, away or offline.', ['status'=>400]);
        }
        if ($status === '') $status = 'online';
        $user = get_current_user_id();
        $forward = new WP_REST_Request('POST', '/sabri-network/v2/presence/devices/heartbeat');
        $forward->set_param('device_id', self::legacy_device_id($user));
        $forward->set_param('state', $status);
        $forward->set_param('ttl', $status === 'offline' ? 30 : 90);
        $forward->set_param('label', 'Compatibility web session');
        $forward->set_param('capabilities', ['realtime']);
        $response = self::heartbeat($forward);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        return rest_ensure_response([
            'presence'=>['user_id'=>$user,'status'=>$status,'last_seen_at'=>current_time('mysql', true),'expires_at'=>(string)($data['expires_at']??'')],
            'compatibility_only'=>true,'canonical_owner'=>'presence_devices',
        ]);
    }

    public static function legacy_get_presence(WP_REST_Request $request): WP_REST_Response {
        $raw = $request->get_param('user_ids');
        if (is_string($raw)) $raw = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array)$raw)))), 0, 100);
        $presence = [];
        foreach ($ids as $target) {
            $forward = new WP_REST_Request('GET', '/sabri-network/v2/presence/users/' . $target);
            $forward->set_url_params(['user_id'=>$target]);
            $result = self::aggregate_presence($forward);
            if (is_wp_error($result)) continue;
            $data = $result->get_data();
            $presence[] = ['user_id'=>(int)($data['user_id']??$target),'status'=>(string)($data['state']??'offline'),'last_seen_at'=>$data['last_seen_at']??null];
        }
        return rest_ensure_response(['presence'=>$presence,'compatibility_only'=>true,'canonical_owner'=>'presence_devices']);
    }

    public static function set_typing(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        if ($conversation <= 0) return self::not_found();
        return self::with_locks(self::conversation_locks($conversation, $actor), static function () use ($request, $conversation, $actor) {
            $auth = self::conversation_authorized($conversation, $actor, 'message');
            if (is_wp_error($auth)) return $auth;
            return SN_REST::set_typing($request);
        });
    }

    public static function get_typing(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        if ($conversation <= 0) return self::not_found();
        return self::with_locks(self::conversation_locks($conversation, $actor), static function () use ($request, $conversation, $actor) {
            $auth = self::conversation_authorized($conversation, $actor, 'message');
            if (is_wp_error($auth)) return $auth;
            $response = SN_REST::get_typing($request);
            if (is_wp_error($response)) return $response;
            $auth = self::conversation_authorized($conversation, $actor, 'message');
            return is_wp_error($auth) ? $auth : $response;
        });
    }

    public static function get_receipts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        if ($conversation <= 0) return self::not_found();
        $locks = array_merge(self::conversation_locks($conversation, $actor), [self::receipt_user_lock($actor)]);
        return self::with_locks($locks, static function () use ($request, $conversation, $actor) {
            $auth = self::conversation_authorized($conversation, $actor, 'message');
            if (is_wp_error($auth)) return $auth;
            $response = SN_Messages::get_receipts($request);
            if (is_wp_error($response)) return $response;
            $auth = self::conversation_authorized($conversation, $actor, 'message');
            return is_wp_error($auth) ? $auth : $response;
        });
    }

    public static function record_receipt(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        if ($conversation <= 0) return self::not_found();
        $locks = array_merge(self::conversation_locks($conversation, $actor), [self::receipt_user_lock($actor)]);
        return self::with_locks($locks, static function () use ($request, $conversation, $actor) {
            $auth = self::conversation_authorized($conversation, $actor, 'message');
            if (is_wp_error($auth)) return $auth;
            return SN_Message_Integrity::record_receipt($request);
        });
    }

    public static function erase_presence_devices(string $email, int $page=1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::erase_done();
        $uid = (int)$user->ID;
        $lock = self::presence_lock($uid);
        if (!self::acquire($lock)) return self::erase_retry(__('Presence-device erasure is busy and must be retried.', 'sabri-network'));
        try {
            $deleted = $wpdb->delete(SN_DB::table('presence_devices'), ['user_id'=>$uid], ['%d']);
            if ($deleted === false) return self::erase_retry(__('Presence devices could not be erased and must be retried.', 'sabri-network'));
            $remaining = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('presence_devices').' WHERE user_id=%d LIMIT 1', $uid));
            if ($wpdb->last_error !== '') return self::erase_retry(__('Presence-device erasure verification failed and must be retried.', 'sabri-network'));
            return ['items_removed'=>$deleted>0,'items_retained'=>$remaining!==null,'messages'=>[],'done'=>$remaining===null];
        } finally {
            self::release($lock);
        }
    }

    public static function erase_message_receipts(string $email, int $page=1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::erase_done();
        $uid = (int)$user->ID;
        $lock = self::receipt_user_lock($uid);
        if (!self::acquire($lock)) return self::erase_retry(__('Message-receipt erasure is busy and must be retried.', 'sabri-network'));
        try {
            $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM '.SN_DB::table('message_receipts').' WHERE user_id=%d ORDER BY id ASC LIMIT 500', $uid));
            if (!is_array($ids) || $wpdb->last_error !== '') return self::erase_retry(__('Message receipts could not be enumerated and must be retried.', 'sabri-network'));
            $ids = array_values(array_filter(array_map('absint', $ids)));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM '.SN_DB::table('message_receipts')." WHERE id IN ($placeholders)", ...$ids));
                if ($deleted === false) return self::erase_retry(__('Message receipts could not be erased and must be retried.', 'sabri-network'));
            } else {
                $deleted = 0;
            }
            $remaining = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('message_receipts').' WHERE user_id=%d LIMIT 1', $uid));
            if ($wpdb->last_error !== '') return self::erase_retry(__('Message-receipt erasure verification failed and must be retried.', 'sabri-network'));
            return ['items_removed'=>$deleted>0,'items_retained'=>$remaining!==null,'messages'=>[],'done'=>$remaining===null];
        } finally {
            self::release($lock);
        }
    }

    public static function admin_health(): WP_REST_Response {
        $response = SN_REST::admin_health();
        $data = $response->get_data();
        if (is_array($data)) {
            $data['notification_adapter'] = self::file19_ready();
            $data['notification_owner'] = 'file-19';
            $response->set_data($data);
        }
        return $response;
    }

    public static function file19_ready(): bool {
        $listener = has_action('sn_network_notification_requested') !== false;
        return (bool) apply_filters('sn_network_file19_notification_adapter_ready', $listener);
    }

    private static function conversation_authorized(int $conversation, int $actor, string $context): bool|WP_Error {
        global $wpdb;
        if ($actor <= 0 || !SN_DB::is_member($conversation, $actor)) return self::not_found();
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,type,status FROM '.SN_DB::table('conversations').' WHERE id=%d', $conversation));
        if (!$row) return self::not_found();
        if ((string)$row->type !== 'direct') return true;
        $peer = self::direct_peer($conversation, $actor);
        if ($peer <= 0) return self::not_found();
        $contact = SN_Policy::can_contact($actor, $peer, $context);
        return is_wp_error($contact) ? new WP_Error('not_found', 'The requested communication object is unavailable.', ['status'=>404]) : true;
    }

    private static function conversation_locks(int $conversation, int $actor): array {
        $locks = [self::conversation_lock($conversation)];
        $peer = self::direct_peer($conversation, $actor);
        if ($peer > 0) $locks[] = SN_Relationships::pair_lock_name($actor, $peer);
        return $locks;
    }

    private static function direct_peer(int $conversation, int $actor): int {
        global $wpdb;
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM '.SN_DB::table('conversations').' WHERE id=%d', $conversation));
        if ($type !== 'direct') return 0;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1', $conversation, $actor));
    }

    private static function legacy_device_id(int $user): string {
        return 'legacy-web-session:' . substr(hash_hmac('sha256', (string)$user, wp_salt('auth').'|sn-legacy-presence-v2'), 0, 32);
    }

    private static function presence_lock(int $user): string { return 'sn:f17:presence:' . substr(hash('sha256', (string)$user), 0, 32); }
    private static function receipt_user_lock(int $user): string { return 'sn:f17:receipt-user:' . substr(hash('sha256', (string)$user), 0, 32); }
    private static function conversation_lock(int $conversation): string { return 'sn:f17:conversation:' . substr(hash('sha256', (string)$conversation), 0, 32); }

    private static function with_locks(array $locks, callable $callback) {
        $locks = array_values(array_unique(array_filter($locks))); sort($locks, SORT_STRING); $held = [];
        try {
            foreach ($locks as $lock) {
                if (!self::acquire((string)$lock)) return new WP_Error('sn_realtime_busy', 'The realtime state is changing. Retry the request.', ['status'=>409]);
                $held[] = (string)$lock;
            }
            return $callback();
        } finally {
            foreach (array_reverse($held) as $lock) self::release($lock);
        }
    }

    private static function acquire(string $lock): bool {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT)) === 1;
    }

    private static function release(string $lock): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }

    private static function erase_done(): array { return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true]; }
    private static function erase_retry(string $message): array { return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false]; }
    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested communication object is unavailable.', ['status'=>404]); }
    private static function presence_unavailable(): WP_Error { return new WP_Error('sn_presence_unavailable', 'Presence is unavailable.', ['status'=>404]); }
}
