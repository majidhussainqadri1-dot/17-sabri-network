<?php
/** Review round 38 + round 40 — global mutation budgets and bounded scheduler crash/expiry recovery. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_O {
    private const BULK_RECOVERY_BATCH = 200;

    public static function register():void{
        add_filter('rest_pre_dispatch',[self::class,'mutation_budget'],6,3);
        // This lock is acquired before SN_Future_Superset::pre_dispatch() snapshots an
        // edited message (priority 8), so concurrent edits cannot select the same next
        // history revision. It is released after dispatch on the same DB connection.
        add_filter('rest_pre_dispatch',[self::class,'serialize_message_version_edit'],7,3);
        add_filter('rest_post_dispatch',[self::class,'release_message_version_edit'],9,3);
        add_action('sn_cleanup_hourly',[self::class,'bulk_job_preflight'],0);
        remove_action('sn_cleanup_hourly',[SN_Future24_Review_Hardening_E::class,'cleanup_breakouts'],40);
        add_action('sn_cleanup_hourly',[SN_Future24_Review_Hardening_E::class,'cleanup_breakouts'],2);
    }

    public static function mutation_budget($result,$server,WP_REST_Request $request){
        if($result!==null)return $result;
        $method=strtoupper($request->get_method());
        if(in_array($method,['GET','HEAD','OPTIONS'],true))return $result;
        $route=$request->get_route();
        $advanced=str_starts_with($route,'/sabri-network/v2/future/')||preg_match('#^/sabri-network/v2/calls/\d+/(lobby|hand-raise|speaker-queue|breakouts|cohosts|host-transfer|host-takeover|network-quality)#',$route);
        if(!$advanced)return $result;
        $user=get_current_user_id();
        if($user<=0)return $result;
        $normalized=preg_replace('/\d+/','{id}',$route);
        $limit=60;
        if(str_contains($route,'device-keys')||str_contains($route,'host-transfer')||str_contains($route,'interop'))$limit=20;
        if(str_contains($route,'ai-assistant')||str_contains($route,'semantic-search'))$limit=30;
        $key=$user.':'.hash('sha256',$method.'|'.$normalized);
        if(!SN_Policy::consume_rate_limit('future24_mutation',$key,$limit,MINUTE_IN_SECONDS))return new WP_Error('sn_future24_rate_limited','Too many advanced communication changes were requested.',['status'=>429]);
        return $result;
    }

    public static function serialize_message_version_edit($result,$server,WP_REST_Request $request){
        if($result!==null||strtoupper($request->get_method())!=='POST')return $result;
        $route=$request->get_route();
        if(!preg_match('#^/sabri-network/v2/messages/(\d+)$#',$route,$match))return $result;
        $message_id=(int)$match[1];if($message_id<=0)return $result;
        global $wpdb;$lock='sn:f17:msg-edit:'.$message_id;
        $acquired=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,5));
        if($acquired!==1)return new WP_Error('sn_message_edit_busy','Another edit of this message is being committed. Retry the edit.',['status'=>409]);
        $request->set_param('_sn_future_version_lock',$lock);
        return $result;
    }

    public static function release_message_version_edit($response,$server,WP_REST_Request $request){
        $lock=(string)$request->get_param('_sn_future_version_lock');
        if($lock!==''){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$request->set_param('_sn_future_version_lock','');}
        return $response;
    }

    public static function bulk_job_preflight():void{
        global $wpdb;
        $table=$wpdb->prefix.'sn_future_records';
        $now=current_time('mysql',true);
        $stale=gmdate('Y-m-d H:i:s',time()-15*MINUTE_IN_SECONDS);
        $batch=self::BULK_RECOVERY_BATCH;
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='expired',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='queued' AND expires_at IS NOT NULL AND expires_at<=%s LIMIT $batch",$now,$now));
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='expired',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='processing' AND updated_at<%s AND expires_at IS NOT NULL AND expires_at<=%s LIMIT $batch",$now,$stale,$now));
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='queued',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='processing' AND updated_at<%s AND (expires_at IS NULL OR expires_at>%s) LIMIT $batch",$now,$stale,$now));
    }
}
