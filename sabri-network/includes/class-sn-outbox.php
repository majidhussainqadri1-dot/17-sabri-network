<?php
defined('ABSPATH') || exit;

/** File-17 transactional event outbox/inbox with bounded retry and dead-letter operations. */
final class SN_Outbox {
    private const SCHEMA_VERSION = '1.0.0';
    private const BATCH_SIZE = 50;
    private const LOCK_SECONDS = 120;
    private const MAX_PAYLOAD_BYTES = 65535;
    private const DEFAULT_MAX_ATTEMPTS = 8;

    public static function register(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action('sn_network_outbox_tick', [self::class, 'dispatch_batch']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $outbox = self::outbox_table();
        $inbox = self::inbox_table();
        dbDelta("CREATE TABLE $outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid CHAR(36) NOT NULL,
            event_key CHAR(64) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            aggregate_type VARCHAR(40) NOT NULL DEFAULT '',
            aggregate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payload LONGTEXT NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            locked_at DATETIME NULL,
            lock_token CHAR(64) NULL,
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            dead_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_key (event_key),
            UNIQUE KEY event_uuid (event_uuid),
            KEY dispatch_queue (status,available_at,id),
            KEY stale_lock (status,locked_at)
        ) $charset;");
        dbDelta("CREATE TABLE $inbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producer VARCHAR(80) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'processing',
            attempts INT UNSIGNED NOT NULL DEFAULT 1,
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY producer_event (producer,event_uuid),
            KEY status_updated (status,updated_at)
        ) $charset;");
        update_option('sn_event_delivery_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_event_delivery_schema_version', '') !== self::SCHEMA_VERSION) self::install();
        self::ensure_schedule();
    }

    public static function deactivate(): void { wp_clear_scheduled_hook('sn_network_outbox_tick'); }

    public static function cron_schedules(array $schedules): array {
        $schedules['sn_every_minute'] ??= ['interval' => MINUTE_IN_SECONDS, 'display' => __('Every minute — Sabri Network event delivery', 'sabri-network')];
        return $schedules;
    }

    private static function ensure_schedule(): bool|WP_Error {
        if (wp_next_scheduled('sn_network_outbox_tick')) { delete_option('sn_outbox_schedule_error'); return true; }
        $scheduled = wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sn_every_minute', 'sn_network_outbox_tick', [], true);
        if (is_wp_error($scheduled) || $scheduled === false) {
            $code = is_wp_error($scheduled) ? $scheduled->get_error_code() : 'schedule_failed';
            update_option('sn_outbox_schedule_error', sanitize_key($code), false);
            SN_DB::audit('event_delivery_schedule_failed','event',0,'failure',['reason'=>sanitize_key($code)],0);
            return is_wp_error($scheduled) ? $scheduled : new WP_Error('sn_outbox_schedule_failed','File 17 event delivery could not be scheduled.');
        }
        delete_option('sn_outbox_schedule_error');
        return true;
    }

    public static function register_routes(): void {
        register_rest_route('sabri-network/v2', '/admin/outbox', ['methods'=>'GET','callback'=>[self::class,'admin_events'],'permission_callback'=>[SN_REST::class,'admin_access']]);
        register_rest_route('sabri-network/v2', '/admin/outbox/(?P<id>\d+)/retry', ['methods'=>'POST','callback'=>[self::class,'admin_retry'],'permission_callback'=>[SN_REST::class,'admin_access']]);
        register_rest_route('sabri-network/v2', '/admin/outbox-health', ['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>[SN_REST::class,'admin_access']]);
    }

    /** Enqueue inside the caller's DB transaction. */
    public static function enqueue(string $type, string $aggregate_type, int $aggregate_id, array $payload, string $idempotency_key): int|WP_Error {
        global $wpdb;
        $type = strtolower(trim($type)); $aggregate_type = sanitize_key($aggregate_type);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $type) || $idempotency_key === '' || strlen($idempotency_key) > 255) return new WP_Error('invalid_event_identity', 'A valid bounded event identity is required.');
        $clean = self::sanitize_payload($payload); $json = (string) wp_json_encode($clean);
        if ($json === '' || strlen($json) > self::MAX_PAYLOAD_BYTES) return new WP_Error('event_payload_invalid', 'The event metadata is invalid or too large.');
        $payload_hash = hash('sha256', $json); $event_key = hash('sha256', $type . '|' . $idempotency_key); $table = self::outbox_table();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id,event_type,payload_hash FROM $table WHERE event_key=%s LIMIT 1", $event_key));
        if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();
        if ($existing) return (string)$existing->event_type === $type && hash_equals((string)$existing->payload_hash, $payload_hash) ? (int)$existing->id : new WP_Error('event_idempotency_conflict', 'The event identity was reused with different metadata.');
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($table, ['event_uuid'=>wp_generate_uuid4(),'event_key'=>$event_key,'event_type'=>$type,'aggregate_type'=>$aggregate_type,'aggregate_id'=>max(0,$aggregate_id),'payload'=>$json,'payload_hash'=>$payload_hash,'status'=>'pending','attempts'=>0,'available_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        if ($ok === false) {
            $race = $wpdb->get_row($wpdb->prepare("SELECT id,event_type,payload_hash FROM $table WHERE event_key=%s LIMIT 1", $event_key));
            if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();
            return $race && (string)$race->event_type === $type && hash_equals((string)$race->payload_hash, $payload_hash) ? (int)$race->id : new WP_Error('event_enqueue_failed', 'The event could not be queued.');
        }
        return (int) $wpdb->insert_id;
    }

    public static function dispatch_batch(): void {
        global $wpdb; $now=current_time('mysql',true); $stale=gmdate('Y-m-d H:i:s',time()-self::LOCK_SECONDS);
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".self::outbox_table()." WHERE ((status IN ('pending','retry') AND available_at<=%s) OR (status='processing' AND locked_at<%s)) ORDER BY id ASC LIMIT %d",$now,$stale,self::BATCH_SIZE));
        if (!is_array($ids)) {
            SN_DB::audit('event_dispatch_queue_read_failed','event',0,'failure',['reason'=>(string)$wpdb->last_error],0);
            return;
        }
        foreach(array_map('absint',$ids) as $id) self::dispatch_one($id);
    }

    public static function dispatch_one(int $id): bool|WP_Error {
        global $wpdb;
        if($id<=0)return new WP_Error('invalid_event','The event is invalid.');
        $table=self::outbox_table();$now=current_time('mysql',true);$stale=gmdate('Y-m-d H:i:s',time()-self::LOCK_SECONDS);$token=hash('sha256',wp_generate_uuid4().':'.$id.':'.microtime(true));
        $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',lock_token=%s,locked_at=%s,attempts=attempts+1,updated_at=%s,version=version+1 WHERE id=%d AND ((status IN ('pending','retry') AND available_at<=%s) OR (status='processing' AND locked_at<%s))",$token,$now,$now,$id,$now,$stale));
        if($claimed===false||($wpdb->last_error ?? '')!=='')return self::storage_unavailable();
        if($claimed!==1)return new WP_Error('event_not_claimed','The event is not available for delivery.');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND lock_token=%s",$id,$token));if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();if(!$row)return new WP_Error('event_claim_lost','The event claim could not be confirmed.');
        try{
            $payload=json_decode((string)$row->payload,true);if(!is_array($payload)||!hash_equals((string)$row->payload_hash,hash('sha256',(string)$row->payload)))throw new RuntimeException('event_payload_integrity_failed');
            $event=['id'=>(int)$row->id,'uuid'=>(string)$row->event_uuid,'type'=>(string)$row->event_type,'aggregate_type'=>(string)$row->aggregate_type,'aggregate_id'=>(int)$row->aggregate_id,'payload'=>$payload,'attempt'=>(int)$row->attempts,'created_at'=>(string)$row->created_at];
            do_action('sn_network_event_dispatched',$event);
            // A durable outbox is not delivered merely because nobody objected. An approved
            // consumer must explicitly acknowledge this exact event UUID through the filter.
            $ack=apply_filters('sn_network_outbox_delivery_result',null,$event);
            if(is_wp_error($ack)||$ack!==true)throw new RuntimeException(is_wp_error($ack)?$ack->get_error_code():'event_not_acknowledged');
            $done=current_time('mysql',true);if($wpdb->query($wpdb->prepare("UPDATE $table SET status='delivered',lock_token=NULL,locked_at=NULL,last_error='',delivered_at=%s,dead_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND lock_token=%s",$done,$done,$id,$token))!==1)throw new RuntimeException('event_delivery_state_failed');
            return true;
        }catch(Throwable $e){
            $attempts=(int)$row->attempts;$dead=$attempts>=self::max_attempts();$delay=min(HOUR_IN_SECONDS,30*(2**max(0,min(10,$attempts-1))));$failed=current_time('mysql',true);$error=mb_substr(sanitize_text_field($e->getMessage()),0,500);
            $persisted=$wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,lock_token=NULL,locked_at=NULL,last_error=%s,available_at=%s,dead_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND lock_token=%s",$dead?'dead':'retry',$error,gmdate('Y-m-d H:i:s',time()+$delay),$dead?$failed:null,$failed,$id,$token));
            if ($persisted !== 1) {
                SN_DB::audit('event_failure_state_persist_failed','event',$id,'failure',['event_type'=>(string)$row->event_type,'attempts'=>$attempts,'reason'=>$error,'db_error'=>(string)$wpdb->last_error],0);
                return new WP_Error('event_delivery_state_unknown','The event delivery result could not be persisted; stale-lease recovery must reconcile it.');
            }
            SN_DB::audit($dead?'event_dead_lettered':'event_delivery_retry_scheduled','event',$id,'failure',['event_type'=>(string)$row->event_type,'attempts'=>$attempts,'reason'=>$error],0);return new WP_Error($dead?'event_dead_lettered':'event_delivery_failed','The event delivery was not acknowledged.');
        }
    }

    /** Transactional, idempotent inbox for local companion handlers. */
    public static function consume_incoming(string $producer, string $event_uuid, array $payload, callable $handler): bool|WP_Error {
        global $wpdb;
        $producer=sanitize_key($producer);if($producer===''||!wp_is_uuid($event_uuid,4))return new WP_Error('invalid_incoming_event','The incoming event identity is invalid.');
        $clean=self::sanitize_payload($payload);$json=(string)wp_json_encode($clean);if($json===''||strlen($json)>self::MAX_PAYLOAD_BYTES)return new WP_Error('incoming_payload_invalid','The incoming event metadata is invalid.');
        $hash=hash('sha256',$json);$table=self::inbox_table();$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE producer=%s AND event_uuid=%s LIMIT 1",$producer,$event_uuid));
        if(($wpdb->last_error ?? '')!=='')return new WP_Error('incoming_event_storage_unavailable','Incoming event storage truth could not be verified.',['status'=>503]);
        if($existing&&!hash_equals((string)$existing->payload_hash,$hash))return new WP_Error('incoming_event_conflict','The incoming event identity conflicts with prior metadata.');
        if($existing&&(string)$existing->status==='processed')return true;
        $now=current_time('mysql',true);
        if($wpdb->query('START TRANSACTION')===false)return new WP_Error('incoming_event_transaction_failed','The incoming event transaction could not be started.');
        try{
            if(!$existing){if($wpdb->insert($table,['producer'=>$producer,'event_uuid'=>$event_uuid,'payload_hash'=>$hash,'status'=>'processing','attempts'=>1,'created_at'=>$now,'updated_at'=>$now])===false)throw new RuntimeException('incoming_event_claim_failed');}
            elseif($wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',attempts=attempts+1,last_error='',updated_at=%s WHERE id=%d AND status<>'processed'",$now,(int)$existing->id))!==1)throw new RuntimeException('incoming_event_claim_failed');
            $result=$handler($clean);if(is_wp_error($result)||$result===false)throw new RuntimeException(is_wp_error($result)?$result->get_error_code():'incoming_event_handler_failed');
            $done=current_time('mysql',true);if($wpdb->query($wpdb->prepare("UPDATE $table SET status='processed',last_error='',processed_at=%s,updated_at=%s WHERE producer=%s AND event_uuid=%s",$done,$done,$producer,$event_uuid))!==1)throw new RuntimeException('incoming_event_completion_failed');
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('incoming_event_commit_failed');
            return true;
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');$failed=current_time('mysql',true);$error=mb_substr(sanitize_text_field($e->getMessage()),0,500);
            $recorded=$wpdb->query($wpdb->prepare("INSERT INTO $table (producer,event_uuid,payload_hash,status,attempts,last_error,created_at,updated_at) VALUES (%s,%s,%s,'failed',1,%s,%s,%s) ON DUPLICATE KEY UPDATE status=IF(status='processed','processed','failed'),attempts=IF(status='processed',attempts,attempts+1),last_error=IF(status='processed',last_error,VALUES(last_error)),updated_at=IF(status='processed',updated_at,VALUES(updated_at))",$producer,$event_uuid,$hash,$error,$failed,$failed));
            if ($recorded === false) SN_DB::audit('incoming_event_failure_record_failed','event',0,'failure',['producer'=>$producer,'event_uuid_hash'=>hash('sha256',$event_uuid),'reason'=>$error,'db_error'=>(string)$wpdb->last_error],0);
            return new WP_Error('incoming_event_failed','The incoming event could not be consumed transactionally.');
        }
    }

    public static function admin_events(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$status=sanitize_key((string)$request->get_param('status'));if(!in_array($status,['pending','processing','retry','delivered','dead'],true))$status='dead';$after=absint($request->get_param('after'));$limit=min(100,max(1,absint($request->get_param('limit'))?:50));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload_hash,status,attempts,available_at,last_error,version,created_at,updated_at,delivered_at,dead_at FROM '.self::outbox_table().' WHERE status=%s AND id>%d ORDER BY id ASC LIMIT %d',$status,$after,$limit));
        if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();
        return rest_ensure_response(['status'=>$status,'events'=>array_map(static fn(object $r):array=>['id'=>(int)$r->id,'event_uuid'=>(string)$r->event_uuid,'event_type'=>(string)$r->event_type,'aggregate_type'=>(string)$r->aggregate_type,'aggregate_id'=>(int)$r->aggregate_id,'payload_hash'=>(string)$r->payload_hash,'status'=>(string)$r->status,'attempts'=>(int)$r->attempts,'available_at'=>(string)$r->available_at,'last_error'=>(string)$r->last_error,'version'=>(int)$r->version,'created_at'=>(string)$r->created_at,'updated_at'=>(string)$r->updated_at,'delivered_at'=>(string)$r->delivered_at,'dead_at'=>(string)$r->dead_at],is_array($rows)?$rows:[])]);
    }

    public static function admin_retry(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$version=absint($request->get_param('version'));if($id<=0||$version<=0)return new WP_Error('invalid_retry','The event and current version are required.',['status'=>400]);if(!SN_Policy::consume_rate_limit('outbox_manual_retry',(string)get_current_user_id(),30,HOUR_IN_SECONDS))return new WP_Error('rate_limited','Too many manual retries.',['status'=>429]);$now=current_time('mysql',true);
        $updated=$wpdb->query($wpdb->prepare("UPDATE ".self::outbox_table()." SET status='pending',available_at=%s,locked_at=NULL,lock_token=NULL,last_error='',dead_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND status IN ('dead','retry')",$now,$now,$id,$version));
        if($updated===false||($wpdb->last_error ?? '')!=='')return self::storage_unavailable();
        return $updated===1?rest_ensure_response(['queued'=>true,'id'=>$id,'version'=>$version+1]):new WP_Error('stale_event_version','The event changed or is not retryable.',['status'=>409]);
    }

    public static function health(): WP_REST_Response|WP_Error {
        global $wpdb;$outbox=self::outbox_table();$inbox=self::inbox_table();
        $outbox_found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($outbox)));if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();$outbox_exists=$outbox_found===$outbox;
        $inbox_found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($inbox)));if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();$inbox_exists=$inbox_found===$inbox;$counts=[];
        if($outbox_exists)foreach(['pending','processing','retry','delivered','dead'] as $status){$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $outbox WHERE status=%s",$status));if(($wpdb->last_error ?? '')!==''||$count===null)return self::storage_unavailable();$counts[$status]=(int)$count;}
        $next_run=(int)wp_next_scheduled('sn_network_outbox_tick');$schedule_error=(string)get_option('sn_outbox_schedule_error','');
        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists&&$next_run>0&&$schedule_error==='','outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>$next_run,'schedule_error'=>$schedule_error,'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);
    }

    public static function cleanup(): void {
        global $wpdb;$delivered=gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS);$dead=gmdate('Y-m-d H:i:s',time()-180*DAY_IN_SECONDS);$inbox=gmdate('Y-m-d H:i:s',time()-90*DAY_IN_SECONDS);
        $outbox_cleanup=$wpdb->query($wpdb->prepare("DELETE FROM ".self::outbox_table()." WHERE (status='delivered' AND delivered_at<%s) OR (status='dead' AND dead_at<%s) LIMIT 1000",$delivered,$dead));
        $inbox_cleanup=$wpdb->query($wpdb->prepare("DELETE FROM ".self::inbox_table()." WHERE status='processed' AND processed_at<%s LIMIT 1000",$inbox));
        if($outbox_cleanup===false||$inbox_cleanup===false)do_action('sn_network_outbox_cleanup_failed',(string)($wpdb->last_error ?? ''));
    }

    private static function sanitize_payload(array $payload, int $depth = 0): array {
        if($depth>5)return[];$blocked=['body','message_body','content','token','secret','password','credential','ice','sdp','candidate','storage_key','attachment_path'];$clean=[];$count=0;
        foreach($payload as $key=>$value){if(++$count>200)break;$safe_key=is_int($key)?$key:sanitize_key((string)$key);if($safe_key===''||(!is_int($safe_key)&&in_array($safe_key,$blocked,true)))continue;if(is_array($value))$clean[$safe_key]=self::sanitize_payload($value,$depth+1);elseif(is_bool($value)||is_int($value)||is_float($value)||$value===null)$clean[$safe_key]=$value;elseif(is_scalar($value))$clean[$safe_key]=mb_substr(sanitize_text_field((string)$value),0,1000);}
        return$clean;
    }

    private static function storage_unavailable(): WP_Error{return new WP_Error('event_storage_unavailable','Event delivery storage truth could not be verified safely.',['status'=>503]);}
    private static function max_attempts():int{return min(20,max(3,(int)apply_filters('sn_network_outbox_max_attempts',self::DEFAULT_MAX_ATTEMPTS)));}
    private static function outbox_table():string{return SN_DB::table('event_outbox');}
    private static function inbox_table():string{return SN_DB::table('event_inbox');}
}
