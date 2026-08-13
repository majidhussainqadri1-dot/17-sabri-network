<?php
/** Review rounds 31–32 + fresh Round 15 — serialized, verifiable key-transparency ledger with revocation lifecycle. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_J {
    private const MAX_VERIFY_ROWS=10000;
    private const LEDGER_LOCK='sn_f17_future_key_log_v1';

    public static function register():void{add_action('rest_api_init',[self::class,'routes'],1999);}
    public static function routes():void{
        register_rest_route('sabri-network/v2','/future/device-keys',['methods'=>'POST','callback'=>[self::class,'register_device_key'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/device-keys/(?P<device_id>[A-Za-z0-9._:-]+)',['methods'=>'DELETE','callback'=>[self::class,'revoke_device_key'],'permission_callback'=>[SN_REST::class,'access']]);
        register_rest_route('sabri-network/v2','/future/device-keys/(?P<user_id>\d+)/safety-number',['methods'=>'GET','callback'=>[self::class,'safety_number'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/key-transparency/(?P<user_id>\d+)',['methods'=>'GET','callback'=>[self::class,'key_transparency'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/key-transparency/(?P<user_id>\d+)/verify',['methods'=>'GET','callback'=>[self::class,'verify_ledger'],'permission_callback'=>[SN_REST::class,'access']]);
        register_rest_route('sabri-network/v2','/future/key-transparency/checkpoint',['methods'=>'GET','callback'=>[self::class,'checkpoint'],'permission_callback'=>[SN_REST::class,'access']]);
    }

    public static function register_device_key(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$user=get_current_user_id();$device=mb_substr(sanitize_text_field((string)$r->get_param('device_id')),0,96);if($device==='')return self::error('sn_device_key_invalid','A valid device identifier is required.',400);$got=self::lock();if(is_wp_error($got))return$got;
        try{
            if($wpdb->query('START TRANSACTION')===false)return self::error('sn_key_ledger_transaction_failed','The key-ledger transaction could not start.',500);
            try{$before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'sn_future_key_log WHERE user_id=%d AND device_id=%s',$user,$device));$response=SN_Future_Superset::register_device_key($r);if(is_wp_error($response)){$wpdb->query('ROLLBACK');return $response;}$after=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'sn_future_key_log WHERE user_id=%d AND device_id=%s',$user,$device));if($after!==$before+1)throw new RuntimeException('sn_key_ledger_write_failed');$integrity=self::verify_all(false);if(is_wp_error($integrity)||empty($integrity['valid']))throw new RuntimeException('sn_key_ledger_integrity_failed');if($wpdb->query('COMMIT')===false)throw new RuntimeException('sn_key_ledger_commit_failed');return $response;}catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_key_ledger_write_failed','Device key update could not be committed safely.',500);}
        }finally{self::unlock();}
    }

    public static function revoke_device_key(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$user=get_current_user_id();$device=mb_substr(sanitize_text_field((string)$r['device_id']),0,96);$got=self::lock();if(is_wp_error($got))return$got;
        try{
            if($wpdb->query('START TRANSACTION')===false)return self::error('sn_key_revoke_failed','The key revocation transaction could not start.',500);
            try{$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_device_keys WHERE user_id=%d AND device_id=%s AND state='active' LIMIT 1 FOR UPDATE",$user,$device));if(!$row){$wpdb->query('ROLLBACK');return self::not_found();}$now=current_time('mysql',true);$ok=$wpdb->update($wpdb->prefix.'sn_future_device_keys',['state'=>'revoked','updated_at'=>$now,'revoked_at'=>$now],['id'=>(int)$row->id,'state'=>'active']);if($ok!==1)throw new RuntimeException('key_state_conflict');if(!self::append_log($user,$device,'revoked',(string)$row->fingerprint,(string)$row->fingerprint,$now))throw new RuntimeException('key_log_failed');$verify=self::verify_all(false);if(is_wp_error($verify)||empty($verify['valid']))throw new RuntimeException('key_log_invalid');if($wpdb->query('COMMIT')===false)throw new RuntimeException('key_revoke_commit_failed');SN_DB::audit('future_device_key_revoked','user',$user,'success',['device_hash'=>hash('sha256',$device),'fingerprint'=>(string)$row->fingerprint],$user);return rest_ensure_response(['device_id'=>$device,'state'=>'revoked','fingerprint'=>(string)$row->fingerprint]);}catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_key_revoke_failed','Device key could not be revoked with ledger integrity.',500);}
        }finally{self::unlock();}
    }

    public static function safety_number(WP_REST_Request $r):WP_REST_Response|WP_Error{$got=self::lock();if(is_wp_error($got))return$got;try{return SN_Future_Superset::safety_number($r);}finally{self::unlock();}}
    public static function key_transparency(WP_REST_Request $r):WP_REST_Response|WP_Error{$got=self::lock();if(is_wp_error($got))return$got;try{return SN_Future_Superset::key_transparency($r);}finally{self::unlock();}}

    public static function verify_ledger(WP_REST_Request $r):WP_REST_Response|WP_Error{$actor=get_current_user_id();$target=absint($r['user_id']);if($target<=0||($target!==$actor&&(SN_DB::is_blocked($actor,$target)||!SN_DB::share_active_conversation($actor,$target))))return self::not_found();$got=self::lock();if(is_wp_error($got))return$got;try{$verify=self::verify_all(true);if(is_wp_error($verify))return$verify;return rest_ensure_response(['user_id'=>$target,'ledger_valid'=>(bool)$verify['valid'],'entries_verified'=>(int)$verify['count'],'latest_entry_hash'=>(string)$verify['latest'],'complete'=>(bool)$verify['complete']]);}finally{self::unlock();}}
    public static function checkpoint():WP_REST_Response|WP_Error{$got=self::lock();if(is_wp_error($got))return$got;try{$verify=self::verify_all(true);if(is_wp_error($verify))return$verify;if(empty($verify['valid'])||empty($verify['complete']))return self::error('sn_key_ledger_not_checkpointable','A complete valid ledger is required for a checkpoint.',409);$claims=['typ'=>'f17-key-transparency-checkpoint','count'=>(int)$verify['count'],'latest'=>(string)$verify['latest'],'iat'=>time(),'exp'=>time()+DAY_IN_SECONDS];$signed=SN_Communication_Crypto::sign($claims,'future-key-transparency-checkpoint');return rest_ensure_response(['checkpoint'=>$claims,'signed_checkpoint'=>$signed]);}finally{self::unlock();}}

    private static function append_log(int $user,string $device,string $event,string $fp,string $prev,string $at):bool{global $wpdb;$last=(string)$wpdb->get_var('SELECT entry_hash FROM '.$wpdb->prefix.'sn_future_key_log ORDER BY id DESC LIMIT 1');$hash=hash('sha256',$last.'|'.$user.'|'.$device.'|'.$event.'|'.$fp.'|'.$prev.'|'.$at);return $wpdb->insert($wpdb->prefix.'sn_future_key_log',['user_id'=>$user,'device_id'=>$device,'event'=>$event,'fingerprint'=>$fp,'previous_fingerprint'=>$prev,'entry_hash'=>$hash,'created_at'=>$at])!==false;}
    private static function verify_all(bool $allow_limit):array|WP_Error{global $wpdb;$count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'sn_future_key_log');if($count>self::MAX_VERIFY_ROWS)return $allow_limit?['valid'=>false,'count'=>self::MAX_VERIFY_ROWS,'latest'=>'','complete'=>false]:self::error('sn_key_ledger_too_large','Key ledger requires the background verifier before accepting another key change.',503);$rows=$wpdb->get_results('SELECT id,user_id,device_id,event,fingerprint,previous_fingerprint,entry_hash,created_at FROM '.$wpdb->prefix.'sn_future_key_log ORDER BY id ASC LIMIT '.self::MAX_VERIFY_ROWS);$last='';$seen=0;foreach(is_array($rows)?$rows:[] as $row){$expected=hash('sha256',$last.'|'.(int)$row->user_id.'|'.(string)$row->device_id.'|'.(string)$row->event.'|'.(string)$row->fingerprint.'|'.(string)$row->previous_fingerprint.'|'.(string)$row->created_at);if(!hash_equals($expected,(string)$row->entry_hash))return ['valid'=>false,'count'=>$seen,'latest'=>$last,'complete'=>true];$last=(string)$row->entry_hash;$seen++;}return ['valid'=>true,'count'=>$seen,'latest'=>$last,'complete'=>$seen===$count];}
    private static function lock():true|WP_Error{global $wpdb;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',self::LEDGER_LOCK));return $got===1?true:self::error('sn_key_ledger_busy','Key transparency is temporarily busy; retry safely.',503);}
    private static function unlock():void{global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::LEDGER_LOCK));}
    private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
