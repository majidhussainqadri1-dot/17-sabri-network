<?php
/** Review rounds 48–49 — joined/admitted conference queues and breakout membership/block hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_U {
    public static function register(): void { add_action('rest_api_init', [self::class, 'routes'], 2130); }
    public static function routes(): void {
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/hand-raise', ['methods'=>'POST','callback'=>[self::class,'hand_raise'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/speaker-queue', [
            ['methods'=>'GET','callback'=>[self::class,'speaker_queue'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'manage_speaker_queue'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/breakouts', ['methods'=>'POST','callback'=>[self::class,'create_breakouts'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/calls/(?P<id>\d+)/breakouts/move', ['methods'=>'POST','callback'=>[self::class,'move_breakout'],'permission_callback'=>[SN_REST::class,'access']], true);
    }
    public static function hand_raise(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $call=absint($r['id']);$user=get_current_user_id();if(!self::joined($call,$user))return self::not_found();$lobby=self::lobby_state($call,$user);if($lobby!==null&&$lobby!=='admitted')return self::error('sn_speaker_lobby_not_admitted','Only an admitted participant may enter the speaker queue.',403);return SN_Future24_Review_Hardening_D::hand_raise($r);
    }
    public static function speaker_queue(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $call=absint($r['id']);$user=get_current_user_id();if(!self::joined($call,$user))return self::not_found();self::preclean($call);$response=SN_Future24_Review_Hardening_D::speaker_queue($r);if(is_wp_error($response))return $response;$data=$response->get_data();$data['items']=array_values(array_filter((array)($data['items']??[]),static fn($item)=>self::joined($call,absint($item['user_id']??0))));$response->set_data($data);return $response;
    }
    public static function manage_speaker_queue(WP_REST_Request $r): WP_REST_Response|WP_Error {self::preclean(absint($r['id']));return SN_Future24_Review_Hardening_D::manage_speaker_queue($r);}
    public static function create_breakouts(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $call=absint($r['id']);$actor=get_current_user_id();$rooms=is_array($r->get_param('rooms'))?$r->get_param('rooms'):[];$all=[];foreach($rooms as $room){$users=array_values(array_unique(array_filter(array_map('absint',(array)($room['user_ids']??[])))));foreach($users as $user){if(!self::joined($call,$user))return self::error('sn_breakout_participant_not_joined','Every breakout participant must be currently joined to the parent call.',409);if(SN_DB::is_blocked($actor,$user))return self::error('sn_breakout_block_conflict','A blocked participant cannot be assigned by this host.',403);$all[]=$user;}for($i=0,$n=count($users);$i<$n;$i++)for($j=$i+1;$j<$n;$j++)if(SN_DB::is_blocked($users[$i],$users[$j]))return self::error('sn_breakout_block_conflict','Blocked participants cannot be assigned to the same breakout room.',409);}if(count($all)!==count(array_unique($all)))return self::error('sn_breakout_duplicate_participant','A participant may be assigned to only one breakout room.',409);return SN_Future24_Review_Hardening_E::create_breakouts($r);
    }
    public static function move_breakout(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $call=absint($r['id']);$actor=get_current_user_id();$target=absint($r->get_param('user_id'));if(!self::joined($call,$target))return self::error('sn_breakout_participant_not_joined','Participant is no longer joined to the parent call.',409);if(SN_DB::is_blocked($actor,$target))return self::error('sn_breakout_block_conflict','A blocked participant cannot be moved by this host.',403);return SN_Future24_Review_Hardening_E::move_breakout($r);
    }
    private static function preclean(int $call):void{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE feature_id='F17-FUT-18' AND scope_type='call' AND scope_id=%d AND state='active' ORDER BY id ASC LIMIT 500",$call));foreach(is_array($rows)?$rows:[] as $row){$data=self::decode($row);if(is_wp_error($data)||empty($data['raised']))continue;$user=absint($data['user_id']??$row->owner_id);if(self::joined($call,$user))continue;$data['raised']=false;$data['speaking']=false;$data['position']=0;$data['removed_reason']='not_joined';$cipher=self::encode($row,$data);if(is_wp_error($cipher))continue;$wpdb->update($wpdb->prefix.'sn_future_records',['payload_cipher'=>$cipher,'updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],['id'=>(int)$row->id,'version'=>(int)$row->version]);}}
    private static function lobby_state(int $call,int $user):?string{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE feature_id='F17-FUT-17' AND owner_id=%d AND scope_type='call' AND scope_id=%d AND state='active' ORDER BY id DESC LIMIT 1",$user,$call));if(!$row)return null;$d=self::decode($row);return is_wp_error($d)?null:sanitize_key((string)($d['state']??''));}
    private static function joined(int $call,int $user):bool{global $wpdb;return $user>0&&(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('call_members')." WHERE call_id=%d AND user_id=%d AND status='joined' LIMIT 1",$call,$user));}
    private static function decode(object $row):array|WP_Error{$p=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($p))return $p;$d=json_decode($p,true);return is_array($d)?$d:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}
    private static function encode(object $row,array $data):string|WP_Error{return SN_Communication_Crypto::encrypt((string)wp_json_encode($data),'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);}
    private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
