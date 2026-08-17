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
            if(!SN_DB::is_member($cid,$actor))return self::not_found();
            return SN_REST::set_typing($r);
        });
    }

    public static function get_typing(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $cid=absint($r['id']);$actor=get_current_user_id();
        if($cid<=0)return self::not_found();
        return self::with_locks([self::conversation_lock($cid)],static function()use($r,$cid,$actor){
            if(!SN_DB::is_member($cid,$actor))return self::not_found();
            // Reusing the canonical reader inside the conversation lock prevents a
            // concurrent leave/removal from leaking a stale typing participant row.
            return SN_REST::get_typing($r);
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
    private static function with_locks(array $locks,callable $cb){global $wpdb;$locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];try{foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('sn_realtime_busy','The realtime state is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return $cb();}finally{foreach(array_reverse($held)as$lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}
    private static function not_found():WP_Error{return new WP_Error('not_found','The requested communication object is unavailable.',['status'=>404]);}
}
