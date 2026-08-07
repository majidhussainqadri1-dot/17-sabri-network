<?php
/** Privacy-bounded multi-device presence, revocation and aggregate state. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Presence_Devices {
    private const SCHEMA_VERSION = '1.0.0';
    private const MIN_TTL = 30;
    private const MAX_TTL = 300;
    private const MAX_DEVICES = 25;
    private const FUTURE_SKEW = 60;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            device_key CHAR(64) NOT NULL,
            device_label VARCHAR(80) NOT NULL DEFAULT '',
            state VARCHAR(20) NOT NULL DEFAULT 'online',
            capabilities LONGTEXT NULL,
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY user_device (user_id,device_key),
            KEY user_active (user_id,revoked_at,expires_at),
            KEY expiry (expires_at,revoked_at)
        ) $charset;");
        update_option('sn_presence_devices_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_presence_devices_schema_version', '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function register_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/presence/devices/heartbeat', ['methods'=>'POST','callback'=>[self::class,'heartbeat'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/presence/devices', ['methods'=>'GET','callback'=>[self::class,'list_own'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/presence/devices/revoke', ['methods'=>'POST','callback'=>[self::class,'revoke'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2', '/presence/users/(?P<user_id>\d+)', ['methods'=>'GET','callback'=>[self::class,'aggregate'],'permission_callback'=>$access]);
    }

    public static function heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('presence_device_heartbeat', (string) $user, 180, MINUTE_IN_SECONDS)) return self::error('sn_presence_rate_limited','Presence updates are temporarily limited.',429);
        $raw = strtolower(trim(wp_unslash((string) $request->get_param('device_id'))));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{15,127}$/', $raw)) return self::error('sn_presence_device_invalid','A valid bounded device identifier is required.',400);
        $state = sanitize_key((string) $request->get_param('state'));
        if (!in_array($state,['online','away','dnd','offline'],true)) $state='online';
        $ttl=max(self::MIN_TTL,min(self::MAX_TTL,absint($request->get_param('ttl'))?:120));
        $device_key=self::key($user,$raw);$now=self::now();$expires=$state==='offline'?$now:gmdate('Y-m-d H:i:s',time()+$ttl);
        $capabilities=self::capabilities($request->get_param('capabilities'));
        $label=self::label((string)$request->get_param('label'));
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id=%d AND device_key=%s',$user,$device_key));
        if(!$existing){
            $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL',$user));
            if($count>=self::MAX_DEVICES)return self::error('sn_presence_device_limit','Revoke an old device before adding another.',409);
            $ok=$wpdb->insert(self::table(),['user_id'=>$user,'device_key'=>$device_key,'device_label'=>$label,'state'=>$state,'capabilities'=>(string)wp_json_encode($capabilities),'last_seen_at'=>$now,'expires_at'=>$expires,'created_at'=>$now,'updated_at'=>$now]);
            if($ok===false)return self::error('sn_presence_write_failed','The presence heartbeat could not be stored.',500);
            $version=1;
        }else{
            if($existing->revoked_at)return self::error('sn_presence_device_revoked','This device session was revoked.',403);
            $changed=$wpdb->update(self::table(),['device_label'=>$label,'state'=>$state,'capabilities'=>(string)wp_json_encode($capabilities),'last_seen_at'=>$now,'expires_at'=>$expires,'updated_at'=>$now,'version'=>(int)$existing->version+1],['id'=>(int)$existing->id,'version'=>(int)$existing->version,'revoked_at'=>null]);
            if($changed!==1)return self::error('sn_presence_conflict','A concurrent heartbeat was detected.',409);
            $version=(int)$existing->version+1;
        }
        return rest_ensure_response(['device_ref'=>self::sign_ref($user,$device_key),'state'=>$state,'expires_at'=>$expires,'version'=>$version]);
    }

    public static function list_own(): WP_REST_Response {
        global $wpdb;$user=get_current_user_id();$now=self::now();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT device_key,device_label,state,last_seen_at,expires_at,revoked_at,version,created_at FROM '.self::table().' WHERE user_id=%d ORDER BY updated_at DESC LIMIT %d',$user,self::MAX_DEVICES));$items=[];
        foreach(is_array($rows)?$rows:[] as $row)$items[]=['device_ref'=>self::sign_ref($user,(string)$row->device_key),'label'=>(string)$row->device_label,'state'=>self::effective_state($row,$now),'last_seen_at'=>(string)$row->last_seen_at,'expires_at'=>(string)$row->expires_at,'revoked'=>(bool)$row->revoked_at,'version'=>(int)$row->version,'created_at'=>(string)$row->created_at];
        return rest_ensure_response(['items'=>$items]);
    }

    public static function revoke(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$user=get_current_user_id();$ref=self::verify_ref((string)$request->get_param('device_ref'),$user);if(is_wp_error($ref))return $ref;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id=%d AND device_key=%s',$user,(string)$ref['device_key']));if(!$row)return self::error('sn_presence_device_missing','The device is unavailable.',404);
        if($row->revoked_at)return rest_ensure_response(['status'=>'revoked']);$now=self::now();
        $changed=$wpdb->update(self::table(),['state'=>'offline','revoked_at'=>$now,'expires_at'=>$now,'updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'version'=>(int)$row->version,'revoked_at'=>null]);
        if($changed!==1)return self::error('sn_presence_revoke_conflict','The device changed concurrently.',409);
        SN_DB::audit('presence_device_revoked','presence_device',(int)$row->id,'success',['device_key_prefix'=>substr((string)$row->device_key,0,12)],$user);
        return rest_ensure_response(['status'=>'revoked']);
    }

    public static function aggregate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$viewer=get_current_user_id();$target=absint($request['user_id']);
        if(!$target||!SN_Policy::can_view_presence($viewer,$target))return self::error('sn_presence_unavailable','Presence is unavailable.',404);
        $now=self::now();$rows=$wpdb->get_results($wpdb->prepare('SELECT state,last_seen_at,expires_at,revoked_at FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s ORDER BY last_seen_at DESC LIMIT %d',$target,$now,self::MAX_DEVICES));
        $state='offline';$last=null;$rank=['offline'=>0,'away'=>1,'online'=>2,'dnd'=>3];
        foreach(is_array($rows)?$rows:[] as $row){$effective=self::effective_state($row,$now);if($rank[$effective]>$rank[$state])$state=$effective;if($last===null||strcmp((string)$row->last_seen_at,$last)>0)$last=(string)$row->last_seen_at;}
        $privacy=SN_Policy::privacy_for($target);$show_last=((string)($privacy['last_seen']??'contacts'))!=='nobody';
        $response=['user_id'=>$target,'state'=>$state,'last_seen_at'=>$show_last?$last:null];
        if($viewer===$target)$response['active_devices']=count($rows);
        return rest_ensure_response($response);
    }

    public static function cleanup(): void {
        global $wpdb;$cutoff=gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS);$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE (revoked_at IS NOT NULL AND revoked_at<%s) OR (expires_at<%s AND updated_at<%s) LIMIT 500',$cutoff,$cutoff,$cutoff));
    }

    public static function register_exporter(array $exporters): array {$exporters['sabri-network-presence-devices']=['exporter_friendly_name'=>__('Network presence devices','sabri-network'),'callback'=>[self::class,'export_data']];return $exporters;}
    public static function register_eraser(array $erasers): array {$erasers['sabri-network-presence-devices']=['eraser_friendly_name'=>__('Network presence devices','sabri-network'),'callback'=>[self::class,'erase_data']];return $erasers;}

    public static function export_data(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['data'=>[],'done'=>true];$limit=100;$offset=max(0,$page-1)*$limit;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,device_label,state,last_seen_at,expires_at,revoked_at,created_at FROM '.self::table().' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',(int)$user->ID,$limit,$offset));$data=[];
        foreach(is_array($rows)?$rows:[] as $row)$data[]=['group_id'=>'sabri-network-presence-devices','group_label'=>__('Network presence devices','sabri-network'),'item_id'=>'presence-device-'.(int)$row->id,'data'=>[['name'=>__('Device label','sabri-network'),'value'=>(string)$row->device_label],['name'=>__('State','sabri-network'),'value'=>(string)$row->state],['name'=>__('Last seen','sabri-network'),'value'=>(string)$row->last_seen_at],['name'=>__('Expires','sabri-network'),'value'=>(string)$row->expires_at],['name'=>__('Revoked','sabri-network'),'value'=>(string)$row->revoked_at],['name'=>__('Created','sabri-network'),'value'=>(string)$row->created_at]]];
        return['data'=>$data,'done'=>count($rows)<$limit];
    }

    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$deleted=$wpdb->delete(self::table(),['user_id'=>(int)$user->ID]);return['items_removed'=>$deleted>0,'items_retained'=>false,'messages'=>[],'done'=>true];}

    private static function effective_state(object $row,string $now): string {
        if($row->revoked_at||strcmp((string)$row->expires_at,$now)<=0)return'offline';$seen=strtotime((string)$row->last_seen_at.' UTC');if(!$seen||$seen>time()+self::FUTURE_SKEW)return'offline';$state=(string)$row->state;return in_array($state,['online','away','dnd','offline'],true)?$state:'offline';
    }
    private static function capabilities(mixed $value): array {$allowed=['audio','video','push','realtime'];$out=[];foreach(is_array($value)?$value:[] as $item){$key=sanitize_key((string)$item);if(in_array($key,$allowed,true)&&!in_array($key,$out,true))$out[]=$key;}return$out;}
    private static function label(string $value): string {$value=mb_substr(sanitize_text_field(wp_unslash($value)),0,80);return$value!==''?$value:'This device';}
    private static function key(int $user,string $raw): string {return hash_hmac('sha256',$user.'|'.$raw,wp_salt('auth').'|sn-presence-device-v1');}
    private static function sign_ref(int $user,string $device_key): string {$payload=self::b64((string)wp_json_encode(['u'=>$user,'d'=>$device_key,'p'=>'presence-device']));$sig=hash_hmac('sha256',$payload,wp_salt('auth'));return$payload.'.'.$sig;}
    private static function verify_ref(string $ref,int $user): array|WP_Error {$parts=explode('.',$ref,2);if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],wp_salt('auth')),$parts[1]))return self::error('sn_presence_ref_invalid','The device reference is invalid.',403);$json=self::unb64($parts[0]);$data=json_decode($json,true);if(!is_array($data)||(int)($data['u']??0)!==$user||($data['p']??'')!=='presence-device'||!preg_match('/^[a-f0-9]{64}$/',(string)($data['d']??'')))return self::error('sn_presence_ref_scope','The device reference has the wrong scope.',403);return['device_key'=>(string)$data['d']];}
    private static function b64(string $value): string {return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private static function unb64(string $value): string {$pad=strlen($value)%4;return(string)base64_decode(strtr($value.($pad?str_repeat('=',4-$pad):''),'-_','+/'),true);}
    private static function now(): string {return current_time('mysql',true);}
    private static function table(): string {return SN_DB::table('presence_devices');}
    private static function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,['status'=>$status]);}
}