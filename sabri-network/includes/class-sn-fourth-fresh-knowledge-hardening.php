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
            $owner=sanitize_key((string)$r->get_param('source_owner'));
            $source_id=mb_substr(sanitize_text_field((string)$r->get_param('canonical_id')),0,191);
            if(!in_array($owner,['file-06','file-12'],true)||$source_id==='')return new WP_Error('sn_citation_source_invalid','Select a canonical File 06 or File 12 source.',['status'=>400]);
            $resolved=apply_filters('sn_network_citation_source_resolve',null,$owner,$source_id,$u,$c);
            if($resolved===null)return new WP_Error('sn_citation_source_unavailable','Scholarly citation requires the approved File 06/File 12 source resolver.',['status'=>503]);
            if(is_wp_error($resolved))return $resolved;
            if(!is_array($resolved)||sanitize_key((string)($resolved['source_owner']??''))!==$owner||!hash_equals($source_id,(string)($resolved['canonical_id']??'')))return new WP_Error('sn_citation_source_mismatch','The canonical source resolver did not confirm this citation.',['status'=>409]);
            $url=esc_url_raw((string)($resolved['canonical_url']??''));
            if($url!==''&&wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url('/'),PHP_URL_HOST))return new WP_Error('sn_citation_url_invalid','The canonical source URL must remain on the platform.',['status'=>409]);
            $forward=new WP_REST_Request('POST','/sabri-network/v2/future/citations');
            $forward->set_param('conversation_id',$c);$forward->set_param('source_owner',$owner);$forward->set_param('canonical_id',$source_id);
            $forward->set_param('canonical_url',$url);$forward->set_param('title',mb_substr(sanitize_text_field((string)($resolved['title']??'')),0,300));
            $forward->set_param('locator',mb_substr(sanitize_text_field((string)($resolved['locator']??$r->get_param('locator'))),0,120));
            $forward->set_param('client_id',(string)$r->get_param('client_id'));
            return SN_Future_Superset::create_citation_card($forward);
        });
    }

    public static function case_discussion(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $c=absint($r->get_param('conversation_id'));$u=get_current_user_id();
        if($c<=0)return self::not_found();
        return self::with_lock($c,static function()use($r,$c,$u){
            if(!SN_DB::is_member($c,$u))return self::not_found();
            $summary=mb_substr(sanitize_textarea_field(wp_unslash((string)$r->get_param('summary'))),0,12000);
            if($summary==='')return new WP_Error('sn_case_discussion_pii','A de-identified case summary is required.',['status'=>400]);
            if(!has_filter('sn_network_case_discussion_deidentified')||apply_filters('sn_network_case_discussion_deidentified',false,$summary,$u,$c)!==true){
                return new WP_Error('sn_case_deidentification_required','Case discussion requires an approved de-identification check before storage.',['status'=>503]);
            }
            return SN_Future_Superset::create_case_discussion($r);
        });
    }

    private static function message(int $id):?object{global $wpdb;return $id>0?($wpdb->get_row($wpdb->prepare('SELECT id,conversation_id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d',$id))?:null):null;}
    private static function with_lock(int $c,callable $cb){global $wpdb;$lock='sn:f17:conversation:'.substr(hash('sha256',(string)$c),0,32);$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('sn_conversation_busy','The conversation is changing. Retry the request.',['status'=>409]);try{return $cb();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}
    private static function not_found():WP_Error{return new WP_Error('not_found','The requested communication object is unavailable.',['status'=>404]);}
}
