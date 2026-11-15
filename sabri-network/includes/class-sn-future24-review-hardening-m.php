<?php
/** Review round 35 — timezone-safe reminder create/reschedule/cancel and due-time authorization rechecks. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_M {
    public static function register():void{add_action('rest_api_init',[self::class,'routes'],2001);add_action('sn_cleanup_hourly',[self::class,'preflight_due'],1);}
    public static function routes():void{
        register_rest_route('sabri-network/v2','/future/reminders',['methods'=>'POST','callback'=>[self::class,'create_reminder'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/reminders/(?P<id>\d+)',[['methods'=>'POST','callback'=>[self::class,'reschedule'],'permission_callback'=>[SN_REST::class,'access']],['methods'=>'DELETE','callback'=>[self::class,'cancel'],'permission_callback'=>[SN_REST::class,'access']]]);
    }
    public static function create_reminder(WP_REST_Request $r):WP_REST_Response|WP_Error{$normalized=self::normalize_time((string)$r->get_param('remind_at'),(string)$r->get_param('timezone'));if(is_wp_error($normalized))return $normalized;$r->set_param('remind_at',$normalized);return SN_Future_Superset::create_reminder($r);}
    public static function reschedule(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $user=get_current_user_id();
        $row=self::row(absint($r['id']),$user);
        if(!$row)return self::not_found();
        if((string)$row->state==='firing')
            return self::error('sn_reminder_busy','This reminder is already being handed off; retry after its current state is known.',409);
        if((string)$row->state!=='active')
            return self::error('sn_reminder_terminal','A fired, cancelled or expired reminder cannot be resurrected; create a new reminder instead.',409);
        // Owning the historical reminder does not regrant revoked conversation access.
        if((int)$row->scope_id<=0||!SN_DB::is_active_conversation_member((int)$row->scope_id,$user))
            return self::error('sn_reminder_access_revoked','Conversation access was revoked; the reminder cannot be rescheduled.',403);
        // Optimistic DB CAS alone does not ensure that this client saw the
        // version it is about to overwrite. Require caller-owned prior version.
        $expected=absint($r->get_param('expected_version'));
        if($expected<=0)
            return self::error('sn_reminder_version_required','Refresh this reminder and provide its current expected version.',400);
        if($expected!==(int)$row->version)
            return self::error('sn_reminder_stale','Reminder changed before rescheduling.',409);
        $when=self::normalize_time((string)$r->get_param('remind_at'),(string)$r->get_param('timezone'));
        if(is_wp_error($when))return $when;
        $ok=$wpdb->update($wpdb->prefix.'sn_future_records',
            ['expires_at'=>$when,'updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],
            ['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);
        if($ok!==1)return self::error('sn_reminder_stale','Reminder changed before rescheduling.',409);
        SN_DB::audit('future_reminder_rescheduled','future_record',(int)$row->id,'success',['remind_at'=>$when],$user);
        return rest_ensure_response(['id'=>(int)$row->id,'state'=>'active','remind_at'=>$when,'version'=>(int)$row->version+1]);
    }
    public static function cancel(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $user=get_current_user_id();
        $row=self::row(absint($r['id']),$user);
        if(!$row)return self::not_found();
        if(in_array((string)$row->state,['cancelled','fired','expired'],true))
            return rest_ensure_response(['id'=>(int)$row->id,'state'=>(string)$row->state]);
        if((string)$row->state==='firing')
            return self::error('sn_reminder_busy','This reminder is already being handed off; retry after its current state is known.',409);
        if((string)$row->state!=='active')
            return self::error('sn_reminder_stale','Reminder is not in a cancellable state.',409);
        $expected=absint($r->get_param('expected_version'));
        if($expected<=0)
            return self::error('sn_reminder_version_required','Refresh this reminder and provide its current expected version.',400);
        if($expected!==(int)$row->version)
            return self::error('sn_reminder_stale','Reminder changed before cancellation.',409);
        $ok=$wpdb->update($wpdb->prefix.'sn_future_records',
            ['state'=>'cancelled','updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],
            ['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);
        if($ok!==1)return self::error('sn_reminder_stale','Reminder changed before cancellation.',409);
        SN_DB::audit('future_reminder_cancelled','future_record',(int)$row->id,'success',[],$user);
        return rest_ensure_response(['id'=>(int)$row->id,'state'=>'cancelled','version'=>(int)$row->version+1]);
    }
    public static function preflight_due():void{global $wpdb;$now=current_time('mysql',true);$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE feature_id='F17-FUT-07' AND state='active' AND expires_at<=%s ORDER BY id ASC LIMIT 300",$now));foreach(is_array($rows)?$rows:[] as $row){$user=(int)$row->owner_id;$conversation=(int)$row->scope_id;$invalid=$conversation<=0||!SN_DB::is_member($conversation,$user);$data=self::decode($row);if(is_wp_error($data))$invalid=true;$message=absint(is_wp_error($data)?0:($data['message_id']??0));if(!$invalid&&$message>0){$m=$wpdb->get_row($wpdb->prepare('SELECT id,sender_id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d',$message,$conversation));if(!$m||!empty($m->deleted_at)||SN_Message_Operations::is_hidden($user,$message)||SN_DB::is_blocked($user,(int)$m->sender_id))$invalid=true;}if(!$invalid)continue;$ok=$wpdb->update($wpdb->prefix.'sn_future_records',['state'=>'cancelled','updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);if($ok===1)SN_DB::audit('future_reminder_cancelled_preflight','future_record',(int)$row->id,'success',['reason'=>'authorization_or_source_changed'],$user);}}
    private static function normalize_time(string $value,string $timezone): string|WP_Error {
        try{
            // Do not allow PHP's permissive natural-language normalization to
            // silently move a reminder away from the time the user selected.
            if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}(?::\\d{2}(?:\\.\\d{1,6})?)?(?:Z|[+-]\\d{2}:?\\d{2})?$/',$value))
                return self::error('sn_reminder_time_invalid','Enter an ISO 8601 date and time.',400);
            $zone=$timezone!==''?new DateTimeZone($timezone):null;
            $explicit=preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/',$value)===1;
            if(!$explicit&&$zone===null)
                return self::error('sn_reminder_timezone_required','Provide an explicit UTC offset/Z or a valid timezone.',400);
            $dt=new DateTimeImmutable($value,$zone?:new DateTimeZone('UTC'));
            if(!$explicit&&$zone!==null){
                $wall=substr(str_replace(' ','T',$value),0,19);
                if(strlen($wall)===16)$wall.=':00';
                if($dt->format('Y-m-d\\TH:i:s')!==$wall)
                    return self::error('sn_reminder_dst_nonexistent','The selected local time does not exist in this timezone.',400);
                // When clocks fall back, a local hour repeats. Without an
                // explicit UTC offset, choosing one of its two instants would
                // be an undocumented guess rather than a user choice.
                $local_ts=(new DateTimeImmutable($wall,new DateTimeZone('UTC')))->getTimestamp();
                $transitions=$zone->getTransitions($dt->getTimestamp()-3*DAY_IN_SECONDS,
                    $dt->getTimestamp()+3*DAY_IN_SECONDS);
                if(is_array($transitions)){
                    $previous_offset=null;
                    foreach($transitions as $transition){
                        $offset=(int)$transition['offset'];
                        if($previous_offset!==null&&$offset<$previous_offset){
                            $first=(int)$transition['ts']+$offset;
                            $last=(int)$transition['ts']+$previous_offset;
                            if($local_ts>=$first&&$local_ts<$last)
                                return self::error('sn_reminder_dst_ambiguous','This local time occurs twice. Supply an explicit UTC offset to choose the intended instant.',400);
                        }
                        $previous_offset=$offset;
                    }
                }
            }
            $ts=$dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
            if($ts<=time()||$ts>time()+365*DAY_IN_SECONDS)
                return self::error('sn_future_time_invalid','Choose a future time within one year.',400);
            return gmdate('Y-m-d H:i:s',$ts);
        }catch(Throwable $error){
            return self::error('sn_reminder_time_invalid','Reminder time or timezone is invalid.',400);
        }
    }
    private static function row(int $id,int $user):?object{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE id=%d AND feature_id='F17-FUT-07' AND owner_id=%d LIMIT 1",$id,$user));return is_object($row)?$row:null;}
    private static function decode(object $row):array|WP_Error{$p=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($p))return $p;$d=json_decode($p,true);return is_array($d)?$d:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
