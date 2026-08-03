<?php
/** Secret-free provider governance and short-lived conference credential issuance. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Conference_Provider {
    private const SCHEMA_VERSION = '1.0.0';
    private const TYPES = ['stun','turn','sfu'];
    private const STATUSES = ['configured','healthy','degraded','disabled'];
    private const MAX_CREDENTIAL_TTL = 10 * MINUTE_IN_SECONDS;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();$table=self::table();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_key VARCHAR(80) NOT NULL,
            provider_type VARCHAR(20) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            endpoint_origin VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'configured',
            capabilities LONGTEXT NULL,
            configuration_version VARCHAR(40) NOT NULL DEFAULT '',
            health_summary VARCHAR(500) NOT NULL DEFAULT '',
            health_checked_at DATETIME NULL,
            configured_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY provider_key (provider_key),
            KEY type_status (provider_type,status),
            KEY health_checked_at (health_checked_at)
        ) $charset;");
        update_option('sn_conference_provider_schema_version',self::SCHEMA_VERSION,false);
        delete_option('sn_turn_secret');delete_option('sn_turn_password');delete_option('sn_sfu_secret');delete_option('sn_conference_api_key');
    }

    public static function maybe_upgrade(): void {if((string)get_option('sn_conference_provider_schema_version','')!==self::SCHEMA_VERSION)self::install();}

    public static function register_routes(): void {
        register_rest_route('sabri-network/v2','/admin/conference-providers',[
            ['methods'=>'GET','callback'=>[self::class,'list_providers'],'permission_callback'=>[SN_REST::class,'admin_access']],
            ['methods'=>'POST','callback'=>[self::class,'configure_provider'],'permission_callback'=>[SN_REST::class,'admin_access']],
        ]);
        register_rest_route('sabri-network/v2','/admin/conference-providers/(?P<key>[a-z0-9_-]+)/health',['methods'=>'POST','callback'=>[self::class,'check_health'],'permission_callback'=>[SN_REST::class,'admin_access']]);
        register_rest_route('sabri-network/v2','/calls/(?P<id>\d+)/media-credentials',['methods'=>'POST','callback'=>[self::class,'issue_credentials'],'permission_callback'=>[SN_REST::class,'access']]);
    }

    public static function configure_provider(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$actor=get_current_user_id();$key=sanitize_key((string)$request->get_param('provider_key'));
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/',$key))return self::error('sn_provider_key_invalid','Enter a valid provider key.',400);
        $type=sanitize_key((string)$request->get_param('provider_type'));if(!in_array($type,self::TYPES,true))return self::error('sn_provider_type_invalid','Select STUN, TURN or SFU.',400);
        $status=sanitize_key((string)$request->get_param('status'));if(!in_array($status,self::STATUSES,true))$status='configured';
        $origin=self::origin((string)$request->get_param('endpoint_origin'),$type);if(is_wp_error($origin))return $origin;
        $name=mb_substr(sanitize_text_field(wp_unslash((string)$request->get_param('display_name'))),0,120);if($name==='')$name=strtoupper($type).' provider';
        $caps=self::capabilities($request->get_param('capabilities'));$config_version=self::version((string)$request->get_param('configuration_version'));
        $payload=['provider_key'=>$key,'provider_type'=>$type,'display_name'=>$name,'endpoint_origin'=>$origin,'status'=>$status,'capabilities'=>$caps,'configuration_version'=>$config_version];
        $action_id=absint($request->get_param('high_risk_action_id'));$wpdb->query('START TRANSACTION');
        try{
            $claim=SN_High_Risk::claim($action_id,$actor,'provider_configuration',$payload);if(is_wp_error($claim)){$wpdb->query('ROLLBACK');return$claim;}
            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE provider_key=%s FOR UPDATE',$key));$now=self::now();$data=['provider_type'=>$type,'display_name'=>$name,'endpoint_origin'=>$origin,'status'=>$status,'capabilities'=>(string)wp_json_encode($caps),'configuration_version'=>$config_version,'configured_by'=>$actor,'updated_at'=>$now];
            if($existing){$data['version']=(int)$existing->version+1;$changed=$wpdb->update(self::table(),$data,['id'=>(int)$existing->id,'version'=>(int)$existing->version]);if($changed!==1)throw new RuntimeException('provider_update_conflict');$id=(int)$existing->id;}
            else{$data+=['provider_key'=>$key,'created_at'=>$now];if($wpdb->insert(self::table(),$data)===false)throw new RuntimeException('provider_insert_failed');$id=(int)$wpdb->insert_id;}
            $event=SN_Outbox::enqueue('conference.provider_configured','conference_provider',$id,['provider_key'=>$key,'provider_type'=>$type,'status'=>$status,'endpoint_origin_hash'=>hash('sha256',$origin),'configuration_version'=>$config_version],'conference.provider_configured:'.$key.':'.$config_version.':'.$now);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            $completed=SN_High_Risk::complete($action_id,$actor,(string)$claim['claim_token'],['provider_key'=>$key,'provider_id'=>$id,'configuration_version'=>$config_version]);if(is_wp_error($completed))throw new RuntimeException($completed->get_error_code());
            SN_DB::audit('conference_provider_configured','conference_provider',$id,'success',['provider_key'=>$key,'provider_type'=>$type,'endpoint_origin_hash'=>hash('sha256',$origin),'configuration_version'=>$config_version],$actor);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('provider_commit_failed');
            return rest_ensure_response(['provider'=>self::format(self::provider($key))]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_provider_configuration_failed','The provider configuration and governance evidence could not be committed.',500);}
    }

    public static function list_providers(): WP_REST_Response {global $wpdb;$rows=$wpdb->get_results('SELECT * FROM '.self::table().' ORDER BY provider_type,provider_key LIMIT 100');return rest_ensure_response(['items'=>array_map([self::class,'format'],is_array($rows)?$rows:[]),'claims'=>['end_to_end_encryption'=>false,'recording'=>false,'credentials_persisted'=>false]]);}

    public static function check_health(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$key=sanitize_key((string)$request['key']);$row=self::provider($key);if(!$row)return self::error('sn_provider_missing','The conference provider is unavailable.',404);
        $result=apply_filters('sn_network_conference_provider_health',null,self::format($row));if(!is_array($result))return self::error('sn_provider_health_adapter_unavailable','No provider health adapter is configured.',503);
        $status=sanitize_key((string)($result['status']??'degraded'));if(!in_array($status,['healthy','degraded','disabled'],true))$status='degraded';$summary=mb_substr(sanitize_text_field((string)($result['summary']??'')),0,500);$now=self::now();
        $changed=$wpdb->update(self::table(),['status'=>$status,'health_summary'=>$summary,'health_checked_at'=>$now,'updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'version'=>(int)$row->version]);if($changed!==1)return self::error('sn_provider_health_conflict','The provider changed concurrently.',409);
        return rest_ensure_response(['provider_key'=>$key,'status'=>$status,'summary'=>$summary,'checked_at'=>$now]);
    }

    public static function issue_credentials(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$call_id=absint($request['id']);$user=get_current_user_id();
        $call=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('calls').' WHERE id=%d',$call_id));if(!$call)return self::error('sn_call_missing','The call is unavailable.',404);
        $member=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('call_members')." WHERE call_id=%d AND user_id=%d AND status NOT IN ('left','removed') LIMIT 1",$call_id,$user));if(!$member)return self::error('sn_call_membership_required','An active call membership is required.',403);
        if(!in_array((string)$call->status,['ringing','accepted','connected','reconnecting','scheduled','live'],true))return self::error('sn_call_state_unavailable','Media credentials are unavailable for this call state.',409);
        $type=sanitize_key((string)$request->get_param('provider_type'));if(!in_array($type,self::TYPES,true))$type=(string)$call->call_type==='group'?'sfu':'turn';
        $provider=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table()." WHERE provider_type=%s AND status='healthy' ORDER BY health_checked_at DESC,id ASC LIMIT 1",$type));
        if(!$provider)return self::error('sn_conference_provider_unavailable','The required conference infrastructure is unavailable.',503);
        if(!$provider->health_checked_at||strtotime((string)$provider->health_checked_at.' UTC')<time()-15*MINUTE_IN_SECONDS)return self::error('sn_conference_provider_health_stale','The provider health evidence is stale.',503);
        $issued=apply_filters('sn_network_issue_conference_credentials',null,self::format($provider),['call_id'=>$call_id,'conversation_id'=>(int)$call->conversation_id,'user_id'=>$user,'member_role'=>(string)$member->role]);
        $credentials=self::validate_credentials($issued,(string)$provider->endpoint_origin,$call_id,$user);if(is_wp_error($credentials))return$credentials;
        SN_DB::audit('conference_credentials_issued','call',$call_id,'success',['provider_key'=>(string)$provider->provider_key,'provider_type'=>(string)$provider->provider_type,'expires_at'=>(string)$credentials['expires_at']],$user);
        return rest_ensure_response(['provider_key'=>(string)$provider->provider_key,'provider_type'=>(string)$provider->provider_type,'credentials'=>$credentials,'claims'=>['end_to_end_encryption'=>false,'recording'=>false]]);
    }

    public static function cleanup(): void {delete_option('sn_turn_secret');delete_option('sn_turn_password');delete_option('sn_sfu_secret');delete_option('sn_conference_api_key');}

    private static function validate_credentials(mixed $value,string $origin,int $call,int $user): array|WP_Error {
        if(!is_array($value))return self::error('sn_conference_credentials_unavailable','Short-lived media credentials could not be issued.',503);
        $expires=(string)($value['expires_at']??'');$ts=strtotime($expires);if(!$ts||$ts<=time()||$ts>time()+self::MAX_CREDENTIAL_TTL)return self::error('sn_conference_credentials_expiry_invalid','The provider returned credentials outside the permitted lifetime.',502);
        $audience=(string)($value['audience']??'');if($audience!==('call:'.$call.':user:'.$user))return self::error('sn_conference_credentials_audience_invalid','The provider credentials have the wrong audience.',502);
        $urls=[];foreach(array_slice(is_array($value['urls']??null)?$value['urls']:[],0,10) as $url){$url=trim((string)$url);if(!preg_match('#^(stun|turn|turns|wss|https):#i',$url))return self::error('sn_conference_credential_url_invalid','The provider returned an invalid media endpoint.',502);$urls[]=$url;}
        if(!$urls)return self::error('sn_conference_credential_url_missing','The provider returned no media endpoints.',502);
        $username=mb_substr((string)($value['username']??''),0,256);$credential=mb_substr((string)($value['credential']??''),0,512);if($credential===''&&str_contains(implode(',',$urls),'turn'))return self::error('sn_conference_credential_missing','TURN credentials are missing.',502);
        return['urls'=>$urls,'username'=>$username,'credential'=>$credential,'expires_at'=>gmdate('Y-m-d H:i:s',$ts),'audience'=>$audience,'origin_hash'=>hash('sha256',$origin)];
    }

    private static function origin(string $url,string $type): string|WP_Error {$url=esc_url_raw(trim($url));$parts=wp_parse_url($url);if(!is_array($parts)||isset($parts['user'],$parts['pass'],$parts['query'],$parts['fragment']))return self::error('sn_provider_origin_invalid','Use a secret-free provider origin without user info or query parameters.',400);$scheme=strtolower((string)($parts['scheme']??''));$allowed=$type==='sfu'?['https','wss']:['https'];if(!in_array($scheme,$allowed,true)||(string)($parts['host']??'')==='')return self::error('sn_provider_origin_invalid','Use an HTTPS or WSS provider origin as permitted for this provider type.',400);$port=isset($parts['port'])?':'.(int)$parts['port']:'';return$scheme.'://'.strtolower((string)$parts['host']).$port;}
    private static function capabilities(mixed $value): array {$allowed=['audio','video','screen_share','captions','group_calls','simulcast','data_channel'];$out=[];foreach(is_array($value)?$value:[] as $item){$key=sanitize_key((string)$item);if(in_array($key,$allowed,true)&&!in_array($key,$out,true))$out[]=$key;}sort($out);return$out;}
    private static function version(string $value): string {$value=trim(wp_unslash($value));return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/',$value)?$value:'';}
    private static function provider(string $key): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE provider_key=%s',$key))?:null;}
    private static function format(?object $row): array {if(!$row)return[];return['id'=>(int)$row->id,'provider_key'=>(string)$row->provider_key,'provider_type'=>(string)$row->provider_type,'display_name'=>(string)$row->display_name,'endpoint_origin'=>(string)$row->endpoint_origin,'status'=>(string)$row->status,'capabilities'=>json_decode((string)$row->capabilities,true)?:[],'configuration_version'=>(string)$row->configuration_version,'health_summary'=>(string)$row->health_summary,'health_checked_at'=>(string)$row->health_checked_at,'version'=>(int)$row->version,'updated_at'=>(string)$row->updated_at];}
    private static function now(): string {return current_time('mysql',true);}
    private static function table(): string {return SN_DB::table('conference_providers');}
    private static function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,['status'=>$status]);}
}
