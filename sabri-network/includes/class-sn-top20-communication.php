<?php
/**
 * Governed Top-20 communication extensions for File 17.
 * Adds scheduled messages, polls, checklists, disappearing-message expiry and
 * a fail-closed translation adapter without creating a parallel chat backend.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Top20_Communication {
    private const SCHEMA_VERSION = '1.0.0';
    private const MAX_SCHEDULE_DAYS = 90;
    private const MAX_POLL_OPTIONS = 12;
    private const MAX_CHECKLIST_ITEMS = 50;
    private const MAX_TRANSLATE_CHARS = 10000;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'dispatch_due']);
        add_action('sn_cleanup_hourly', [self::class, 'expire_messages']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE ".self::scheduled_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            body LONGTEXT NOT NULL,
            deliver_at DATETIME NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY due (status,deliver_at),
            KEY sender_status (sender_id,status)
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
        update_option('sn_top20_communication_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string)get_option('sn_top20_communication_schema_version', '') !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    public static function register_routes(): void {
        $access = [SN_REST::class, 'access'];
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
            'methods'=>'POST','callback'=>[self::class,'set_expiry'],'permission_callback'=>$access,
        ]);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)/translate', [
            'methods'=>'POST','callback'=>[self::class,'translate_message'],'permission_callback'=>$access,
        ]);
    }

    public static function schedule_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']); $actor = get_current_user_id();
        $conversation = self::conversation($conversation_id);
        if (!$conversation || !SN_DB::is_member($conversation_id, $actor)) return self::not_found();
        $policy = SN_Policy::can_post_to_conversation($conversation, $actor); if (is_wp_error($policy)) return $policy;
        if (!SN_Policy::consume_rate_limit('scheduled_message', (string)$actor, 60, DAY_IN_SECONDS)) return self::error('sn_schedule_rate_limited','Too many scheduled messages were requested.',429);
        $body = trim(wp_kses_post((string)$request->get_param('body')));
        if ($body === '' || mb_strlen(wp_strip_all_tags($body)) > 10000) return self::error('sn_schedule_body_invalid','A valid message body is required.',400);
        $deliver_raw = (string)$request->get_param('deliver_at'); $ts = strtotime($deliver_raw);
        $now_ts = time(); if (!$ts || $ts < $now_ts + 60 || $ts > $now_ts + self::MAX_SCHEDULE_DAYS * DAY_IN_SECONDS) return self::error('sn_schedule_time_invalid','Delivery time must be between one minute and 90 days from now.',400);
        $client = strtolower(trim((string)$request->get_param('client_id'))) ?: wp_generate_uuid4();
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) return self::error('sn_schedule_client_invalid','A valid idempotency key is required.',400);
        $idem = hash('sha256', $actor.':'.$conversation_id.':scheduled:'.$client); $now = current_time('mysql', true); $deliver = gmdate('Y-m-d H:i:s', $ts);
        $ok = $wpdb->insert(self::scheduled_table(), ['conversation_id'=>$conversation_id,'sender_id'=>$actor,'body'=>$body,'deliver_at'=>$deliver,'idempotency_key'=>$idem,'status'=>'pending','created_at'=>$now,'updated_at'=>$now]);
        if ($ok === false) { $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE idempotency_key=%s',$idem)); if($row) return rest_ensure_response(self::scheduled_payload($row)); return self::error('sn_schedule_failed','The scheduled message could not be saved.',500); }
        $id=(int)$wpdb->insert_id; SN_DB::audit('message_scheduled','scheduled_message',$id,'success',['conversation_id'=>$conversation_id,'deliver_at'=>$deliver],$actor);
        return new WP_REST_Response(['id'=>$id,'conversation_id'=>$conversation_id,'deliver_at'=>$deliver,'status'=>'pending'],201);
    }

    public static function list_scheduled(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $conversation_id=absint($request['id']); $actor=get_current_user_id();
        if (!SN_DB::is_member($conversation_id,$actor)) return self::not_found();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE conversation_id=%d AND sender_id=%d AND status IN (\'pending\',\'failed\') ORDER BY deliver_at ASC LIMIT 100',$conversation_id,$actor));
        return rest_ensure_response(['items'=>array_map([self::class,'scheduled_payload'],$rows ?: [])]);
    }

    public static function cancel_scheduled(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $id=absint($request['id']); $actor=get_current_user_id();
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE id=%d AND sender_id=%d',$id,$actor)); if(!$row) return self::not_found();
        if((string)$row->status!=='pending') return self::error('sn_schedule_not_pending','Only pending scheduled messages can be cancelled.',409);
        if($wpdb->update(self::scheduled_table(),['status'=>'cancelled','updated_at'=>current_time('mysql',true)],['id'=>$id,'sender_id'=>$actor,'status'=>'pending'])===false) return self::error('sn_schedule_cancel_failed','The scheduled message could not be cancelled.',500);
        SN_DB::audit('message_schedule_cancelled','scheduled_message',$id,'success',[],$actor); return rest_ensure_response(['id'=>$id,'status'=>'cancelled']);
    }

    public static function dispatch_due(): void {
        global $wpdb; $now=current_time('mysql',true);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::scheduled_table().' WHERE status=\'pending\' AND deliver_at<=%s ORDER BY id ASC LIMIT 50',$now));
        foreach($rows ?: [] as $row){
            $claimed=$wpdb->query($wpdb->prepare('UPDATE '.self::scheduled_table().' SET status=\'processing\',updated_at=%s WHERE id=%d AND status=\'pending\'',$now,(int)$row->id)); if($claimed!==1) continue;
            $conversation=self::conversation((int)$row->conversation_id); $policy=$conversation ? SN_Policy::can_post_to_conversation($conversation,(int)$row->sender_id) : self::error('sn_schedule_conversation_missing','Conversation unavailable.',404);
            if(is_wp_error($policy)){self::mark_schedule_failed((int)$row->id,$policy->get_error_code());continue;}
            $message=self::insert_message((int)$row->conversation_id,(int)$row->sender_id,(string)$row->body,'text',['scheduled'=>true,'scheduled_id'=>(int)$row->id],(string)$row->idempotency_key);
            if(is_wp_error($message)){self::mark_schedule_failed((int)$row->id,$message->get_error_code());continue;}
            $wpdb->update(self::scheduled_table(),['status'=>'sent','message_id'=>$message,'last_error'=>'','updated_at'=>$now],['id'=>(int)$row->id]);
            SN_DB::audit('scheduled_message_sent','message',$message,'success',['scheduled_id'=>(int)$row->id],(int)$row->sender_id);
        }
    }

    public static function create_poll(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation_id=absint($request['id']); $actor=get_current_user_id(); $conversation=self::conversation($conversation_id);
        if(!$conversation||!SN_DB::is_member($conversation_id,$actor)) return self::not_found(); $policy=SN_Policy::can_post_to_conversation($conversation,$actor); if(is_wp_error($policy)) return $policy;
        $question=trim(sanitize_text_field((string)$request->get_param('question'))); $raw=$request->get_param('options'); $options=[];
        foreach(is_array($raw)?$raw:[] as $option){$option=trim(sanitize_text_field((string)$option)); if($option!==''&&!in_array($option,$options,true))$options[]=$option;}
        if($question===''||count($options)<2||count($options)>self::MAX_POLL_OPTIONS) return self::error('sn_poll_invalid','Polls require a question and 2–12 unique options.',400);
        $id=self::insert_message($conversation_id,$actor,$question,'poll',['poll'=>['question'=>$question,'options'=>$options,'single_choice'=>true]],'poll:'.$actor.':'.$conversation_id.':'.wp_generate_uuid4()); if(is_wp_error($id)) return $id;
        return new WP_REST_Response(['message_id'=>$id,'question'=>$question,'options'=>$options],201);
    }

    public static function vote_poll(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $id=absint($request['id']); $actor=get_current_user_id(); $message=self::message($id); if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)||$message->deleted_at) return self::not_found();
        $meta=self::meta($message); $options=$meta['poll']['options'] ?? null; if((string)$message->message_type!=='poll'||!is_array($options)) return self::error('sn_poll_required','The message is not an active poll.',409);
        $option=absint($request->get_param('option')); if(!array_key_exists($option,$options)) return self::error('sn_poll_option_invalid','The selected poll option is invalid.',400); $now=current_time('mysql',true);
        $sql=$wpdb->prepare('INSERT INTO '.self::poll_votes_table().' (message_id,user_id,option_index,created_at,updated_at) VALUES (%d,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE option_index=VALUES(option_index),updated_at=VALUES(updated_at)',$id,$actor,$option,$now,$now);
        if($wpdb->query($sql)===false) return self::error('sn_poll_vote_failed','The poll vote could not be saved.',500);
        $counts=array_fill(0,count($options),0); foreach($wpdb->get_results($wpdb->prepare('SELECT option_index,COUNT(*) total FROM '.self::poll_votes_table().' WHERE message_id=%d GROUP BY option_index',$id)) ?: [] as $row){if(isset($counts[(int)$row->option_index]))$counts[(int)$row->option_index]=(int)$row->total;}
        SN_DB::audit('poll_vote_changed','message',$id,'success',['option'=>$option],$actor); return rest_ensure_response(['message_id'=>$id,'option'=>$option,'counts'=>$counts]);
    }

    public static function create_checklist(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation_id=absint($request['id']); $actor=get_current_user_id(); $conversation=self::conversation($conversation_id); if(!$conversation||!SN_DB::is_member($conversation_id,$actor)) return self::not_found(); $policy=SN_Policy::can_post_to_conversation($conversation,$actor); if(is_wp_error($policy)) return $policy;
        $title=trim(sanitize_text_field((string)$request->get_param('title'))); $raw=$request->get_param('items'); $items=[]; foreach(is_array($raw)?$raw:[] as $item){$label=trim(sanitize_text_field((string)$item)); if($label!=='')$items[]=['label'=>$label,'done'=>false,'by'=>0,'at'=>''];}
        if($title===''||count($items)<1||count($items)>self::MAX_CHECKLIST_ITEMS) return self::error('sn_checklist_invalid','Checklists require a title and 1–50 items.',400);
        $id=self::insert_message($conversation_id,$actor,$title,'checklist',['checklist'=>['title'=>$title,'items'=>$items]],'checklist:'.$actor.':'.$conversation_id.':'.wp_generate_uuid4()); if(is_wp_error($id)) return $id; return new WP_REST_Response(['message_id'=>$id,'title'=>$title,'items'=>$items],201);
    }

    public static function toggle_checklist(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $id=absint($request['id']); $index=absint($request['item']); $actor=get_current_user_id(); $message=self::message($id); if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)||$message->deleted_at) return self::not_found();
        $meta=self::meta($message); $items=$meta['checklist']['items'] ?? null; if((string)$message->message_type!=='checklist'||!is_array($items)||!array_key_exists($index,$items)) return self::error('sn_checklist_item_invalid','The checklist item is unavailable.',409);
        $desired=$request->get_param('done'); $done=is_bool($desired)?$desired:filter_var($desired,FILTER_VALIDATE_BOOLEAN); $items[$index]['done']=$done; $items[$index]['by']=$actor; $items[$index]['at']=current_time('mysql',true); $meta['checklist']['items']=$items;
        if($wpdb->update(SN_DB::table('messages'),['metadata'=>(string)wp_json_encode($meta),'edited_at'=>current_time('mysql',true)],['id'=>$id])===false) return self::error('sn_checklist_update_failed','The checklist could not be updated.',500);
        SN_DB::audit('checklist_item_changed','message',$id,'success',['item'=>$index,'done'=>$done],$actor); return rest_ensure_response(['message_id'=>$id,'items'=>$items]);
    }

    public static function set_expiry(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $id=absint($request['id']); $actor=get_current_user_id(); $message=self::message($id); if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)) return self::not_found(); if((int)$message->sender_id!==$actor) return self::error('sn_expiry_author_required','Only the sender may configure disappearing-message expiry.',403);
        $seconds=absint($request->get_param('seconds')); if(!in_array($seconds,[0,3600,86400,604800,2592000],true)) return self::error('sn_expiry_invalid','Expiry must be off, 1 hour, 1 day, 7 days or 30 days.',400); $meta=self::meta($message);
        if($seconds===0) unset($meta['expires_at']); else $meta['expires_at']=gmdate('Y-m-d H:i:s',time()+$seconds);
        if($wpdb->update(SN_DB::table('messages'),['metadata'=>(string)wp_json_encode($meta)],['id'=>$id,'sender_id'=>$actor])===false) return self::error('sn_expiry_failed','The expiry setting could not be saved.',500); return rest_ensure_response(['message_id'=>$id,'expires_at'=>$meta['expires_at'] ?? null]);
    }

    public static function expire_messages(): void {
        global $wpdb; $now=current_time('mysql',true); $rows=$wpdb->get_results('SELECT id,metadata FROM '.SN_DB::table('messages').' WHERE deleted_at IS NULL AND metadata IS NOT NULL ORDER BY id DESC LIMIT 1000');
        foreach($rows ?: [] as $row){$meta=json_decode((string)$row->metadata,true); $expires=is_array($meta)?($meta['expires_at']??''):''; if(!$expires||$expires>$now)continue; $wpdb->update(SN_DB::table('messages'),['body'=>'','attachment_id'=>0,'attachment_source'=>'none','metadata'=>(string)wp_json_encode(['expired'=>true]),'deleted_at'=>$now],['id'=>(int)$row->id]); SN_DB::audit('message_expired','message',(int)$row->id,'success',[] ,0);}
    }

    public static function translate_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']); $actor=get_current_user_id(); $message=self::message($id); if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor)||$message->deleted_at) return self::not_found(); $target=sanitize_key((string)$request->get_param('target_language')); if(!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/',$target)) return self::error('sn_translation_language_invalid','A valid target language is required.',400);
        $text=mb_substr(wp_strip_all_tags((string)$message->body),0,self::MAX_TRANSLATE_CHARS); if($text==='') return self::error('sn_translation_empty','This message has no translatable text.',409);
        $result=apply_filters('sn_network_translate_message',null,$text,$target,['message_id'=>$id,'conversation_id'=>(int)$message->conversation_id,'viewer_id'=>$actor]);
        if(!is_array($result)||empty($result['text'])) return self::error('sn_translation_provider_unavailable','Translation is unavailable because no approved provider completed the request.',503);
        return rest_ensure_response(['message_id'=>$id,'target_language'=>$target,'text'=>(string)$result['text'],'provider'=>(string)($result['provider']??'approved-adapter')]);
    }

    public static function register_exporter(array $exporters): array {$exporters['sabri-network-scheduled-messages']=['exporter_friendly_name'=>'Sabri scheduled messages','callback'=>[self::class,'exporter']]; return $exporters;}
    public static function register_eraser(array $erasers): array {$erasers['sabri-network-scheduled-messages']=['eraser_friendly_name'=>'Sabri scheduled messages','callback'=>[self::class,'eraser']]; return $erasers;}
    public static function exporter(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return ['data'=>[],'done'=>true];$rows=$wpdb->get_results($wpdb->prepare('SELECT id,conversation_id,deliver_at,status,created_at FROM '.self::scheduled_table().' WHERE sender_id=%d ORDER BY id ASC LIMIT 100 OFFSET %d',(int)$user->ID,max(0,$page-1)*100));$data=[];foreach($rows?:[] as $row)$data[]=['group_id'=>'sabri-network-scheduled-messages','group_label'=>'Scheduled messages','item_id'=>'scheduled-'.(int)$row->id,'data'=>[['name'=>'Conversation','value'=>(int)$row->conversation_id],['name'=>'Delivery','value'=>(string)$row->deliver_at],['name'=>'Status','value'=>(string)$row->status],['name'=>'Created','value'=>(string)$row->created_at]]];return ['data'=>$data,'done'=>count($rows)<100];}
    public static function eraser(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$removed=$wpdb->query($wpdb->prepare('DELETE FROM '.self::scheduled_table().' WHERE sender_id=%d AND status IN (\'pending\',\'cancelled\',\'failed\') LIMIT 100',(int)$user->ID));return ['items_removed'=>$removed>0,'items_retained'=>false,'messages'=>[],'done'=>($removed??0)<100];}

    private static function insert_message(int $conversation_id,int $sender_id,string $body,string $type,array $metadata,string $idempotency): int|WP_Error {global $wpdb;$idem=hash('sha256',$idempotency);$existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing)return (int)$existing;$now=current_time('mysql',true);$wpdb->query('START TRANSACTION');try{$space=SN_Spaces::assert_post_allowed_in_transaction($conversation_id,$sender_id);if(is_wp_error($space)){$wpdb->query('ROLLBACK');return $space;}$ok=$wpdb->insert(SN_DB::table('messages'),['conversation_id'=>$conversation_id,'sender_id'=>$sender_id,'message_type'=>$type,'body'=>$body,'attachment_id'=>0,'attachment_source'=>'none','reply_to'=>0,'idempotency_key'=>$idem,'metadata'=>(string)wp_json_encode($metadata),'created_at'=>$now]);if($ok===false)throw new RuntimeException('insert_failed');$id=(int)$wpdb->insert_id;$wpdb->query($wpdb->prepare('UPDATE '.SN_DB::table('conversations').' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d',$id,$now,$conversation_id));SN_Spaces::mark_posted_for_conversation($conversation_id,$sender_id,$now);$indexed=SN_Message_Search::index_message($id);if(is_wp_error($indexed))throw new RuntimeException($indexed->get_error_code());$event=SN_Outbox::enqueue('message.created','message',$id,['message_id'=>$id,'conversation_id'=>$conversation_id,'sender_id'=>$sender_id,'message_type'=>$type],'message.created:'.$id);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');return $id;}catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_top20_message_failed','The communication item could not be committed.',500);}}
    private static function mark_schedule_failed(int $id,string $code): void {global $wpdb;$wpdb->update(self::scheduled_table(),['status'=>'failed','last_error'=>sanitize_key($code),'updated_at'=>current_time('mysql',true)],['id'=>$id]);}
    private static function scheduled_payload(object $row): array {return ['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'deliver_at'=>(string)$row->deliver_at,'status'=>(string)$row->status,'message_id'=>(int)$row->message_id];}
    private static function conversation(int $id): ?object {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('conversations').' WHERE id=%d AND status=\'active\'',$id)) ?: null;}
    private static function message(int $id): ?object {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d',$id)) ?: null;}
    private static function meta(object $message): array {$decoded=json_decode((string)($message->metadata??''),true);return is_array($decoded)?$decoded:[];}
    private static function scheduled_table(): string {return SN_DB::table('scheduled_messages');}
    private static function poll_votes_table(): string {return SN_DB::table('poll_votes');}
    private static function not_found(): WP_Error {return self::error('sn_not_found','The requested communication object is unavailable.',404);}
    private static function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,['status'=>$status]);}
}
