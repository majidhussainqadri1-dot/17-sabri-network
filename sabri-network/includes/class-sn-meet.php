<?php
defined('ABSPATH') || exit;

/**
 * Sabri Meet control plane.
 *
 * File 17 owns meeting identity, admission, participant/session state, signaling,
 * moderation, audit and privacy. Audio/video transport remains provider-gated:
 * no SFU, caption, recording or end-to-end-encryption claim is made without an
 * approved adapter and runtime evidence.
 */
final class SN_Meet {
    public const DB_VERSION = '1.0.0';
    private const NS = 'sabri-network/v2';
    private const MAX_SIGNAL_BYTES = 65536;
    private const SESSION_TTL = 90;
    private const SIGNAL_TTL = 120;
    private const MAX_TITLE = 191;
    private const MAX_DESCRIPTION = 2000;
    private const MAX_INVITES = 100;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'privacy_erasers']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('meetings') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(64) NOT NULL,
            host_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(191) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            access_mode VARCHAR(20) NOT NULL DEFAULT 'invited',
            lobby_enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            participant_limit SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            scheduled_start DATETIME NULL,
            scheduled_end DATETIME NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            settings LONGTEXT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            idempotency_key CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY host_idempotency (host_id,idempotency_key),
            KEY host_status (host_id,status),
            KEY conversation_status (conversation_id,status)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('participants') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'participant',
            state VARCHAR(20) NOT NULL DEFAULT 'invited',
            invited_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            admitted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            media_policy LONGTEXT NULL,
            joined_at DATETIME NULL,
            left_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY meeting_user (meeting_id,user_id),
            KEY meeting_state (meeting_id,state),
            KEY user_state (user_id,state)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('sessions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            session_hash CHAR(64) NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'waiting',
            media_state LONGTEXT NULL,
            joined_at DATETIME NULL,
            left_at DATETIME NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY meeting_session (meeting_id,session_hash),
            KEY meeting_user_state (meeting_id,user_id,state),
            KEY last_seen_at (last_seen_at)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('signals') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id BIGINT UNSIGNED NOT NULL,
            from_user_id BIGINT UNSIGNED NOT NULL,
            to_user_id BIGINT UNSIGNED NOT NULL,
            signal_type VARCHAR(32) NOT NULL,
            payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY recipient_queue (meeting_id,to_user_id,consumed_at,id),
            KEY expires_at (expires_at)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('events') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_type VARCHAR(64) NOT NULL,
            subject_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY meeting_event (meeting_id,id),
            KEY actor_event (actor_id,event_type),
            KEY subject_event (subject_user_id,event_type)
        ) $charset;");
        update_option('sn_meet_db_version', self::DB_VERSION, false);
        update_option('sn_meet_schema_version', self::DB_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_meet_db_version', '') !== self::DB_VERSION) self::install();
    }

    public static function register_routes(): void {
        $access = [self::class, 'access'];
        register_rest_route(self::NS, '/meetings', [
            ['methods' => 'GET', 'callback' => [self::class, 'list_meetings'], 'permission_callback' => $access],
            ['methods' => 'POST', 'callback' => [self::class, 'create_meeting'], 'permission_callback' => $access],
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})', [
            'methods' => 'GET', 'callback' => [self::class, 'get_meeting'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/invite', [
            'methods' => 'POST', 'callback' => [self::class, 'invite'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/join', [
            'methods' => 'POST', 'callback' => [self::class, 'join'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/heartbeat', [
            'methods' => 'POST', 'callback' => [self::class, 'heartbeat'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/leave', [
            'methods' => 'POST', 'callback' => [self::class, 'leave'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/participants', [
            'methods' => 'GET', 'callback' => [self::class, 'participants'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/moderate', [
            'methods' => 'POST', 'callback' => [self::class, 'moderate'], 'permission_callback' => $access,
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/signals', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_signals'], 'permission_callback' => $access],
            ['methods' => 'POST', 'callback' => [self::class, 'send_signal'], 'permission_callback' => $access],
        ]);
        register_rest_route(self::NS, '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/signals/ack', [
            'methods' => 'POST', 'callback' => [self::class, 'ack_signals'], 'permission_callback' => $access,
        ]);
    }

    public static function access(): bool|WP_Error {
        return SN_REST::access();
    }

    public static function create_meeting(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $title = trim(sanitize_text_field((string) $request->get_param('title')));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) return new WP_Error('invalid_meeting_title', 'Enter a meeting title of 1 to 191 characters.', ['status' => 400]);
        $description = trim(sanitize_textarea_field((string) $request->get_param('description')));
        if (mb_strlen($description) > self::MAX_DESCRIPTION) return new WP_Error('invalid_meeting_description', 'The meeting description is too long.', ['status' => 400]);
        $conversation_id = absint($request->get_param('conversation_id'));
        if ($conversation_id > 0 && !SN_DB::is_member($conversation_id, $user_id)) return self::not_found();
        if (!SN_Policy::consume_rate_limit('meet_create', (string) $user_id, 30, DAY_IN_SECONDS)) return self::rate_limited();
        $raw_key = trim((string) ($request->get_header('X-Idempotency-Key') ?: $request->get_param('idempotency_key')));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $raw_key)) return new WP_Error('idempotency_key_required', 'A valid idempotency key is required.', ['status' => 400]);
        $request_key = hash_hmac('sha256', $user_id . ':' . $raw_key, wp_salt('nonce'));
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('meetings') . ' WHERE host_id=%d AND idempotency_key=%s LIMIT 1', $user_id, $request_key));
        if ($existing) return rest_ensure_response(['meeting' => self::format_meeting($existing, $user_id), 'duplicate' => true]);
        $scheduled_start = self::parse_datetime($request->get_param('scheduled_start'));
        if (is_wp_error($scheduled_start)) return $scheduled_start;
        $scheduled_end = self::parse_datetime($request->get_param('scheduled_end'));
        if (is_wp_error($scheduled_end)) return $scheduled_end;
        $now_ts = time();
        $start_ts = $scheduled_start ? strtotime($scheduled_start . ' UTC') : $now_ts;
        $end_ts = $scheduled_end ? strtotime($scheduled_end . ' UTC') : 0;
        if ($start_ts > $now_ts + YEAR_IN_SECONDS) return new WP_Error('meeting_schedule_too_far', 'Meetings may be scheduled up to one year ahead.', ['status' => 400]);
        if ($end_ts && ($end_ts <= $start_ts || $end_ts > $start_ts + DAY_IN_SECONDS)) return new WP_Error('invalid_meeting_end', 'The meeting end must be after the start and within 24 hours.', ['status' => 400]);
        $max_limit = max(2, min(500, (int) apply_filters('sn_network_meet_max_participants', 100, $user_id)));
        $participant_limit = absint($request->get_param('participant_limit'));
        $participant_limit = min($max_limit, max(2, $participant_limit ?: min(100, $max_limit)));
        $access_mode = $conversation_id > 0 && sanitize_key((string) $request->get_param('access_mode')) === 'conversation' ? 'conversation' : 'invited';
        $lobby_enabled = self::bool_param($request, 'lobby_enabled', true);
        if (is_wp_error($lobby_enabled)) return $lobby_enabled;
        $now = current_time('mysql', true);
        $public_id = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::new_public_id();
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('meetings') . ' WHERE public_id=%s', $candidate));
            if (!$exists) { $public_id = $candidate; break; }
        }
        if ($public_id === '') return new WP_Error('meeting_identifier_unavailable', 'A secure meeting identifier could not be allocated.', ['status' => 503]);
        $meeting_id = 0;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::database_error();
        }
        try {
            $inserted = $wpdb->insert(self::table('meetings'), [
                'public_id' => $public_id,'host_id' => $user_id,'conversation_id' => $conversation_id,'title' => $title,'description' => $description,
                'status' => $start_ts > $now_ts ? 'scheduled' : 'live','access_mode' => $access_mode,'lobby_enabled' => $lobby_enabled ? 1 : 0,
                'is_locked' => 0,'participant_limit' => $participant_limit,'scheduled_start' => $scheduled_start ?: $now,'scheduled_end' => $scheduled_end,
                'started_at' => $start_ts <= $now_ts ? $now : null,'settings' => wp_json_encode(['mode' => 'video', 'recording' => false]),
                'version' => 1,'idempotency_key' => $request_key,'created_at' => $now,'updated_at' => $now,
            ]);
            if ($inserted === false) throw new RuntimeException('meeting_insert_failed');
            $meeting_id = (int) $wpdb->insert_id;
            if ($wpdb->insert(self::table('participants'), [
                'meeting_id' => $meeting_id,'user_id' => $user_id,'role' => 'host','state' => 'admitted','invited_by' => $user_id,'admitted_by' => $user_id,
                'media_policy' => '{}','version' => 1,'created_at' => $now,'updated_at' => $now,
            ]) === false) throw new RuntimeException('host_participant_insert_failed');
            if (!self::insert_event($meeting_id, $user_id, 'meeting_created', $user_id, ['conversation_id' => $conversation_id,'access_mode' => $access_mode,'participant_limit' => $participant_limit])) throw new RuntimeException('meeting_event_insert_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('meetings') . ' WHERE host_id=%d AND idempotency_key=%s LIMIT 1', $user_id, $request_key));
            if ($race) return rest_ensure_response(['meeting' => self::format_meeting($race, $user_id), 'duplicate' => true]);
            SN_DB::audit('meet_create_failed', 'meeting', $meeting_id, 'failure');
            return self::database_error();
        }
        SN_DB::audit('meet_created', 'meeting', $meeting_id, 'success', ['conversation_id' => $conversation_id]);
        do_action('sn_network_meet_created', $meeting_id, $user_id, $conversation_id);
        $row = self::meeting_by_id($meeting_id);
        return rest_ensure_response(['meeting' => self::format_meeting($row, $user_id), 'duplicate' => false]);
    }

    public static function list_meetings(): WP_REST_Response { global $wpdb; $user_id=get_current_user_id(); $rows=$wpdb->get_results($wpdb->prepare('SELECT m.*,p.role participant_role,p.state participant_state FROM '.self::table('meetings')." m INNER JOIN ".self::table('participants')." p ON p.meeting_id=m.id AND p.user_id=%d WHERE m.status IN ('scheduled','live','ended','cancelled') ORDER BY FIELD(m.status,'live','scheduled','ended','cancelled'),COALESCE(m.scheduled_start,m.created_at) DESC LIMIT 100",$user_id)); return rest_ensure_response(['meetings'=>array_map(fn($row)=>self::format_meeting($row,$user_id),$rows)]); }

    public static function get_meeting(WP_REST_Request $request): WP_REST_Response|WP_Error { $meeting=self::meeting_by_public((string)$request['meeting']);$user_id=get_current_user_id();if(!$meeting||!self::can_discover($meeting,$user_id))return self::not_found();return rest_ensure_response(['meeting'=>self::format_meeting($meeting,$user_id),'participant'=>self::format_participant(self::participant_row((int)$meeting->id,$user_id),$user_id,true)]); }

    public static function invite(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$meeting=self::meeting_by_public((string)$request['meeting']);$actor_id=get_current_user_id();$actor=$meeting?self::participant_row((int)$meeting->id,$actor_id):null;
        if(!$meeting||!$actor||!in_array((string)$actor->role,['host','cohost'],true))return self::not_found();
        if(!in_array((string)$meeting->status,['scheduled','live'],true))return new WP_Error('meeting_closed','Invitations are closed for this meeting.',['status'=>409]);
        if(!SN_Policy::consume_rate_limit('meet_invite',$actor_id.':'.(int)$meeting->id,150,DAY_IN_SECONDS))return self::rate_limited();
        $ids=$request->get_param('user_ids');if(!is_array($ids))$ids=[absint($request->get_param('user_id'))];$ids=array_slice(array_values(array_unique(array_filter(array_map('absint',$ids)))),0,100);if(!$ids)return new WP_Error('invalid_invitees','Select at least one eligible member.',['status'=>400]);
        $invited=$skipped=$failed=0;
        foreach($ids as $target_id){
            if($target_id===$actor_id||!get_user_by('id',$target_id)||SN_Policy::is_suspended($target_id)||SN_DB::is_blocked($actor_id,$target_id)||SN_DB::is_blocked((int)$meeting->host_id,$target_id)){$skipped++;continue;}
            $age_state=SN_Policy::age_state($target_id);if($age_state==='unknown'||($age_state==='minor'&&(!SN_Policy::has_guardian_consent($target_id)||!(bool)apply_filters('sn_network_minor_meet_allowed',false,$target_id,$actor_id,(int)$meeting->id)))){$skipped++;continue;}
            if((int)$meeting->conversation_id>0&&(string)$meeting->access_mode==='conversation'&&!SN_DB::is_member((int)$meeting->conversation_id,$target_id)){$skipped++;continue;}
            $notify=false;
            if ($wpdb->query('START TRANSACTION') === false) {
                return self::database_error();
            }
            try{
                $locked_meeting=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE id=%d FOR UPDATE',(int)$meeting->id));
                $locked_actor=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$actor_id));
                if(!$locked_meeting||!in_array((string)$locked_meeting->status,['scheduled','live'],true)||!$locked_actor||!in_array((string)$locked_actor->role,['host','cohost'],true)||((string)$locked_actor->role==='cohost'&&!in_array((string)$locked_actor->state,['admitted','joined'],true)))throw new DomainException('invite_authority_changed');
                $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$target_id));if($existing&&!in_array((string)$existing->state,['denied','removed'],true))throw new DomainException('already_invited');
                $now=current_time('mysql',true);$data=['meeting_id'=>(int)$meeting->id,'user_id'=>$target_id,'role'=>'participant','state'=>'invited','invited_by'=>$actor_id,'admitted_by'=>0,'media_policy'=>'{}','joined_at'=>null,'left_at'=>null,'last_seen_at'=>null,'version'=>$existing?((int)$existing->version+1):1,'created_at'=>$existing?(string)$existing->created_at:$now,'updated_at'=>$now];
                $ok=$existing?$wpdb->update(self::table('participants'),$data,['id'=>(int)$existing->id,'version'=>(int)$existing->version]):$wpdb->insert(self::table('participants'),$data);if(($existing&&$ok!==1)||(!$existing&&$ok===false))throw new RuntimeException('invite_write_failed');
                if(!self::insert_event((int)$meeting->id,$actor_id,'participant_invited',$target_id))throw new RuntimeException('meeting_event_insert_failed');
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');$notify=true;$invited++;
            }catch(DomainException $e){$wpdb->query('ROLLBACK');$skipped++;}catch(Throwable $e){$wpdb->query('ROLLBACK');$failed++;SN_DB::audit('meet_invite_failed','meeting',(int)$meeting->id,'failure',['target_id'=>$target_id]);}
            if($notify)SN_DB::add_notification($target_id,'meet_invitation','Sabri Meet invitation',(string)$meeting->title,'meeting',(int)$meeting->id);
        }
        $outcome=$failed>0?'failure':'success';SN_DB::audit('meet_invites_updated','meeting',(int)$meeting->id,$outcome,['invited'=>$invited,'skipped'=>$skipped,'failed'=>$failed]);return new WP_REST_Response(['invited'=>$invited,'skipped'=>$skipped,'failed'=>$failed,'partial'=>$failed>0],$failed>0?207:200);
    }

    public static function join(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$user_id=get_current_user_id();$public_id=(string)$request['meeting'];$session_id=trim((string)$request->get_param('session_id'));if(!preg_match('/^[A-Za-z0-9._:-]{32,128}$/',$session_id))return new WP_Error('invalid_session_id','A valid device session identifier is required.',['status'=>400]);if(!SN_Policy::consume_rate_limit('meet_join',(string)$user_id,120,HOUR_IN_SECONDS))return self::rate_limited();$session_hash=self::session_hash($session_id,$user_id);$now=current_time('mysql',true);$meeting=null;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::database_error();
        }
        try{
            $meeting=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE public_id=%s FOR UPDATE',$public_id));if(!$meeting||!in_array((string)$meeting->status,['scheduled','live'],true))throw new DomainException('meeting_unavailable');
            $age_state=SN_Policy::age_state($user_id);if($age_state==='unknown'||($age_state==='minor'&&(!SN_Policy::has_guardian_consent($user_id)||!(bool)apply_filters('sn_network_minor_meet_allowed',false,$user_id,(int)$meeting->host_id,(int)$meeting->id))))throw new DomainException('meeting_policy_denied');if(SN_DB::is_blocked($user_id,(int)$meeting->host_id))throw new DomainException('meeting_policy_denied');
            $participant=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$user_id));
            if(!$participant&&(string)$meeting->access_mode==='conversation'&&(int)$meeting->conversation_id>0&&SN_DB::is_member((int)$meeting->conversation_id,$user_id)){$wpdb->insert(self::table('participants'),['meeting_id'=>(int)$meeting->id,'user_id'=>$user_id,'role'=>'participant','state'=>'invited','invited_by'=>(int)$meeting->host_id,'admitted_by'=>0,'media_policy'=>'{}','version'=>1,'created_at'=>$now,'updated_at'=>$now]);$participant=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$user_id));}
            if(!$participant||in_array((string)$participant->state,['denied','removed'],true))throw new DomainException('meeting_not_invited');if((bool)$meeting->is_locked&&!in_array((string)$participant->role,['host','cohost'],true))throw new DomainException('meeting_locked');
            $auto_admit=in_array((string)$participant->role,['host','cohost'],true)||!(bool)$meeting->lobby_enabled||(string)$participant->state==='admitted';if($auto_admit){$active_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('participants')." WHERE meeting_id=%d AND state IN ('admitted','joined')",(int)$meeting->id));$already_active=in_array((string)$participant->state,['admitted','joined'],true);if(!$already_active&&$active_count>=(int)$meeting->participant_limit)throw new DomainException('meeting_full');}
            $session=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sessions').' WHERE meeting_id=%d AND session_hash=%s FOR UPDATE',(int)$meeting->id,$session_hash));if(!$session){$user_sessions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('sessions')." WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",(int)$meeting->id,$user_id));$all_sessions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('sessions')." WHERE meeting_id=%d AND state IN ('waiting','joined')",(int)$meeting->id));if($user_sessions>=3||$all_sessions>=((int)$meeting->participant_limit*3))throw new DomainException('session_limit_reached');}
            $session_data=['meeting_id'=>(int)$meeting->id,'user_id'=>$user_id,'session_hash'=>$session_hash,'state'=>$auto_admit?'joined':'waiting','media_state'=>'{}','joined_at'=>$auto_admit?$now:null,'left_at'=>null,'last_seen_at'=>$now,'created_at'=>$session?(string)$session->created_at:$now];if($session){if($wpdb->update(self::table('sessions'),$session_data,['id'=>(int)$session->id])===false)throw new RuntimeException('session_update_failed');}elseif($wpdb->insert(self::table('sessions'),$session_data)===false)throw new RuntimeException('session_insert_failed');
            $pstate=$auto_admit?'joined':'waiting';$pdata=['state'=>$pstate,'joined_at'=>$auto_admit?($participant->joined_at?:$now):$participant->joined_at,'left_at'=>null,'last_seen_at'=>$now,'version'=>(int)$participant->version+1,'updated_at'=>$now];if($wpdb->update(self::table('participants'),$pdata,['id'=>(int)$participant->id,'version'=>(int)$participant->version])!==1)throw new RuntimeException('participant_join_failed');
            if($auto_admit&&(string)$meeting->status==='scheduled'){$start_ts=strtotime((string)$meeting->scheduled_start.' UTC');if(!$start_ts||$start_ts<=time()+300){if($wpdb->update(self::table('meetings'),['status'=>'live','started_at'=>$meeting->started_at?:$now,'version'=>(int)$meeting->version+1,'updated_at'=>$now],['id'=>(int)$meeting->id,'version'=>(int)$meeting->version])!==1)throw new RuntimeException('meeting_start_failed');}}
            if(!self::insert_event((int)$meeting->id,$user_id,$auto_admit?'participant_joined':'participant_waiting',$user_id,['session_id'=>$session?(int)$session->id:(int)$wpdb->insert_id]))throw new RuntimeException('meeting_event_insert_failed');if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return match($e->getMessage()){'meeting_unavailable'=>new WP_Error('meeting_unavailable','This meeting is no longer available.',['status'=>410]),'meeting_policy_denied'=>new WP_Error('meeting_policy_denied','This account is not eligible to join the meeting.',['status'=>403]),'meeting_not_invited'=>self::not_found(),'meeting_locked'=>new WP_Error('meeting_locked','The host has locked this meeting.',['status'=>423]),'meeting_full'=>new WP_Error('meeting_full','The meeting has reached its participant limit.',['status'=>409]),'session_limit_reached'=>new WP_Error('meeting_session_limit','The meeting session limit has been reached for this account or room.',['status'=>409]),default=>self::database_error()};}
        $meeting=self::meeting_by_public($public_id);$participant=self::participant_row((int)$meeting->id,$user_id);$session=self::session_row((int)$meeting->id,$session_hash,$user_id);return rest_ensure_response(['meeting'=>self::format_meeting($meeting,$user_id),'participant'=>self::format_participant($participant,$user_id,true),'session'=>self::format_session($session),'media'=>self::media_config($meeting,$participant,$session)]);
    }

    public static function heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$user_id=get_current_user_id();$session_id=trim((string)$request->get_param('session_id'));if(!preg_match('/^[A-Za-z0-9._:-]{32,128}$/',$session_id))return new WP_Error('invalid_session_id','A valid device session identifier is required.',['status'=>400]);$session_hash=self::session_hash($session_id,$user_id);$media=self::media_state($request->get_param('media'));if(is_wp_error($media))return $media;$now=current_time('mysql',true);$meeting=$participant=$session=null;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::database_error();
        }
        try{$meeting=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE public_id=%s FOR UPDATE',(string)$request['meeting']));if(!$meeting||(string)$meeting->status!=='live')throw new DomainException('meeting_unavailable');$participant=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$user_id));$session=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sessions').' WHERE meeting_id=%d AND user_id=%d AND session_hash=%s FOR UPDATE',(int)$meeting->id,$user_id,$session_hash));if(!$participant||!$session||(string)$session->state!=='joined'||!in_array((string)$participant->state,['admitted','joined'],true))throw new DomainException('admission_required');$last_seen=strtotime((string)$session->last_seen_at.' UTC');if(!$last_seen||$last_seen<time()-self::SESSION_TTL)throw new DomainException('session_expired');$policy=self::decode_json((string)$participant->media_policy);if(!empty($policy['forced_muted']))$media['mic']=false;if($wpdb->update(self::table('sessions'),['media_state'=>wp_json_encode($media),'last_seen_at'=>$now],['id'=>(int)$session->id,'state'=>'joined'])!==1)throw new RuntimeException('session_heartbeat_failed');if($wpdb->update(self::table('participants'),['state'=>'joined','last_seen_at'=>$now,'version'=>(int)$participant->version+1,'updated_at'=>$now],['id'=>(int)$participant->id,'version'=>(int)$participant->version])!==1)throw new RuntimeException('participant_heartbeat_failed');if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');}catch(Throwable $e){$wpdb->query('ROLLBACK');return match($e->getMessage()){'meeting_unavailable'=>new WP_Error('meeting_unavailable','This meeting is no longer live.',['status'=>410]),'admission_required'=>new WP_Error('meeting_admission_required','Host admission is required before using meeting media.',['status'=>403]),'session_expired'=>new WP_Error('meeting_session_expired','This device session expired. Join the meeting again.',['status'=>409]),default=>self::database_error()};}$clean_media=self::media_config($meeting,$participant,$session);return rest_ensure_response(['ok'=>true,'meeting_version'=>(int)$meeting->version,'media'=>$clean_media]);
    }

    public static function leave(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$user_id=get_current_user_id();$public_id=(string)$request['meeting'];$session_id=trim((string)$request->get_param('session_id'));if(!preg_match('/^[A-Za-z0-9._:-]{32,128}$/',$session_id))return new WP_Error('invalid_session_id','A valid device session identifier is required.',['status'=>400]);$session_hash=self::session_hash($session_id,$user_id);$now=current_time('mysql',true);$meeting=$participant=$session=null;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::database_error();
        }
        try{$meeting=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE public_id=%s FOR UPDATE',$public_id));if(!$meeting)throw new DomainException('meeting_not_found');$participant=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$user_id));$session=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sessions').' WHERE meeting_id=%d AND user_id=%d AND session_hash=%s FOR UPDATE',(int)$meeting->id,$user_id,$session_hash));if(!$participant||!$session||in_array((string)$participant->state,['denied','removed'],true))throw new DomainException('session_not_found');if((string)$session->state==='left'){if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');return rest_ensure_response(['left'=>true,'duplicate'=>true]);}if($wpdb->update(self::table('sessions'),['state'=>'left','left_at'=>$now,'last_seen_at'=>$now],['id'=>(int)$session->id,'state'=>(string)$session->state])!==1)throw new RuntimeException('session_leave_failed');$other_sessions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('sessions')." WHERE meeting_id=%d AND user_id=%d AND id<>%d AND state='joined' AND last_seen_at>=%s",(int)$meeting->id,$user_id,(int)$session->id,gmdate('Y-m-d H:i:s',time()-self::SESSION_TTL)));if($other_sessions===0&&!in_array((string)$participant->state,['left','denied','removed'],true)){if($wpdb->update(self::table('participants'),['state'=>'left','left_at'=>$now,'last_seen_at'=>$now,'version'=>(int)$participant->version+1,'updated_at'=>$now],['id'=>(int)$participant->id,'version'=>(int)$participant->version])!==1)throw new RuntimeException('participant_leave_failed');}if(!self::insert_event((int)$meeting->id,$user_id,'participant_left',$user_id,['session_id'=>(int)$session->id]))throw new RuntimeException('meeting_event_insert_failed');if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');}catch(DomainException $e){$wpdb->query('ROLLBACK');return self::not_found();}catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('meet_leave_failed','meeting',$meeting?(int)$meeting->id:0,'failure');return self::database_error();}return rest_ensure_response(['left'=>true,'duplicate'=>false]);
    }

    public static function participants(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$meeting=self::meeting_by_public((string)$request['meeting']);$viewer_id=get_current_user_id();$viewer=$meeting?self::participant_row((int)$meeting->id,$viewer_id):null;if(!$meeting||!$viewer||in_array((string)$viewer->state,['denied','removed'],true))return self::not_found();if(!in_array((string)$viewer->state,['admitted','joined'],true)&&!in_array((string)$viewer->role,['host','cohost'],true))return new WP_Error('meeting_admission_required','Host admission is required before viewing the participant roster.',['status'=>403]);$states=['invited','waiting','admitted','joined','left'];$placeholders=implode(',',array_fill(0,count($states),'%s'));$params=array_merge([(int)$meeting->id],$states);$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('participants')." WHERE meeting_id=%d AND state IN ($placeholders) ORDER BY FIELD(role,'host','cohost','participant'),FIELD(state,'joined','admitted','waiting','invited','left'),id ASC LIMIT 500",...$params));$active=$wpdb->get_results($wpdb->prepare("SELECT user_id,media_state FROM ".self::table('sessions')." WHERE meeting_id=%d AND state='joined' AND last_seen_at>=%s ORDER BY id ASC LIMIT 1500",(int)$meeting->id,gmdate('Y-m-d H:i:s',time()-self::SESSION_TTL)));$media_by_user=[];foreach($active as $session){$uid=(int)$session->user_id;$media=self::decode_json((string)$session->media_state);if(!isset($media_by_user[$uid]))$media_by_user[$uid]=['mic'=>false,'camera'=>false,'screen'=>false,'hand'=>false];foreach(['mic','camera','screen','hand'] as $key)$media_by_user[$uid][$key]=!empty($media_by_user[$uid][$key])||!empty($media[$key]);}$items=[];foreach($rows as $row){$item=self::format_participant($row,$viewer_id,true);if(isset($media_by_user[(int)$row->user_id]))$item['media']=$media_by_user[(int)$row->user_id];$items[]=$item;}return rest_ensure_response(['participants'=>$items,'meeting_version'=>(int)$meeting->version]);}

    public static function moderate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$meeting=self::meeting_by_public((string)$request['meeting']);$actor_id=get_current_user_id();$actor=$meeting?self::participant_row((int)$meeting->id,$actor_id):null;if(!$meeting||!$actor||!in_array((string)$actor->role,['host','cohost'],true))return self::not_found();if(!SN_Policy::consume_rate_limit('meet_moderate',$actor_id.':'.(int)$meeting->id,240,HOUR_IN_SECONDS))return self::rate_limited();$action=sanitize_key((string)$request->get_param('action'));$meeting_actions=['start','end','lock','unlock'];$participant_actions=['admit','deny','remove','mute','lower_hand','promote','demote'];if(!in_array($action,array_merge($meeting_actions,$participant_actions),true))return new WP_Error('invalid_meet_action','The meeting moderation action is invalid.',['status'=>400]);if(in_array($action,['end','lock','unlock','promote','demote'],true)&&(string)$actor->role!=='host')return new WP_Error('host_required','Only the meeting host may perform this action.',['status'=>403]);$target_id=absint($request->get_param('user_id'));$now=current_time('mysql',true);$target=null;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::database_error();
        }
        try{$meeting=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE id=%d FOR UPDATE',(int)$meeting->id));$actor=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$actor_id));if(!$actor||!in_array((string)$actor->role,['host','cohost'],true)||((string)$actor->role==='cohost'&&!in_array((string)$actor->state,['admitted','joined'],true)))throw new DomainException('moderator_revoked');
            if(in_array($action,$meeting_actions,true)){$meeting_data=['version'=>(int)$meeting->version+1,'updated_at'=>$now];if($action==='start'){if(!in_array((string)$meeting->status,['scheduled','live'],true))throw new DomainException('invalid_state');$meeting_data['status']='live';$meeting_data['started_at']=$meeting->started_at?:$now;}elseif($action==='end'){if(!in_array((string)$meeting->status,['scheduled','live'],true))throw new DomainException('invalid_state');$meeting_data['status']='ended';$meeting_data['ended_at']=$now;$meeting_data['is_locked']=1;}elseif($action==='lock'){if(!in_array((string)$meeting->status,['scheduled','live'],true))throw new DomainException('invalid_state');$meeting_data['is_locked']=1;}else{if(!in_array((string)$meeting->status,['scheduled','live'],true))throw new DomainException('invalid_state');$meeting_data['is_locked']=0;}$updated=$wpdb->update(self::table('meetings'),$meeting_data,['id'=>(int)$meeting->id,'version'=>(int)$meeting->version]);if($updated!==1)throw new RuntimeException('meeting_cas_failed');if($action==='end'){if($wpdb->query($wpdb->prepare("UPDATE ".self::table('sessions')." SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND state IN ('waiting','joined')",$now,$now,(int)$meeting->id))===false)throw new RuntimeException('meeting_end_sessions_failed');if($wpdb->query($wpdb->prepare("UPDATE ".self::table('participants')." SET state='left',left_at=%s,updated_at=%s,version=version+1 WHERE meeting_id=%d AND state IN ('waiting','admitted','joined')",$now,$now,(int)$meeting->id))===false)throw new RuntimeException('meeting_end_participants_failed');}}
            else{$target=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d FOR UPDATE',(int)$meeting->id,$target_id));if(!$target||(string)$target->role==='host'||$target_id===$actor_id)throw new DomainException('invalid_target');if((string)$actor->role==='cohost'&&(string)$target->role==='cohost')throw new DomainException('invalid_target');$data=['version'=>(int)$target->version+1,'updated_at'=>$now];if($action==='admit'){if((string)$target->state!=='waiting')throw new DomainException('invalid_state');$active_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('participants')." WHERE meeting_id=%d AND state IN ('admitted','joined')",(int)$meeting->id));if($active_count>=(int)$meeting->participant_limit)throw new DomainException('meeting_full');$data+=['state'=>'joined','admitted_by'=>$actor_id,'joined_at'=>$target->joined_at?:$now,'left_at'=>null,'last_seen_at'=>$now];if($wpdb->query($wpdb->prepare("UPDATE ".self::table('sessions')." SET state='joined',joined_at=COALESCE(joined_at,%s),left_at=NULL,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",$now,$now,(int)$meeting->id,$target_id))===false)throw new RuntimeException('meeting_admit_sessions_failed');}elseif($action==='deny'){if(!in_array((string)$target->state,['waiting','invited'],true))throw new DomainException('invalid_state');$data+=['state'=>'denied','left_at'=>$now];if($wpdb->query($wpdb->prepare("UPDATE ".self::table('sessions')." SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",$now,$now,(int)$meeting->id,$target_id))===false)throw new RuntimeException('meeting_deny_sessions_failed');}elseif($action==='remove'){$data+=['state'=>'removed','left_at'=>$now];if($wpdb->query($wpdb->prepare("UPDATE ".self::table('sessions')." SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",$now,$now,(int)$meeting->id,$target_id))===false)throw new RuntimeException('meeting_remove_sessions_failed');}elseif($action==='mute'){$policy=self::decode_json((string)$target->media_policy);$policy['forced_muted']=true;$data['media_policy']=wp_json_encode($policy);$active_sessions=$wpdb->get_results($wpdb->prepare("SELECT id,media_state FROM ".self::table('sessions')." WHERE meeting_id=%d AND user_id=%d AND state='joined' FOR UPDATE",(int)$meeting->id,$target_id));foreach($active_sessions as $active_session){$media_state=self::decode_json((string)$active_session->media_state);$media_state['mic']=false;if($wpdb->update(self::table('sessions'),['media_state'=>wp_json_encode($media_state)],['id'=>(int)$active_session->id])===false)throw new RuntimeException('session_mute_failed');}}elseif($action==='lower_hand'){$active_sessions=$wpdb->get_results($wpdb->prepare("SELECT id,media_state FROM ".self::table('sessions')." WHERE meeting_id=%d AND user_id=%d AND state='joined' FOR UPDATE",(int)$meeting->id,$target_id));foreach($active_sessions as $active_session){$media_state=self::decode_json((string)$active_session->media_state);$media_state['hand']=false;if($wpdb->update(self::table('sessions'),['media_state'=>wp_json_encode($media_state)],['id'=>(int)$active_session->id])===false)throw new RuntimeException('session_hand_lower_failed');}}elseif($action==='promote'){$data['role']='cohost';}elseif($action==='demote'){$data['role']='participant';}$updated=$wpdb->update(self::table('participants'),$data,['id'=>(int)$target->id,'version'=>(int)$target->version]);if($updated!==1)throw new RuntimeException('participant_cas_failed');}
            if(!self::insert_event((int)$meeting->id,$actor_id,'moderation_'.$action,$target_id))throw new RuntimeException('meeting_event_insert_failed');if ($wpdb->query('COMMIT') === false) throw new RuntimeException('meeting_commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return match($e->getMessage()){'moderator_revoked'=>new WP_Error('moderator_revoked','Your meeting moderation authority has changed.',['status'=>403]),'invalid_target'=>self::not_found(),'invalid_state'=>new WP_Error('invalid_meet_transition','This meeting state transition is no longer available.',['status'=>409]),'meeting_full'=>new WP_Error('meeting_full','The meeting has reached its participant limit.',['status'=>409]),'session_limit_reached'=>new WP_Error('meeting_session_limit','The meeting session limit has been reached for this account or room.',['status'=>409]),default=>self::database_error()};}
        do_action('sn_network_meet_moderation',$action,(int)$meeting->id,$actor_id,$target_id);SN_DB::audit('meet_'.$action,'meeting',(int)$meeting->id,'success',['target_id'=>$target_id]);$meeting=self::meeting_by_id((int)$meeting->id);return rest_ensure_response(['meeting'=>self::format_meeting($meeting,$actor_id),'action'=>$action]);
    }

    public static function send_signal(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;if(!(bool)apply_filters('sn_network_meet_peer_signaling_enabled',false))return new WP_Error('meet_transport_unavailable','Real-time meeting transport is not configured on this environment.',['status'=>503]);$context=self::session_context($request,true);if(is_wp_error($context))return $context;[$meeting,$participant,$session]=$context;$to_user=absint($request->get_param('to_user_id'));$target=self::participant_row((int)$meeting->id,$to_user);if(!$to_user||!$target||!in_array((string)$target->state,['admitted','joined'],true))return self::not_found();$type=sanitize_key((string)$request->get_param('type'));if(!in_array($type,['offer','answer','ice','renegotiate'],true))return new WP_Error('invalid_signal_type','The signaling type is invalid.',['status'=>400]);$payload=$request->get_param('payload');if(!is_array($payload))return new WP_Error('invalid_signal_payload','A JSON signaling payload is required.',['status'=>400]);$json=wp_json_encode($payload);if(!is_string($json)||strlen($json)>self::MAX_SIGNAL_BYTES)return new WP_Error('signal_too_large','The signaling payload exceeds the permitted size.',['status'=>413]);if(!SN_Policy::consume_rate_limit('meet_signal',get_current_user_id().':'.(int)$meeting->id,1200,MINUTE_IN_SECONDS))return self::rate_limited();$now=current_time('mysql',true);$ok=$wpdb->insert(self::table('signals'),['meeting_id'=>(int)$meeting->id,'from_user_id'=>get_current_user_id(),'to_user_id'=>$to_user,'signal_type'=>$type,'payload'=>$json,'created_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+self::SIGNAL_TTL)]);if($ok===false)return self::database_error();return new WP_REST_Response(['signal_id'=>(int)$wpdb->insert_id,'expires_in'=>self::SIGNAL_TTL],201);}
    public static function get_signals(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$context=self::session_context($request,true);if(is_wp_error($context))return $context;[$meeting]=$context;$after=absint($request->get_param('after'));$rows=$wpdb->get_results($wpdb->prepare('SELECT id,from_user_id,signal_type,payload,created_at FROM '.self::table('signals').' WHERE meeting_id=%d AND to_user_id=%d AND id>%d AND consumed_at IS NULL AND expires_at>%s ORDER BY id ASC LIMIT 100',(int)$meeting->id,get_current_user_id(),$after,current_time('mysql',true)));$output=[];foreach($rows as $row){$payload=json_decode((string)$row->payload,true);if(!is_array($payload))continue;$output[]=['id'=>(int)$row->id,'from_user_id'=>(int)$row->from_user_id,'type'=>(string)$row->signal_type,'payload'=>$payload,'created_at'=>(string)$row->created_at];}return rest_ensure_response(['signals'=>$output]);}
    public static function ack_signals(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$context=self::session_context($request,true);if(is_wp_error($context))return $context;[$meeting]=$context;$ids=$request->get_param('ids');if(!is_array($ids))return new WP_Error('invalid_signal_ids','Signal identifiers are required.',['status'=>400]);$ids=array_slice(array_values(array_unique(array_filter(array_map('absint',$ids)))),0,100);if(!$ids)return rest_ensure_response(['acknowledged'=>0]);$placeholders=implode(',',array_fill(0,count($ids),'%d'));$params=array_merge([current_time('mysql',true),(int)$meeting->id,get_current_user_id()],$ids);$updated=$wpdb->query($wpdb->prepare('UPDATE '.self::table('signals')." SET consumed_at=%s WHERE meeting_id=%d AND to_user_id=%d AND consumed_at IS NULL AND id IN ($placeholders)",...$params));if($updated===false)return self::database_error();return rest_ensure_response(['acknowledged'=>max(0,(int)$updated)]);}

    private static function session_context(WP_REST_Request $request,bool $require_joined):array|WP_Error{$meeting=self::meeting_by_public((string)$request['meeting']);$user_id=get_current_user_id();if(!$meeting||!in_array((string)$meeting->status,['scheduled','live'],true))return self::not_found();$session_id=trim((string)$request->get_param('session_id'));if(!preg_match('/^[A-Za-z0-9._:-]{32,128}$/',$session_id))return new WP_Error('invalid_session_id','A valid device session identifier is required.',['status'=>400]);$session=self::session_row((int)$meeting->id,self::session_hash($session_id,$user_id),$user_id);$participant=self::participant_row((int)$meeting->id,$user_id);if(!$session||!$participant||in_array((string)$participant->state,['denied','removed'],true))return self::not_found();if($require_joined&&((string)$session->state!=='joined'||!in_array((string)$participant->state,['admitted','joined'],true)))return new WP_Error('meeting_admission_required','Host admission is required before meeting signaling.',['status'=>403]);if((string)$session->state!=='left'&&strtotime((string)$session->last_seen_at.' UTC')<time()-self::SESSION_TTL)return new WP_Error('meeting_session_expired','This meeting session expired. Join again.',['status'=>409]);return[$meeting,$participant,$session];}
    private static function can_discover(object $meeting,int $user_id):bool{$participant=self::participant_row((int)$meeting->id,$user_id);if($participant&&!in_array((string)$participant->state,['denied','removed'],true))return true;return (string)$meeting->access_mode==='conversation'&&(int)$meeting->conversation_id>0&&SN_DB::is_member((int)$meeting->conversation_id,$user_id);}
    private static function media_state(mixed $value):array|WP_Error{$value=is_array($value)?$value:[];$out=[];foreach(['mic','camera','screen','hand']as$key){if(isset($value[$key])&&!is_bool($value[$key]))return new WP_Error('invalid_media_state','Media state flags must be JSON booleans.',['status'=>400]);$out[$key]=!empty($value[$key]);}return$out;}
    private static function media_config(object $meeting,object $participant,object $session):array{$base=['available'=>false,'provider'=>'none','join_token'=>null,'expires_at'=>null,'features'=>['audio'=>false,'video'=>false,'screen_share'=>false,'captions'=>false,'recording'=>false]];$provided=apply_filters('sn_network_meet_media_config',$base,$meeting,get_current_user_id(),$session);if(!is_array($provided))return$base;return array_replace_recursive($base,$provided);}
    private static function insert_event(int $meeting_id,int $actor_id,string $event_type,int $subject_user_id=0,array $context=[]):bool{global $wpdb;return$wpdb->insert(self::table('events'),['meeting_id'=>$meeting_id,'actor_id'=>$actor_id,'event_type'=>$event_type,'subject_user_id'=>$subject_user_id,'context'=>(string)wp_json_encode($context),'created_at'=>current_time('mysql',true)])!==false;}
    private static function meeting_by_public(string $public_id):?object{global $wpdb;if(!preg_match('/^[A-Za-z0-9_-]{22,64}$/',$public_id))return null;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE public_id=%s',$public_id))?:null;}
    private static function meeting_by_id(int $id):?object{global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('meetings').' WHERE id=%d',$id))?:null;}
    private static function participant_row(int $meeting_id,int $user_id):?object{global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('participants').' WHERE meeting_id=%d AND user_id=%d',$meeting_id,$user_id))?:null;}
    private static function session_row(int $meeting_id,string $session_hash,int $user_id):?object{global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sessions').' WHERE meeting_id=%d AND user_id=%d AND session_hash=%s',$meeting_id,$user_id,$session_hash))?:null;}
    private static function parse_datetime(mixed $value):string|WP_Error|null{if($value===null||$value==='')return null;$raw=trim((string)$value);$dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();if(!$dt||($errors!==false&&((int)$errors['warning_count']>0||(int)$errors['error_count']>0))||$dt->format('Y-m-d\TH:i:s\Z')!==$raw)return new WP_Error('invalid_datetime','Meeting dates must use exact UTC ISO-8601 format.',['status'=>400]);return$dt->format('Y-m-d H:i:s');}
    private static function bool_param(WP_REST_Request $request,string $key,bool $default):bool|WP_Error{if(!$request->has_param($key))return$default;$value=$request->get_param($key);if(!is_bool($value))return new WP_Error('invalid_boolean','Boolean meeting settings must use JSON true or false.',['status'=>400]);return$value;}
    private static function format_meeting(?object $row,int $viewer):array{if(!$row)return[];$participant=self::participant_row((int)$row->id,$viewer);return['id'=>(string)$row->public_id,'title'=>(string)$row->title,'description'=>(string)$row->description,'status'=>(string)$row->status,'access_mode'=>(string)$row->access_mode,'lobby_enabled'=>(bool)$row->lobby_enabled,'locked'=>(bool)$row->is_locked,'participant_limit'=>(int)$row->participant_limit,'scheduled_start'=>(string)$row->scheduled_start,'scheduled_end'=>$row->scheduled_end?(string)$row->scheduled_end:null,'started_at'=>$row->started_at?(string)$row->started_at:null,'ended_at'=>$row->ended_at?(string)$row->ended_at:null,'host_id'=>(int)$row->host_id,'conversation_id'=>(int)$row->conversation_id,'role'=>$participant?(string)$participant->role:null,'participant_state'=>$participant?(string)$participant->state:null,'version'=>(int)$row->version,'capabilities'=>['lobby'=>true,'moderation'=>true,'signaling'=>(bool)apply_filters('sn_network_meet_peer_signaling_enabled',false),'media_provider'=>(bool)apply_filters('sn_network_meet_media_available',false),'recording'=>false,'captions'=>false,'end_to_end_encryption'=>false]];}
    private static function format_participant(?object $row,int $viewer,bool $include_identity=false):array{if(!$row)return[];$out=['role'=>(string)$row->role,'state'=>(string)$row->state,'joined_at'=>$row->joined_at?(string)$row->joined_at:null,'left_at'=>$row->left_at?(string)$row->left_at:null,'last_seen_at'=>$row->last_seen_at?(string)$row->last_seen_at:null,'version'=>(int)$row->version];if($include_identity)$out['user_id']=(int)$row->user_id;return$out;}
    private static function format_session(?object $row):array{if(!$row)return[];return['state'=>(string)$row->state,'joined_at'=>$row->joined_at?(string)$row->joined_at:null,'last_seen_at'=>(string)$row->last_seen_at,'expires_in'=>self::SESSION_TTL];}
    private static function new_public_id():string{return rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');}
    private static function session_hash(string $session_id,int $user_id):string{return hash_hmac('sha256',$user_id.':'.$session_id,wp_salt('auth'));}
    private static function decode_json(string $json):array{$decoded=json_decode($json,true);return is_array($decoded)?$decoded:[];}
    public static function meeting_url(string $public_id):string{return home_url('/calls/'.rawurlencode($public_id).'/');}

    public static function cleanup():void{global $wpdb;$now=current_time('mysql',true);$stale=gmdate('Y-m-d H:i:s',time()-self::SESSION_TTL);$results=[];$results[]=$wpdb->query($wpdb->prepare('DELETE FROM '.self::table('signals').' WHERE expires_at<=%s',$now));$results[]=$wpdb->query($wpdb->prepare("UPDATE ".self::table('sessions')." SET state='left',left_at=COALESCE(left_at,%s) WHERE state IN ('waiting','joined') AND last_seen_at<%s",$now,$stale));$results[]=$wpdb->query($wpdb->prepare("UPDATE ".self::table('participants')." p SET p.state='left',p.left_at=COALESCE(p.left_at,%s),p.updated_at=%s,p.version=p.version+1 WHERE p.state='joined' AND p.last_seen_at<%s AND NOT EXISTS (SELECT 1 FROM ".self::table('sessions')." s WHERE s.meeting_id=p.meeting_id AND s.user_id=p.user_id AND s.state='joined' AND s.last_seen_at>=%s)",$now,$now,$stale,$stale));$results[]=$wpdb->query($wpdb->prepare("UPDATE ".self::table('meetings')." SET status='ended',ended_at=COALESCE(ended_at,%s),is_locked=1,updated_at=%s,version=version+1 WHERE status IN ('scheduled','live') AND scheduled_end IS NOT NULL AND scheduled_end<%s",$now,$now,$now));if(in_array(false,$results,true))SN_DB::audit('meet_cleanup_failed','system',0,'failure');}
    public static function privacy_exporters(array $exporters):array{$exporters['sabri-meet']=['exporter_friendly_name'=>__('Sabri Meet','sabri-network'),'callback'=>[self::class,'privacy_export']];return$exporters;}
    public static function privacy_erasers(array $erasers):array{$erasers['sabri-meet']=['eraser_friendly_name'=>__('Sabri Meet','sabri-network'),'callback'=>[self::class,'privacy_erase']];return$erasers;}
    public static function privacy_export(string $email_address,int $page=1):array{global $wpdb;$user=get_user_by('email',$email_address);if(!$user)return['data'=>[],'done'=>true];$user_id=(int)$user->ID;$limit=100;$offset=max(0,$page-1)*$limit;$rows=$wpdb->get_results($wpdb->prepare('SELECT m.public_id,m.title,m.status,m.scheduled_start,m.started_at,m.ended_at,p.role,p.state,p.joined_at,p.left_at FROM '.self::table('meetings').' m INNER JOIN '.self::table('participants').' p ON p.meeting_id=m.id WHERE p.user_id=%d ORDER BY m.id ASC LIMIT %d OFFSET %d',$user_id,$limit,$offset));$data=[];foreach($rows as$row)$data[]=['group_id'=>'sabri-meet-sessions','group_label'=>__('Sabri Meet sessions','sabri-network'),'item_id'=>'sabri-meet-'.sanitize_key((string)$row->public_id),'data'=>[['name'=>__('Meeting title','sabri-network'),'value'=>(string)$row->title],['name'=>__('Meeting status','sabri-network'),'value'=>(string)$row->status],['name'=>__('Participant role','sabri-network'),'value'=>(string)$row->role],['name'=>__('Participant state','sabri-network'),'value'=>(string)$row->state],['name'=>__('Scheduled start','sabri-network'),'value'=>(string)$row->scheduled_start],['name'=>__('Joined','sabri-network'),'value'=>(string)$row->joined_at],['name'=>__('Left','sabri-network'),'value'=>(string)$row->left_at],['name'=>__('Meeting started','sabri-network'),'value'=>(string)$row->started_at],['name'=>__('Meeting ended','sabri-network'),'value'=>(string)$row->ended_at]]];return['data'=>$data,'done'=>count($rows)<$limit];}
    public static function privacy_erase(string $email_address,int $page=1):array{global $wpdb;$user=get_user_by('email',$email_address);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$user_id=(int)$user->ID;if((bool)apply_filters('sn_network_retention_prevents_erasure',false,$user_id))return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Some Sabri Meet data is retained under an approved legal or safety hold.','sabri-network')],'done'=>true];if($page>1)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$now=current_time('mysql',true);if($wpdb->query('START TRANSACTION')===false)return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Sabri Meet erasure could not start and must be retried by an administrator.','sabri-network')],'done'=>true];try{$hosted=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::table('meetings').' WHERE host_id=%d FOR UPDATE',$user_id)));if($hosted){$placeholders=implode(',',array_fill(0,count($hosted),'%d'));if($wpdb->query($wpdb->prepare('UPDATE '.self::table('meetings')." SET ended_at=CASE WHEN status='live' THEN %s ELSE ended_at END,host_id=0,title='Erased meeting',description='',status=CASE WHEN status='scheduled' THEN 'cancelled' WHEN status='live' THEN 'ended' ELSE status END,is_locked=1,settings='{}',version=version+1,updated_at=%s WHERE id IN ($placeholders)",$now,$now,...$hosted))===false)throw new RuntimeException('hosted_meeting_erasure_failed');if($wpdb->query($wpdb->prepare('UPDATE '.self::table('sessions')." SET state='left',left_at=COALESCE(left_at,%s),last_seen_at=%s WHERE meeting_id IN ($placeholders) AND state IN ('waiting','joined')",$now,$now,...$hosted))===false)throw new RuntimeException('hosted_session_erasure_failed');}if($wpdb->delete(self::table('sessions'),['user_id'=>$user_id],['%d'])===false)throw new RuntimeException('session_erasure_failed');if($wpdb->query($wpdb->prepare('DELETE FROM '.self::table('signals').' WHERE from_user_id=%d OR to_user_id=%d',$user_id,$user_id))===false)throw new RuntimeException('signal_erasure_failed');if($wpdb->delete(self::table('participants'),['user_id'=>$user_id],['%d'])===false)throw new RuntimeException('participant_erasure_failed');if($wpdb->query($wpdb->prepare('UPDATE '.self::table('events').' SET context=CASE WHEN actor_id=%d OR subject_user_id=%d THEN %s ELSE context END,actor_id=CASE WHEN actor_id=%d THEN 0 ELSE actor_id END,subject_user_id=CASE WHEN subject_user_id=%d THEN 0 ELSE subject_user_id END WHERE actor_id=%d OR subject_user_id=%d',$user_id,$user_id,'{}',$user_id,$user_id,$user_id,$user_id))===false)throw new RuntimeException('event_erasure_failed');if($wpdb->query('COMMIT')===false)throw new RuntimeException('erasure_commit_failed');}catch(Throwable $e){$wpdb->query('ROLLBACK');return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Sabri Meet erasure failed and must be retried by an administrator.','sabri-network')],'done'=>true];}SN_DB::audit('meet_privacy_erasure','user',0,'success',[],0);return['items_removed'=>true,'items_retained'=>false,'messages'=>[],'done'=>true];}
    public static function register_assets():void{wp_register_style('sn-meet',SN_URL.'assets/css/meet.css',[],SN_VERSION);wp_register_script('sn-meet',SN_URL.'assets/js/meet.js',[],SN_VERSION,true);}
    public static function enqueue_assets():void{$route=(string)get_query_var('sn_meet_app');if($route==='')return;wp_enqueue_style('sn-meet');wp_enqueue_script('sn-meet');wp_localize_script('sn-meet','snMeetConfig',['root'=>esc_url_raw(rest_url(self::NS.'/')),'nonce'=>wp_create_nonce('wp_rest'),'route'=>$route,'claims'=>['recording'=>false,'captions'=>false,'end_to_end_encryption'=>false]]);}
    private static function table(string $suffix):string{global $wpdb;return$wpdb->prefix.'sn_meet_'.$suffix;}
    private static function rate_limited():WP_Error{return new WP_Error('meet_rate_limited','Too many meeting requests were made. Retry later.',['status'=>429]);}
    private static function not_found():WP_Error{return new WP_Error('meet_not_found','The requested meeting is unavailable.',['status'=>404]);}
    private static function database_error():WP_Error{return new WP_Error('meet_database_error','The meeting state could not be committed. Retry safely.',['status'=>500]);}
}