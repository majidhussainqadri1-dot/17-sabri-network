<?php
/** Fourth fresh cycle: serialize space governance decisions with their mutations. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Space_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2200);
    }

    public static function override_routes(): void {
        $a = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2','/spaces',[
            ['methods'=>'GET','callback'=>[SN_Spaces::class,'list_spaces'],'permission_callback'=>$a],
            ['methods'=>'POST','callback'=>[self::class,'create_space'],'permission_callback'=>$a],
        ],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)',[
            ['methods'=>'GET','callback'=>[SN_Spaces::class,'get_space'],'permission_callback'=>$a],
            ['methods'=>'PATCH','callback'=>[self::class,'update_space'],'permission_callback'=>$a],
        ],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/join',['methods'=>'POST','callback'=>[self::class,'join_space'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/leave',['methods'=>'POST','callback'=>[self::class,'leave_space'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/join-requests/(?P<user_id>\d+)',['methods'=>'POST','callback'=>[self::class,'decide_join_request'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/invites',['methods'=>'POST','callback'=>[self::class,'create_invite'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/space-invites/(?P<id>\d+)',['methods'=>'POST','callback'=>[self::class,'decide_invite'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/members/(?P<user_id>\d+)',['methods'=>'PATCH','callback'=>[self::class,'change_member'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/bans',['methods'=>'POST','callback'=>[self::class,'change_ban'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/lifecycle',['methods'=>'POST','callback'=>[self::class,'change_lifecycle'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/transfer',['methods'=>'POST','callback'=>[self::class,'transfer_owner'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/community-settings',[
            ['methods'=>'GET','callback'=>[SN_Two_Plan_Completion::class,'get_community_settings'],'permission_callback'=>$a],
            ['methods'=>'POST','callback'=>[self::class,'update_community_settings'],'permission_callback'=>$a],
        ],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/community-artifacts',[
            ['methods'=>'GET','callback'=>[SN_Two_Plan_Completion::class,'list_community_artifacts'],'permission_callback'=>$a],
            ['methods'=>'POST','callback'=>[self::class,'create_community_artifact'],'permission_callback'=>$a],
        ],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/community-artifacts/(?P<artifact>\d+)/respond',['methods'=>'POST','callback'=>[self::class,'respond_community_artifact'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/spaces/(?P<id>\d+)/community-artifacts/(?P<artifact>\d+)/moderate',['methods'=>'POST','callback'=>[self::class,'moderate_community_artifact'],'permission_callback'=>$a],true);
    }

    public static function create_space(WP_REST_Request $r){
        $parent=absint($r->get_param('parent_id'));
        return $parent>0 ? self::with_space($parent,static fn()=>SN_Spaces::create_space($r)) : SN_Spaces::create_space($r);
    }
    public static function update_space(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::update_space($r));}
    public static function join_space(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::join_space($r));}
    public static function leave_space(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::leave_space($r));}
    public static function decide_join_request(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::decide_join_request($r));}
    public static function create_invite(WP_REST_Request $r){
        $space=absint($r['id']);$actor=get_current_user_id();$target=absint($r->get_param('user_id'));
        return self::with_locks(array_merge([self::space_lock($space)],$target>0?[SN_Relationships::pair_lock_name($actor,$target)]:[]),static fn()=>SN_Spaces::create_invite($r));
    }
    public static function decide_invite(WP_REST_Request $r){
        global $wpdb;$id=absint($r['id']);
        $row=$wpdb->get_row($wpdb->prepare('SELECT space_id,inviter_id,invitee_id FROM '.SN_DB::table('space_invites').' WHERE id=%d',$id));
        if(!$row)return self::not_found();
        return self::with_locks([self::space_lock((int)$row->space_id),SN_Relationships::pair_lock_name((int)$row->inviter_id,(int)$row->invitee_id)],static fn()=>SN_Spaces::decide_invite($r));
    }
    public static function change_member(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::change_member($r));}
    public static function change_ban(WP_REST_Request $r){
        $space=absint($r['id']);$actor=get_current_user_id();$target=absint($r->get_param('user_id'));
        return self::with_locks(array_merge([self::space_lock($space)],$target>0?[SN_Relationships::pair_lock_name($actor,$target)]:[]),static fn()=>SN_Spaces::change_ban($r));
    }
    public static function change_lifecycle(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::change_lifecycle($r));}
    public static function transfer_owner(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Spaces::transfer_owner($r));}
    public static function update_community_settings(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Two_Plan_Completion::update_community_settings($r));}
    public static function create_community_artifact(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Two_Plan_Completion::create_community_artifact($r));}
    public static function respond_community_artifact(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Two_Plan_Completion::respond_community_artifact($r));}
    public static function moderate_community_artifact(WP_REST_Request $r){return self::with_space(absint($r['id']),static fn()=>SN_Two_Plan_Completion::moderate_community_artifact($r));}

    private static function with_space(int $space, callable $cb){return $space>0?self::with_locks([self::space_lock($space)],$cb):self::not_found();}
    private static function space_lock(int $space): string{return 'sn:f17:space:'.substr(hash('sha256',(string)$space),0,32);}
    private static function with_locks(array $locks, callable $callback){
        global $wpdb;$locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];
        try{foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('sn_space_busy','The space or relationship is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return $callback();}
        finally{foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function not_found(): WP_Error{return new WP_Error('not_found','The requested space object is unavailable.',['status'=>404]);}
}
