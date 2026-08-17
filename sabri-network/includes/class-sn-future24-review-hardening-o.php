<?php
/** Review hardening — mutation budgets, message serialization and bounded scheduler recovery. */
declare(strict_types=1);
defined('ABSPATH') || exit;
require_once SN_DIR . 'includes/class-sn-space-runtime-hardening.php';

final class SN_Future24_Review_Hardening_O {
    private const BULK_RECOVERY_BATCH = 200;
    private const LOCK_TIMEOUT = 5;

    public static function register():void{
        add_filter('rest_pre_dispatch',[self::class,'interop_scope_guard'],5,3);
        add_filter('rest_pre_dispatch',[self::class,'mutation_budget'],6,3);
        add_filter('rest_pre_dispatch',[self::class,'serialize_message_version_edit'],7,3);
        add_filter('rest_post_dispatch',[self::class,'release_message_version_edit'],9,3);
        add_action('sn_cleanup_hourly',[self::class,'bulk_job_preflight'],0);
        remove_action('sn_cleanup_hourly',[SN_Future24_Review_Hardening_E::class,'cleanup_breakouts'],40);
        add_action('sn_cleanup_hourly',[SN_Future24_Review_Hardening_E::class,'cleanup_breakouts'],2);
        SN_Space_Runtime_Hardening::register();
    }

    /**
     * Interoperability is a private conversation operation. WordPress administrator
     * capability is not blanket permission to inspect or bridge another conversation.
     * Enforce current membership + owner/moderator scope before the legacy route
     * callbacks can apply any broader manage_options compatibility rule. Provider-auth
     * inbound delivery is deliberately excluded and is governed by its own adapter gate.
     */
    public static function interop_scope_guard($result,$server,WP_REST_Request $request){
        if($result!==null)return $result;
        $route=$request->get_route();
        if(!str_starts_with($route,'/sabri-network/v2/future/interop'))return $result;
        if(preg_match('#^/sabri-network/v2/future/interop/\d+/inbound$#',$route))return $result;
        $actor=get_current_user_id();
        if($actor<=0)return new WP_Error('forbidden','Current conversation management authority is required.',['status'=>403]);
        $conversation=0;
        if($route==='/sabri-network/v2/future/interop'){
            $conversation=absint($request->get_param('conversation_id'));
            if($conversation<=0)return new WP_Error('forbidden','Current conversation management authority is required.',['status'=>403]);
        }elseif(preg_match('#^/sabri-network/v2/future/interop/(\d+)(?:/outbound)?$#',$route,$match)){
            global $wpdb;
            $conversation=(int)$wpdb->get_var($wpdb->prepare("SELECT scope_id FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-24' AND scope_type='conversation' AND state='active' LIMIT 1",(int)$match[1]));
            if($conversation<=0)return new WP_Error('not_found','Requested communication object is unavailable.',['status'=>404]);
        }else return $result;
        if(!SN_DB::is_member($conversation,$actor)||!in_array(SN_DB::member_role($conversation,$actor),['owner','moderator'],true)){
            return new WP_Error($route==='/sabri-network/v2/future/interop'?'forbidden':'not_found',$route==='/sabri-network/v2/future/interop'?'Current conversation management authority is required.':'Requested communication object is unavailable.',['status'=>$route==='/sabri-network/v2/future/interop'?403:404]);
        }
        return $result;
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
        if($result!==null)return $result;
        $method=strtoupper($request->get_method());
        $route=$request->get_route();
        $locks=[];
        global $wpdb;

        if($method==='POST' && preg_match('#^/sabri-network/v2/conversations/(\d+)/messages$#',$route,$match)){
            $conversation=(int)$match[1];
            if($conversation>0){
                $locks[]=self::conversation_lock($conversation);
                $type=(string)$wpdb->get_var($wpdb->prepare('SELECT type FROM '.SN_DB::table('conversations').' WHERE id=%d',$conversation));
                if($type==='direct'){
                    $members=array_values(array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 3',$conversation))?:[]));
                    if(count($members)===2)$locks[]=SN_Relationships::pair_lock_name($members[0],$members[1]);
                }
            }
        } elseif(in_array($method,['POST','DELETE'],true) && preg_match('#^/sabri-network/v2/messages/(\d+)(?:/(mentions|pin|star|hide|expiry|translate))?$#',$route,$match)){
            $message_id=(int)$match[1];
            if($message_id>0){
                $locks[]='sn:f17:msg-edit:'.$message_id;
                $conversation=(int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM '.SN_DB::table('messages').' WHERE id=%d',$message_id));
                if($conversation>0)$locks[]=self::conversation_lock($conversation);
                $suffix=(string)($match[2]??'');
                if($suffix==='mentions'){
                    $actor=get_current_user_id();
                    foreach(array_slice(array_values(array_unique(array_filter(array_map('absint',(array)$request->get_param('user_ids'))))),0,20) as $target){
                        if($actor>0&&$target>0&&$target!==$actor)$locks[]=SN_Relationships::pair_lock_name($actor,$target);
                    }
                }
                if($suffix==='translate' && $conversation>0){
                    $members=array_values(array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 3',$conversation))?:[]));
                    $type=(string)$wpdb->get_var($wpdb->prepare('SELECT type FROM '.SN_DB::table('conversations').' WHERE id=%d',$conversation));
                    if($type==='direct'&&count($members)===2)$locks[]=SN_Relationships::pair_lock_name($members[0],$members[1]);
                }
            }
        }

        if(!$locks)return $result;
        $locks=array_values(array_unique($locks));sort($locks,SORT_STRING);$held=[];
        foreach($locks as $lock){
            $acquired=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
            if($acquired!==1){
                foreach(array_reverse($held) as $held_lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$held_lock));
                return new WP_Error('sn_message_mutation_busy','This message or conversation is changing. Retry the request.',['status'=>409]);
            }
            $held[]=$lock;
        }
        $request->set_param('_sn_future_version_locks',$held);
        if(preg_match('#^/sabri-network/v2/messages/\d+$#',$route))$request->set_param('_sn_future_version_lock',$held[0]??'');
        return $result;
    }

    public static function release_message_version_edit($response,$server,WP_REST_Request $request){
        $locks=$request->get_param('_sn_future_version_locks');
        if(!is_array($locks)){
            $legacy=(string)$request->get_param('_sn_future_version_lock');
            $locks=$legacy!==''?[$legacy]:[];
        }
        if($locks){
            global $wpdb;
            foreach(array_reverse(array_values(array_unique(array_map('strval',$locks)))) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
            $request->set_param('_sn_future_version_locks',[]);$request->set_param('_sn_future_version_lock','');
        }
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

    private static function conversation_lock(int $id):string{return 'sn:f17:conversation:'.substr(hash('sha256',(string)$id),0,32);}
}
