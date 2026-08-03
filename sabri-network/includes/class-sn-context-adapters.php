<?php
/** Opaque, reauthorized context links to File 08, File 18 and File 21. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Context_Adapters {
    private const SCHEMA_VERSION = '1.0.0';
    private const PROVIDERS = ['file08_appointment','file18_marketplace','file21_content'];
    private const MAX_CONTEXTS = 25;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();$table=self::table();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            context_uuid CHAR(36) NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL,
            provider_object_id VARCHAR(191) NOT NULL,
            provider_version VARCHAR(40) NOT NULL DEFAULT '',
            purpose VARCHAR(80) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            attached_by BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME NULL,
            detached_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY context_uuid (context_uuid),
            UNIQUE KEY conversation_provider_object (conversation_id,provider,provider_object_id),
            KEY conversation_status (conversation_id,status,expires_at),
            KEY attached_by (attached_by,created_at)
        ) $charset;");
        update_option('sn_context_adapters_schema_version',self::SCHEMA_VERSION,false);
    }

    public static function maybe_upgrade(): void {if((string)get_option('sn_context_adapters_schema_version','')!==self::SCHEMA_VERSION)self::install();}

    public static function register_routes(): void {
        $access=[SN_REST::class,'access'];
        register_rest_route('sabri-network/v2','/conversations/(?P<id>\d+)/contexts',[
            ['methods'=>'GET','callback'=>[self::class,'get_contexts'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'attach_context'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2','/conversation-contexts/(?P<uuid>[a-f0-9-]{36})',['methods'=>'DELETE','callback'=>[self::class,'detach_context'],'permission_callback'=>$access]);
    }

    public static function attach_context(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$conversation=absint($request['id']);$actor=get_current_user_id();
        if(!SN_DB::is_member($conversation,$actor))return self::not_found();
        $provider=sanitize_key((string)$request->get_param('provider'));if(!in_array($provider,self::PROVIDERS,true))return self::error('sn_context_provider_invalid','Select an approved context provider.',400);
        $object=self::opaque_id((string)$request->get_param('provider_object_id'));if($object==='')return self::error('sn_context_object_invalid','A valid opaque provider object reference is required.',400);
        $purpose=mb_substr(sanitize_text_field(wp_unslash((string)$request->get_param('purpose'))),0,80);
        $expires=self::expiry((string)$request->get_param('expires_at'));if(is_wp_error($expires))return $expires;
        $authorization=apply_filters('sn_network_context_authorize',new WP_Error('sn_context_provider_unavailable','The context provider is unavailable.',['status'=>503]),$provider,$object,$conversation,$actor,'attach');
        if(is_wp_error($authorization))return $authorization;if($authorization!==true)return self::error('sn_context_authorization_denied','The context provider denied this attachment.',403);
        $projection=apply_filters('sn_network_context_projection',null,$provider,$object,$conversation,$actor);
        $projection=self::validate_projection($projection);if(is_wp_error($projection))return $projection;
        if($expires!==null&&strtotime($expires.' UTC')<=time())return self::error('sn_context_expired','An already-expired context cannot be attached.',410);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table()." WHERE conversation_id=%d AND status='active'",$conversation));if($count>=self::MAX_CONTEXTS)return self::error('sn_context_limit','The conversation context limit has been reached.',409);
        $now=self::now();$wpdb->query('START TRANSACTION');
        try{
            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE conversation_id=%d AND provider=%s AND provider_object_id=%s FOR UPDATE',$conversation,$provider,$object));
            if($existing){
                $changed=$wpdb->update(self::table(),['status'=>'active','provider_version'=>self::version((string)$request->get_param('provider_version')),'purpose'=>$purpose,'attached_by'=>$actor,'expires_at'=>$expires,'detached_at'=>null,'updated_at'=>$now,'version'=>(int)$existing->version+1],['id'=>(int)$existing->id,'version'=>(int)$existing->version]);if($changed!==1)throw new RuntimeException('context_update_conflict');$id=(int)$existing->id;$uuid=(string)$existing->context_uuid;
            }else{
                $uuid=wp_generate_uuid4();$ok=$wpdb->insert(self::table(),['context_uuid'=>$uuid,'conversation_id'=>$conversation,'provider'=>$provider,'provider_object_id'=>$object,'provider_version'=>self::version((string)$request->get_param('provider_version')),'purpose'=>$purpose,'status'=>'active','attached_by'=>$actor,'expires_at'=>$expires,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)throw new RuntimeException('context_insert_failed');$id=(int)$wpdb->insert_id;
            }
            $event=SN_Outbox::enqueue('conversation.context_attached','conversation',$conversation,['conversation_id'=>$conversation,'context_uuid'=>$uuid,'provider'=>$provider,'provider_object_hash'=>hash('sha256',$object),'expires_at'=>$expires],'conversation.context_attached:'.$uuid.':'.$now);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            SN_DB::audit('conversation_context_attached','conversation',$conversation,'success',['context_uuid'=>$uuid,'provider'=>$provider,'provider_object_hash'=>hash('sha256',$object)],$actor);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('context_attach_commit_failed');
            return new WP_REST_Response(['context'=>self::format($id,$uuid,$provider,$object,$purpose,$expires,$projection)],201);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_context_attach_failed','The context and its event evidence could not be committed.',500);}
    }

    public static function get_contexts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$conversation=absint($request['id']);$viewer=get_current_user_id();if(!SN_DB::is_member($conversation,$viewer))return self::not_found();$now=self::now();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table()." WHERE conversation_id=%d AND status='active' AND (expires_at IS NULL OR expires_at>%s) ORDER BY id ASC LIMIT %d",$conversation,$now,self::MAX_CONTEXTS));$items=[];
        foreach(is_array($rows)?$rows:[] as $row){$auth=apply_filters('sn_network_context_authorize',new WP_Error('sn_context_provider_unavailable','The context provider is unavailable.',['status'=>503]),(string)$row->provider,(string)$row->provider_object_id,$conversation,$viewer,'read');if($auth!==true)continue;$projection=self::validate_projection(apply_filters('sn_network_context_projection',null,(string)$row->provider,(string)$row->provider_object_id,$conversation,$viewer));if(is_wp_error($projection))continue;$items[]=self::format((int)$row->id,(string)$row->context_uuid,(string)$row->provider,(string)$row->provider_object_id,(string)$row->purpose,$row->expires_at?(string)$row->expires_at:null,$projection);}
        return rest_ensure_response(['items'=>$items]);
    }

    public static function detach_context(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$uuid=strtolower((string)$request['uuid']);$actor=get_current_user_id();$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE context_uuid=%s',$uuid));if(!$row||!SN_DB::is_member((int)$row->conversation_id,$actor))return self::not_found();
        $auth=apply_filters('sn_network_context_authorize',new WP_Error('sn_context_provider_unavailable','The context provider is unavailable.',['status'=>503]),(string)$row->provider,(string)$row->provider_object_id,(int)$row->conversation_id,$actor,'detach');if(is_wp_error($auth))return $auth;if($auth!==true&&$actor!==(int)$row->attached_by)return self::error('sn_context_detach_forbidden','This context cannot be detached by the current account.',403);
        if((string)$row->status!=='active')return rest_ensure_response(['status'=>(string)$row->status]);$now=self::now();$wpdb->query('START TRANSACTION');
        try{$changed=$wpdb->update(self::table(),['status'=>'detached','detached_at'=>$now,'updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'status'=>'active','version'=>(int)$row->version]);if($changed!==1)throw new RuntimeException('context_detach_conflict');$event=SN_Outbox::enqueue('conversation.context_detached','conversation',(int)$row->conversation_id,['conversation_id'=>(int)$row->conversation_id,'context_uuid'=>$uuid,'provider'=>(string)$row->provider],'conversation.context_detached:'.$uuid);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());SN_DB::audit('conversation_context_detached','conversation',(int)$row->conversation_id,'success',['context_uuid'=>$uuid,'provider'=>(string)$row->provider],$actor);if($wpdb->query('COMMIT')===false)throw new RuntimeException('context_detach_commit_failed');return rest_ensure_response(['status'=>'detached']);}catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_context_detach_failed','The context detachment could not be committed.',500);}
    }

    public static function cleanup(): void {global $wpdb;$now=self::now();$wpdb->query($wpdb->prepare("UPDATE ".self::table()." SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at IS NOT NULL AND expires_at<=%s LIMIT 500",$now,$now));}

    public static function register_exporter(array $exporters): array {$exporters['sabri-network-contexts']=['exporter_friendly_name'=>__('Network conversation contexts','sabri-network'),'callback'=>[self::class,'export_data']];return$exporters;}
    public static function register_eraser(array $erasers): array {$erasers['sabri-network-contexts']=['eraser_friendly_name'=>__('Network conversation contexts','sabri-network'),'callback'=>[self::class,'erase_data']];return$erasers;}
    public static function export_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['data'=>[],'done'=>true];$limit=100;$offset=max(0,$page-1)*$limit;$rows=$wpdb->get_results($wpdb->prepare('SELECT context_uuid,conversation_id,provider,provider_version,purpose,status,expires_at,created_at,updated_at FROM '.self::table().' WHERE attached_by=%d ORDER BY id ASC LIMIT %d OFFSET %d',(int)$user->ID,$limit,$offset));$data=[];foreach(is_array($rows)?$rows:[] as $row)$data[]=['group_id'=>'sabri-network-contexts','group_label'=>__('Network conversation contexts','sabri-network'),'item_id'=>'context-'.(string)$row->context_uuid,'data'=>[['name'=>__('Conversation ID','sabri-network'),'value'=>(int)$row->conversation_id],['name'=>__('Provider','sabri-network'),'value'=>(string)$row->provider],['name'=>__('Provider version','sabri-network'),'value'=>(string)$row->provider_version],['name'=>__('Purpose','sabri-network'),'value'=>(string)$row->purpose],['name'=>__('Status','sabri-network'),'value'=>(string)$row->status],['name'=>__('Expires','sabri-network'),'value'=>(string)$row->expires_at],['name'=>__('Created','sabri-network'),'value'=>(string)$row->created_at]]];return['data'=>$data,'done'=>count($rows)<$limit];}
    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$changed=$wpdb->update(self::table(),['attached_by'=>0,'updated_at'=>self::now()],['attached_by'=>(int)$user->ID]);return['items_removed'=>$changed>0,'items_retained'=>false,'messages'=>[],'done'=>true];}

    private static function validate_projection(mixed $value): array|WP_Error {if(!is_array($value))return self::error('sn_context_projection_unavailable','The provider projection is unavailable.',503);$url=esc_url_raw((string)($value['url']??''));if(!self::same_origin_https($url))return self::error('sn_context_projection_url_invalid','The provider returned an unsafe projection URL.',502);return['url'=>$url,'label'=>mb_substr(sanitize_text_field((string)($value['label']??'Context')),0,120),'status'=>mb_substr(sanitize_key((string)($value['status']??'available')),0,40),'summary'=>mb_substr(sanitize_text_field((string)($value['summary']??'')),0,240)];}
    private static function same_origin_https(string $url): bool {if($url==='')return false;$parts=wp_parse_url($url);$home=wp_parse_url(home_url('/'));if(!is_array($parts)||!is_array($home))return false;$scheme=strtolower((string)($parts['scheme']??''));return$scheme==='https'&&strcasecmp((string)($parts['host']??''),(string)($home['host']??''))===0&&!isset($parts['user'],$parts['pass']);}
    private static function format(int $id,string $uuid,string $provider,string $object,string $purpose,?string $expires,array $projection): array {return['id'=>$id,'context_uuid'=>$uuid,'provider'=>$provider,'provider_object_hash'=>hash('sha256',$object),'purpose'=>$purpose,'expires_at'=>$expires,'projection'=>$projection];}
    private static function expiry(string $value): string|WP_Error|null {$value=trim($value);if($value==='')return null;$ts=strtotime($value);if(!$ts||$ts<=time()||$ts>time()+365*DAY_IN_SECONDS)return self::error('sn_context_expiry_invalid','Use a future context expiry within one year.',400);return gmdate('Y-m-d H:i:s',$ts);}
    private static function opaque_id(string $value): string {$value=trim(wp_unslash($value));return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/',$value)?$value:'';}
    private static function version(string $value): string {$value=trim(wp_unslash($value));return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/',$value)?$value:'';}
    private static function now(): string {return current_time('mysql',true);}
    private static function table(): string {return SN_DB::table('conversation_contexts');}
    private static function not_found(): WP_Error {return self::error('sn_context_not_found','The requested conversation context is unavailable.',404);}
    private static function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,['status'=>$status]);}
}
