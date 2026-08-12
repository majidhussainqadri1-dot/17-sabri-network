<?php
/** Review round 52 — block-aware AI and semantic-search visibility revalidation. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_W {
    public static function register(): void { add_action('rest_api_init', [self::class, 'routes'], 2150); }
    public static function routes(): void {
        register_rest_route('sabri-network/v2','/future/ai-assistant',['methods'=>'POST','callback'=>[self::class,'ai_assistant'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/semantic-search',['methods'=>'POST','callback'=>[self::class,'semantic_search'],'permission_callback'=>[SN_REST::class,'access']],true);
    }
    public static function ai_assistant(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$user=get_current_user_id();$conversation=absint($r->get_param('conversation_id'));if($conversation<=0||!SN_DB::is_member($conversation,$user))return self::not_found();$ids=array_slice(array_values(array_unique(array_filter(array_map('absint',(array)$r->get_param('message_ids'))))),0,50);foreach($ids as $id){$row=$wpdb->get_row($wpdb->prepare('SELECT conversation_id,sender_id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d',$id));if(!$row||(int)$row->conversation_id!==$conversation||!empty($row->deleted_at)||SN_Message_Operations::is_hidden($user,$id)||SN_DB::is_blocked($user,(int)$row->sender_id))return self::not_found();}return SN_Future24_Review_Hardening_G::ai_assistant($r);
    }
    public static function semantic_search(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$user=get_current_user_id();$conversation=absint($r->get_param('conversation_id'));if($conversation<=0||!SN_DB::is_member($conversation,$user))return self::not_found();$response=SN_Future24_Review_Hardening_G::semantic_search($r);if(is_wp_error($response))return $response;if(!SN_DB::is_member($conversation,$user))return self::not_found();$data=$response->get_data();$items=[];foreach((array)($data['items']??[]) as $item){$id=absint($item['message_id']??0);$row=$id>0?$wpdb->get_row($wpdb->prepare('SELECT conversation_id,sender_id,deleted_at FROM '.SN_DB::table('messages').' WHERE id=%d',$id)):null;if(!$row||(int)$row->conversation_id!==$conversation||!empty($row->deleted_at)||SN_Message_Operations::is_hidden($user,$id)||SN_DB::is_blocked($user,(int)$row->sender_id))continue;$items[]=$item;}$data['items']=$items;$data['block_visibility_rechecked']=true;$response->set_data($data);return $response;
    }
    private static function not_found():WP_Error{return new WP_Error('not_found','Requested communication object is unavailable.',['status'=>404]);}
}
