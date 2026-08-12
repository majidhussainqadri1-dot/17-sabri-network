<?php
/** Review round 38 — global mutation budgets and scheduler crash/expiry ordering hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_O {
    public static function register():void{
        add_filter('rest_pre_dispatch',[self::class,'mutation_budget'],6,3);
        add_action('sn_cleanup_hourly',[self::class,'bulk_job_preflight'],0);
        // Breakout provider cleanup must happen before the generic Future-24 expiry sweep.
        remove_action('sn_cleanup_hourly',[SN_Future24_Review_Hardening_E::class,'cleanup_breakouts'],40);
        add_action('sn_cleanup_hourly',[SN_Future24_Review_Hardening_E::class,'cleanup_breakouts'],2);
    }
    public static function mutation_budget($result,$server,WP_REST_Request $request){
        if($result!==null)return $result;$method=strtoupper($request->get_method());if(in_array($method,['GET','HEAD','OPTIONS'],true))return $result;$route=$request->get_route();$advanced=str_starts_with($route,'/sabri-network/v2/future/')||preg_match('#^/sabri-network/v2/calls/\d+/(lobby|hand-raise|speaker-queue|breakouts|cohosts|host-transfer|host-takeover|network-quality)#',$route);if(!$advanced)return $result;$user=get_current_user_id();if($user<=0)return $result;$normalized=preg_replace('/\d+/','{id}',$route);$limit=60;if(str_contains($route,'device-keys')||str_contains($route,'host-transfer')||str_contains($route,'interop'))$limit=20;if(str_contains($route,'ai-assistant')||str_contains($route,'semantic-search'))$limit=30;$key=$user.':'.hash('sha256',$method.'|'.$normalized);if(!SN_Policy::consume_rate_limit('future24_mutation',$key,$limit,MINUTE_IN_SECONDS))return new WP_Error('sn_future24_rate_limited','Too many advanced communication changes were requested.',['status'=>429]);return $result;
    }
    public static function bulk_job_preflight():void{
        global $wpdb;$now=current_time('mysql',true);$stale=gmdate('Y-m-d H:i:s',time()-15*MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare("UPDATE ".$wpdb->prefix."sn_future_records SET state='expired',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state IN ('queued','processing') AND expires_at IS NOT NULL AND expires_at<=%s",$now,$now));
        $wpdb->query($wpdb->prepare("UPDATE ".$wpdb->prefix."sn_future_records SET state='queued',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-10' AND state='processing' AND updated_at<%s AND (expires_at IS NULL OR expires_at>%s)",$now,$stale,$now));
    }
}
