<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
require_once SN_DIR . 'includes/class-sn-attachment-runtime-hardening.php';

/** Commit-first canonical message mutation correction. */
final class SN_Message_Runtime_Hardening {
    private const MAX_MESSAGE_CHARS = 10000;

    public static function register():void{
        add_action('rest_api_init',[self::class,'override_routes'],1850);
        SN_Attachment_Runtime_Hardening::register();
    }

    public static function override_routes():void{
        register_rest_route('sabri-network/v2','/conversations/(?P<id>\d+)/messages',[
            ['methods'=>'GET','callback'=>[SN_Message_Visibility::class,'get_messages'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'send_message'],'permission_callback'=>[SN_REST::class,'access']],
        ],true);
    }

    public static function send_message(WP_REST_Request $request):WP_REST_Response|WP_Error{
        global $wpdb;
        $conversation_id=absint($request['id']);$user_id=get_current_user_id();
        $conversation=self::conversation($conversation_id);
        if(!$conversation||!SN_DB::is_member($conversation_id,$user_id))return self::not_found();
        $post=SN_Policy::can_post_to_conversation($conversation,$user_id);if(is_wp_error($post))return $post;
        $contact=self::contact_check($conversation,$conversation_id,$user_id);if(is_wp_error($contact))return $contact;
        if(!SN_Policy::consume_rate_limit('message_send',(string)$user_id,120,MINUTE_IN_SECONDS))return new WP_Error('rate_limited','Too many message requests.',['status'=>429]);
        $body=trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('body'))));
        if(mb_strlen($body)>self::MAX_MESSAGE_CHARS)return new WP_Error('message_too_long','The message is longer than the permitted limit.',['status'=>413]);
        $type=sanitize_key((string)$request->get_param('message_type'))?:'text';if(!in_array($type,['text','image','video','audio','document'],true))$type='text';
        $reply=absint($request->get_param('reply_to'));
        if($reply>0){$replyRow=$wpdb->get_row($wpdb->prepare('SELECT id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d',$reply,$conversation_id));if(!$replyRow||$replyRow->deleted_at)return new WP_Error('invalid_reply','The replied-to message is unavailable.',['status'=>400]);if(SN_Message_Operations::is_hidden($user_id,$reply))return new WP_Error('invalid_reply','The replied-to message is unavailable.',['status'=>400]);}
        $client=strtolower(trim((string)$request->get_param('client_id')))?:wp_generate_uuid4();if(!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$client))return new WP_Error('invalid_client_id','A valid message idempotency key is required.',['status'=>400]);
        $idem=hash('sha256',$user_id.':'.$conversation_id.':'.$client);$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing)return self::reconcile_existing($existing,$user_id,true);
        $attachment=null;$files=$request->get_file_params();
        if(!empty($files['attachment'])&&is_array($files['attachment'])){$attachment=SN_Private_Files::create_from_upload($files['attachment'],$user_id);if(is_wp_error($attachment))return $attachment;$type=(string)$attachment['type'];}
        if($body===''&&!$attachment)return new WP_Error('empty_message','Write a message or attach a file.',['status'=>400]);if(!$attachment)$type='text';
        $cipher=SN_Message_Body::encrypt($body,$conversation_id,$user_id);if(is_wp_error($cipher)){if($attachment)SN_Private_Files::delete((int)$attachment['id'],$user_id);return $cipher;}
        $now=current_time('mysql',true);$message_id=0;$event_id=null;
        if($wpdb->query('START TRANSACTION')===false){if($attachment)SN_Private_Files::delete((int)$attachment['id'],$user_id);return self::database_error();}
        try{
            $locked=SN_Spaces::assert_post_allowed_in_transaction($conversation_id,$user_id);if(is_wp_error($locked)){$wpdb->query('ROLLBACK');if($attachment)SN_Private_Files::delete((int)$attachment['id'],$user_id);return $locked;}
            $fresh=self::conversation($conversation_id);if(!$fresh||!SN_DB::is_member($conversation_id,$user_id))throw new RuntimeException('message_membership_changed');
            $post=SN_Policy::can_post_to_conversation($fresh,$user_id);if(is_wp_error($post)){$wpdb->query('ROLLBACK');if($attachment)SN_Private_Files::delete((int)$attachment['id'],$user_id);return $post;}
            $contact=self::contact_check($fresh,$conversation_id,$user_id);if(is_wp_error($contact)){$wpdb->query('ROLLBACK');if($attachment)SN_Private_Files::delete((int)$attachment['id'],$user_id);return $contact;}
            if($reply>0){$replyStill=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d AND deleted_at IS NULL',$reply,$conversation_id));if(!$replyStill||SN_Message_Operations::is_hidden($user_id,$reply))throw new RuntimeException('reply_state_changed');}
            if($wpdb->insert(SN_DB::table('messages'),['conversation_id'=>$conversation_id,'sender_id'=>$user_id,'message_type'=>$type,'body'=>$cipher,'attachment_id'=>$attachment?(int)$attachment['id']:0,'attachment_source'=>$attachment?'private':'none','reply_to'=>$reply,'idempotency_key'=>$idem,'metadata'=>'{}','created_at'=>$now])===false)throw new RuntimeException('message_insert_failed');
            $message_id=(int)$wpdb->insert_id;
            if($wpdb->query($wpdb->prepare('UPDATE '.SN_DB::table('conversations').' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d',$message_id,$now,$conversation_id))===false)throw new RuntimeException('message_pointer_failed');
            SN_Spaces::mark_posted_for_conversation($conversation_id,$user_id,$now);
            $indexed=SN_Message_Search::index_message($message_id);if(is_wp_error($indexed))throw new RuntimeException($indexed->get_error_code());
            $event_id=SN_Outbox::enqueue('message.sent','message',$message_id,['message_id'=>$message_id,'conversation_id'=>$conversation_id,'sender_id'=>$user_id,'message_type'=>$type,'created_at'=>$now],'message.sent:'.$message_id);if(is_wp_error($event_id))throw new RuntimeException($event_id->get_error_code());
            SN_DB::audit('message_sent','message',$message_id,'success',['conversation_id'=>$conversation_id,'type'=>$type],$user_id);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('message_commit_failed');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');$race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));
            if($race)return self::reconcile_existing($race,$user_id,true);
            if($attachment)SN_Private_Files::delete((int)$attachment['id'],$user_id);
            SN_DB::audit('message_atomic_send_failed','conversation',$conversation_id,'failure',['reason'=>$e->getMessage()],$user_id);return new WP_Error('message_atomic_send_failed','The message could not be committed with its search and delivery records.',['status'=>500]);
        }
        foreach(self::recipients($conversation_id,$user_id) as $recipient)SN_DB::add_notification($recipient,'message_received','New Network message','','conversation',$conversation_id);
        do_action('sn_network_event_queued',$event_id,'message.sent');
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d',$message_id));
        return rest_ensure_response(['message'=>self::format($row,$user_id)]);
    }

    private static function reconcile_existing(object $message,int $user,bool $duplicate):WP_REST_Response|WP_Error{
        global $wpdb;$secured=SN_Message_Body::ensure_encrypted_row($message);if(is_wp_error($secured))return $secured;$message=$secured;if($wpdb->query('START TRANSACTION')===false)return self::database_error();
        try{$indexed=SN_Message_Search::index_message((int)$message->id);if(is_wp_error($indexed))throw new RuntimeException($indexed->get_error_code());$event=SN_Outbox::enqueue('message.sent','message',(int)$message->id,['message_id'=>(int)$message->id,'conversation_id'=>(int)$message->conversation_id,'sender_id'=>(int)$message->sender_id,'message_type'=>(string)$message->message_type,'created_at'=>(string)$message->created_at],'message.sent:'.(int)$message->id);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());if($wpdb->query('COMMIT')===false)throw new RuntimeException('duplicate_message_commit_failed');do_action('sn_network_event_queued',$event,'message.sent');return rest_ensure_response(['message'=>self::format($message,$user),'duplicate'=>$duplicate]);}catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('message_duplicate_reconciliation_failed','message',(int)$message->id,'failure',['reason'=>$e->getMessage()],$user);return new WP_Error('message_duplicate_reconciliation_failed','The existing message could not be reconciled with its search and delivery records.',['status'=>500]);}
    }

    private static function contact_check(object $conversation,int $conversation_id,int $actor):bool|WP_Error{$others=self::recipients($conversation_id,$actor);if((string)$conversation->type!=='direct'){foreach($others as $target)if(SN_DB::is_blocked($actor,$target))return new WP_Error('blocked','A conversation member is unavailable.',['status'=>403]);return true;}if(count($others)!==1)return new WP_Error('invalid_direct_conversation','The direct conversation membership is invalid.',['status'=>409]);return SN_Policy::can_contact($actor,$others[0],'message');}
    private static function conversation(int $id):?object{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('conversations')." WHERE id=%d AND status='active'",$id));return $row?:null;}
    private static function recipients(int $conversation,int $sender):array{global $wpdb;return array_values(array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1000',$conversation,$sender))?:[]));}
    private static function reactions(int $message):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total FROM '.SN_DB::table('reactions').' WHERE message_id=%d GROUP BY reaction ORDER BY reaction ASC',$message));return array_map(static fn($row)=>['reaction'=>(string)$row->reaction,'count'=>(int)$row->total],is_array($rows)?$rows:[]);}
    private static function format(?object $row,int $viewer):array{if(!$row)return[];$sender=SN_Auth::public_user((int)$row->sender_id)?:['id'=>0,'name'=>'Unavailable account','avatar'=>SN_URL.'assets/network-default-avatar.svg'];$attachment=!$row->deleted_at&&(int)$row->attachment_id>0&&(string)$row->attachment_source==='private'?SN_Private_Files::formatted((int)$row->attachment_id,$viewer):null;$plain=$row->deleted_at?'':SN_Message_Body::decrypt_row($row);$unavailable=is_wp_error($plain);return['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'sender'=>$sender,'message_type'=>(string)$row->message_type,'body'=>$unavailable?'':(string)$plain,'body_unavailable'=>$unavailable,'attachment'=>$attachment,'reply_to'=>(int)$row->reply_to,'reactions'=>self::reactions((int)$row->id),'edited'=>(bool)$row->edited_at,'deleted'=>(bool)$row->deleted_at,'created_at'=>(string)$row->created_at];}
    private static function not_found():WP_Error{return new WP_Error('not_found','The requested conversation is unavailable.',['status'=>404]);}
    private static function database_error():WP_Error{return new WP_Error('database_error','The message request could not be committed safely.',['status'=>500]);}
}
