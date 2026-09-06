from pathlib import Path

# R9-D01 — fail closed if hourly expired-update cleanup cannot start/commit its transaction.
p=Path('sabri-network/includes/class-sn-db.php')
s=p.read_text(encoding='utf-8')
old="""            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query('START TRANSACTION');
            try {
                $views_deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('update_views') . " WHERE update_id IN ($placeholders)", ...$ids));
                $updates_deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('updates') . " WHERE id IN ($placeholders)", ...$ids));
                if ($views_deleted === false || $updates_deleted === false) {
                    throw new RuntimeException('expired_update_delete_failed');
                }
                $wpdb->query('COMMIT');
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                self::audit('expired_update_cleanup_failed', 'update', 0, 'failure', ['batch' => $batch]);
                break;
            }
"""
new="""            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            if ($wpdb->query('START TRANSACTION') === false) {
                self::audit('expired_update_cleanup_failed', 'update', 0, 'failure', ['batch' => $batch, 'reason' => 'transaction_start_failed']);
                break;
            }
            try {
                $views_deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('update_views') . " WHERE update_id IN ($placeholders)", ...$ids));
                $updates_deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('updates') . " WHERE id IN ($placeholders)", ...$ids));
                if ($views_deleted === false || $updates_deleted === false) {
                    throw new RuntimeException('expired_update_delete_failed');
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('expired_update_cleanup_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                self::audit('expired_update_cleanup_failed', 'update', 0, 'failure', ['batch' => $batch, 'reason' => $e->getMessage()]);
                break;
            }
"""
if old not in s: raise SystemExit('R9 DB cleanup target mismatch')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# R9-D02 — authoritative legal-hold retained count must not fail open.
p=Path('sabri-network/includes/class-sn-safety-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        global $wpdb;$table=SN_DB::table('reports');$now=current_time('mysql',true);$empty=SN_Safety::evidence_hash([]);
        $retained=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE legal_hold=1 AND (reporter_id=%d OR reported_user_id=%d)",$user_id,$user_id));
        $lock='sn:f17:report-user:'.$user_id;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];
"""
new="""        global $wpdb;$table=SN_DB::table('reports');$now=current_time('mysql',true);$empty=SN_Safety::evidence_hash([]);
        $wpdb->last_error='';
        $retained_raw=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE legal_hold=1 AND (reporter_id=%d OR reported_user_id=%d)",$user_id,$user_id));
        if($wpdb->last_error!==''||$retained_raw===null)return['redacted'=>0,'retained'=>0,'held_reporter_minimized'=>0,'failed'=>true,'read_failed'=>true];
        $retained=(int)$retained_raw;
        $lock='sn:f17:report-user:'.$user_id;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];
"""
if old not in s: raise SystemExit('R9 safety retained-count target mismatch')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# R9-D03 — high-risk admin queue cannot publish false-empty truth.
p=Path('sabri-network/includes/class-sn-high-risk.php')
s=p.read_text(encoding='utf-8')
old="""    public static function list_actions(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $status = sanitize_key((string) $request->get_param('status'));
        $limit = max(1, min(100, absint($request->get_param('limit')) ?: 50));
        $where = $status !== '' ? $wpdb->prepare(' WHERE status=%s', $status) : '';
        $rows = $wpdb->get_results("SELECT id,action_uuid,action_type,requester_id,approver_id,executor_id,payload_hash,status,reason,expires_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM " . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
        return rest_ensure_response(['items' => is_array($rows) ? $rows : []]);
    }
"""
new="""    public static function list_actions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $status = sanitize_key((string) $request->get_param('status'));
        $limit = max(1, min(100, absint($request->get_param('limit')) ?: 50));
        $where = $status !== '' ? $wpdb->prepare(' WHERE status=%s', $status) : '';
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SELECT id,action_uuid,action_type,requester_id,approver_id,executor_id,payload_hash,status,reason,expires_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM " . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
        if ($wpdb->last_error !== '' || !is_array($rows)) return self::error('sn_high_risk_queue_unavailable', 'The high-risk action queue could not be read safely.', 503);
        return rest_ensure_response(['items' => $rows]);
    }
"""
if old not in s: raise SystemExit('R9 high-risk queue target mismatch')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# R9-D04 — outbox admin/health truth must be DB-error aware.
p=Path('sabri-network/includes/class-sn-outbox.php')
s=p.read_text(encoding='utf-8')
old="""    public static function admin_events(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;$status=sanitize_key((string)$request->get_param('status'));if(!in_array($status,['pending','processing','retry','delivered','dead'],true))$status='dead';$after=absint($request->get_param('after'));$limit=min(100,max(1,absint($request->get_param('limit'))?:50));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload_hash,status,attempts,available_at,last_error,version,created_at,updated_at,delivered_at,dead_at FROM '.self::outbox_table().' WHERE status=%s AND id>%d ORDER BY id ASC LIMIT %d',$status,$after,$limit));
        return rest_ensure_response(['status'=>$status,'events'=>array_map(static fn(object $r):array=>['id'=>(int)$r->id,'event_uuid'=>(string)$r->event_uuid,'event_type'=>(string)$r->event_type,'aggregate_type'=>(string)$r->aggregate_type,'aggregate_id'=>(int)$r->aggregate_id,'payload_hash'=>(string)$r->payload_hash,'status'=>(string)$r->status,'attempts'=>(int)$r->attempts,'available_at'=>(string)$r->available_at,'last_error'=>(string)$r->last_error,'version'=>(int)$r->version,'created_at'=>(string)$r->created_at,'updated_at'=>(string)$r->updated_at,'delivered_at'=>(string)$r->delivered_at,'dead_at'=>(string)$r->dead_at],is_array($rows)?$rows:[])]);
    }
"""
new="""    public static function admin_events(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$status=sanitize_key((string)$request->get_param('status'));if(!in_array($status,['pending','processing','retry','delivered','dead'],true))$status='dead';$after=absint($request->get_param('after'));$limit=min(100,max(1,absint($request->get_param('limit'))?:50));
        $wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_uuid,event_type,aggregate_type,aggregate_id,payload_hash,status,attempts,available_at,last_error,version,created_at,updated_at,delivered_at,dead_at FROM '.self::outbox_table().' WHERE status=%s AND id>%d ORDER BY id ASC LIMIT %d',$status,$after,$limit));
        if($wpdb->last_error!==''||!is_array($rows))return new WP_Error('outbox_queue_unavailable','The event delivery queue could not be read safely.',['status'=>503]);
        return rest_ensure_response(['status'=>$status,'events'=>array_map(static fn(object $r):array=>['id'=>(int)$r->id,'event_uuid'=>(string)$r->event_uuid,'event_type'=>(string)$r->event_type,'aggregate_type'=>(string)$r->aggregate_type,'aggregate_id'=>(int)$r->aggregate_id,'payload_hash'=>(string)$r->payload_hash,'status'=>(string)$r->status,'attempts'=>(int)$r->attempts,'available_at'=>(string)$r->available_at,'last_error'=>(string)$r->last_error,'version'=>(int)$r->version,'created_at'=>(string)$r->created_at,'updated_at'=>(string)$r->updated_at,'delivered_at'=>(string)$r->delivered_at,'dead_at'=>(string)$r->dead_at],$rows)]);
    }
"""
if old not in s: raise SystemExit('R9 outbox admin target mismatch')
s=s.replace(old,new,1)
old="""    public static function health(): WP_REST_Response {
        global $wpdb;$outbox=self::outbox_table();$inbox=self::inbox_table();$outbox_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($outbox)))===$outbox;$inbox_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($inbox)))===$inbox;$counts=[];
        if($outbox_exists)foreach(['pending','processing','retry','delivered','dead'] as $status)$counts[$status]=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $outbox WHERE status=%s",$status));
        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists,'outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>(int)wp_next_scheduled('sn_network_outbox_tick'),'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);
    }
"""
new="""    public static function health(): WP_REST_Response {
        global $wpdb;$outbox=self::outbox_table();$inbox=self::inbox_table();$outbox_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($outbox)))===$outbox;$inbox_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($inbox)))===$inbox;$counts=[];$read_error=false;
        if($outbox_exists)foreach(['pending','processing','retry','delivered','dead'] as $status){$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $outbox WHERE status=%s",$status));if($wpdb->last_error!==''||$raw===null){$read_error=true;break;}$counts[$status]=(int)$raw;}
        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists&&!$read_error,'outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'database_read_error'=>$read_error,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>(int)wp_next_scheduled('sn_network_outbox_tick'),'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);
    }
"""
if old not in s: raise SystemExit('R9 outbox health target mismatch')
p.write_text(s.replace(old,new,1),encoding='utf-8')
