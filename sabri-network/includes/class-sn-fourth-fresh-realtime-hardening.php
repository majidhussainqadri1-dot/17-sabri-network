<?php
/** Fourth fresh cycle: realtime typing and device-lifecycle serialization. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Realtime_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2220);
    }

    public static function override_routes(): void {
        $a=[SN_REST::class,'access'];
        register_rest_route('sabri-network/v2','/conversations/(?P<id>\d+)/typing',[
            ['methods'=>'GET','callback'=>[self::class,'get_typing'],'permission_callback'=>$a],
            ['methods'=>'POST','callback'=>[self::class,'set_typing'],'permission_callback'=>$a],
        ],true);
        register_rest_route('sabri-network/v2','/presence/devices/revoke',[
            'methods'=>'POST','callback'=>[self::class,'revoke_device'],'permission_callback'=>$a,
        ],true);
    }

    public static function set_typing(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $cid=absint($r['id']);$actor=get_current_user_id();
        if($cid<=0)return self::not_found();
        return self::with_locks(self::conversation_locks($cid,$actor),static function()use($r,$cid,$actor){
            global $wpdb;
            if(!SN_DB::is_member($cid,$actor))return self::not_found();
            $response=SN_REST::set_typing($r);
            if(is_wp_error($response))return $response;
            // The legacy canonical clear path did not surface a DELETE failure. Re-run the
            // idempotent clear while the conversation lock is held and fail closed if the
            // database still cannot remove the stale typing row. A zero-row delete is valid.
            if(!rest_sanitize_boolean($r->get_param('typing'))){
                $deleted=$wpdb->delete(SN_DB::table('typing'),['conversation_id'=>$cid,'user_id'=>$actor],['%d','%d']);
                if($deleted===false)return new WP_Error('sn_typing_clear_failed','The typing state could not be cleared safely.',['status'=>500]);
            }
            return $response;
        });
    }

    public static function get_typing(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $cid=absint($r['id']);$actor=get_current_user_id();
        if($cid<=0)return self::not_found();
        return self::with_locks([self::conversation_lock($cid)],static function()use($cid,$actor){
            global $wpdb;
            if(!SN_DB::is_member($cid,$actor))return self::not_found();
            $conversation=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('conversations').' WHERE id=%d LIMIT 1',$cid));
            if((string)$wpdb->last_error!=='')return self::read_error();
            if((int)$conversation!==$cid)return self::not_found();
            $rows=$wpdb->get_results($wpdb->prepare(
                'SELECT t.user_id,t.expires_at FROM '.SN_DB::table('typing').' t INNER JOIN '.SN_DB::table('members').' m ON m.conversation_id=t.conversation_id AND m.user_id=t.user_id AND m.left_at IS NULL WHERE t.conversation_id=%d AND t.user_id<>%d AND t.expires_at>%s ORDER BY t.updated_at DESC LIMIT 20',
                $cid,$actor,current_time('mysql',true)
            ));
            if(!is_array($rows)||(string)$wpdb->last_error!=='')return self::read_error();
            $users=[];
            foreach($rows as $row){
                if(SN_DB::is_blocked($actor,(int)$row->user_id))continue;
                $projection=SN_Auth::public_user((int)$row->user_id);
                if($projection)$users[]=$projection;
            }
            return rest_ensure_response(['typing'=>$users]);
        });
    }

    public static function revoke_device(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $user=get_current_user_id();
        return self::with_locks([self::presence_lock($user)],static fn()=>SN_Presence_Devices::revoke($r));
    }

    private static function conversation_locks(int $cid,int $actor): array {
        global $wpdb;$locks=[self::conversation_lock($cid)];
        $type=(string)$wpdb->get_var($wpdb->prepare('SELECT type FROM '.SN_DB::table('conversations').' WHERE id=%d',$cid));
        if($type==='direct'){
            $peer=(int)$wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',$cid,$actor));
            if($peer>0)$locks[]=SN_Relationships::pair_lock_name($actor,$peer);
        }
        return $locks;
    }
    private static function conversation_lock(int $cid):string{return 'sn:f17:conversation:'.substr(hash('sha256',(string)$cid),0,32);}
    private static function presence_lock(int $user):string{return 'sn:f17:presence:'.substr(hash('sha256',(string)$user),0,32);}
    private static function with_locks(array $locks,callable $cb){global $wpdb;$locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];try{foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('sn_realtime_busy','The realtime state is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return $cb();}finally{foreach(array_reverse($held)as$lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock));}}
    private static function not_found():WP_Error{return new WP_Error('not_found','The requested communication object is unavailable.',['status'=>404]);}
    private static function read_error():WP_Error{return new WP_Error('sn_typing_read_failed','Typing state is temporarily unavailable.',['status'=>500]);}
}
