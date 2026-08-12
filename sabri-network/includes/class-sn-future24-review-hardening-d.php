<?php
/** Review rounds 22+ — conference privacy, queue, breakout and host-continuity hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_D {
    public static function register():void{add_action('rest_api_init',[self::class,'routes'],1980);}
    public static function routes():void{register_rest_route('sabri-network/v2','/calls/(?P<id>\d+)/lobby',['methods'=>'GET','callback'=>[self::class,'call_lobby'],'permission_callback'=>[SN_REST::class,'access']],true);}
    public static function call_lobby(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$call=absint($r['id']);$user=get_current_user_id();if(!self::call_member($call,$user))return self::not_found();$manager=self::call_manager($call,$user);
        $sql="SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE feature_id='F17-FUT-17' AND scope_type='call' AND scope_id=%d AND state='active'";$args=[$call];if(!$manager){$sql.=' AND owner_id=%d';$args[]=$user;}$sql.=' ORDER BY id ASC LIMIT 200';$rows=$wpdb->get_results($wpdb->prepare($sql,...$args));$items=[];foreach(is_array($rows)?$rows:[] as $row){$d=self::decode($row);if(is_wp_error($d))continue;$items[]=['user_id'=>(int)($d['user_id']??$row->owner_id),'state'=>(string)($d['state']??'waiting'),'moderated_by'=>$manager?(int)($d['moderated_by']??0):0];}
        return rest_ensure_response(['call_id'=>$call,'lobby'=>$items,'view'=>$manager?'moderator':'self','privacy_minimized'=>!$manager]);
    }
    private static function call_member(int $call,int $u):bool{global $wpdb;return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('call_members')." WHERE call_id=%d AND user_id=%d AND status IN ('invited','joined') LIMIT 1",$call,$u));}
    private static function call_manager(int $call,int $u):bool{global $wpdb;$c=(int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM '.SN_DB::table('calls').' WHERE id=%d',$call));if(!$c)return false;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE feature_id='F17-FUT-20' AND scope_type='call' AND scope_id=%d AND state='active' ORDER BY id DESC LIMIT 1",$call));if($rows){$d=self::decode($rows[0]);if(!is_wp_error($d)&&(int)($d['host_id']??0)===$u)return true;}return in_array(SN_DB::member_role($c,$u),['owner','moderator'],true)||current_user_can('manage_options');}
    private static function decode(object $row):array|WP_Error{$p=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($p))return $p;$d=json_decode($p,true);return is_array($d)?$d:new WP_Error('sn_future_record_invalid','Advanced communication data is invalid.',['status'=>500]);}
    private static function not_found():WP_Error{return new WP_Error('not_found','Requested communication object is unavailable.',['status'=>404]);}
}
