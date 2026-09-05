<?php
/**
 * File 17 two-plan completion layer (2026-08-11).
 *
 * Implements the remaining repository-owned communication capabilities from the
 * consolidated governing plan and the File 17 harmonized plan without creating
 * a parallel identity, messaging, calls, search, notification, or clinical backend.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Two_Plan_Completion {
    public const SCHEMA_VERSION = '2.1.0';
    private const MAX_REQUEST_CHARS = 4000;
    private const MAX_SCHEDULE_DAYS = 90;
    private const MAX_POLL_OPTIONS = 12;
    private const MAX_CHECKLIST_ITEMS = 50;
    private const MAX_TRANSLATE_CHARS = 10000;
    private const MAX_ARTIFACT_BODY = 20000;
    private const MAX_ARTIFACT_TITLE = 191;
    private const MAX_RESPONSE_BODY = 10000;
    private const REQUEST_COOLDOWN_DAYS = 30;

    public static function register(): void {
        add_action('init', [self::class, 'maybe_upgrade'], 25);
        add_action('rest_api_init', [self::class, 'register_routes'], 1400);
        add_action('sn_cleanup_hourly', [self::class, 'dispatch_due_scheduled']);
        add_action('sn_cleanup_hourly', [self::class, 'expire_messages']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
        add_action('sn_network_two_plan_contract_request', [self::class, 'emit_contract']);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_two_plan_schema_version', '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE ".self::requests_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requester_id BIGINT UNSIGNED NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            pair_key CHAR(64) NOT NULL,
            client_key CHAR(64) NOT NULL,
            body_cipher LONGTEXT NOT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            report_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cooldown_until DATETIME NULL,
            decided_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pair_key (pair_key),
            UNIQUE KEY client_key (client_key),
            KEY recipient_queue (recipient_id,status,updated_at),
            KEY requester_queue (requester_id,status,updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE ".self::scheduled_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            body_cipher LONGTEXT NOT NULL,
            deliver_at DATETIME NOT NULL,
            client_key CHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY client_key (client_key),
            KEY due (status,deliver_at),
            KEY sender_status (sender_id,status,deliver_at)
        ) $charset;");

        dbDelta("CREATE TABLE ".self::poll_votes_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            option_index SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_user (message_id,user_id),
            KEY message_option (message_id,option_index)
        ) $charset;");

        dbDelta("CREATE TABLE ".self::community_settings_table()." (
            space_id BIGINT UNSIGNED NOT NULL,
            rules_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            rules_text LONGTEXT NULL,
            join_questions LONGTEXT NULL,
            orientation LONGTEXT NULL,
            updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (space_id)
        ) $charset;");

        dbDelta("CREATE TABLE ".self::artifacts_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            space_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(191) NOT NULL,
            body_cipher LONGTEXT NULL,
            metadata LONGTEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            best_response_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY space_type (space_id,type,status,id),
            KEY author_id (author_id,id),
            KEY timing (type,status,starts_at,ends_at)
        ) $charset;");

        dbDelta("CREATE TABLE ".self::responses_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            artifact_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body_cipher LONGTEXT NOT NULL,
            metadata LONGTEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY artifact_status (artifact_id,status,id),
            KEY user_id (user_id,id)
        ) $charset;");

        update_option('sn_two_plan_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function register_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/message-requests', [
            ['methods'=>'GET','callback'=>[self::class,'list_message_requests'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'create_message_request'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2', '/message-requests/(?P<id>\\d+)', [
            'methods'=>'POST','callback'=>[self::class,'decide_message_request'],'permission_callback'=>$access,
        ]);

        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\\d+)/scheduled-messages', [
            ['methods'=>'GET','callback'=>[self::class,'list_scheduled'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'schedule_message'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2', '/scheduled-messages/(?P<id>\\d+)', [
            'methods'=>'DELETE','callback'=>[self::class,'cancel_scheduled'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\\d+)/polls', [
            'methods'=>'POST','callback'=>[self::class,'create_poll'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)/poll-vote', [
            'methods'=>'POST','callback'=>[self::class,'vote_poll'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\\d+)/checklists', [
            'methods'=>'POST','callback'=>[self::class,'create_checklist'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)/checklist-items/(?P<item>\\d+)', [
            'methods'=>'POST','callback'=>[self::class,'toggle_checklist'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)/expiry', [
            'methods'=>'POST','callback'=>[self::class,'set_message_expiry'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)/translate', [
            'methods'=>'POST','callback'=>[self::class,'translate_message'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\\d+)/voice-notes', [
            'methods'=>'POST','callback'=>[self::class,'send_voice_note'],'permission_callback'=>$access,
        ]);

        register_rest_route('sabri-network/v2', '/updates', [
            ['methods'=>'GET','callback'=>[self::class,'get_updates'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'create_update'],'permission_callback'=>$access],
        ], true);
        register_rest_route('sabri-network/v2', '/updates/(?P<id>\\d+)/view', [
            'methods'=>'POST','callback'=>[self::class,'view_update'],'permission_callback'=>$access,
        ], true);

        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\\d+)/community-settings', [
            ['methods'=>'GET','callback'=>[self::class,'get_community_settings'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'update_community_settings'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\\d+)/community-artifacts', [
            ['methods'=>'GET','callback'=>[self::class,'list_community_artifacts'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'create_community_artifact'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\\d+)/community-artifacts/(?P<artifact>\\d+)/respond', [
            'methods'=>'POST','callback'=>[self::class,'respond_to_artifact'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\\d+)/community-artifacts/(?P<artifact>\\d+)/moderate', [
            'methods'=>'POST','callback'=>[self::class,'moderate_artifact'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\\d+)/community-health', [
            'methods'=>'GET','callback'=>[self::class,'community_health'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/admin/two-plan-completion', [
            'methods'=>'GET','callback'=>[self::class,'admin_status'],'permission_callback'=>[SN_REST::class,'admin_access'],
        ]);
    }

    public static function create_message_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $recipient = absint($request->get_param('user_id'));
        if ($recipient <= 0 || $recipient === $actor || !get_user_by('id', $recipient)) return self::error('sn_message_request_invalid_user','Select a valid recipient.',400);
        if (SN_DB::are_contacts($actor, $recipient)) return self::error('sn_message_request_contact_exists','This recipient is already an accepted contact; use a direct conversation.',409);
        $allowed = SN_Policy::can_contact($actor, $recipient, 'request');
        if (is_wp_error($allowed)) return $allowed;
        if (!SN_Policy::consume_rate_limit('message_request_sender', (string)$actor, 10, DAY_IN_SECONDS)) return self::error('sn_message_request_rate_limited','Too many new message requests were sent.',429);
        if (!SN_Policy::consume_rate_limit('message_request_recipient', (string)$recipient, 50, DAY_IN_SECONDS)) return self::error('sn_message_request_recipient_protected','This inbox is temporarily protected from new requests.',429);

        $body = mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('message')))),0,self::MAX_REQUEST_CHARS);
        $reason = mb_substr(trim(sanitize_text_field((string)$request->get_param('reason'))),0,500);
        if ($body === '') return self::error('sn_message_request_message_required','A short first message is required.',400);
        $risk = self::link_risk($body);
        if (is_wp_error($risk)) return $risk;

        $client = self::client_id((string)$request->get_param('client_id'));
        if (is_wp_error($client)) return $client;
        $pair_key = self::unordered_pair_key($actor,$recipient);
        $client_key = hash('sha256',$actor.':'.$recipient.':message-request:'.$client);
        $now = current_time('mysql',true);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::requests_table().' WHERE pair_key=%s',$pair_key));
        if ($existing) {
            if ((string)$existing->status === 'pending') return rest_ensure_response(self::format_request($existing,$actor));
            if ((string)$existing->cooldown_until !== '' && strtotime((string)$existing->cooldown_until.' UTC') > time()) return self::error('sn_message_request_cooldown','A previous request was declined or reported; wait before trying again.',429);
        }
        $cipher = SN_Communication_Crypto::encrypt($body,'message-request|'.$actor.'|'.$recipient);
        if (is_wp_error($cipher)) return $cipher;

        $wpdb->query('START TRANSACTION');
        try {
            if ($existing) {
                $changed = $wpdb->query($wpdb->prepare(
                    'UPDATE '.self::requests_table()." SET requester_id=%d,recipient_id=%d,client_key=%s,body_cipher=%s,reason=%s,status='pending',version=version+1,conversation_id=0,report_id=0,cooldown_until=NULL,decided_at=NULL,updated_at=%s WHERE id=%d AND version=%d",
                    $actor,$recipient,$client_key,$cipher,$reason,$now,(int)$existing->id,(int)$existing->version
                ));
                if ($changed !== 1) throw new RuntimeException('request_conflict');
                $id=(int)$existing->id;
            } else {
                $ok=$wpdb->insert(self::requests_table(),[
                    'requester_id'=>$actor,'recipient_id'=>$recipient,'pair_key'=>$pair_key,'client_key'=>$client_key,
                    'body_cipher'=>$cipher,'reason'=>$reason,'status'=>'pending','version'=>1,'created_at'=>$now,'updated_at'=>$now,
                ]);
                if ($ok===false) throw new RuntimeException('request_insert_failed');
                $id=(int)$wpdb->insert_id;
            }
            $event=SN_Outbox::enqueue('message_request.created','message_request',$id,[
                'request_id'=>$id,'requester_id'=>$actor,'recipient_id'=>$recipient,'created_at'=>$now,
            ],'message_request.created:'.$id.':'.$client_key);
            if(is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_request_created','message_request',$id,'success',['recipient_id'=>$recipient,'reason_supplied'=>$reason!==''],$actor);
            if($wpdb->query('COMMIT')===false) throw new RuntimeException('request_commit_failed');
            do_action('sn_network_event_queued',$event,'message_request.created');
        } catch(Throwable $e) {
            $wpdb->query('ROLLBACK');
            $race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::requests_table().' WHERE client_key=%s',$client_key));
            if($race) return rest_ensure_response(self::format_request($race,$actor));
            return self::error('sn_message_request_failed','The message request could not be saved safely.',500);
        }
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::requests_table().' WHERE id=%d',$id));
        return new WP_REST_Response(self::format_request($row,$actor),201);
    }

    public static function list_message_requests(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $actor=get_current_user_id();
        $scope=sanitize_key((string)$request->get_param('scope'))==='outgoing'?'outgoing':'incoming';
        $status=sanitize_key((string)$request->get_param('status'));
        $allowed=['pending','accepted','declined','reported','cancelled'];
        $status=in_array($status,$allowed,true)?$status:'pending';
        $column=$scope==='outgoing'?'requester_id':'recipient_id';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::requests_table()." WHERE $column=%d AND status=%s ORDER BY updated_at DESC LIMIT 100",$actor,$status));
        return rest_ensure_response(['scope'=>$scope,'items'=>array_map(static fn($row)=>self::format_request($row,$actor),is_array($rows)?$rows:[])]);
    }

    public static function decide_message_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor=get_current_user_id();$id=absint($request['id']);$action=sanitize_key((string)$request->get_param('action'));
        $allowed=['accept','decline','report','cancel'];
        if(!in_array($action,$allowed,true)) return self::error('sn_message_request_action_invalid','Choose accept, decline, report, or cancel.',400);
        $table=self::requests_table();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
        if(!$row||(string)$row->status!=='pending') return self::not_found();
        $is_recipient=(int)$row->recipient_id===$actor;$is_requester=(int)$row->requester_id===$actor;
        if($action==='cancel'&&!$is_requester) return self::not_found();
        if($action!=='cancel'&&!$is_recipient) return self::not_found();
        if(SN_DB::is_blocked((int)$row->requester_id,(int)$row->recipient_id)) return self::error('sn_message_request_blocked','The request is no longer actionable.',403);
        if(!SN_Policy::consume_rate_limit('message_request_decision',(string)$actor,60,HOUR_IN_SECONDS)) return self::error('sn_message_request_decision_rate_limited','Too many request decisions were submitted.',429);

        $now=current_time('mysql',true);$conversation_id=0;$report_id=0;
        if($action==='accept') {
            $policy=SN_Policy::can_contact((int)$row->recipient_id,(int)$row->requester_id,'request');
            if(is_wp_error($policy)) return $policy;
            $accepted=self::accept_request_transactionally($row,$actor);
            if(is_wp_error($accepted)) return $accepted;
            $conversation_id=(int)$accepted['conversation_id'];
            $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
            return rest_ensure_response(['request'=>self::format_request($fresh,$actor),'conversation_id'=>$conversation_id]);
        }
        if($action==='report') {
            $report=new WP_REST_Request('POST','/sabri-network/v2/report');
            $report->set_param('client_id',wp_generate_uuid4());
            $report->set_param('reported_user_id',(int)$row->requester_id);
            $report->set_param('category',sanitize_key((string)$request->get_param('category'))?:'spam');
            $report->set_param('details',mb_substr(sanitize_textarea_field((string)$request->get_param('details')),0,4000));
            $report->set_param('evidence',['message_request_id'=>$id,'request_hash'=>hash('sha256',(string)$row->body_cipher)]);
            $result=SN_REST::report($report);
            if(is_wp_error($result)) return $result;
            $data=$result->get_data();$report_id=(int)($data['id']??0);
        }
        $new_status=$action==='report'?'reported':($action==='cancel'?'cancelled':'declined');
        $cooldown=in_array($new_status,['declined','reported'],true)?gmdate('Y-m-d H:i:s',time()+self::REQUEST_COOLDOWN_DAYS*DAY_IN_SECONDS):null;
        $changed=$wpdb->query($wpdb->prepare(
            "UPDATE $table SET status=%s,report_id=%d,cooldown_until=%s,decided_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND status='pending' AND version=%d",
            $new_status,$report_id,$cooldown,$now,$now,$id,(int)$row->version
        ));
        if($changed!==1) return self::error('sn_message_request_conflict','The request changed before the decision was saved.',409);
        SN_DB::audit('message_request_'.$new_status,'message_request',$id,'success',['report_id'=>$report_id],$actor);
        $event=SN_Outbox::enqueue('message_request.'.$new_status,'message_request',$id,['request_id'=>$id,'requester_id'=>(int)$row->requester_id,'recipient_id'=>(int)$row->recipient_id,'report_id'=>$report_id],'message_request.'.$new_status.':'.$id.':'.((int)$row->version+1));
        if(!is_wp_error($event)) do_action('sn_network_event_queued',$event,'message_request.'.$new_status);
        $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
        return rest_ensure_response(['request'=>self::format_request($fresh,$actor)]);
    }

    private static function accept_request_transactionally(object $request_row,int $actor): array|WP_Error {
        global $wpdb;
        $requester=(int)$request_row->requester_id;$recipient=(int)$request_row->recipient_id;$now=current_time('mysql',true);
        $pair=SN_DB::contact_pair_key($requester,$recipient);$direct_key=SN_DB::direct_key($requester,$recipient);
        $wpdb->query('START TRANSACTION');
        try {
            $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::requests_table().' WHERE id=%d FOR UPDATE',(int)$request_row->id));
            if(!$locked||(string)$locked->status!=='pending'||(int)$locked->recipient_id!==$actor) throw new DomainException('request_conflict');
            $contact=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('contacts').' WHERE pair_key=%s FOR UPDATE',$pair));
            if($contact) {
                if($wpdb->update(SN_DB::table('contacts'),['status'=>'accepted','requested_by'=>$requester,'updated_at'=>$now],['id'=>(int)$contact->id])===false) throw new RuntimeException('contact_update_failed');
            } else {
                if($wpdb->insert(SN_DB::table('contacts'),['user_id'=>min($requester,$recipient),'contact_user_id'=>max($requester,$recipient),'pair_key'=>$pair,'requested_by'=>$requester,'status'=>'accepted','created_at'=>$now,'updated_at'=>$now])===false) throw new RuntimeException('contact_insert_failed');
            }
            $conversation=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('conversations').' WHERE direct_key=%s FOR UPDATE',$direct_key));
            if(!$conversation) {
                if($wpdb->insert(SN_DB::table('conversations'),['type'=>'direct','title'=>'','slug'=>'','direct_key'=>$direct_key,'owner_id'=>$requester,'description'=>'','privacy'=>'private','status'=>'active','settings'=>'{}','created_at'=>$now,'updated_at'=>$now])===false) throw new RuntimeException('conversation_insert_failed');
                $conversation_id=(int)$wpdb->insert_id;
            } else {
                $conversation_id=(int)$conversation->id;
                if((string)$conversation->status!=='active'&&$wpdb->update(SN_DB::table('conversations'),['status'=>'active','updated_at'=>$now],['id'=>$conversation_id])===false) throw new RuntimeException('conversation_restore_failed');
            }
            foreach([$requester,$recipient] as $uid) {
                $member=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d FOR UPDATE',$conversation_id,$uid));
                if($member) {
                    if($wpdb->update(SN_DB::table('members'),['left_at'=>null,'joined_at'=>$now],['id'=>(int)$member->id])===false) throw new RuntimeException('member_restore_failed');
                } else {
                    if($wpdb->insert(SN_DB::table('members'),['conversation_id'=>$conversation_id,'user_id'=>$uid,'role'=>$uid===$requester?'owner':'member','joined_at'=>$now])===false) throw new RuntimeException('member_insert_failed');
                }
            }
            $first=SN_Communication_Crypto::decrypt((string)$locked->body_cipher,'message-request|'.$requester.'|'.$recipient);
            if(is_wp_error($first)) throw new RuntimeException('request_decrypt_failed');
            $message_id=self::insert_canonical_message($conversation_id,$requester,(string)$first,'text',['message_request_id'=>(int)$locked->id],hash('sha256','message-request-accept:'.(int)$locked->id), true);
            if(is_wp_error($message_id)) throw new RuntimeException($message_id->get_error_code());
            $changed=$wpdb->query($wpdb->prepare("UPDATE ".self::requests_table()." SET status='accepted',conversation_id=%d,decided_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND status='pending' AND version=%d",$conversation_id,$now,$now,(int)$locked->id,(int)$locked->version));
            if($changed!==1) throw new RuntimeException('request_accept_conflict');
            $event=SN_Outbox::enqueue('message_request.accepted','message_request',(int)$locked->id,['request_id'=>(int)$locked->id,'conversation_id'=>$conversation_id,'message_id'=>(int)$message_id],'message_request.accepted:'.(int)$locked->id.':'.((int)$locked->version+1));
            if(is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false) throw new RuntimeException('request_accept_commit_failed');
            SN_DB::audit('message_request_accepted','message_request',(int)$locked->id,'success',['conversation_id'=>$conversation_id,'message_id'=>(int)$message_id],$actor);
            do_action('sn_network_event_queued',$event,'message_request.accepted');
            return ['conversation_id'=>$conversation_id,'message_id'=>(int)$message_id];
        } catch(Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::error('sn_message_request_accept_failed','The request could not be accepted atomically.',409);
        }
    }

    public static function schedule_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id=absint($request['id']);$actor=get_current_user_id();$conversation=self::conversation($conversation_id);
        if(!$conversation||!SN_DB::is_member($conversation_id,$actor)) return self::not_found();
        $policy=self::post_policy($conversation,$actor);if(is_wp_error($policy))return $policy;
        if(!SN_Policy::consume_rate_limit('scheduled_message',(string)$actor,60,DAY_IN_SECONDS))return self::error('sn_schedule_rate_limited','Too many scheduled messages were requested.',429);
        $body=mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('body')))),0,10000);
        if($body==='')return self::error('sn_schedule_body_invalid','A message body is required.',400);
        $risk=self::link_risk($body);if(is_wp_error($risk))return $risk;
        $ts=strtotime((string)$request->get_param('deliver_at'));
        if(!$ts||$ts<time()+60||$ts>time()+self::MAX_SCHEDULE_DAYS*DAY_IN_SECONDS)return self::error('sn_schedule_time_invalid','Delivery time must be between one minute and 90 days from now.',400);
        $client=self::client_id((string)$request->get_param('client_id'));if(is_wp_error($client))return $client;
        $key=hash('sha256',$actor.':'.$conversation_id.':scheduled:'.$client);$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE client_key=%s',$key));
        if($existing)return rest_ensure_response(self::scheduled_payload($existing));
        $cipher=SN_Communication_Crypto::encrypt($body,'scheduled-message|'.$actor.'|'.$conversation_id);if(is_wp_error($cipher))return $cipher;
        $now=current_time('mysql',true);$deliver=gmdate('Y-m-d H:i:s',$ts);
        $ok=$wpdb->insert(self::scheduled_table(),['conversation_id'=>$conversation_id,'sender_id'=>$actor,'body_cipher'=>$cipher,'deliver_at'=>$deliver,'client_key'=>$key,'status'=>'pending','created_at'=>$now,'updated_at'=>$now]);
        if($ok===false){$race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE client_key=%s',$key));if($race)return rest_ensure_response(self::scheduled_payload($race));return self::error('sn_schedule_failed','The scheduled message could not be saved.',500);}
        $id=(int)$wpdb->insert_id;SN_DB::audit('message_scheduled','scheduled_message',$id,'success',['conversation_id'=>$conversation_id,'deliver_at'=>$deliver],$actor);
        return new WP_REST_Response(['id'=>$id,'conversation_id'=>$conversation_id,'deliver_at'=>$deliver,'status'=>'pending'],201);
    }

    public static function list_scheduled(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$cid=absint($request['id']);$actor=get_current_user_id();if(!SN_DB::is_member($cid,$actor))return self::not_found();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::scheduled_table()." WHERE conversation_id=%d AND sender_id=%d AND status IN ('pending','failed') ORDER BY deliver_at ASC LIMIT 100",$cid,$actor));
        return rest_ensure_response(['items'=>array_map([self::class,'scheduled_payload'],is_array($rows)?$rows:[])]);
    }

    public static function cancel_scheduled(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE id=%d AND sender_id=%d',$id,$actor));
        if(!$row)return self::not_found();if((string)$row->status!=='pending')return self::error('sn_schedule_not_pending','Only pending scheduled messages can be cancelled.',409);
        $changed=$wpdb->query($wpdb->prepare("UPDATE ".self::scheduled_table()." SET status='cancelled',body_cipher='',updated_at=%s WHERE id=%d AND sender_id=%d AND status='pending'",current_time('mysql',true),$id,$actor));
        if($changed!==1)return self::error('sn_schedule_cancel_conflict','The scheduled message changed before cancellation.',409);
        SN_DB::audit('message_schedule_cancelled','scheduled_message',$id,'success',[],$actor);return rest_ensure_response(['id'=>$id,'status'=>'cancelled']);
    }

    public static function dispatch_due_scheduled(): void {
        global $wpdb;
        $now=current_time('mysql',true);$table=self::scheduled_table();$stale=gmdate('Y-m-d H:i:s',time()-15*MINUTE_IN_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE deliver_at<=%s AND ((status IN ('pending','failed') AND attempts<5) OR (status='processing' AND updated_at<=%s)) ORDER BY deliver_at ASC,id ASC LIMIT 50",$now,$stale));
        foreach(is_array($rows)?$rows:[] as $row){
            if((string)$row->status==='processing'&&(int)$row->attempts>=5){
                $wpdb->update($table,['status'=>'failed','last_error'=>'stale_processing_max_attempts','updated_at'=>$now],['id'=>(int)$row->id,'status'=>'processing']);
                SN_DB::audit('scheduled_message_reconciliation_required','scheduled_message',(int)$row->id,'failure',['reason'=>'stale_processing_max_attempts'],0);
                continue;
            }
            $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',attempts=attempts+1,updated_at=%s WHERE id=%d AND ((status IN ('pending','failed') AND attempts<5) OR (status='processing' AND updated_at<=%s AND attempts<5))",$now,(int)$row->id,$stale));
            if($claimed!==1)continue;
            $conversation=self::conversation((int)$row->conversation_id);$policy=$conversation?self::post_policy($conversation,(int)$row->sender_id):self::error('sn_schedule_conversation_missing','Conversation unavailable.',404);
            if(is_wp_error($policy)){self::schedule_failed((int)$row->id,$policy->get_error_code());continue;}
            $plain=SN_Communication_Crypto::decrypt((string)$row->body_cipher,'scheduled-message|'.(int)$row->sender_id.'|'.(int)$row->conversation_id);
            if(is_wp_error($plain)){self::schedule_failed((int)$row->id,$plain->get_error_code());continue;}
            $message=self::insert_canonical_message((int)$row->conversation_id,(int)$row->sender_id,(string)$plain,'text',['scheduled'=>true,'scheduled_id'=>(int)$row->id],(string)$row->client_key);
            if(is_wp_error($message)){self::schedule_failed((int)$row->id,$message->get_error_code());continue;}
            $published=$wpdb->update($table,['status'=>'sent','message_id'=>(int)$message,'body_cipher'=>'','last_error'=>'','updated_at'=>$now],['id'=>(int)$row->id,'status'=>'processing']);
            if($published!==1){self::schedule_failed((int)$row->id,'schedule_finalize_failed');SN_DB::audit('scheduled_message_reconciliation_required','scheduled_message',(int)$row->id,'failure',['message_id'=>(int)$message,'reason'=>'schedule_finalize_failed'],0);continue;}
            SN_DB::audit('scheduled_message_sent','message',(int)$message,'success',['scheduled_id'=>(int)$row->id],(int)$row->sender_id);
        }
    }

    public static function create_poll(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $cid=absint($request['id']);$actor=get_current_user_id();$conversation=self::conversation($cid);if(!$conversation||!SN_DB::is_member($cid,$actor))return self::not_found();$policy=self::post_policy($conversation,$actor);if(is_wp_error($policy))return $policy;
        $question=mb_substr(trim(sanitize_text_field((string)$request->get_param('question'))),0,500);$options=[];
        foreach((array)$request->get_param('options') as $option){$v=mb_substr(trim(sanitize_text_field((string)$option)),0,300);if($v!==''&&!in_array($v,$options,true))$options[]=$v;}
        if($question===''||count($options)<2||count($options)>self::MAX_POLL_OPTIONS)return self::error('sn_poll_invalid','Polls require a question and 2–12 unique options.',400);
        $client=self::client_id((string)$request->get_param('client_id'));if(is_wp_error($client))return $client;
        $id=self::insert_canonical_message($cid,$actor,$question,'poll',['poll'=>['question'=>$question,'options'=>$options,'single_choice'=>true,'clinical_decision_substitute'=>false]],'poll:'.$actor.':'.$cid.':'.$client);if(is_wp_error($id))return $id;
        return new WP_REST_Response(['message_id'=>$id,'question'=>$question,'options'=>$options],201);
    }

    public static function vote_poll(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)||$message->deleted_at)return self::not_found();
        $meta=self::message_meta($message);$options=$meta['poll']['options']??null;if((string)$message->message_type!=='poll'||!is_array($options))return self::error('sn_poll_required','The message is not an active poll.',409);
        $option=filter_var($request->get_param('option'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($option===false||!array_key_exists((int)$option,$options))return self::error('sn_poll_option_invalid','The selected poll option is invalid.',400);
        if(!SN_Policy::consume_rate_limit('poll_vote',$actor.':'.$id,60,HOUR_IN_SECONDS))return self::error('sn_poll_rate_limited','Too many poll changes were submitted.',429);
        $now=current_time('mysql',true);$sql=$wpdb->prepare('INSERT INTO '.self::poll_votes_table().' (message_id,user_id,option_index,created_at,updated_at) VALUES (%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE option_index=VALUES(option_index),updated_at=VALUES(updated_at)',$id,$actor,(int)$option,$now,$now);
        if($wpdb->query($sql)===false)return self::error('sn_poll_vote_failed','The poll vote could not be saved.',500);
        return rest_ensure_response(['message_id'=>$id,'option'=>(int)$option,'counts'=>self::poll_counts($id,count($options))]);
    }

    public static function create_checklist(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $cid=absint($request['id']);$actor=get_current_user_id();$conversation=self::conversation($cid);if(!$conversation||!SN_DB::is_member($cid,$actor))return self::not_found();$policy=self::post_policy($conversation,$actor);if(is_wp_error($policy))return $policy;
        $title=mb_substr(trim(sanitize_text_field((string)$request->get_param('title'))),0,500);$items=[];
        foreach(array_slice((array)$request->get_param('items'),0,self::MAX_CHECKLIST_ITEMS) as $item){$v=mb_substr(trim(sanitize_text_field((string)$item)),0,300);if($v!=='')$items[]=['label'=>$v,'done'=>false,'by'=>0,'at'=>''];}
        if($title===''||count($items)<1)return self::error('sn_checklist_invalid','Checklists require a title and at least one item.',400);
        $client=self::client_id((string)$request->get_param('client_id'));if(is_wp_error($client))return $client;
        $id=self::insert_canonical_message($cid,$actor,$title,'checklist',['checklist'=>['title'=>$title,'items'=>$items,'clinical_decision_substitute'=>false]],'checklist:'.$actor.':'.$cid.':'.$client);if(is_wp_error($id))return $id;
        return new WP_REST_Response(['message_id'=>$id,'title'=>$title,'items'=>$items],201);
    }

    public static function toggle_checklist(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$index=absint($request['item']);$actor=get_current_user_id();if($wpdb->query('START TRANSACTION')===false)return self::error('sn_checklist_transaction_failed','The checklist transaction could not be started safely.',503);
        try{$message=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d FOR UPDATE',$id));if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)||$message->deleted_at)throw new DomainException('not_found');
            $meta=self::message_meta($message);$items=$meta['checklist']['items']??null;if((string)$message->message_type!=='checklist'||!is_array($items)||!array_key_exists($index,$items))throw new DomainException('invalid_item');
            $done=rest_sanitize_boolean($request->get_param('done'));$items[$index]['done']=$done;$items[$index]['by']=$actor;$items[$index]['at']=current_time('mysql',true);$meta['checklist']['items']=$items;
            if($wpdb->update(SN_DB::table('messages'),['metadata'=>(string)wp_json_encode($meta),'edited_at'=>current_time('mysql',true)],['id'=>$id])===false)throw new RuntimeException('update_failed');
            $event=SN_Outbox::enqueue('checklist.item_changed','message',$id,['message_id'=>$id,'item'=>$index,'done'=>$done,'actor_id'=>$actor],'checklist.item_changed:'.$id.':'.$index.':'.$actor.':'.($done?'1':'0').':'.time());if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');SN_DB::audit('checklist_item_changed','message',$id,'success',['item'=>$index,'done'=>$done],$actor);do_action('sn_network_event_queued',$event,'checklist.item_changed');return rest_ensure_response(['message_id'=>$id,'items'=>$items]);
        }catch(DomainException $e){$wpdb->query('ROLLBACK');return $e->getMessage()==='not_found'?self::not_found():self::error('sn_checklist_item_invalid','The checklist item is unavailable.',409);}catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_checklist_update_failed','The checklist could not be updated safely.',500);}
    }

    public static function set_message_expiry(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor))return self::not_found();if((int)$message->sender_id!==$actor)return self::error('sn_expiry_author_required','Only the sender may configure disappearing-message expiry.',403);
        if(self::message_has_legal_hold($id))return self::error('sn_expiry_legal_hold','This message is preserved by a safety/legal hold.',409);
        $seconds=absint($request->get_param('seconds'));if(!in_array($seconds,[0,3600,86400,604800,2592000],true))return self::error('sn_expiry_invalid','Expiry must be off, 1 hour, 1 day, 7 days or 30 days.',400);
        $meta=self::message_meta($message);if($seconds===0)unset($meta['expires_at']);else$meta['expires_at']=gmdate('Y-m-d H:i:s',time()+$seconds);
        if($wpdb->update(SN_DB::table('messages'),['metadata'=>(string)wp_json_encode($meta)],['id'=>$id,'sender_id'=>$actor])===false)return self::error('sn_expiry_failed','The expiry setting could not be saved.',500);
        SN_DB::audit('message_expiry_changed','message',$id,'success',['seconds'=>$seconds],$actor);return rest_ensure_response(['message_id'=>$id,'expires_at'=>$meta['expires_at']??null]);
    }

    public static function expire_messages(): void {
        global $wpdb;$now=current_time('mysql',true);$messages=SN_DB::table('messages');
        $rows=$wpdb->get_results("SELECT * FROM $messages WHERE deleted_at IS NULL AND metadata IS NOT NULL AND metadata<>'' ORDER BY id ASC LIMIT 1000");
        foreach(is_array($rows)?$rows:[] as $row){
            $meta=self::message_meta($row);$expires=(string)($meta['expires_at']??'');if($expires===''||strtotime($expires.' UTC')>time())continue;
            $id=(int)$row->id;$lock='sn:f17:message-retention:'.$id;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,5));
            if($got!==1){SN_DB::audit('message_expiry_deferred','message',$id,'failure',['reason'=>'retention_lock_busy'],0);continue;}
            try{
                if($wpdb->query('START TRANSACTION')===false){SN_DB::audit('message_expiry_failed','message',$id,'failure',['reason'=>'transaction_start_failed'],0);continue;}
                try{
                    $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE",$id));
                    if(!$locked||$locked->deleted_at!==null){$wpdb->query('ROLLBACK');continue;}
                    $locked_meta=self::message_meta($locked);$locked_expires=(string)($locked_meta['expires_at']??'');
                    if($locked_expires===''||strtotime($locked_expires.' UTC')>time()){$wpdb->query('ROLLBACK');continue;}
                    $held=self::message_has_legal_hold($id);if(is_wp_error($held))throw new RuntimeException($held->get_error_code());if($held){$wpdb->query('ROLLBACK');continue;}
                    $attachment=(string)$locked->attachment_source==='private'?(int)$locked->attachment_id:0;
                    if($wpdb->update($messages,['body'=>'','attachment_id'=>0,'attachment_source'=>'expired','metadata'=>(string)wp_json_encode(['expired'=>true,'expired_at'=>$now]),'deleted_at'=>$now],['id'=>$id,'deleted_at'=>null])===false)throw new RuntimeException('expire_update_failed');
                    if($wpdb->delete(SN_DB::table('reactions'),['message_id'=>$id],['%d'])===false)throw new RuntimeException('expire_reactions_failed');
                    $removed=SN_Message_Search::remove_message($id);if(is_wp_error($removed))throw new RuntimeException($removed->get_error_code());
                    $event=SN_Outbox::enqueue('message.expired','message',$id,['message_id'=>$id,'conversation_id'=>(int)$locked->conversation_id,'expired_at'=>$now],'message.expired:'.$id);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
                    if($wpdb->query('COMMIT')===false)throw new RuntimeException('expire_commit_failed');
                    if($attachment>0)SN_Private_Files::delete($attachment,(int)$locked->sender_id);SN_DB::audit('message_expired','message',$id,'success',[],0);do_action('sn_network_event_queued',$event,'message.expired');
                }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('message_expiry_failed','message',$id,'failure',['reason'=>$e->getMessage()],0);}
            }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        }
    }

    public static function translate_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);$actor=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)||$message->deleted_at)return self::not_found();
        $target=str_replace('_','-',trim((string)$request->get_param('target_language')));if(!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/',$target))return self::error('sn_translation_language_invalid','A valid target language is required.',400);
        $plain=SN_Message_Body::decrypt_row($message);if(is_wp_error($plain))return $plain;$text=mb_substr(wp_strip_all_tags((string)$plain),0,self::MAX_TRANSLATE_CHARS);if($text==='')return self::error('sn_translation_empty','This message has no translatable text.',409);
        $result=apply_filters('sn_network_translate_message',null,$text,$target,['message_id'=>$id,'conversation_id'=>(int)$message->conversation_id,'viewer_id'=>$actor]);
        if(!is_array($result)||empty($result['text']))return self::error('sn_translation_provider_unavailable','Translation is unavailable because no approved provider completed the request.',503);
        return rest_ensure_response(['message_id'=>$id,'target_language'=>$target,'text'=>(string)$result['text'],'provider'=>(string)($result['provider']??'approved-adapter'),'source_persisted'=>false]);
    }

    public static function send_voice_note(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files=$request->get_file_params();if(empty($files['attachment'])||!is_array($files['attachment']))return self::error('sn_voice_note_file_required','An audio recording or audio file is required.',400);
        $forward=new WP_REST_Request('POST','/sabri-network/v2/conversations/'.absint($request['id']).'/messages');$forward->set_param('id',absint($request['id']));$forward->set_param('body','');$forward->set_param('message_type','audio');$forward->set_param('client_id',(string)$request->get_param('client_id'));$forward->set_file_params(['attachment'=>$files['attachment']]);
        $result=SN_Message_Integrity::send_message($forward);if(is_wp_error($result))return $result;$data=$result->get_data();$message=$data['message']??[];$id=(int)($message['id']??0);if($id<=0)return self::error('sn_voice_note_send_failed','The voice note could not be finalized.',500);
        global $wpdb;$row=self::message($id);$meta=self::message_meta($row);$meta['voice_note']=['playback_speeds'=>[0.75,1,1.25,1.5,2],'waveform_adapter'=>'sn_network_voice_waveform','transcript_available'=>false];
        $transcript=mb_substr(trim(sanitize_textarea_field((string)$request->get_param('transcript'))),0,10000);if($transcript!==''){$meta['voice_note']['transcript']=$transcript;$meta['voice_note']['transcript_available']=true;}
        $wpdb->update(SN_DB::table('messages'),['metadata'=>(string)wp_json_encode($meta)],['id'=>$id]);return rest_ensure_response(['message_id'=>$id,'message'=>$message,'voice_note'=>$meta['voice_note']]);
    }

    public static function create_update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$actor=get_current_user_id();if(!SN_Policy::consume_rate_limit('update_create',(string)$actor,20,DAY_IN_SECONDS))return self::error('sn_update_rate_limited','Too many temporary updates were created.',429);
        $body=mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('body')))),0,5000);$privacy=sanitize_key((string)$request->get_param('privacy'))?:'contacts';if(!in_array($privacy,['contacts','private','public'],true))$privacy='contacts';
        if($privacy==='public'&&!SN_Policy::can_publish_public_update($actor))return self::error('sn_public_update_forbidden','You cannot publish a public temporary update.',403);
        $attachment=null;$files=$request->get_file_params();if(!empty($files['attachment'])&&is_array($files['attachment'])){$attachment=SN_Private_Files::create_from_upload($files['attachment'],$actor);if(is_wp_error($attachment))return $attachment;}
        if($body===''&&!$attachment)return self::error('sn_empty_update','Write an update or attach approved media.',400);
        $cipher='';if($body!==''){$cipher=SN_Communication_Crypto::encrypt($body,'temporary-update|'.$actor);if(is_wp_error($cipher)){if($attachment)SN_Private_Files::delete((int)$attachment['id'],$actor);return $cipher;}}
        $hours=min(168,max(1,absint($request->get_param('expires_in_hours'))?:24));$now=current_time('mysql',true);$ok=$wpdb->insert(SN_DB::table('updates'),['user_id'=>$actor,'body'=>$cipher,'media_id'=>$attachment?(int)$attachment['id']:0,'media_source'=>$attachment?'private':'none','media_type'=>$attachment?(string)$attachment['type']:'text','privacy'=>$privacy,'expires_at'=>gmdate('Y-m-d H:i:s',time()+$hours*HOUR_IN_SECONDS),'created_at'=>$now]);
        if($ok===false){if($attachment)SN_Private_Files::delete((int)$attachment['id'],$actor);return self::error('sn_update_failed','The temporary update could not be saved.',500);}
        $id=(int)$wpdb->insert_id;SN_DB::audit('update_created','update',$id,'success',['privacy'=>$privacy,'encrypted_body'=>$body!==''],$actor);$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('updates').' WHERE id=%d',$id));return new WP_REST_Response(['update'=>self::format_update($row,$actor)],201);
    }

    public static function get_updates(): WP_REST_Response {
        global $wpdb;$viewer=get_current_user_id();$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.SN_DB::table('updates').' WHERE expires_at>%s ORDER BY created_at DESC LIMIT 200',current_time('mysql',true)));$items=[];
        foreach(is_array($rows)?$rows:[] as $row)if(self::can_view_update($row,$viewer))$items[]=self::format_update($row,$viewer);return rest_ensure_response(['updates'=>$items]);
    }

    public static function view_update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$viewer=get_current_user_id();$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('updates').' WHERE id=%d AND expires_at>%s',$id,current_time('mysql',true)));if(!$row||!self::can_view_update($row,$viewer))return self::not_found();
        if((int)$row->user_id!==$viewer)$wpdb->replace(SN_DB::table('update_views'),['update_id'=>$id,'viewer_id'=>$viewer,'viewed_at'=>current_time('mysql',true)]);return rest_ensure_response(['viewed'=>true]);
    }

    public static function get_community_settings(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$actor=get_current_user_id();$space=self::space($space_id);if(!$space||!self::can_view_space($space_id,$actor,$space))return self::not_found();
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::community_settings_table().' WHERE space_id=%d',$space_id));return rest_ensure_response(['space_id'=>$space_id,'rules_version'=>$row?(int)$row->rules_version:0,'rules'=>$row?(string)$row->rules_text:(string)$space->rules,'join_questions'=>$row?json_decode((string)$row->join_questions,true):[],'orientation'=>$row?(string)$row->orientation:'']);
    }

    public static function update_community_settings(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$actor=get_current_user_id();if(!self::can_manage_space($space_id,$actor))return self::not_found();if(!SN_Policy::consume_rate_limit('community_settings',$actor.':'.$space_id,30,HOUR_IN_SECONDS))return self::error('sn_community_settings_rate_limited','Too many settings changes were submitted.',429);
        $rules=mb_substr(sanitize_textarea_field((string)$request->get_param('rules')),0,10000);$orientation=mb_substr(sanitize_textarea_field((string)$request->get_param('orientation')),0,6000);$questions=[];foreach(array_slice((array)$request->get_param('join_questions'),0,10) as $q){$v=mb_substr(trim(sanitize_text_field((string)$q)),0,300);if($v!=='')$questions[]=$v;}
        $now=current_time('mysql',true);$table=self::community_settings_table();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE space_id=%d",$space_id));
        if($row){$ok=$wpdb->query($wpdb->prepare("UPDATE $table SET rules_version=rules_version+1,rules_text=%s,join_questions=%s,orientation=%s,updated_by=%d,updated_at=%s WHERE space_id=%d",$rules,(string)wp_json_encode($questions),$orientation,$actor,$now,$space_id));}
        else{$ok=$wpdb->insert($table,['space_id'=>$space_id,'rules_version'=>1,'rules_text'=>$rules,'join_questions'=>(string)wp_json_encode($questions),'orientation'=>$orientation,'updated_by'=>$actor,'updated_at'=>$now]);}
        if($ok===false)return self::error('sn_community_settings_failed','Community rules could not be saved.',500);SN_DB::audit('community_settings_updated','space',$space_id,'success',['join_questions'=>count($questions)],$actor);return self::get_community_settings($request);
    }

    public static function create_community_artifact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$actor=get_current_user_id();$space=self::space($space_id);if(!$space||!self::active_space_member($space_id,$actor))return self::not_found();
        $type=sanitize_key((string)$request->get_param('type'));$types=['forum_question','ama_session','wiki_page','event'];if(!in_array($type,$types,true))return self::error('sn_community_artifact_type_invalid','Choose forum_question, ama_session, wiki_page, or event.',400);
        if(in_array($type,['ama_session','wiki_page','event'],true)&&!self::can_manage_space($space_id,$actor))return self::error('sn_community_artifact_forbidden','A moderator or administrator role is required for this item.',403);
        if(!SN_Policy::consume_rate_limit('community_artifact',$actor.':'.$space_id,30,DAY_IN_SECONDS))return self::error('sn_community_artifact_rate_limited','Too many community items were created.',429);
        $title=mb_substr(trim(sanitize_text_field((string)$request->get_param('title'))),0,self::MAX_ARTIFACT_TITLE);$body=mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('body')))),0,self::MAX_ARTIFACT_BODY);if($title===''||($body===''&&$type!=='event'))return self::error('sn_community_artifact_invalid','A title and required body are needed.',400);
        $cipher=$body!==''?SN_Communication_Crypto::encrypt($body,'community-artifact|'.$space_id.'|'.$type):'';if(is_wp_error($cipher))return $cipher;
        $starts=self::optional_time((string)$request->get_param('starts_at'));if(is_wp_error($starts))return $starts;$ends=self::optional_time((string)$request->get_param('ends_at'));if(is_wp_error($ends))return $ends;if($starts&&$ends&&strtotime($ends.' UTC')<=strtotime($starts.' UTC'))return self::error('sn_community_artifact_time_invalid','End time must be after start time.',400);
        $meta=['tags'=>self::clean_list((array)$request->get_param('tags'),20,60),'verified_host'=>$type==='ama_session','personal_diagnosis_prohibited'=>$type==='ama_session','attendance_private_default'=>$type==='event','file06_canonical_integration_required'=>$type==='wiki_page'];$now=current_time('mysql',true);
        $ok=$wpdb->insert(self::artifacts_table(),['space_id'=>$space_id,'type'=>$type,'author_id'=>$actor,'title'=>$title,'body_cipher'=>$cipher,'metadata'=>(string)wp_json_encode($meta),'status'=>'active','starts_at'=>$starts,'ends_at'=>$ends,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_community_artifact_failed','The community item could not be created.',500);
        $id=(int)$wpdb->insert_id;$event=SN_Outbox::enqueue('community_artifact.created','community_artifact',$id,['artifact_id'=>$id,'space_id'=>$space_id,'type'=>$type,'author_id'=>$actor],'community_artifact.created:'.$id);if(!is_wp_error($event))do_action('sn_network_event_queued',$event,'community_artifact.created');SN_DB::audit('community_artifact_created','community_artifact',$id,'success',['space_id'=>$space_id,'type'=>$type],$actor);$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::artifacts_table().' WHERE id=%d',$id));return new WP_REST_Response(['artifact'=>self::format_artifact($row,$actor)],201);
    }

    public static function list_community_artifacts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$actor=get_current_user_id();$space=self::space($space_id);if(!$space||!self::can_view_space($space_id,$actor,$space))return self::not_found();$type=sanitize_key((string)$request->get_param('type'));$types=['forum_question','ama_session','wiki_page','event'];$after=absint($request->get_param('after'));$limit=min(100,max(1,absint($request->get_param('limit'))?:30));
        if(in_array($type,$types,true))$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::artifacts_table()." WHERE space_id=%d AND type=%s AND status='active' AND id>%d ORDER BY id ASC LIMIT %d",$space_id,$type,$after,$limit));else$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::artifacts_table()." WHERE space_id=%d AND status='active' AND id>%d ORDER BY id ASC LIMIT %d",$space_id,$after,$limit));
        return rest_ensure_response(['items'=>array_map(static fn($row)=>self::format_artifact($row,$actor),is_array($rows)?$rows:[])]);
    }

    public static function respond_to_artifact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$artifact_id=absint($request['artifact']);$actor=get_current_user_id();if(!self::active_space_member($space_id,$actor))return self::not_found();$artifact=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::artifacts_table()." WHERE id=%d AND space_id=%d AND status='active'",$artifact_id,$space_id));if(!$artifact)return self::not_found();
        $body=mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('body')))),0,self::MAX_RESPONSE_BODY);if($body==='')return self::error('sn_community_response_required','A response is required.',400);$cipher=SN_Communication_Crypto::encrypt($body,'community-response|'.$artifact_id.'|'.$actor);if(is_wp_error($cipher))return $cipher;
        $kind=(string)$artifact->type==='ama_session'?'question':((string)$artifact->type==='forum_question'?'answer':'comment');$now=current_time('mysql',true);$ok=$wpdb->insert(self::responses_table(),['artifact_id'=>$artifact_id,'user_id'=>$actor,'body_cipher'=>$cipher,'metadata'=>(string)wp_json_encode(['kind'=>$kind]),'status'=>'active','created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_community_response_failed','The response could not be saved.',500);$id=(int)$wpdb->insert_id;SN_DB::audit('community_response_created','community_response',$id,'success',['artifact_id'=>$artifact_id,'kind'=>$kind],$actor);return new WP_REST_Response(['response'=>self::format_response($wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::responses_table().' WHERE id=%d',$id)),$actor)],201);
    }

    public static function moderate_artifact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$artifact_id=absint($request['artifact']);$actor=get_current_user_id();if(!self::can_manage_space($space_id,$actor))return self::not_found();$artifact=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::artifacts_table().' WHERE id=%d AND space_id=%d',$artifact_id,$space_id));if(!$artifact)return self::not_found();$action=sanitize_key((string)$request->get_param('action'));
        if($action==='best_answer'){$response_id=absint($request->get_param('response_id'));$valid=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::responses_table()." WHERE id=%d AND artifact_id=%d AND status='active'",$response_id,$artifact_id));if($valid<=0)return self::error('sn_best_answer_invalid','Select an active answer.',400);$ok=$wpdb->update(self::artifacts_table(),['best_response_id'=>$response_id,'updated_at'=>current_time('mysql',true),'version'=>(int)$artifact->version+1],['id'=>$artifact_id,'version'=>(int)$artifact->version]);}
        elseif(in_array($action,['close','archive','reopen'],true)){$status=$action==='reopen'?'active':($action==='archive'?'archived':'closed');$ok=$wpdb->update(self::artifacts_table(),['status'=>$status,'updated_at'=>current_time('mysql',true),'version'=>(int)$artifact->version+1],['id'=>$artifact_id,'version'=>(int)$artifact->version]);}
        else return self::error('sn_community_moderation_action_invalid','Choose best_answer, close, archive, or reopen.',400);
        if($ok!==1)return self::error('sn_community_moderation_conflict','The community item changed before moderation was saved.',409);SN_DB::audit('community_artifact_moderated','community_artifact',$artifact_id,'success',['action'=>$action],$actor);$fresh=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::artifacts_table().' WHERE id=%d',$artifact_id));return rest_ensure_response(['artifact'=>self::format_artifact($fresh,$actor)]);
    }

    public static function community_health(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$space_id=absint($request['id']);$actor=get_current_user_id();$space=self::space($space_id);if(!$space||!self::can_view_space($space_id,$actor,$space))return self::not_found();$members=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND status='active'",$space_id));$questions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::artifacts_table()." WHERE space_id=%d AND type='forum_question' AND status='active'",$space_id));$answered=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT a.id) FROM ".self::artifacts_table()." a INNER JOIN ".self::responses_table()." r ON r.artifact_id=a.id AND r.status='active' WHERE a.space_id=%d AND a.type='forum_question' AND a.status='active'",$space_id));$reports=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".SN_DB::table('reports')." WHERE conversation_id=%d AND created_at>=%s",(int)$space->conversation_id,gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS)));
        return rest_ensure_response(['space_id'=>$space_id,'member_count'=>$members,'open_questions'=>$questions,'answered_questions'=>$answered,'unanswered_questions'=>max(0,$questions-$answered),'report_count_30d'=>$reports,'engagement_only_score'=>false,'privacy'=>'aggregate-only']);
    }

    public static function admin_status(): WP_REST_Response {
        global $wpdb;$tables=[self::requests_table(),self::scheduled_table(),self::poll_votes_table(),self::community_settings_table(),self::artifacts_table(),self::responses_table()];$missing=[];foreach($tables as $table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)$missing[]=$table;
        return rest_ensure_response(['ok'=>empty($missing),'schema_version'=>self::SCHEMA_VERSION,'missing_tables'=>$missing,'features'=>['message_requests'=>true,'scheduled_messages'=>true,'polls_checklists'=>true,'disappearing_messages'=>true,'translation_adapter'=>true,'encrypted_temporary_updates'=>true,'voice_note_contract'=>true,'community_rules_onboarding'=>true,'forum_mode'=>true,'ama_sessions'=>true,'community_wiki'=>true,'events_cohorts'=>true,'community_health'=>true],'external_gates'=>['approved_translation_provider'=>has_filter('sn_network_translate_message'),'approved_sfu'=>(bool)apply_filters('sn_network_sfu_available',false,get_current_user_id(),0),'staging_acceptance'=>false,'live_deployment'=>false]]);
    }

    public static function emit_contract(): void {
        do_action('sn_network_two_plan_contract_registered',['owner'=>'file-17','version'=>self::SCHEMA_VERSION,'message_requests'=>'/sabri-network/v2/message-requests','scheduled_messages'=>'/sabri-network/v2/conversations/{conversation_id}/scheduled-messages','polls'=>'/sabri-network/v2/conversations/{conversation_id}/polls','checklists'=>'/sabri-network/v2/conversations/{conversation_id}/checklists','community_artifacts'=>'/sabri-network/v2/spaces/{space_id}/community-artifacts','temporary_updates_encryption'=>'authenticated-at-rest','notification_owner'=>'file-19','global_search_owner'=>'file-26','identity_owner'=>'file-00/file-02','clinical_truth_owner'=>'file-08/cf-01']);
    }

    public static function register_exporter(array $exporters): array {$exporters['sabri-network-two-plan']=['exporter_friendly_name'=>'Sabri communication extensions','callback'=>[self::class,'exporter']];return $exporters;}
    public static function register_eraser(array $erasers): array {$erasers['sabri-network-two-plan']=['eraser_friendly_name'=>'Sabri communication extensions','callback'=>[self::class,'eraser']];return $erasers;}

    public static function exporter(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['data'=>[],'done'=>true];$uid=(int)$user->ID;$offset=max(0,$page-1)*100;$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::requests_table().' WHERE requester_id=%d OR recipient_id=%d ORDER BY id ASC LIMIT 100 OFFSET %d',$uid,$uid,$offset));$data=[];
        foreach(is_array($rows)?$rows:[] as $row){$plain=SN_Communication_Crypto::decrypt((string)$row->body_cipher,'message-request|'.(int)$row->requester_id.'|'.(int)$row->recipient_id);$data[]=['group_id'=>'sabri-network-message-requests','group_label'=>'Message requests','item_id'=>'message-request-'.(int)$row->id,'data'=>[['name'=>'Requester','value'=>(int)$row->requester_id],['name'=>'Recipient','value'=>(int)$row->recipient_id],['name'=>'Status','value'=>(string)$row->status],['name'=>'Message','value'=>is_wp_error($plain)?'[encrypted value unavailable]':(string)$plain],['name'=>'Created','value'=>(string)$row->created_at]]];}
        return['data'=>$data,'done'=>count($rows)<100];
    }

    public static function eraser(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;$removed=0;
        $removed+=(int)$wpdb->query($wpdb->prepare("DELETE FROM ".self::scheduled_table()." WHERE sender_id=%d AND status IN ('pending','cancelled','failed') LIMIT 100",$uid));
        $removed+=(int)$wpdb->query($wpdb->prepare("UPDATE ".self::requests_table()." SET body_cipher='',reason='',updated_at=%s WHERE requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>'' LIMIT 100",current_time('mysql',true),$uid));
        return['items_removed'=>$removed>0,'items_retained'=>false,'messages'=>[],'done'=>$removed<200];
    }

    private static function insert_canonical_message(int $conversation_id,int $sender_id,string $body,string $type,array $metadata,string $idempotency,bool $already_in_transaction=false): int|WP_Error {
        global $wpdb;$conversation=self::conversation($conversation_id);if(!$conversation||!SN_DB::is_member($conversation_id,$sender_id))return self::not_found();$policy=self::post_policy($conversation,$sender_id);if(is_wp_error($policy))return $policy;
        $idem=hash('sha256',$idempotency);$existing=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing>0)return $existing;$stored=SN_Message_Body::encrypt($body,$conversation_id,$sender_id);if(is_wp_error($stored))return $stored;$now=current_time('mysql',true);
        $started=!$already_in_transaction;if($started&&$wpdb->query('START TRANSACTION')===false)return self::error('sn_two_plan_transaction_failed','The communication transaction could not be started safely.',503);
        try{$space=SN_Spaces::assert_post_allowed_in_transaction($conversation_id,$sender_id);if(is_wp_error($space))throw new DomainException($space->get_error_code());$ok=$wpdb->insert(SN_DB::table('messages'),['conversation_id'=>$conversation_id,'sender_id'=>$sender_id,'message_type'=>$type,'body'=>$stored,'attachment_id'=>0,'attachment_source'=>'none','reply_to'=>0,'idempotency_key'=>$idem,'metadata'=>(string)wp_json_encode($metadata),'created_at'=>$now]);if($ok===false)throw new RuntimeException('insert_failed');$id=(int)$wpdb->insert_id;
            if($wpdb->query($wpdb->prepare('UPDATE '.SN_DB::table('conversations').' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d',$id,$now,$conversation_id))===false)throw new RuntimeException('pointer_failed');SN_Spaces::mark_posted_for_conversation($conversation_id,$sender_id,$now);$indexed=SN_Message_Search::index_message($id);if(is_wp_error($indexed))throw new RuntimeException($indexed->get_error_code());$event=SN_Outbox::enqueue('message.sent','message',$id,['message_id'=>$id,'conversation_id'=>$conversation_id,'sender_id'=>$sender_id,'message_type'=>$type,'created_at'=>$now],'message.sent:'.$id);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());if($started&&$wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');if(!$already_in_transaction)do_action('sn_network_event_queued',$event,'message.sent');return $id;
        }catch(Throwable $e){if($started)$wpdb->query('ROLLBACK');$race=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));return $race>0?$race:self::error('sn_two_plan_message_failed','The communication item could not be committed safely.',500);}
    }

    private static function post_policy(object $conversation,int $actor): bool|WP_Error {
        $policy=SN_Policy::can_post_to_conversation($conversation,$actor);if(is_wp_error($policy))return $policy;if((string)$conversation->type==='direct'){$peer=self::direct_peer((int)$conversation->id,$actor);if($peer<=0)return self::not_found();$contact=SN_Policy::can_contact($actor,$peer,'message');if(is_wp_error($contact))return $contact;}else{$ids=self::conversation_member_ids((int)$conversation->id,$actor);foreach($ids as $target)if(SN_DB::is_blocked($actor,$target))return self::error('sn_space_member_blocked','A conversation member is unavailable.',403);}return true;
    }

    private static function direct_peer(int $conversation_id,int $actor): int {global $wpdb;return(int)$wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',$conversation_id,$actor));}
    private static function conversation_member_ids(int $conversation_id,int $actor): array {global $wpdb;return array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL',$conversation_id,$actor))?:[]);}
    private static function conversation(int $id): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('conversations')." WHERE id=%d AND status='active'",$id))?:null;}
    private static function message(int $id): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d',$id))?:null;}
    private static function message_meta(?object $message): array {$decoded=$message?json_decode((string)($message->metadata??''),true):null;return is_array($decoded)?$decoded:[];}
    private static function poll_counts(int $message_id,int $count): array {global $wpdb;$out=array_fill(0,$count,0);foreach($wpdb->get_results($wpdb->prepare('SELECT option_index,COUNT(*) total FROM '.self::poll_votes_table().' WHERE message_id=%d GROUP BY option_index',$message_id))?:[] as $row){$i=(int)$row->option_index;if(isset($out[$i]))$out[$i]=(int)$row->total;}return$out;}
    private static function message_has_legal_hold(int $message_id): bool|WP_Error {global $wpdb;$wpdb->last_error='';$held=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('reports').' WHERE message_id=%d AND legal_hold=1 LIMIT 1',$message_id));if($wpdb->last_error!=='')return self::error('sn_legal_hold_read_failed','Legal-hold state could not be verified safely.',503);return $held!==null;}
    private static function schedule_failed(int $id,string $code): void {global $wpdb;$wpdb->update(self::scheduled_table(),['status'=>'failed','last_error'=>sanitize_key($code),'updated_at'=>current_time('mysql',true)],['id'=>$id]);}
    public static function scheduled_payload(object $row): array {return['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'deliver_at'=>(string)$row->deliver_at,'status'=>(string)$row->status,'message_id'=>(int)$row->message_id,'attempts'=>(int)$row->attempts,'last_error'=>(string)$row->last_error];}

    private static function format_request(?object $row,int $viewer): array {if(!$row)return[];$authorized=in_array($viewer,[(int)$row->requester_id,(int)$row->recipient_id],true);$plain=$authorized&&$row->body_cipher!==''?SN_Communication_Crypto::decrypt((string)$row->body_cipher,'message-request|'.(int)$row->requester_id.'|'.(int)$row->recipient_id):'';return['id'=>(int)$row->id,'requester'=>SN_Auth::public_user((int)$row->requester_id),'recipient'=>SN_Auth::public_user((int)$row->recipient_id),'message'=>$authorized?(is_wp_error($plain)?'':(string)$plain):'','message_unavailable'=>is_wp_error($plain),'reason'=>$authorized?(string)$row->reason:'','status'=>(string)$row->status,'version'=>(int)$row->version,'conversation_id'=>(int)$row->conversation_id,'report_id'=>$viewer===(int)$row->recipient_id?(int)$row->report_id:0,'cooldown_until'=>(string)$row->cooldown_until,'created_at'=>(string)$row->created_at,'updated_at'=>(string)$row->updated_at];}

    private static function format_update(object $row,int $viewer): array {$plain='';if((string)$row->body!==''){$prefix=substr((string)$row->body,0,4);if(in_array($prefix,['SNC1','SNC2','SNC3','SNC4'],true)){$plain=SN_Communication_Crypto::decrypt((string)$row->body,'temporary-update|'.(int)$row->user_id);if(is_wp_error($plain))$plain='';}else{$plain=(string)$row->body;self::migrate_legacy_update($row,(string)$plain);}}$media=(int)$row->media_id>0&&(string)$row->media_source==='private'?SN_Private_Files::formatted((int)$row->media_id,$viewer):null;return['id'=>(int)$row->id,'user'=>SN_Auth::public_user((int)$row->user_id),'body'=>(string)$plain,'media'=>$media,'media_type'=>(string)$row->media_type,'privacy'=>(string)$row->privacy,'expires_at'=>(string)$row->expires_at,'created_at'=>(string)$row->created_at];}
    private static function migrate_legacy_update(object $row,string $plain): void {global $wpdb;if($plain==='')return;$cipher=SN_Communication_Crypto::encrypt($plain,'temporary-update|'.(int)$row->user_id);if(is_wp_error($cipher))return;$wpdb->update(SN_DB::table('updates'),['body'=>$cipher],['id'=>(int)$row->id,'body'=>(string)$row->body]);}
    private static function can_view_update(object $row,int $viewer): bool {if((int)$row->user_id===$viewer)return true;if(SN_DB::is_blocked($viewer,(int)$row->user_id))return false;if((string)$row->privacy==='private')return false;if((string)$row->privacy==='public')return true;return SN_DB::are_contacts($viewer,(int)$row->user_id);}

    private static function space(int $id): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('spaces').' WHERE id=%d',$id))?:null;}
    private static function active_space_member(int $space_id,int $user_id): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status='active'",$space_id,$user_id))?:null;}
    private static function can_view_space(int $space_id,int $user_id,?object $space=null): bool {$space=$space?:self::space($space_id);if(!$space||in_array((string)$space->state,['closed','deletion_requested'],true))return false;if(in_array((string)$space->visibility,['public','discoverable_private'],true))return true;return(bool)self::active_space_member($space_id,$user_id);}
    private static function can_manage_space(int $space_id,int $user_id): bool {$m=self::active_space_member($space_id,$user_id);return$m&&in_array((string)$m->role,['owner','administrator','moderator'],true);}

    private static function format_artifact(object $row,int $viewer): array {$plain='';if((string)$row->body_cipher!==''){$plain=SN_Communication_Crypto::decrypt((string)$row->body_cipher,'community-artifact|'.(int)$row->space_id.'|'.(string)$row->type);if(is_wp_error($plain))$plain='';}global $wpdb;$response_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::responses_table()." WHERE artifact_id=%d AND status='active'",(int)$row->id));return['id'=>(int)$row->id,'space_id'=>(int)$row->space_id,'type'=>(string)$row->type,'author'=>SN_Auth::public_user((int)$row->author_id),'title'=>(string)$row->title,'body'=>(string)$plain,'metadata'=>json_decode((string)$row->metadata,true)?:[],'status'=>(string)$row->status,'best_response_id'=>(int)$row->best_response_id,'response_count'=>$response_count,'starts_at'=>(string)$row->starts_at,'ends_at'=>(string)$row->ends_at,'version'=>(int)$row->version,'created_at'=>(string)$row->created_at,'updated_at'=>(string)$row->updated_at];}
    private static function format_response(object $row,int $viewer): array {$plain=SN_Communication_Crypto::decrypt((string)$row->body_cipher,'community-response|'.(int)$row->artifact_id.'|'.(int)$row->user_id);return['id'=>(int)$row->id,'artifact_id'=>(int)$row->artifact_id,'user'=>SN_Auth::public_user((int)$row->user_id),'body'=>is_wp_error($plain)?'':(string)$plain,'metadata'=>json_decode((string)$row->metadata,true)?:[],'status'=>(string)$row->status,'version'=>(int)$row->version,'created_at'=>(string)$row->created_at];}

    private static function link_risk(string $text): bool|WP_Error {$urls=[];preg_match_all('~https?://[^\\s<]+~iu',$text,$m);$urls=$m[0]??[];if(count($urls)>5)return self::error('sn_link_safety_limit','Too many external links were included.',400);foreach($urls as $url){$parts=wp_parse_url($url);$scheme=strtolower((string)($parts['scheme']??''));if(!in_array($scheme,['http','https'],true))return self::error('sn_link_safety_protocol','An unsafe link protocol was blocked.',400);$decision=apply_filters('sn_network_link_safety_result',true,$url);if($decision!==true)return is_wp_error($decision)?$decision:self::error('sn_link_safety_blocked','A link was blocked by the communication safety policy.',403);}return true;}
    private static function client_id(string $raw): string|WP_Error {$raw=strtolower(trim($raw))?:strtolower(wp_generate_uuid4());return preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$raw)?$raw:self::error('sn_client_id_invalid','A valid idempotency identifier is required.',400);}
    private static function unordered_pair_key(int $a,int $b): string {return hash('sha256',min($a,$b).':'.max($a,$b));}
    private static function optional_time(string $raw): string|WP_Error|null {$raw=trim($raw);if($raw==='')return null;$ts=strtotime($raw);return $ts?gmdate('Y-m-d H:i:s',$ts):self::error('sn_time_invalid','Use a valid date and time.',400);}
    private static function clean_list(array $items,int $max,int $chars): array {$out=[];foreach(array_slice($items,0,$max) as $item){$v=mb_substr(trim(sanitize_text_field((string)$item)),0,$chars);if($v!==''&&!in_array($v,$out,true))$out[]=$v;}return$out;}

    private static function requests_table(): string{return SN_DB::table('message_requests');}
    private static function scheduled_table(): string{return SN_DB::table('scheduled_messages');}
    private static function poll_votes_table(): string{return SN_DB::table('poll_votes');}
    private static function community_settings_table(): string{return SN_DB::table('community_settings');}
    private static function artifacts_table(): string{return SN_DB::table('community_artifacts');}
    private static function responses_table(): string{return SN_DB::table('community_responses');}
    private static function not_found(): WP_Error{return self::error('sn_not_found','The requested communication object is unavailable.',404);}
    private static function error(string $code,string $message,int $status): WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
}
