<?php
/** Review round 30+ — Future-24 privacy export/erasure completeness for owned and shared scoped data. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_I {
    private const EXPORT_PAGE_SIZE = 100;
    private const SHARED_SCAN_PAGE_SIZE = 200;

    public static function register():void{
        add_filter('wp_privacy_personal_data_exporters',[self::class,'exporters'],2000);
        add_filter('wp_privacy_personal_data_erasers',[self::class,'erasers'],2000);
    }
    public static function exporters(array $e):array{if(isset($e['sabri-network-future']))$e['sabri-network-future']['callback']=[self::class,'privacy_export'];return $e;}
    public static function erasers(array $e):array{if(isset($e['sabri-network-future']))$e['sabri-network-future']['callback']=[self::class,'privacy_erase'];return $e;}
    public static function privacy_export(string $email,int $page=1):array{
        global $wpdb;
        $user=get_user_by('email',$email);
        if(!$user)return ['data'=>[],'done'=>true];
        $uid=(int)$user->ID;
        $page=max(1,$page);
        $base=SN_Future_Superset::privacy_export($email,$page);
        $data=is_array($base['data']??null)?$base['data']:[];
        $offset=($page-1)*self::EXPORT_PAGE_SIZE;

        $keys=$wpdb->get_results($wpdb->prepare(
            'SELECT device_id,algorithm,fingerprint,state,created_at,updated_at FROM '.$wpdb->prefix.'sn_future_device_keys WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $uid,self::EXPORT_PAGE_SIZE,$offset
        ));
        $keys=is_array($keys)?$keys:[];
        foreach($keys as $k)$data[]=['group_id'=>'sabri-network-future','group_label'=>'Advanced Communication','item_id'=>'future-device-key-'.hash('sha256',(string)$k->device_id),'data'=>[['name'=>'Feature','value'=>'F17-FUT-02'],['name'=>'Device','value'=>(string)$k->device_id],['name'=>'Algorithm','value'=>(string)$k->algorithm],['name'=>'Fingerprint','value'=>(string)$k->fingerprint],['name'=>'State','value'=>(string)$k->state]]];

        $logs=$wpdb->get_results($wpdb->prepare(
            'SELECT id,device_id,event,fingerprint,previous_fingerprint,created_at FROM '.$wpdb->prefix.'sn_future_key_log WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $uid,self::EXPORT_PAGE_SIZE,$offset
        ));
        $logs=is_array($logs)?$logs:[];
        foreach($logs as $k)$data[]=['group_id'=>'sabri-network-future','group_label'=>'Advanced Communication','item_id'=>'future-key-log-'.(int)$k->id,'data'=>[['name'=>'Feature','value'=>'F17-FUT-03'],['name'=>'Device','value'=>(string)$k->device_id],['name'=>'Event','value'=>(string)$k->event],['name'=>'Fingerprint','value'=>(string)$k->fingerprint],['name'=>'Previous fingerprint','value'=>(string)$k->previous_fingerprint],['name'=>'Created','value'=>(string)$k->created_at]]];

        $shared_offset=($page-1)*self::SHARED_SCAN_PAGE_SIZE;
        $shared=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE owner_id=0 AND state NOT IN ('deleted','erased') AND feature_id IN ('F17-FUT-01','F17-FUT-05','F17-FUT-06','F17-FUT-17','F17-FUT-19','F17-FUT-20','F17-FUT-24') ORDER BY id ASC LIMIT %d OFFSET %d",
            self::SHARED_SCAN_PAGE_SIZE,$shared_offset
        ));
        $shared=is_array($shared)?$shared:[];
        foreach($shared as $row){$view=self::shared_view($row,$uid);if($view===null)continue;$data[]=['group_id'=>'sabri-network-future','group_label'=>'Advanced Communication','item_id'=>'future-shared-'.(int)$row->id,'data'=>[['name'=>'Feature','value'=>(string)$row->feature_id],['name'=>'Scope','value'=>(string)$row->scope_type.':'.(int)$row->scope_id],['name'=>'State','value'=>(string)$row->state],['name'=>'Your scoped data','value'=>wp_json_encode($view)]]];}

        $extra_done=count($keys)<self::EXPORT_PAGE_SIZE
            && count($logs)<self::EXPORT_PAGE_SIZE
            && count($shared)<self::SHARED_SCAN_PAGE_SIZE;
        return ['data'=>$data,'done'=>(bool)($base['done']??true)&&$extra_done];
    }
    public static function privacy_erase(string $email,int $page=1):array{
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;$base=SN_Future_Superset::privacy_erase($email,$page);$removed=(bool)($base['items_removed']??false);$retained=(bool)($base['items_retained']??false);$messages=is_array($base['messages']??null)?$base['messages']:[];
        if($page===1){$deleted=$wpdb->delete($wpdb->prefix.'sn_future_device_keys',['user_id'=>$uid],['%d']);if($deleted>0)$removed=true;$shared_count=0;$rows=$wpdb->get_results("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE owner_id=0 AND state NOT IN ('deleted','erased') AND feature_id IN ('F17-FUT-01','F17-FUT-05','F17-FUT-06','F17-FUT-17','F17-FUT-19','F17-FUT-20','F17-FUT-24') ORDER BY id ASC LIMIT 1000");foreach(is_array($rows)?$rows:[] as $row)if(self::shared_view($row,$uid)!==null){$shared_count=1;break;}$log_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'sn_future_key_log WHERE user_id=%d',$uid));if($shared_count||$log_count){$retained=true;$messages[]='Shared communication governance records and append-only key-transparency integrity entries were retained where unilateral erasure would alter other participants’ or security-integrity records.';}}
        return ['items_removed'=>$removed,'items_retained'=>$retained,'messages'=>array_values(array_unique($messages)),'done'=>(bool)($base['done']??true)];
    }
    private static function shared_view(object $row,int $uid):?array{
        $d=self::decode($row);if(is_wp_error($d))return null;$type=(string)$row->scope_type;$scope=(int)$row->scope_id;if($type==='conversation'&&!SN_DB::is_member($scope,$uid))return null;if($type==='call'&&!self::call_member($scope,$uid))return null;
        switch((string)$row->feature_id){case 'F17-FUT-01':return ['enabled'=>(bool)($d['enabled']??false),'protocol'=>(string)($d['protocol']??'')];case 'F17-FUT-05':return ['enabled'=>(bool)($d['enabled']??false),'you_are_assignee'=>(int)($d['assignee_id']??0)===$uid,'status'=>(string)($d['status']??'')];case 'F17-FUT-06':return ['you_are_assignee'=>(int)($d['assignee_id']??0)===$uid,'status'=>(string)($d['status']??'')];case 'F17-FUT-17':if((int)($d['user_id']??$row->owner_id)!==$uid)return null;return ['your_lobby_state'=>(string)($d['state']??'')];case 'F17-FUT-19':$rooms=[];foreach((array)($d['rooms']??[]) as $room)if(in_array($uid,array_map('absint',(array)($room['user_ids']??[])),true))$rooms[]=['room_id'=>$room['room_id']??'','name'=>$room['name']??''];return $rooms?['your_rooms'=>$rooms]:null;case 'F17-FUT-20':return ['you_are_host'=>(int)($d['host_id']??0)===$uid,'you_are_cohost'=>in_array($uid,array_map('absint',(array)($d['cohosts']??[])),true)];case 'F17-FUT-24':return ['protocol'=>(string)($d['protocol']??''),'direction'=>(string)($d['direction']??''),'remote_endpoint'=>(string)($d['remote_endpoint']??'')];}return null;
    }
    private static function call_member(int $call,int $uid):bool{global $wpdb;return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('call_members')." WHERE call_id=%d AND user_id=%d AND status IN ('invited','joined') LIMIT 1",$call,$uid));}
    private static function decode(object $row):array|WP_Error{$p=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($p))return $p;$d=json_decode($p,true);return is_array($d)?$d:new WP_Error('sn_future_record_invalid','Advanced communication data is invalid.',['status'=>500]);}
}
