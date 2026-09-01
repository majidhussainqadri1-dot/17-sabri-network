<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Smail_Runtime_Hardening {
    private const MAX_RECIPIENTS = 50;
    private const MAX_SUBJECT = 200;
    private const LOCK_TIMEOUT = 5;
    private const ERASE_BATCH = 100;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2150);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_eraser'], 1300);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/smail/send', ['methods'=>'POST','callback'=>[self::class,'send'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/smail/messages/(?P<id>\d+)/state', ['methods'=>'POST','callback'=>[self::class,'update_state'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/smail/drafts', [
            ['methods'=>'GET','callback'=>[SN_Smail::class,'list_drafts'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'save_draft'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/smail/drafts/(?P<public_id>[a-f0-9-]{36})', [
            ['methods'=>'GET','callback'=>[SN_Smail::class,'get_draft'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'save_draft'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'DELETE','callback'=>[self::class,'delete_draft'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
    }

    public static function send(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $sender=get_current_user_id();
        $recipients=array_values(array_diff(array_values(array_unique(array_filter(array_map('absint',(array)$request->get_param('recipient_ids'))))),[$sender]));
        sort($recipients,SORT_NUMERIC);
        if(!$recipients||count($recipients)>self::MAX_RECIPIENTS)return new WP_Error('invalid_recipients','Select between one and fifty permitted recipients.',['status'=>400]);
        $subject=mb_substr(sanitize_text_field((string)$request->get_param('subject')),0,self::MAX_SUBJECT);
        $body=trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('body'))));
        if($subject===''||$body==='')return new WP_Error('smail_content_required','A subject and message are required.',['status'=>400]);
        if(!SN_Policy::consume_rate_limit('smail_send',(string)$sender,60,HOUR_IN_SECONDS))return new WP_Error('smail_rate_limited','Too many Smail messages were sent. Try again later.',['status'=>429]);
        $client=strtolower(trim((string)$request->get_param('client_id')));
        if($client===''||!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$client))return new WP_Error('invalid_client_id','A caller-supplied Smail idempotency key is required.',['status'=>400]);
        $client_key=hash('sha256',$sender.'|'.$client);
        $locks=['sn:f17:smail:'.$client_key];foreach($recipients as $recipient)$locks[]=SN_Relationships::pair_lock_name($sender,$recipient);
        return self::with_locks($locks,function()use($request,$sender,$recipients,$subject,$body,$client_key,$wpdb){
            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
            if($wpdb->last_error!==''){SN_DB::audit('smail_idempotency_lookup_failed','smail',0,'failure',['reason'=>(string)$wpdb->last_error],$sender);return new WP_Error('smail_idempotency_unavailable','Smail idempotency state could not be verified safely.',['status'=>503]);}
            if($existing){$match=self::idempotency_matches($existing,$sender,$recipients,$subject,$body);if(is_wp_error($match))return $match;return rest_ensure_response(['smail'=>self::format($existing),'duplicate'=>true]);}
            foreach($recipients as $recipient){$allowed=SN_Policy::can_contact($sender,$recipient,count($recipients)===1?'message':'group');if(is_wp_error($allowed))return $allowed;}
            $conversation=SN_Central_Plan_Hardening::resolve_smail_conversation($sender,$recipients,$subject,$client_key);if(is_wp_error($conversation))return $conversation;$conversation=(int)$conversation;if($conversation<=0)return new WP_Error('smail_conversation_failed','The Smail conversation could not be resolved.',['status'=>500]);
            foreach($recipients as $recipient){$allowed=SN_Policy::can_contact($sender,$recipient,count($recipients)===1?'message':'group');if(is_wp_error($allowed))return $allowed;}
            $message_request=new WP_REST_Request('POST','/sabri-network/v2/conversations/'.$conversation.'/messages');$message_request->set_param('id',$conversation);$message_request->set_param('body',$body);$message_request->set_param('message_type','text');$message_request->set_param('client_id','smail:'.substr($client_key,0,40));
            $message_response=SN_Message_Runtime_Hardening::send_message($message_request);if(is_wp_error($message_response))return $message_response;$message_data=$message_response->get_data();$message_id=absint($message_data['message']['id']??0);if($message_id<=0)return new WP_Error('smail_message_failed','The canonical message could not be confirmed.',['status'=>500]);
            $now=current_time('mysql',true);$smail_id=0;$event=null;
            if($wpdb->query('START TRANSACTION')===false)return new WP_Error('smail_projection_failed','The Smail projection transaction could not start.',['status'=>500]);
            try{
                $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s FOR UPDATE',$client_key));
                if($existing){$wpdb->query('ROLLBACK');$match=self::idempotency_matches($existing,$sender,$recipients,$subject,$body);if(is_wp_error($match))return $match;return rest_ensure_response(['smail'=>self::format($existing),'message'=>$message_data['message']??null,'duplicate'=>true]);}
                if($wpdb->insert(SN_DB::table('smail_messages'),['message_id'=>$message_id,'conversation_id'=>$conversation,'sender_id'=>$sender,'subject'=>$subject,'client_key'=>$client_key,'created_at'=>$now])===false)throw new RuntimeException('smail_projection_failed');
                $smail_id=(int)$wpdb->insert_id;
                foreach(array_values(array_unique(array_merge([$sender],$recipients))) as $user){if($wpdb->insert(SN_DB::table('smail_states'),['smail_message_id'=>$smail_id,'user_id'=>$user,'updated_at'=>$now,'read_at'=>$user===$sender?$now:null])===false)throw new RuntimeException('smail_state_failed');}
                $event=SN_Outbox::enqueue('smail.sent','smail',$smail_id,['smail_id'=>$smail_id,'conversation_id'=>$conversation,'message_id'=>$message_id,'sender_id'=>$sender,'recipient_count'=>count($recipients)],'smail-sent-'.$smail_id);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('smail_projection_commit_failed');
            }catch(Throwable $e){$wpdb->query('ROLLBACK');$race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));if($race){$match=self::idempotency_matches($race,$sender,$recipients,$subject,$body);if(is_wp_error($match))return $match;return rest_ensure_response(['smail'=>self::format($race),'message'=>$message_data['message']??null,'duplicate'=>true,'commit_reconciled'=>true]);}SN_DB::audit('smail_projection_failed','message',$message_id,'failure',['conversation_id'=>$conversation,'reason'=>$e->getMessage()],$sender);return new WP_Error('smail_projection_failed','The canonical message exists but its mailbox projection needs a safe retry.',['status'=>503,'message_id'=>$message_id]);}
            foreach($recipients as $recipient)SN_DB::add_notification($recipient,'smail_received','New Smail message','','smail',$smail_id);do_action('sn_network_event_queued',$event,'smail.sent');SN_DB::audit('smail_sent','smail',$smail_id,'success',['conversation_id'=>$conversation,'recipients'=>count($recipients)],$sender);
            $draft=sanitize_text_field((string)$request->get_param('draft_id'));if($draft!=='')self::trash_draft($draft,$sender);
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE id=%d',$smail_id));return rest_ensure_response(['smail'=>self::format($row),'message'=>$message_data['message']??null]);
        });
    }

    private static function idempotency_matches(object $smail,int $sender,array $recipients,string $subject,string $body): bool|WP_Error {
        global $wpdb;
        if((int)$smail->sender_id!==$sender||!hash_equals((string)$smail->subject,$subject))return self::idempotency_conflict();
        $stored=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('smail_states').' WHERE smail_message_id=%d AND user_id<>%d ORDER BY user_id ASC',(int)$smail->id,$sender))?:[]);
        sort($stored,SORT_NUMERIC);$expected=array_values($recipients);sort($expected,SORT_NUMERIC);
        if($stored!==$expected)return self::idempotency_conflict();
        $message=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d',(int)$smail->message_id,(int)$smail->conversation_id));
        if(!$message||$message->deleted_at!==null)return new WP_Error('smail_idempotency_source_unavailable','The original Smail message is unavailable for safe retry reconciliation.',['status'=>409]);
        $plain=SN_Message_Body::decrypt_row($message);if(is_wp_error($plain))return new WP_Error('smail_idempotency_source_unavailable','The original Smail message cannot be verified for safe retry reconciliation.',['status'=>503]);
        return hash_equals((string)$plain,$body)?true:self::idempotency_conflict();
    }

    private static function idempotency_conflict(): WP_Error {return new WP_Error('smail_idempotency_conflict','This Smail idempotency key was already used for a different request.',['status'=>409]);}

    public static function update_state(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);$user=get_current_user_id();
        return self::with_locks(['sn:f17:smail-state:'.$user.':'.$id],function()use($request,$id,$user){global $wpdb;$table=SN_DB::table('smail_states');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE smail_message_id=%d AND user_id=%d",$id,$user));if(!$row)return new WP_Error('smail_not_found','The Smail item is unavailable.',['status'=>404]);$allowed=['starred'=>'is_starred','archived'=>'is_archived','spam'=>'is_spam','trashed'=>'trashed_at','read'=>'read_at'];$field=sanitize_key((string)$request->get_param('field'));if(!isset($allowed[$field]))return new WP_Error('invalid_smail_state','Select a valid Smail state.',['status'=>400]);$raw=$request->get_param('value');if(!is_bool($raw))return new WP_Error('invalid_smail_state_value','Smail state values must be JSON booleans.',['status'=>400]);$column=$allowed[$field];$now=current_time('mysql',true);$value=$raw;$data=['updated_at'=>$now,$column=>in_array($column,['trashed_at','read_at'],true)?($value?$now:null):($value?1:0)];$changed=$wpdb->update($table,$data,['id'=>(int)$row->id]);if($changed===false)return new WP_Error('smail_state_failed','The Smail state could not be updated.',['status'=>500]);SN_DB::audit('smail_state_updated','smail',$id,'success',['field'=>$field,'value'=>$value],$user);return rest_ensure_response(['updated'=>true,'field'=>$field,'value'=>$value]);});
    }

    public static function save_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$owner=get_current_user_id();$public=sanitize_text_field((string)($request['public_id']?:$request->get_param('id')));$lock='sn:f17:smail-draft:'.$owner.':'.($public!==''?$public:'new');
        return self::with_locks([$lock],function()use($request,$owner,$public,$wpdb){if($public!==''){$row=$wpdb->get_row($wpdb->prepare('SELECT version FROM '.SN_DB::table('smail_drafts').' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',$public,$owner));if(!$row)return new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]);$expected=absint($request->get_param('version'));if($expected<=0)return new WP_Error('draft_version_required','The current draft version is required for updates.',['status'=>400]);if($expected!==(int)$row->version)return new WP_Error('draft_conflict','The Smail draft changed on another device.',['status'=>409]);}return SN_Smail::save_draft($request);});
    }

    public static function delete_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $owner=get_current_user_id();$public=sanitize_text_field((string)$request['public_id']);return self::with_locks(['sn:f17:smail-draft:'.$owner.':'.$public],static fn()=>self::trash_draft($public,$owner)?rest_ensure_response(['deleted'=>true]):new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]));
    }

    public static function override_eraser(array $erasers): array {
        if(isset($erasers['sabri-network-smail']))$erasers['sabri-network-smail']['callback']=[self::class,'erase_personal_data'];return $erasers;
    }

    public static function erase_personal_data(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;
        if((bool)apply_filters('sn_network_retention_prevents_erasure',false,$uid))return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail data is retained under an approved legal or safety hold.'],'done'=>true];
        $states=SN_DB::table('smail_states');$drafts=SN_DB::table('smail_drafts');
        $ids_raw=$wpdb->get_col($wpdb->prepare("SELECT id FROM $states WHERE user_id=%d ORDER BY id ASC LIMIT %d",$uid,self::ERASE_BATCH));
        if($wpdb->last_error!=='')return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail erasure state enumeration failed; retry is required.'],'done'=>false];
        $ids=array_map('intval',$ids_raw?:[]);
        $draft_ids_raw=$wpdb->get_col($wpdb->prepare("SELECT id FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d",$uid,self::ERASE_BATCH));
        if($wpdb->last_error!=='')return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail erasure draft enumeration failed; retry is required.'],'done'=>false];
        $draft_ids=array_map('intval',$draft_ids_raw?:[]);$removed=false;$now=current_time('mysql',true);$empty=hash_hmac('sha256','',wp_salt('auth').'|sn-sm-draft-blind-v1');
        if($wpdb->query('START TRANSACTION')===false)return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail erasure could not start; retry is required.'],'done'=>false];
        try{foreach($ids as $id){if($wpdb->delete($states,['id'=>$id,'user_id'=>$uid],['%d','%d'])===false)throw new RuntimeException('smail_state_erase_failed');$removed=true;}foreach($draft_ids as $id){$changed=$wpdb->query($wpdb->prepare("UPDATE $drafts SET encrypted_payload='',payload_hash=%s,deleted_at=%s,updated_at=%s WHERE id=%d AND owner_id=%d AND deleted_at IS NULL",$empty,$now,$now,$id,$uid));if($changed!==1)throw new RuntimeException('smail_draft_erase_failed');$removed=true;}if($wpdb->query('COMMIT')===false)throw new RuntimeException('smail_erasure_commit_failed');}catch(Throwable $e){$wpdb->query('ROLLBACK');return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail erasure could not be committed and must be retried.'],'done'=>false];}
        $more_states=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $states WHERE user_id=%d LIMIT 1",$uid));
        if($wpdb->last_error!=='')return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Smail erasure completion could not verify remaining state rows; retry is required.'],'done'=>false];
        $more_drafts=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1",$uid));
        if($wpdb->last_error!=='')return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Smail erasure completion could not verify remaining draft rows; retry is required.'],'done'=>false];
        $more=(bool)$more_states||(bool)$more_drafts;return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Canonical messages remain subject to File-17 conversation retention, legal hold and participant rights.'],'done'=>!$more];
    }

    private static function trash_draft(string $public,int $owner): bool {global $wpdb;$now=current_time('mysql',true);return $wpdb->query($wpdb->prepare("UPDATE ".SN_DB::table('smail_drafts')." SET deleted_at=%s,encrypted_payload='',payload_hash=%s,updated_at=%s WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL",$now,hash_hmac('sha256','',wp_salt('auth').'|sn-sm-draft-blind-v1'),$now,$public,$owner))===1;}
    private static function format(object $row): array{return['id'=>(int)$row->id,'message_id'=>(int)$row->message_id,'conversation_id'=>(int)$row->conversation_id,'subject'=>(string)$row->subject,'created_at'=>(string)$row->created_at];}
    private static function with_locks(array $locks,callable $callback){global $wpdb;$locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];try{foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('smail_busy','The Smail item is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return $callback();}finally{foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}
}