<?php
/** Fourth fresh cycle: AI/private-search visibility and scholarly-source authority. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Knowledge_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2300);
    }

    public static function override_routes(): void {
        $a=[SN_REST::class,'access'];
        register_rest_route('sabri-network/v2','/future/ai-assistant',['methods'=>'POST','callback'=>[self::class,'ai_assistant'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/future/semantic-search',['methods'=>'POST','callback'=>[self::class,'semantic_search'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/future/citations',['methods'=>'POST','callback'=>[self::class,'citation'],'permission_callback'=>$a],true);
        register_rest_route('sabri-network/v2','/future/case-discussions',['methods'=>'POST','callback'=>[self::class,'case_discussion'],'permission_callback'=>$a],true);
    }

    public static function ai_assistant(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $c=absint($r->get_param('conversation_id'));$u=get_current_user_id();
        if($c<=0)return self::not_found();
        return self::with_lock($c,static function()use($r,$c,$u){
            if(!SN_DB::is_member($c,$u))return self::not_found();
            foreach(array_slice(array_values(array_unique(array_filter(array_map('absint',(array)$r->get_param('message_ids'))))),0,50) as $id){
                $m=self::message($id);
                if(!$m||(int)$m->conversation_id!==$c||$m->deleted_at!==null||SN_Message_Operations::is_hidden($u,$id))return self::not_found();
            }
            // Membership and per-viewer visibility now remain serialized through the
            // plaintext handoff to the approved File-16 adapter.
            return SN_Future_Superset::ai_assistant($r);
        });
    }

    public static function semantic_search(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $c=absint($r->get_param('conversation_id'));$u=get_current_user_id();
        if($c<=0)return self::not_found();
        return self::with_lock($c,static function()use($r,$c,$u){
            if(!SN_DB::is_member($c,$u))return self::not_found();
            $response=SN_Future_Superset::semantic_search($r);
            if(is_wp_error($response))return $response;
            $data=$response->get_data();
            if(!is_array($data)||!is_array($data['items']??null))return $response;
            $data['items']=array_values(array_filter($data['items'],static function($item)use($u,$c){
                $id=absint(is_array($item)?($item['message_id']??0):0);$m=$id>0?self::message($id):null;
                return $m&&(int)$m->conversation_id===$c&&$m->deleted_at===null&&!SN_Message_Operations::is_hidden($u,$id);
            }));
            $response->set_data($data);return $response;
        });
    }

    public static function citation(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $c=absint($r->get_param('conversation_id'));$u=get_current_user_id();
        if($c<=0)return self::not_found();
        return self::with_lock($c,static function()use($r,$c,$u){
            if(!SN_DB::is_member($c,$u))return self::not_found();
            // Preserve the stronger canonical-owner contract from Future24-C on the
            // final route owner: exists/current/allowed, same-site canonical URL and
            // authoritative File-06/File-12 resolution all remain mandatory.
            return SN_Future24_Review_Hardening_C::create_citation_card($r);
        });
    }

    public static function case_discussion(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $c=absint($r->get_param('conversation_id'));$u=get_current_user_id();
        if($c<=0)return self::not_found();
        return self::with_lock($c,static function()use($r,$c,$u){
            if(!SN_DB::is_member($c,$u))return self::not_found();
            // Future24-C owns the stronger case contract: approved de-identification,
            // consent sufficiency, professional authority, locked revalidation and
            // bounded retention. Final route precedence must never weaken it.
            return SN_Future24_Review_Hardening_C::create_case_discussion($r);
        });
    }

    private static function message(int $id):?object{global $wpdb;return $id>0?($wpdb->get_row($wpdb->prepare('SELECT id,conversation_id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d',$id))?:null):null;}
    private static function with_lock(int $c,callable $cb){global $wpdb;$lock='sn:f17:conversation:'.substr(hash('sha256',(string)$c),0,32);$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('sn_conversation_busy','The conversation is changing. Retry the request.',['status'=>409]);try{return $cb();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}
    private static function not_found():WP_Error{return new WP_Error('not_found','The requested communication object is unavailable.',['status'=>404]);}
}
