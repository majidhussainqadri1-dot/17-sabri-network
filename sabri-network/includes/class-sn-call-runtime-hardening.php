<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Corrective call/Meet boundary: stable relationship state, fail-closed provider issuance and protected signaling. */
final class SN_Call_Runtime_Hardening {
    private const LOCK_TIMEOUT = 5;
    private const CLASSIC_SIGNAL_TTL = 120;
    private const CLASSIC_SIGNAL_PREFIX = 'SNCALLSIG1:';
    private const MEET_SIGNAL_PREFIX = 'SNMEETSIG1:';

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'guard_protected_reads'], -29998, 3);
        add_filter('rest_pre_dispatch', [self::class, 'lock_mutation'], 4, 3);
        add_filter('rest_post_dispatch', [self::class, 'verify_and_release'], 12, 3);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_meet_privacy_eraser'], PHP_INT_MAX);
        add_action('rest_api_init', [self::class, 'override_routes'], 2050);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup_classic_signals'], 0);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/media-credentials', [
            'methods' => 'POST', 'callback' => [self::class, 'issue_credentials'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/signals', [
            ['methods'=>'GET','callback'=>[self::class,'get_classic_signals'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'send_classic_signal'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/signals', [
            ['methods'=>'GET','callback'=>[self::class,'get_meet_signals'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'send_meet_signal'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
    }

    public static function issue_credentials(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user = get_current_user_id();
        SN_Membership_Assertions::clear_cache($user);
        $assertion = SN_Membership_Assertions::communication($user);
        if (is_wp_error($assertion)) return $assertion;
        if ($assertion['can_call'] !== true || $assertion['suspended'] === true) {
            return new WP_Error('sn_call_eligibility_denied', 'The current File 00 communication assertion does not permit calling.', ['status'=>403]);
        }
        $result = SN_Conference_Provider::issue_credentials($request);
        if (is_wp_error($result)) return $result;
        SN_Membership_Assertions::clear_cache($user);
        $fresh = SN_Membership_Assertions::communication($user);
        if (is_wp_error($fresh) || $fresh['can_call'] !== true || $fresh['suspended'] === true) {
            return new WP_Error('sn_call_eligibility_changed', 'Calling eligibility changed before credential delivery.', ['status'=>403]);
        }
        return $result;
    }

    /** Persist classic WebRTC signaling only as an authenticated encrypted envelope. */
    public static function send_classic_signal(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        self::cleanup_classic_signals();
        if ($wpdb->query('START TRANSACTION') === false) return self::signal_error('sn_signal_transaction_failed');
        try {
            $response = SN_REST::send_signal($request);
            if (is_wp_error($response)) { $wpdb->query('ROLLBACK'); return $response; }
            $data = $response->get_data();
            $id = absint(is_array($data) ? ($data['id'] ?? 0) : 0);
            $row = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('signals') . ' WHERE id=%d FOR UPDATE', $id)) : null;
            if (!$row) throw new RuntimeException('signal_row_missing');
            $protected = self::protect_signal_payload((string)$row->payload, self::classic_signal_context($row), self::CLASSIC_SIGNAL_PREFIX);
            if (is_wp_error($protected)) throw new RuntimeException($protected->get_error_code());
            $changed = $wpdb->query($wpdb->prepare(
                'UPDATE ' . SN_DB::table('signals') . ' SET payload=%s WHERE id=%d AND payload=%s',
                $protected, $id, (string)$row->payload
            ));
            if ($changed !== 1) throw new RuntimeException('signal_encrypt_cas_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('signal_encrypt_commit_failed');
            return $response;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('call_signal_encrypt_failed','call',absint($request['id']),'failure',['reason'=>$e->getMessage()],get_current_user_id());
            return self::signal_error('sn_signal_storage_failed');
        }
    }

    /** Authorize through the canonical handler, then decrypt only still-live recipient-scoped signals. */
    public static function get_classic_signals(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        self::cleanup_classic_signals();
        $authorized = SN_REST::get_signals($request);
        if (is_wp_error($authorized)) return $authorized;
        $data = $authorized->get_data();
        $items = is_array($data['signals'] ?? null) ? $data['signals'] : [];
        $out = [];
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::CLASSIC_SIGNAL_TTL);
        foreach ($items as $item) {
            $id = absint(is_array($item) ? ($item['id'] ?? 0) : 0);
            if ($id <= 0) continue;
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . SN_DB::table('signals') . ' WHERE id=%d AND call_id=%d AND to_user_id=%d AND consumed_at IS NULL AND created_at>=%s',
                $id, absint($request['id']), get_current_user_id(), $cutoff
            ));
            if (!$row) continue;
            $payload = self::unprotect_signal_payload((string)$row->payload, self::classic_signal_context($row), self::CLASSIC_SIGNAL_PREFIX);
            if (is_wp_error($payload)) return $payload;
            self::rotate_legacy_signal_row(SN_DB::table('signals'), $row, $payload, self::classic_signal_context($row), self::CLASSIC_SIGNAL_PREFIX);
            $out[] = ['id'=>(int)$row->id,'from_user_id'=>(int)$row->from_user_id,'type'=>(string)$row->signal_type,'payload'=>$payload];
        }
        return rest_ensure_response(['signals'=>$out]);
    }

    /** Sabri Meet uses its canonical state machine, but commits the payload only after encryption succeeds. */
    public static function send_meet_signal(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) return self::signal_error('sn_meet_signal_transaction_failed');
        try {
            $response = SN_Meet::send_signal($request);
            if (is_wp_error($response)) { $wpdb->query('ROLLBACK'); return $response; }
            $data = $response->get_data();
            $id = absint(is_array($data) ? ($data['signal_id'] ?? 0) : 0);
            $table = $wpdb->prefix . 'sn_meet_signals';
            $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id)) : null;
            if (!$row) throw new RuntimeException('meet_signal_row_missing');
            $protected = self::protect_signal_payload((string)$row->payload, self::meet_signal_context($row), self::MEET_SIGNAL_PREFIX);
            if (is_wp_error($protected)) throw new RuntimeException($protected->get_error_code());
            $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET payload=%s WHERE id=%d AND payload=%s", $protected, $id, (string)$row->payload));
            if ($changed !== 1) throw new RuntimeException('meet_signal_encrypt_cas_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meet_signal_encrypt_commit_failed');
            return $response;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('meet_signal_encrypt_failed','meeting',0,'failure',['reason'=>$e->getMessage()],get_current_user_id());
            return self::signal_error('sn_meet_signal_storage_failed');
        }
    }

    /** Let SN_Meet prove the active joined session, then project decrypted live signals. */
    public static function get_meet_signals(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $authorized = SN_Meet::get_signals($request);
        if (is_wp_error($authorized)) return $authorized;
        $public = (string)$request['meeting'];
        $meeting_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s", $public));
        if ($meeting_id <= 0) return self::not_found();
        $after = absint($request->get_param('after'));
        $now = current_time('mysql', true);
        $table = $wpdb->prefix . 'sn_meet_signals';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE meeting_id=%d AND to_user_id=%d AND id>%d AND consumed_at IS NULL AND expires_at>%s ORDER BY id ASC LIMIT 100",
            $meeting_id, get_current_user_id(), $after, $now
        ));
        if (!is_array($rows)) return self::signal_error('sn_meet_signal_read_failed');
        $out=[];
        foreach ($rows as $row) {
            $payload = self::unprotect_signal_payload((string)$row->payload, self::meet_signal_context($row), self::MEET_SIGNAL_PREFIX);
            if (is_wp_error($payload)) return $payload;
            self::rotate_legacy_signal_row($table, $row, $payload, self::meet_signal_context($row), self::MEET_SIGNAL_PREFIX);
            $out[]=['id'=>(int)$row->id,'from_user_id'=>(int)$row->from_user_id,'type'=>(string)$row->signal_type,'payload'=>$payload,'created_at'=>(string)$row->created_at];
        }
        return rest_ensure_response(['signals'=>$out]);
    }

    public static function cleanup_classic_signals(): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::CLASSIC_SIGNAL_TTL);
        $consumed = gmdate('Y-m-d H:i:s', time() - 60);
        $ok = $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . SN_DB::table('signals') . ' WHERE created_at<%s OR (consumed_at IS NOT NULL AND consumed_at<%s)',
            $cutoff, $consumed
        ));
        if ($ok === false) SN_DB::audit('call_signal_cleanup_failed','system',0,'failure',[],0);
    }

    private static function protect_signal_payload(string $plain, string $context, string $prefix): string|WP_Error {
        if (str_starts_with($plain, $prefix)) return $plain;
        $cipher = SN_Communication_Crypto::encrypt($plain, $context);
        return is_wp_error($cipher) ? $cipher : $prefix . base64_encode($cipher);
    }

    private static function unprotect_signal_payload(string $stored, string $context, string $prefix): array|WP_Error {
        $plain = $stored;
        if (str_starts_with($stored, $prefix)) {
            $raw = base64_decode(substr($stored, strlen($prefix)), true);
            if (!is_string($raw) || $raw === '') return self::signal_error('sn_signal_cipher_invalid');
            $decoded = SN_Communication_Crypto::decrypt($raw, $context);
            if (is_wp_error($decoded)) return new WP_Error('sn_signal_decrypt_failed','The protected call signal could not be decrypted.',['status'=>503]);
            $plain = $decoded;
        }
        $payload = json_decode($plain, true);
        return is_array($payload) ? $payload : self::signal_error('sn_signal_payload_invalid');
    }

    /** Opportunistically encrypt authorized legacy plaintext without exposing a plaintext rewrite race. */
    private static function rotate_legacy_signal_row(string $table, object $row, array $payload, string $context, string $prefix): void {
        if (str_starts_with((string)$row->payload, $prefix)) return;
        global $wpdb;
        $plain = (string)wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        $protected = self::protect_signal_payload($plain, $context, $prefix);
        if (is_wp_error($protected)) return;
        $wpdb->query($wpdb->prepare("UPDATE $table SET payload=%s WHERE id=%d AND payload=%s", $protected, (int)$row->id, (string)$row->payload));
    }

    private static function classic_signal_context(object $row): string {
        return 'classic-call-signal|' . (int)$row->call_id . '|' . (int)$row->from_user_id . '|' . (int)$row->to_user_id . '|' . (string)$row->signal_type;
    }

    private static function meet_signal_context(object $row): string {
        return 'meet-signal|' . (int)$row->meeting_id . '|' . (int)$row->from_user_id . '|' . (int)$row->to_user_id . '|' . (string)$row->signal_type;
    }

    private static function signal_error(string $code): WP_Error {
        return new WP_Error($code, 'Protected call signaling is temporarily unavailable.', ['status'=>503]);
    }

    /** Protected call/meeting reads must not rely on a still-fresh browser/session projection after eligibility revocation. */
    public static function guard_protected_reads($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null || strtoupper($request->get_method()) !== 'GET') return $result;
        $route = $request->get_route();
        $actor = get_current_user_id();
        if ($actor <= 0) return $result;
        $is_meet = preg_match('#^/sabri-network/v2/meetings/([A-Za-z0-9_-]{22,64})/(participants|signals)$#', $route, $meet_match) === 1;
        $is_call = preg_match('#^/sabri-network/v2/calls/(\d+)/signals$#', $route, $call_match) === 1;
        if (!$is_meet && !$is_call) return $result;

        SN_Membership_Assertions::clear_cache($actor);
        $assertion = SN_Membership_Assertions::communication($actor);
        if (is_wp_error($assertion) || ($assertion['can_call'] ?? false) !== true || ($assertion['suspended'] ?? true) === true) {
            return new WP_Error('sn_call_eligibility_denied', 'Current communication eligibility does not permit this protected call read.', ['status'=>403]);
        }

        global $wpdb;
        if ($is_meet) {
            $meeting = $wpdb->get_row($wpdb->prepare(
                "SELECT id,host_id,conversation_id,access_mode,status FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s",
                (string)$meet_match[1]
            ));
            if (!$meeting || !in_array((string)$meeting->status, ['scheduled','live'], true)) return self::not_found();
            $participant = $wpdb->get_row($wpdb->prepare(
                "SELECT state FROM {$wpdb->prefix}sn_meet_participants WHERE meeting_id=%d AND user_id=%d",
                (int)$meeting->id,
                $actor
            ));
            if (!$participant || !in_array((string)$participant->state, ['admitted','joined'], true)) return self::not_found();
            if ((string)$meeting->access_mode === 'conversation' && (int)$meeting->conversation_id > 0
                && !SN_DB::is_member((int)$meeting->conversation_id, $actor)) return self::not_found();
            if ((int)$meeting->host_id > 0 && (int)$meeting->host_id !== $actor
                && (SN_DB::is_blocked($actor, (int)$meeting->host_id) || SN_DB::is_blocked((int)$meeting->host_id, $actor))) return self::not_found();
            return $result;
        }

        $call_id = (int)$call_match[1];
        $call = $wpdb->get_row($wpdb->prepare('SELECT conversation_id,status FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call_id));
        if (!$call || !in_array((string)$call->status, ['ringing','active','accepted','connected','reconnecting'], true)
            || !SN_DB::is_member((int)$call->conversation_id, $actor)) return self::not_found();
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM " . SN_DB::table('call_members') . " WHERE call_id=%d AND user_id=%d",
            $call_id,
            $actor
        ));
        if (!$member || !in_array((string)$member->status, ['invited','joined'], true)) return self::not_found();
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', (int)$call->conversation_id));
        if ($type === 'direct') {
            $peer = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',
                (int)$call->conversation_id,
                $actor
            ));
            if ($peer <= 0 || SN_DB::is_blocked($actor, $peer) || SN_DB::is_blocked($peer, $actor)) return self::not_found();
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
                self::append_direct_pair_lock($locks, $conversation, $actor);
            }
        } elseif (preg_match('#^/sabri-network/v2/meetings/([A-Za-z0-9_-]{22,64})(?:/|$)#', $route, $m)) {
            $public = (string)$m[1];
            $locks[] = 'sn:f17:meet:' . substr(hash('sha256', $public), 0, 32);
            $meeting = $wpdb->get_row($wpdb->prepare("SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s", $public));
            if ($meeting) {
                if ((int)$meeting->conversation_id > 0) {
                    $locks[] = self::conversation_lock((int)$meeting->conversation_id);
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
            $conversation = absint($request->get_param('conversation_id'));
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
            }
        } elseif (preg_match('#^/sabri-network/v2/calls/(\d+)(?:/|$)#', $route, $m)) {
            $call = (int)$m[1];
            $locks[] = 'sn:f17:call:' . $call;
            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
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

        if ($route === '/sabri-network/v2/meetings') {
            $reuse = self::validate_meeting_idempotency_reuse($request, $actor);
            if (is_wp_error($reuse)) {
                self::release($held);
                $request->set_param('_sn_call_runtime_locks', []);
                return $reuse;
            }
        }

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

    /** Reject reuse of a meeting idempotency key for materially different meeting semantics. */
    private static function validate_meeting_idempotency_reuse(WP_REST_Request $request, int $actor): bool|WP_Error {
        global $wpdb;
        $raw = trim((string)($request->get_header('X-Idempotency-Key') ?: $request->get_param('idempotency_key')));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $raw)) return true;
        $key = hash_hmac('sha256', $actor . ':' . $raw, wp_salt('nonce'));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sn_meet_meetings WHERE host_id=%d AND idempotency_key=%s LIMIT 1",
            $actor,
            $key
        ));
        if (!$row) return true;

        $title = trim(sanitize_text_field((string)$request->get_param('title')));
        $description = trim(sanitize_textarea_field((string)$request->get_param('description')));
        $conversation = absint($request->get_param('conversation_id'));
        $access = $conversation > 0 && sanitize_key((string)$request->get_param('access_mode')) === 'conversation' ? 'conversation' : 'invited';
        $lobby = $request->has_param('lobby_enabled') ? $request->get_param('lobby_enabled') : true;
        if (!is_bool($lobby)) return new WP_Error('invalid_boolean', 'Boolean meeting settings must use JSON true or false.', ['status'=>400]);
        $max = max(2, min(500, (int)apply_filters('sn_network_meet_max_participants', 100, $actor)));
        $limit = absint($request->get_param('participant_limit'));
        $limit = min($max, max(2, $limit ?: min(100, $max)));
        $start = self::normalize_meeting_datetime($request->get_param('scheduled_start'));
        if (is_wp_error($start)) return $start;
        $end = self::normalize_meeting_datetime($request->get_param('scheduled_end'));
        if (is_wp_error($end)) return $end;

        $matches = hash_equals((string)$row->title, $title)
            && hash_equals((string)$row->description, $description)
            && (int)$row->conversation_id === $conversation
            && hash_equals((string)$row->access_mode, $access)
            && (bool)$row->lobby_enabled === $lobby
            && (int)$row->participant_limit === $limit
            && ($end === null ? $row->scheduled_end === null : hash_equals((string)$row->scheduled_end, $end));
        if ($start !== null) {
            $matches = $matches && hash_equals((string)$row->scheduled_start, $start);
        } else {
            $created = strtotime((string)$row->created_at . ' UTC');
            $stored_start = strtotime((string)$row->scheduled_start . ' UTC');
            $matches = $matches && $created !== false && $stored_start !== false && abs($stored_start - $created) <= 2;
        }
        return $matches ? true : new WP_Error('sn_meet_idempotency_conflict', 'This meeting idempotency key was already used for a different request.', ['status'=>409]);
    }

    private static function normalize_meeting_datetime(mixed $value): string|WP_Error|null {
        $value = trim((string)$value);
        if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            return new WP_Error('invalid_meeting_datetime', 'Meeting dates must use exact UTC ISO-8601 format.', ['status'=>400]);
        }
        return $date->format('Y-m-d H:i:s');
    }

    /** Keep WordPress privacy retries alive when the canonical Meet eraser reports an operational failure. */
    public static function override_meet_privacy_eraser(array $erasers): array {
        if (isset($erasers['sabri-meet'])) $erasers['sabri-meet']['callback'] = [self::class, 'meet_privacy_erase_retry_safe'];
        return $erasers;
    }

    public static function meet_privacy_erase_retry_safe(string $email, int $page = 1): array {
        $result = SN_Meet::privacy_erase($email, $page);
        $messages = array_map('strval', is_array($result['messages'] ?? null) ? $result['messages'] : []);
        $failure = false;
        foreach ($messages as $message) {
            $normalized = strtolower($message);
            if (str_contains($normalized, 'could not start') || str_contains($normalized, 'failed and must be retried')) {
                $failure = true;
                break;
            }
        }
        if ($failure) {
            $result['done'] = false;
            $result['items_retained'] = true;
        }
        return $result;
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

    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:' . substr(hash('sha256',(string)$id),0,32); }
    private static function release(array $locks): void { global $wpdb; foreach(array_reverse($locks) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock)); }
    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested call or meeting is unavailable.', ['status'=>404]); }
}
