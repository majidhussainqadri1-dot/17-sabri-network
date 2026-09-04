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
        register_rest_route('sabri-network/v2', '/meetings', [
            ['methods'=>'GET','callback'=>[SN_Meet::class,'list_meetings'],'permission_callback'=>[SN_Meet::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'create_meeting'],'permission_callback'=>[SN_Meet::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/media-credentials', [
            'methods' => 'POST', 'callback' => [self::class, 'issue_credentials'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    /** Preserve caller-owned Meet idempotency semantics by proving an existing key is the same normalized request. */
    public static function create_meeting(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user = get_current_user_id();
        $raw_key = trim((string) ($request->get_header('X-Idempotency-Key') ?: $request->get_param('idempotency_key')));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $raw_key)) {
            return new WP_Error('idempotency_key_required', 'A valid idempotency key is required.', ['status'=>400]);
        }
        $request_key = hash_hmac('sha256', $user . ':' . $raw_key, wp_salt('nonce'));
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sn_meet_meetings WHERE host_id=%d AND idempotency_key=%s LIMIT 1",
            $user,
            $request_key
        ));
        if ($existing) {
            $same = self::same_meeting_create_request($existing, $request, $user);
            if (is_wp_error($same)) return $same;
            if ($same !== true) {
                return new WP_Error('sn_meet_idempotency_conflict', 'This meeting idempotency key was already used for different meeting parameters.', ['status'=>409]);
            }
        }
        return SN_Meet::create_meeting($request);
    }

    public static function issue_credentials(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = get_current_user_id();
        // The REST permission callback may already have populated the per-request File-00 cache.
        // Credential issuance is a distinct high-value transition, so force a fresh assertion immediately before it.
        SN_Membership_Assertions::clear_cache($user);
        $assertion = SN_Membership_Assertions::communication($user);
        if (is_wp_error($assertion)) return $assertion;
        if ($assertion['can_call'] !== true || $assertion['suspended'] === true) {
            return new WP_Error('sn_call_eligibility_denied', 'The current File 00 communication assertion does not permit calling.', ['status'=>403]);
        }
        $result = SN_Conference_Provider::issue_credentials($request);
        if (is_wp_error($result)) return $result;
        // Never reuse the pre-provider assertion for the delivery decision.
        SN_Membership_Assertions::clear_cache($user);
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
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
                self::append_space_owner_lock($locks, $conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
            }
        } elseif (preg_match('#^/sabri-network/v2/meetings/([A-Za-z0-9_-]{22,64})(?:/|$)#', $route, $m)) {
            $public = (string)$m[1];
            $locks[] = 'sn:f17:meet:' . substr(hash('sha256', $public), 0, 32);
            $meeting = $wpdb->get_row($wpdb->prepare("SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s", $public));
            if ($meeting) {
                if ((int)$meeting->conversation_id > 0) {
                    $locks[] = self::conversation_lock((int)$meeting->conversation_id);
                    self::append_space_owner_lock($locks, (int)$meeting->conversation_id);
                    self::append_direct_pair_lock($locks, (int)$meeting->conversation_id, $actor);
                }
                $targets = $request->get_param('user_ids');
                if (!is_array($targets)) $targets = [absint($request->get_param('user_id'))];
                foreach (array_slice(array_values(array_unique(array_filter(array_map('absint', $targets)))), 0, 100) as $target) {
                    if ($actor > 0 && $target > 0 && $actor !== $target) $locks[] = SN_Relationships::pair_lock_name($actor, $target);
                    if ((int)$meeting->host_id > 0 && $target > 0 && (int)$meeting->host_id !== $target) $locks[] = SN_Relationships::pair_lock_name((int)$meeting->host_id, $target);
                }
                if ($actor > 0 && (int)$meeting->host_id > 0 && $actor !== (int)$meeting->host_id) $locks[] = SN_Relationships::pair_lock_name($actor, (int)$meeting->host_id);
            }
        } elseif ($route === '/sabri-network/v2/calls') {
            // Call creation previously had no call-runtime lock at all. Serializing the
            // conversation, its canonical space owner (when present), and the direct
            // peer relationship ensures membership/block transitions cannot race media.
            $conversation = absint($request->get_param('conversation_id'));
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
                self::append_space_owner_lock($locks, $conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
            }
        } elseif (preg_match('#^/sabri-network/v2/calls/(\d+)(?:/|$)#', $route, $m)) {
            $call = (int)$m[1];
            $locks[] = 'sn:f17:call:' . $call;
            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
                self::append_space_owner_lock($locks, $conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
            }
        }

        if (!$locks) return $result;
        $locks = array_values(array_unique($locks)); sort($locks, SORT_STRING); $held=[];
        foreach ($locks as $lock) {
            $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
            if($ok!==1){self::release($held);return new WP_Error('sn_call_mutation_busy','The call or meeting is changing. Retry the request.',['status'=>409]);}
            $held[]=$lock;
        }
        $request->set_param('_sn_call_runtime_locks',$held);

        // Permission callbacks run before this hook and can populate the File-00 cache.
        // Once relationship/call/space-owner locks are held, refresh eligibility for
        // mutations that create/join/use media. Exit/decline/leave stays available so
        // a newly restricted account is never trapped in an active communication session.
        if (self::requires_fresh_call_eligibility($route, $request)) {
            SN_Membership_Assertions::clear_cache($actor);
            $assertion = SN_Membership_Assertions::communication($actor);
            if (is_wp_error($assertion) || ($assertion['can_call'] ?? false) !== true || ($assertion['suspended'] ?? true) === true) {
                self::release($held);
                $request->set_param('_sn_call_runtime_locks', []);
                return is_wp_error($assertion)
                    ? $assertion
                    : new WP_Error('sn_call_eligibility_denied', 'Current File 00 communication eligibility does not permit this call action.', ['status'=>403]);
            }
        }
        $target_eligibility = self::positive_target_call_eligibility($route, $request);
        if (is_wp_error($target_eligibility)) {
            self::release($held);
            $request->set_param('_sn_call_runtime_locks', []);
            return $target_eligibility;
        }
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
                    return rest_convert_error_to_response(new WP_Error('sn_meet_commit_unconfirmed','The meeting transaction could not be confirmed. Retry safely with the same idempotency key.',['status'=>503]));
                }
            }
            return $response;
        } finally {
            $held=$request->get_param('_sn_call_runtime_locks');if(is_array($held)&&$held)self::release($held);$request->set_param('_sn_call_runtime_locks',[]);
        }
    }

    /** Join call/meeting authorization to the canonical space mutation domain. */
    private static function append_space_owner_lock(array &$locks, int $conversation): void {
        global $wpdb;
        if ($conversation <= 0) return;
        $space = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SN_DB::table('spaces') . ' WHERE conversation_id=%d LIMIT 1',
            $conversation
        ));
        if ($space > 0) $locks[] = 'sn:f17:space:' . substr(hash('sha256', (string)$space), 0, 32);
    }

    private static function append_direct_pair_lock(array &$locks, int $conversation, int $actor): void {
        global $wpdb;
        if ($conversation <= 0 || $actor <= 0) return;
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $conversation));
        if ($type !== 'direct') return;
        $peer = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',
            $conversation,
            $actor
        ));
        if ($peer > 0) $locks[] = SN_Relationships::pair_lock_name($actor, $peer);
    }

    private static function requires_fresh_call_eligibility(string $route, WP_REST_Request $request): bool {
        if ($route === '/sabri-network/v2/calls' || $route === '/sabri-network/v2/meetings') return true;
        if (preg_match('#^/sabri-network/v2/calls/\d+/status$#', $route)) {
            return sanitize_key((string)$request->get_param('status')) === 'joined';
        }
        if (preg_match('#^/sabri-network/v2/calls/\d+/(?:signals|media-credentials|hand-raise|speaker-queue|breakouts|host-transfer|network-quality)#', $route)) return true;
        if (preg_match('#^/sabri-network/v2/meetings/[A-Za-z0-9_-]{22,64}/(?:join|heartbeat|invite|moderate|signals)#', $route)) return true;
        return false;
    }

    /** Positive moderator transitions must not grant call authority to a target whose File-00 state has changed. */
    private static function positive_target_call_eligibility(string $route, WP_REST_Request $request): bool|WP_Error {
        if (!preg_match('#^/sabri-network/v2/meetings/[A-Za-z0-9_-]{22,64}/moderate$#', $route)) return true;
        $action = sanitize_key((string)$request->get_param('action'));
        if (!in_array($action, ['admit','promote'], true)) return true;
        $target = absint($request->get_param('user_id'));
        if ($target <= 0) return true; // Canonical moderation owns invalid-target response shaping.
        SN_Membership_Assertions::clear_cache($target);
        $assertion = SN_Membership_Assertions::communication($target);
        if (is_wp_error($assertion)) return $assertion;
        if (($assertion['eligible'] ?? false) !== true || ($assertion['can_call'] ?? false) !== true || ($assertion['suspended'] ?? true) === true) {
            return new WP_Error('sn_call_target_eligibility_denied', 'The target account is no longer eligible for this positive meeting transition.', ['status'=>403]);
        }
        return true;
    }

    /** Compare every stable caller-controlled create field that can be reconstructed from the committed meeting. */
    private static function same_meeting_create_request(object $meeting, WP_REST_Request $request, int $user): bool|WP_Error {
        $title = trim(sanitize_text_field((string)$request->get_param('title')));
        $description = trim(sanitize_textarea_field((string)$request->get_param('description')));
        if ($title === '' || mb_strlen($title) > 191) return new WP_Error('invalid_meeting_title', 'Enter a meeting title of 1 to 191 characters.', ['status'=>400]);
        if (mb_strlen($description) > 2000) return new WP_Error('invalid_meeting_description', 'The meeting description is too long.', ['status'=>400]);
        $conversation = absint($request->get_param('conversation_id'));
        $access = $conversation > 0 && sanitize_key((string)$request->get_param('access_mode')) === 'conversation' ? 'conversation' : 'invited';
        if ($request->has_param('lobby_enabled') && !is_bool($request->get_param('lobby_enabled'))) {
            return new WP_Error('invalid_boolean', 'Boolean meeting settings must use JSON true or false.', ['status'=>400]);
        }
        $lobby = $request->has_param('lobby_enabled') ? (bool)$request->get_param('lobby_enabled') : true;
        $max = max(2, min(500, (int)apply_filters('sn_network_meet_max_participants', 100, $user)));
        $requested_limit = absint($request->get_param('participant_limit'));
        $limit = min($max, max(2, $requested_limit ?: min(100, $max)));
        if ((string)$meeting->title !== $title || (string)$meeting->description !== $description
            || (int)$meeting->conversation_id !== $conversation || (string)$meeting->access_mode !== $access
            || (bool)$meeting->lobby_enabled !== $lobby || (int)$meeting->participant_limit !== $limit) return false;
        foreach (['scheduled_start','scheduled_end'] as $field) {
            if (!$request->has_param($field)) continue;
            $value = trim((string)$request->get_param($field));
            if ($value === '') {
                if ($field === 'scheduled_end' && $meeting->$field !== null && (string)$meeting->$field !== '') return false;
                continue;
            }
            $parsed = self::parse_exact_utc($value);
            if (is_wp_error($parsed)) return $parsed;
            if ((string)$meeting->$field !== $parsed) return false;
        }
        return true;
    }

    private static function parse_exact_utc(string $value): string|WP_Error {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            return new WP_Error('invalid_meeting_datetime', 'Meeting dates must use exact UTC ISO-8601 format.', ['status'=>400]);
        }
        return $date->format('Y-m-d H:i:s');
    }

    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:' . substr(hash('sha256',(string)$id),0,32); }
    private static function release(array $locks): void { global $wpdb; foreach(array_reverse($locks) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock)); }
}
