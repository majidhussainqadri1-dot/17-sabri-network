from pathlib import Path
root=Path('sabri-network'); p=root/'includes/class-sn-outbox.php'; t=p.read_text(encoding='utf-8')

# R32 frozen Defect Ledger: outbox/inbox/admin/health DB uncertainty could be collapsed
# into absence, contention, stale version, or successful empty/zero state; cleanup failure was silent.
old="$existing = $wpdb->get_row($wpdb->prepare(\"SELECT id,event_type,payload_hash FROM $table WHERE event_key=%s LIMIT 1\", $event_key));\n        if ($existing)"
new="$existing = $wpdb->get_row($wpdb->prepare(\"SELECT id,event_type,payload_hash FROM $table WHERE event_key=%s LIMIT 1\", $event_key));\n        if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();\n        if ($existing)"
assert old in t; t=t.replace(old,new,1)
old="""        if ($ok === false) {
            $race = $wpdb->get_row($wpdb->prepare("SELECT id,event_type,payload_hash FROM $table WHERE event_key=%s LIMIT 1", $event_key));
            return $race && (string)$race->event_type === $type && hash_equals((string)$race->payload_hash, $payload_hash) ? (int)$race->id : new WP_Error('event_enqueue_failed', 'The event could not be queued.');
        }"""
new="""        if ($ok === false) {
            $race = $wpdb->get_row($wpdb->prepare("SELECT id,event_type,payload_hash FROM $table WHERE event_key=%s LIMIT 1", $event_key));
            if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();
            return $race && (string)$race->event_type === $type && hash_equals((string)$race->payload_hash, $payload_hash) ? (int)$race->id : new WP_Error('event_enqueue_failed', 'The event could not be queued.');
        }"""
assert old in t; t=t.replace(old,new,1)
old="$claimed=$wpdb->query($wpdb->prepare(\"UPDATE $table SET status='processing',lock_token=%s,locked_at=%s,attempts=attempts+1,updated_at=%s,version=version+1 WHERE id=%d AND ((status IN ('pending','retry') AND available_at<=%s) OR (status='processing' AND locked_at<%s))\",$token,$now,$now,$id,$now,$stale));\n        if($claimed!==1)return new WP_Error('event_not_claimed','The event is not available for delivery.');\n        $row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE id=%d AND lock_token=%s\",$id,$token));if(!$row)return new WP_Error('event_claim_lost','The event claim could not be confirmed.');"
new="$claimed=$wpdb->query($wpdb->prepare(\"UPDATE $table SET status='processing',lock_token=%s,locked_at=%s,attempts=attempts+1,updated_at=%s,version=version+1 WHERE id=%d AND ((status IN ('pending','retry') AND available_at<=%s) OR (status='processing' AND locked_at<%s))\",$token,$now,$now,$id,$now,$stale));\n        if($claimed===false||($wpdb->last_error ?? '')!=='')return self::storage_unavailable();\n        if($claimed!==1)return new WP_Error('event_not_claimed','The event is not available for delivery.');\n        $row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE id=%d AND lock_token=%s\",$id,$token));if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();if(!$row)return new WP_Error('event_claim_lost','The event claim could not be confirmed.');"
assert old in t; t=t.replace(old,new,1)
old="$hash=hash('sha256',$json);$table=self::inbox_table();$existing=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE producer=%s AND event_uuid=%s LIMIT 1\",$producer,$event_uuid));\n        if($existing&&!hash_equals((string)$existing->payload_hash,$hash))"
new="$hash=hash('sha256',$json);$table=self::inbox_table();$existing=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE producer=%s AND event_uuid=%s LIMIT 1\",$producer,$event_uuid));\n        if(($wpdb->last_error ?? '')!=='')return new WP_Error('incoming_event_storage_unavailable','Incoming event storage truth could not be verified.',['status'=>503]);\n        if($existing&&!hash_equals((string)$existing->payload_hash,$hash))"
assert old in t; t=t.replace(old,new,1)
old="public static function admin_events(WP_REST_Request $request): WP_REST_Response {"
assert old in t; t=t.replace(old,"public static function admin_events(WP_REST_Request $request): WP_REST_Response|WP_Error {",1)
old="$rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload_hash,status,attempts,available_at,last_error,version,created_at,updated_at,delivered_at,dead_at FROM '.self::outbox_table().' WHERE status=%s AND id>%d ORDER BY id ASC LIMIT %d',$status,$after,$limit));\n        return rest_ensure_response"
new="$rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload_hash,status,attempts,available_at,last_error,version,created_at,updated_at,delivered_at,dead_at FROM '.self::outbox_table().' WHERE status=%s AND id>%d ORDER BY id ASC LIMIT %d',$status,$after,$limit));\n        if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();\n        return rest_ensure_response"
assert old in t; t=t.replace(old,new,1)
old="$updated=$wpdb->query($wpdb->prepare(\"UPDATE \".self::outbox_table().\" SET status='pending',available_at=%s,locked_at=NULL,lock_token=NULL,last_error='',dead_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND status IN ('dead','retry')\",$now,$now,$id,$version));\n        return $updated===1?rest_ensure_response(['queued'=>true,'id'=>$id,'version'=>$version+1]):new WP_Error('stale_event_version','The event changed or is not retryable.',['status'=>409]);"
new="$updated=$wpdb->query($wpdb->prepare(\"UPDATE \".self::outbox_table().\" SET status='pending',available_at=%s,locked_at=NULL,lock_token=NULL,last_error='',dead_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND status IN ('dead','retry')\",$now,$now,$id,$version));\n        if($updated===false||($wpdb->last_error ?? '')!=='')return self::storage_unavailable();\n        return $updated===1?rest_ensure_response(['queued'=>true,'id'=>$id,'version'=>$version+1]):new WP_Error('stale_event_version','The event changed or is not retryable.',['status'=>409]);"
assert old in t; t=t.replace(old,new,1)
old="""    public static function health(): WP_REST_Response {
        global $wpdb;$outbox=self::outbox_table();$inbox=self::inbox_table();$outbox_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($outbox)))===$outbox;$inbox_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($inbox)))===$inbox;$counts=[];
        if($outbox_exists)foreach(['pending','processing','retry','delivered','dead'] as $status)$counts[$status]=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $outbox WHERE status=%s",$status));
        $next_run=(int)wp_next_scheduled('sn_network_outbox_tick');$schedule_error=(string)get_option('sn_outbox_schedule_error','');
        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists&&$next_run>0&&$schedule_error==='','outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>$next_run,'schedule_error'=>$schedule_error,'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);
    }"""
new="""    public static function health(): WP_REST_Response|WP_Error {
        global $wpdb;$outbox=self::outbox_table();$inbox=self::inbox_table();
        $outbox_found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($outbox)));if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();$outbox_exists=$outbox_found===$outbox;
        $inbox_found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($inbox)));if(($wpdb->last_error ?? '')!=='')return self::storage_unavailable();$inbox_exists=$inbox_found===$inbox;$counts=[];
        if($outbox_exists)foreach(['pending','processing','retry','delivered','dead'] as $status){$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $outbox WHERE status=%s",$status));if(($wpdb->last_error ?? '')!==''||$count===null)return self::storage_unavailable();$counts[$status]=(int)$count;}
        $next_run=(int)wp_next_scheduled('sn_network_outbox_tick');$schedule_error=(string)get_option('sn_outbox_schedule_error','');
        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists&&$next_run>0&&$schedule_error==='','outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>$next_run,'schedule_error'=>$schedule_error,'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);
    }"""
assert old in t; t=t.replace(old,new,1)
old="""        $wpdb->query($wpdb->prepare("DELETE FROM ".self::outbox_table()." WHERE (status='delivered' AND delivered_at<%s) OR (status='dead' AND dead_at<%s) LIMIT 1000",$delivered,$dead));
        $wpdb->query($wpdb->prepare("DELETE FROM ".self::inbox_table()." WHERE status='processed' AND processed_at<%s LIMIT 1000",$inbox));"""
new="""        $outbox_cleanup=$wpdb->query($wpdb->prepare("DELETE FROM ".self::outbox_table()." WHERE (status='delivered' AND delivered_at<%s) OR (status='dead' AND dead_at<%s) LIMIT 1000",$delivered,$dead));
        $inbox_cleanup=$wpdb->query($wpdb->prepare("DELETE FROM ".self::inbox_table()." WHERE status='processed' AND processed_at<%s LIMIT 1000",$inbox));
        if($outbox_cleanup===false||$inbox_cleanup===false)do_action('sn_network_outbox_cleanup_failed',(string)($wpdb->last_error ?? ''));"""
assert old in t; t=t.replace(old,new,1)
anchor="    private static function max_attempts():int"
assert anchor in t
t=t.replace(anchor,"    private static function storage_unavailable(): WP_Error{return new WP_Error('event_storage_unavailable','Event delivery storage truth could not be verified safely.',['status'=>503]);}\n    private static function max_attempts():int",1)
p.write_text(t,encoding='utf-8')

q=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; s=q.read_text(encoding='utf-8'); marker='\nif ($fail) {\n'; assert marker in s and '// Round 32 —' not in s
block=r'''

// Round 32 — durable event delivery preserves database truth across enqueue, dispatch, inbox and administration.
$outbox=$read('includes/class-sn-outbox.php');
$check(str_contains($outbox,'event_storage_unavailable') && substr_count($outbox,'self::storage_unavailable()')>=7, 'Round 32: outbox DB uncertainty must not become absence, contention, stale version, or empty administration state.');
$check(str_contains($outbox,'incoming_event_storage_unavailable') && str_contains($outbox,"if(($wpdb->last_error ?? '')!=='')return new WP_Error('incoming_event_storage_unavailable'"), 'Round 32: incoming idempotency source DB uncertainty must fail closed before transactional handling.');
$check(str_contains($outbox,'public static function admin_events(WP_REST_Request $request): WP_REST_Response|WP_Error') && str_contains($outbox,'public static function health(): WP_REST_Response|WP_Error'), 'Round 32: admin list and health endpoints must surface storage unavailability rather than valid empty/zero state.');
$check(str_contains($outbox,'sn_network_outbox_cleanup_failed') && str_contains($outbox,'$outbox_cleanup===false||$inbox_cleanup===false'), 'Round 32: outbox/inbox retention cleanup failure must be observable.');
'''
q.write_text(s.replace(marker,block+marker,1),encoding='utf-8')
