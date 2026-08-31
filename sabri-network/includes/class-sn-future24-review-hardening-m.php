<?php
/** Review round 35 — timezone-safe reminder lifecycle plus truthful File-19 delivery handoff. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_M {
    private const DELIVERY_RETRY_LEASE = 5 * MINUTE_IN_SECONDS;

    public static function register():void{add_action('rest_api_init',[self::class,'routes'],2001);add_action('sn_cleanup_hourly',[self::class,'preflight_due'],1);}
    public static function routes():void{
        register_rest_route('sabri-network/v2','/future/reminders',[['methods'=>'GET','callback'=>[SN_Future_Superset::class,'list_reminders'],'permission_callback'=>[SN_REST::class,'access']],['methods'=>'POST','callback'=>[self::class,'create_reminder'],'permission_callback'=>[SN_REST::class,'access']]],true);
        register_rest_route('sabri-network/v2','/future/reminders/(?P<id>\d+)',[['methods'=>'POST','callback'=>[self::class,'reschedule'],'permission_callback'=>[SN_REST::class,'access']],['methods'=>'DELETE','callback'=>[self::class,'cancel'],'permission_callback'=>[SN_REST::class,'access']]]);
    }
    public static function create_reminder(WP_REST_Request $r):WP_REST_Response|WP_Error{$normalized=self::normalize_time((string)$r->get_param('remind_at'),(string)$r->get_param('timezone'));if(is_wp_error($normalized))return $normalized;$r->set_param('remind_at',$normalized);return SN_Future_Superset::create_reminder($r);}
    public static function reschedule(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$user=get_current_user_id();$row=self::row(absint($r['id']),$user);if(!$row)return self::not_found();
        if((string)$row->state==='firing')return self::error('sn_reminder_busy','This reminder is already being handed off; retry after its current state is known.',409);
        if((string)$row->state!=='active')return self::error('sn_reminder_terminal','A fired, cancelled or expired reminder cannot be resurrected; create a new reminder instead.',409);
        $when=self::normalize_time((string)$r->get_param('remind_at'),(string)$r->get_param('timezone'));if(is_wp_error($when))return $when;$expected=absint($r->get_param('expected_version'));if($expected>0&&$expected!==(int)$row->version)return self::error('sn_reminder_stale','Reminder changed before rescheduling.',409);
        $ok=$wpdb->update($wpdb->prefix.'sn_future_records',['expires_at'=>$when,'updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);if($ok!==1)return self::error('sn_reminder_stale','Reminder changed before rescheduling.',409);
        SN_DB::audit('future_reminder_rescheduled','future_record',(int)$row->id,'success',['remind_at'=>$when],$user);return rest_ensure_response(['id'=>(int)$row->id,'state'=>'active','remind_at'=>$when,'version'=>(int)$row->version+1]);
    }
    public static function cancel(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$user=get_current_user_id();$row=self::row(absint($r['id']),$user);if(!$row)return self::not_found();
        if(in_array((string)$row->state,['cancelled','fired','expired'],true))return rest_ensure_response(['id'=>(int)$row->id,'state'=>(string)$row->state]);
        if((string)$row->state==='firing')return self::error('sn_reminder_busy','This reminder is already being handed off; retry after its current state is known.',409);
        if((string)$row->state!=='active')return self::error('sn_reminder_stale','Reminder is not in a cancellable state.',409);
        $ok=$wpdb->update($wpdb->prefix.'sn_future_records',['state'=>'cancelled','updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);if($ok!==1)return self::error('sn_reminder_stale','Reminder changed before cancellation.',409);
        SN_DB::audit('future_reminder_cancelled','future_record',(int)$row->id,'success',[],$user);return rest_ensure_response(['id'=>(int)$row->id,'state'=>'cancelled']);
    }

    /**
     * Claims due reminders before the legacy Future cleanup runs. A File-19 handoff
     * is only terminal after an explicit adapter acknowledgement. Unacknowledged
     * handoffs remain `firing`, are retried with the same idempotency key, and have
     * their lease refreshed so the legacy cleanup cannot falsely mark them fired.
     */
    public static function preflight_due():void{
        global $wpdb;$now=current_time('mysql',true);$table=$wpdb->prefix.'sn_future_records';$retry_cutoff=gmdate('Y-m-d H:i:s',time()-self::DELIVERY_RETRY_LEASE);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE feature_id='F17-FUT-07' AND expires_at<=%s AND (state='active' OR (state='firing' AND updated_at<=%s)) ORDER BY id ASC LIMIT 300",$now,$retry_cutoff));
        if(!is_array($rows)){SN_DB::audit('future_reminder_queue_read_failed','future_record',0,'failure',['db_error'=>(string)$wpdb->last_error],0);return;}
        foreach($rows as $row){
            $user=(int)$row->owner_id;$conversation=(int)$row->scope_id;$data=self::decode($row);$invalid=$conversation<=0||!SN_DB::is_member($conversation,$user)||is_wp_error($data);$message=absint(is_wp_error($data)?0:($data['message_id']??0));
            if(!$invalid&&$message>0){$m=$wpdb->get_row($wpdb->prepare('SELECT id,sender_id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d',$message,$conversation));if(!$m||!empty($m->deleted_at)||SN_Message_Operations::is_hidden($user,$message)||SN_DB::is_blocked($user,(int)$m->sender_id))$invalid=true;}
            if($invalid){
                $ok=$wpdb->update($table,['state'=>'cancelled','updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'state'=>(string)$row->state,'version'=>(int)$row->version]);
                if($ok===1)SN_DB::audit('future_reminder_cancelled_preflight','future_record',(int)$row->id,'success',['reason'=>'authorization_or_source_changed'],$user);
                elseif($ok===false)SN_DB::audit('future_reminder_cancel_persist_failed','future_record',(int)$row->id,'failure',['db_error'=>(string)$wpdb->last_error],$user);
                continue;
            }

            if((string)$row->state==='active'){
                $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET state='firing',updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND state='active'",$now,(int)$row->id,(int)$row->version));
            }else{
                $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND state='firing' AND updated_at<=%s",$now,(int)$row->id,(int)$row->version,$retry_cutoff));
            }
            if($claimed!==1)continue;
            $claimed_version=(int)$row->version+1;
            $event=['owner'=>'file-17','recipient_id'=>$user,'type'=>'communication_reminder','title'=>'Communication reminder','body'=>mb_substr(sanitize_text_field((string)($data['label']??'Reminder')),0,191),'entity_type'=>'conversation','entity_id'=>$conversation,'message_id'=>$message,'idempotency_key'=>'file17-future-reminder:'.(int)$row->id];

            if(!SN_Seventh_Fresh_R13_Hardening::file19_ready()){
                SN_DB::audit('future_reminder_file19_unavailable','future_record',(int)$row->id,'failure',['idempotency_key_hash'=>hash('sha256',(string)$event['idempotency_key'])],$user);
                continue;
            }

            try{
                do_action('sn_network_notification_requested',$event);
                $ack=apply_filters('sn_network_notification_delivery_result',null,$event);
                if(is_wp_error($ack)||$ack!==true){
                    SN_DB::audit('future_reminder_handoff_unacknowledged','future_record',(int)$row->id,'failure',['reason'=>is_wp_error($ack)?$ack->get_error_code():'missing_explicit_ack'],$user);
                    continue;
                }
                $fired_at=self::now();
                $fired=$wpdb->query($wpdb->prepare("UPDATE $table SET state='fired',updated_at=%s,version=version+1 WHERE id=%d AND state='firing' AND version=%d",$fired_at,(int)$row->id,$claimed_version));
                if($fired!==1){SN_DB::audit('future_reminder_fired_persist_failed','future_record',(int)$row->id,'failure',['db_error'=>(string)$wpdb->last_error],$user);continue;}
                SN_DB::audit('future_reminder_handoff_acknowledged','future_record',(int)$row->id,'success',[],$user);
            }catch(Throwable $error){SN_DB::audit('future_reminder_handoff_failed','future_record',(int)$row->id,'failure',['reason'=>$error->getMessage()],$user);}
        }
    }

    private static function normalize_time(string $value,string $timezone):string|WP_Error{try{$tz=$timezone!==''?new DateTimeZone($timezone):null;if(!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/',$value)&&!$tz)return self::error('sn_reminder_timezone_required','Provide an explicit UTC offset/Z or a valid timezone.',400);$dt=new DateTimeImmutable($value,$tz?:new DateTimeZone('UTC'));$utc=$dt->setTimezone(new DateTimeZone('UTC'));$ts=$utc->getTimestamp();if($ts<=time()||$ts>time()+365*DAY_IN_SECONDS)return self::error('sn_future_time_invalid','Choose a future time within one year.',400);return gmdate('Y-m-d H:i:s',$ts);}catch(Throwable $e){return self::error('sn_reminder_time_invalid','Reminder time or timezone is invalid.',400);}}
    private static function row(int $id,int $user):?object{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE id=%d AND feature_id='F17-FUT-07' AND owner_id=%d LIMIT 1",$id,$user));return is_object($row)?$row:null;}
    private static function decode(object $row):array|WP_Error{$p=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($p))return $p;$d=json_decode($p,true);return is_array($d)?$d:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}
    private static function now():string{return current_time('mysql',true);}
    private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
