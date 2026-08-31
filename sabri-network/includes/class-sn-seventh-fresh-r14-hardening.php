<?php
/** Seventh fresh review R14: complete report identity, scoped holds and dual-control appeals. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Seventh_Fresh_R14_Hardening {
    private const SCHEMA_VERSION = '1.0.0';
    private const LOCK_TIMEOUT = 5;
    private const BATCH = 100;
    private const LISTING_REF_MAX = 191;
    private static array $retention_before_native = [];

    public static function register(): void {
        add_action('init', [self::class, 'maybe_upgrade_schema'], 20);
        add_action('rest_api_init', [self::class, 'override_routes'], 2500);
        add_filter('sn_network_retention_prevents_erasure', [self::class, 'capture_retention_before_native'], 19, 2);
        add_filter('sn_network_retention_prevents_erasure', [self::class, 'scope_native_report_hold'], 21, 2);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_core_eraser'], 10000);
    }

    public static function maybe_upgrade_schema(): void {
        if ((string)get_option('sn_r14_safety_schema_version', '') === self::SCHEMA_VERSION) return;
        global $wpdb;
        $table = SN_DB::table('reports');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;
        $columns = [
            'target_type' => "ALTER TABLE $table ADD COLUMN target_type VARCHAR(24) NOT NULL DEFAULT 'user' AFTER client_uuid",
            'target_ref' => "ALTER TABLE $table ADD COLUMN target_ref VARCHAR(191) NOT NULL DEFAULT '' AFTER target_type",
            'request_fingerprint' => "ALTER TABLE $table ADD COLUMN request_fingerprint CHAR(64) NOT NULL DEFAULT '' AFTER target_key",
            'appeal_count' => "ALTER TABLE $table ADD COLUMN appeal_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER appeal_status",
        ];
        foreach ($columns as $column=>$sql) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $column));
            if (!$exists && $wpdb->query($sql) === false) {
                SN_DB::audit('r14_safety_schema_failed','reports',0,'failure',['column'=>$column,'reason'=>(string)$wpdb->last_error],0);
                return;
            }
        }
        $index = $wpdb->get_var($wpdb->prepare(
            'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s LIMIT 1',
            $table,
            'target_ref_created'
        ));
        if (!$index && $wpdb->query("ALTER TABLE $table ADD KEY target_ref_created (target_type,target_ref,created_at)") === false) {
            SN_DB::audit('r14_safety_schema_failed','reports',0,'failure',['index'=>'target_ref_created','reason'=>(string)$wpdb->last_error],0);
            return;
        }
        if (!self::backfill_reports()) return;
        update_option('sn_r14_safety_schema_version', self::SCHEMA_VERSION, false);
    }

    private static function backfill_reports(): bool {
        global $wpdb;
        $table = SN_DB::table('reports');
        for ($batch=0; $batch<40; $batch++) {
            $rows = $wpdb->get_results(
                "SELECT id,reported_user_id,conversation_id,message_id,target_type,target_ref,target_key,request_fingerprint,category,details,evidence,evidence_hash FROM $table WHERE target_ref='' OR request_fingerprint='' ORDER BY id ASC LIMIT 250"
            );
            if (!is_array($rows)) return false;
            if (!$rows) return true;
            foreach ($rows as $row) {
                if ((int)$row->message_id > 0) { $type='message'; $ref=(string)(int)$row->message_id; }
                elseif ((int)$row->conversation_id > 0) { $type='conversation'; $ref=(string)(int)$row->conversation_id; }
                else { $type='user'; $ref=(string)max(0,(int)$row->reported_user_id); }
                $key = self::target_key($type,$ref);
                $evidence = json_decode((string)$row->evidence, true);
                $evidence = is_array($evidence) ? $evidence : [];
                $evidence_hash = (string)$row->evidence_hash !== '' ? (string)$row->evidence_hash : SN_Safety::evidence_hash($evidence);
                $fingerprint = self::request_fingerprint($key,(string)$row->category,(string)$row->details,$evidence_hash);
                $changed = $wpdb->update($table,[
                    'target_type'=>$type,'target_ref'=>$ref,'target_key'=>$key,'request_fingerprint'=>$fingerprint,'evidence_hash'=>$evidence_hash,
                ],['id'=>(int)$row->id]);
                if ($changed === false) return false;
            }
            if (count($rows) < 250) return true;
        }
        return false;
    }

    public static function override_routes(): void {
        $access = [SN_REST::class, 'access'];
        $admin = [SN_REST::class, 'admin_access'];
        register_rest_route('sabri-network/v2','/report',['methods'=>'POST','callback'=>[self::class,'report'],'permission_callback'=>$access],true);
        register_rest_route('sabri-network/v2','/reports/appealable',['methods'=>'GET','callback'=>[self::class,'appealable_reports'],'permission_callback'=>$access],true);
        register_rest_route('sabri-network/v2','/reports/(?P<id>\d+)/appeal',['methods'=>'POST','callback'=>[self::class,'appeal_report'],'permission_callback'=>$access],true);
        register_rest_route('sabri-network/v2','/admin/reports',['methods'=>'GET','callback'=>[self::class,'admin_reports'],'permission_callback'=>$admin],true);
        register_rest_route('sabri-network/v2','/admin/reports/(?P<id>\d+)/appeal',['methods'=>'POST','callback'=>[self::class,'admin_decide_report_appeal'],'permission_callback'=>$admin],true);
    }

    public static function report(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $reporter = get_current_user_id();
        if ($reporter <= 0) return self::not_found();
        return self::with_locks([self::privacy_lock($reporter),self::report_user_lock($reporter)], static fn()=>self::create_report_locked($request,$reporter));
    }

    private static function create_report_locked(WP_REST_Request $request,int $reporter): WP_REST_Response|WP_Error {
        global $wpdb;
        $client = strtolower(trim((string)$request->get_param('client_id')));
        if (!SN_Safety::valid_uuid($client)) return new WP_Error('invalid_report_client_id','A valid report idempotency identifier is required.',['status'=>400]);
        $target = self::resolve_target($request,$reporter); if (is_wp_error($target)) return $target;
        $category = sanitize_key((string)$request->get_param('category'));
        if (!in_array($category,self::allowed_categories(),true)) return new WP_Error('invalid_report_category','Choose a valid report category.',['status'=>400]);
        $details = mb_substr(sanitize_textarea_field((string)$request->get_param('details')),0,4000);
        $evidence = self::sanitize_evidence($request->get_param('evidence'));
        $evidence_hash = SN_Safety::evidence_hash($evidence);
        $fingerprint = self::request_fingerprint((string)$target['key'],$category,$details,$evidence_hash);
        $table = SN_DB::table('reports');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id,target_key,request_fingerprint,status,retention_until,version FROM $table WHERE reporter_id=%d AND client_uuid=%s LIMIT 1",$reporter,$client));
        if ($existing) return self::reconcile_duplicate($existing,(string)$target['key'],$fingerprint);
        if (!SN_Policy::consume_rate_limit('report_global',(string)$reporter,20,DAY_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests. Please wait and try again.',['status'=>429]);
        if (!SN_Policy::consume_rate_limit('report_target',$reporter.':'.(string)$target['key'],5,DAY_IN_SECONDS)) return new WP_Error('report_target_rate_limited','Too many reports were submitted for this same target.',['status'=>429]);
        $now=current_time('mysql',true);$retention=SN_Safety::retention_until($category,$now);
        $ok=$wpdb->insert($table,[
            'reporter_id'=>$reporter,'reported_user_id'=>(int)$target['reported_user_id'],'conversation_id'=>(int)$target['conversation_id'],'message_id'=>(int)$target['message_id'],
            'client_uuid'=>$client,'target_type'=>(string)$target['type'],'target_ref'=>(string)$target['ref'],'target_key'=>(string)$target['key'],'request_fingerprint'=>$fingerprint,
            'category'=>$category,'details'=>$details,'evidence'=>(string)wp_json_encode($evidence),'evidence_hash'=>$evidence_hash,'status'=>'open','legal_hold'=>0,'appeal_count'=>0,
            'retention_until'=>$retention,'version'=>1,'created_at'=>$now,'updated_at'=>$now,
        ]);
        if ($ok===false) {
            $race=$wpdb->get_row($wpdb->prepare("SELECT id,target_key,request_fingerprint,status,retention_until,version FROM $table WHERE reporter_id=%d AND client_uuid=%s LIMIT 1",$reporter,$client));
            return $race ? self::reconcile_duplicate($race,(string)$target['key'],$fingerprint) : self::database_error();
        }
        $id=(int)$wpdb->insert_id;
        SN_DB::audit('report_created','report',$id,'success',['category'=>$category,'target_type'=>(string)$target['type'],'target_ref'=>(string)$target['ref'],'retention_until'=>$retention],$reporter);
        do_action('sn_network_report_created',$id,$reporter,(int)$target['reported_user_id'],(int)$target['conversation_id'],(int)$target['message_id'],$category,(string)$target['type'],(string)$target['ref']);
        return new WP_REST_Response(['reported'=>true,'id'=>$id,'status'=>'open','target_type'=>(string)$target['type'],'target_ref'=>(string)$target['ref'],'retention_until'=>$retention,'version'=>1,'duplicate'=>false],201);
    }

    public static function appeal_report(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$user=get_current_user_id();
        if (!SN_Policy::consume_rate_limit('report_appeal',(string)$user,10,DAY_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests. Please wait and try again.',['status'=>429]);
        $id=absint($request['id']);$expected=absint($request->get_param('version'));$reason=mb_substr(sanitize_textarea_field((string)$request->get_param('reason')),0,2000);
        if($id<=0||$expected<=0)return new WP_Error('invalid_report_version','A valid report version is required.',['status'=>400]);
        if(mb_strlen(trim($reason))<20)return new WP_Error('appeal_reason_required','Explain the appeal in at least 20 characters.',['status'=>400]);
        $table=SN_DB::table('reports');
        if($wpdb->query('START TRANSACTION')===false)return self::database_error();
        try{
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id));
            if(!$row||(int)$row->reported_user_id!==$user||$row->anonymized_at){$wpdb->query('ROLLBACK');return self::not_found();}
            if(!in_array((string)$row->status,['actioned','closed'],true)){$wpdb->query('ROLLBACK');return new WP_Error('report_not_appealable','This report does not currently have an appealable decision.',['status'=>409]);}
            if((string)$row->appeal_status!=='none'&&!(bool)apply_filters('sn_network_report_reappeal_allowed',false,$user,$row)){$wpdb->query('ROLLBACK');return new WP_Error('report_already_appealed','An appeal has already been recorded for this report.',['status'=>409]);}
            $now=current_time('mysql',true);$changed=$wpdb->query($wpdb->prepare("UPDATE $table SET appeal_status='pending',appeal_count=appeal_count+1,appeal_reason=%s,appealed_at=%s,appeal_decided_by=0,appeal_decision_reason='',appeal_decided_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND reported_user_id=%d",$reason,$now,$now,$id,$expected,$user));
            if($changed!==1)throw new RuntimeException('report_appeal_conflict');
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('report_appeal_commit_failed');
            $count=(int)$row->appeal_count+1;SN_DB::audit('report_appealed','report',$id,'success',['appeal_count'=>$count,'previous_version'=>$expected],$user);do_action('sn_network_report_appealed',$id,$user,$count);
            return rest_ensure_response(['id'=>$id,'appeal_status'=>'pending','appeal_count'=>$count,'appealed_at'=>$now,'version'=>$expected+1]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return $e->getMessage()==='report_appeal_conflict'?new WP_Error('report_appeal_conflict','The report changed before this appeal was saved.',['status'=>409]):self::database_error();}
    }

    public static function admin_decide_report_appeal(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$admin=get_current_user_id();
        if(!SN_Policy::consume_rate_limit('admin_report_appeal',(string)$admin,60,HOUR_IN_SECONDS))return new WP_Error('rate_limited','Too many requests. Please wait and try again.',['status'=>429]);
        $id=absint($request['id']);$expected=absint($request->get_param('version'));$decision=sanitize_key((string)$request->get_param('decision'));$reason=mb_substr(sanitize_textarea_field((string)$request->get_param('reason')),0,2000);
        if($id<=0||$expected<=0||!in_array($decision,['uphold','overturn'],true))return new WP_Error('invalid_appeal_decision','A valid report version and appeal decision are required.',['status'=>400]);
        if(mb_strlen(trim($reason))<20)return new WP_Error('appeal_decision_reason_required','Explain the appeal decision in at least 20 characters.',['status'=>400]);
        $table=SN_DB::table('reports');if($wpdb->query('START TRANSACTION')===false)return self::database_error();$claim=null;$action_id=0;
        try{
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id));
            if(!$row||$row->anonymized_at){$wpdb->query('ROLLBACK');return self::not_found();}
            if((string)$row->appeal_status!=='pending'){$wpdb->query('ROLLBACK');return new WP_Error('appeal_not_pending','This report has no pending appeal.',['status'=>409]);}
            if((int)$row->decision_by>0&&(int)$row->decision_by===$admin){$wpdb->query('ROLLBACK');return new WP_Error('appeal_reviewer_conflict','A different authorized reviewer must decide this appeal.',['status'=>403]);}
            if(!(bool)apply_filters('sn_network_report_appeal_reviewer_authorized',true,$admin,$row)){$wpdb->query('ROLLBACK');return new WP_Error('appeal_reviewer_not_authorized','This administrator is not authorized to decide the appeal.',['status'=>403]);}
            $high=self::is_high_risk_category((string)$row->category);
            if($high){
                $action_id=absint($request->get_param('high_risk_action_id'));if($action_id<=0){$wpdb->query('ROLLBACK');return new WP_Error('appeal_dual_control_required','A separately approved high-risk moderation action is required for this appeal decision.',['status'=>403]);}
                $payload=['operation'=>'report_appeal_decision','report_id'=>$id,'appeal_count'=>(int)$row->appeal_count,'decision'=>$decision,'category'=>(string)$row->category];
                $claim=SN_High_Risk::claim($action_id,$admin,'mass_moderation',$payload);if(is_wp_error($claim)){$wpdb->query('ROLLBACK');return $claim;}
            }
            $appeal_status=$decision==='uphold'?'upheld':'overturned';$status=$decision==='overturn'?'reviewing':(string)$row->status;$now=current_time('mysql',true);
            $changed=$wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,appeal_status=%s,appeal_decided_by=%d,appeal_decision_reason=%s,appeal_decided_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND version=%d AND appeal_status='pending'",$status,$appeal_status,$admin,$reason,$now,$now,$id,$expected));if($changed!==1)throw new RuntimeException('report_appeal_conflict');
            if(is_array($claim)){$completed=SN_High_Risk::complete($action_id,$admin,(string)$claim['claim_token'],['operation'=>'report_appeal_decision','report_id'=>$id,'appeal_status'=>$appeal_status,'appeal_count'=>(int)$row->appeal_count]);if(is_wp_error($completed))throw new RuntimeException('high_risk_complete_failed:'.$completed->get_error_code());}
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('report_appeal_commit_failed');
            SN_DB::audit('report_appeal_decided','report',$id,'success',['decision'=>$decision,'resulting_status'=>$status,'appeal_count'=>(int)$row->appeal_count,'high_risk'=>$high,'reason'=>$reason,'previous_version'=>$expected],$admin);
            if((int)$row->reported_user_id>0)SN_DB::add_notification((int)$row->reported_user_id,'report_appeal_decision','Your safety-report appeal was decided',$decision==='overturn'?'The former decision was reopened for review.':'The former decision was upheld.','report',$id);
            do_action('sn_network_report_appeal_decided',$id,$appeal_status,$admin,(int)$row->appeal_count,$high);
            return rest_ensure_response(['id'=>$id,'status'=>$status,'appeal_status'=>$appeal_status,'appeal_count'=>(int)$row->appeal_count,'appeal_decision_reason'=>$reason,'appeal_decided_by'=>$admin,'appeal_decided_at'=>$now,'high_risk_dual_control'=>$high,'version'=>$expected+1]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return str_starts_with($e->getMessage(),'report_appeal_conflict')?new WP_Error('report_appeal_conflict','The report changed before this appeal decision was saved.',['status'=>409]):self::database_error();}
    }

    public static function admin_reports(WP_REST_Request $request): WP_REST_Response|WP_Error {return self::enrich_report_list(SN_REST::admin_reports($request));}
    public static function appealable_reports(WP_REST_Request $request): WP_REST_Response|WP_Error {return self::enrich_report_list(SN_REST::appealable_reports($request));}

    private static function enrich_report_list(WP_REST_Response|WP_Error $response): WP_REST_Response|WP_Error {
        if(is_wp_error($response))return $response;global $wpdb;$data=$response->get_data();if(!is_array($data))return $response;$key=isset($data['reports'])?'reports':null;if($key===null||!is_array($data[$key]))return $response;$table=SN_DB::table('reports');
        foreach($data[$key] as &$item){$id=absint($item['id']??0);if($id<=0)continue;$meta=$wpdb->get_row($wpdb->prepare("SELECT target_type,target_ref,appeal_count FROM $table WHERE id=%d",$id));if($meta){$item['target_type']=(string)$meta->target_type;$item['target_ref']=(string)$meta->target_ref;$item['appeal_count']=(int)$meta->appeal_count;}}unset($item);$response->set_data($data);return $response;
    }

    public static function capture_retention_before_native(bool $retained,int $user_id): bool {self::$retention_before_native[$user_id]=$retained;return $retained;}
    public static function scope_native_report_hold(bool $retained,int $user_id): bool {
        $before=(bool)(self::$retention_before_native[$user_id]??false);unset(self::$retention_before_native[$user_id]);
        if(!$before&&$retained&&self::has_native_report_hold($user_id))return false;
        return $retained;
    }

    private static function has_native_report_hold(int $user_id): bool {
        if($user_id<=0)return false;global $wpdb;$reports=SN_DB::table('reports');$messages=SN_DB::table('messages');
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT r.id FROM $reports r LEFT JOIN $messages m ON m.id=r.message_id WHERE r.legal_hold=1 AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d) LIMIT 1",$user_id,$user_id,$user_id));
    }

    public static function override_core_eraser(array $erasers): array {if(isset($erasers['sabri-network']))$erasers['sabri-network']['callback']=[self::class,'erase_core'];return $erasers;}

    public static function erase_core(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return self::erase_done();$uid=(int)$user->ID;
        if((bool)apply_filters('sn_network_retention_prevents_erasure',false,$uid))return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('File 17 account data is retained by a separate account-wide retention authority.','sabri-network')],'done'=>true];
        $lock=self::privacy_lock($uid);if(!self::acquire($lock))return self::erase_retry(__('Privacy erasure is already running. Retry this page.','sabri-network'));
        try{
            $message=self::erase_message_batch($uid);if($message!==null)return $message;
            $update=self::erase_update_batch($uid);if($update!==null)return $update;
            $safety=self::erase_report_data($uid);if(!empty($safety['failed']))return self::erase_retry(__('Safety/report privacy minimization could not be committed and must be retried.','sabri-network'));
            $rel=self::erase_relational_state($uid);if(empty($rel['done']))return $rel;
            if((int)($safety['redacted']??0)>0||(int)($safety['held_reporter_minimized']??0)>0)$rel['items_removed']=true;
            if((int)($safety['retained']??0)>0){$rel['items_retained']=true;$rel['messages'][]=sprintf(__('%d safety/report record(s), and directly held message/attachment evidence, remain under scoped legal hold.','sabri-network'),(int)$safety['retained']);}
            $rel['messages']=array_values(array_unique(array_filter(array_map('strval',(array)($rel['messages']??[])))));return $rel;
        }finally{self::release($lock);}
    }

    private static function erase_message_batch(int $uid): ?array {
        global $wpdb;$messages=SN_DB::table('messages');$reports=SN_DB::table('reports');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.attachment_id,m.attachment_source FROM $messages m WHERE m.sender_id=%d AND NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=m.id AND r.legal_hold=1) ORDER BY m.id ASC LIMIT %d",$uid,self::BATCH));
        if(!is_array($rows))return self::erase_retry(__('Message erasure could not enumerate its work and must be retried.','sabri-network'));if(!$rows)return null;$now=current_time('mysql',true);$attachments=[];
        if($wpdb->query('START TRANSACTION')===false)return self::erase_retry(__('The message-erasure transaction could not start.','sabri-network'));
        try{foreach($rows as $row){$id=(int)$row->id;$locked=$wpdb->get_row($wpdb->prepare("SELECT id,sender_id,attachment_id,attachment_source FROM $messages WHERE id=%d FOR UPDATE",$id));if(!$locked||(int)$locked->sender_id!==$uid)continue;if((string)$locked->attachment_source==='private'&&(int)$locked->attachment_id>0)$attachments[]=(int)$locked->attachment_id;$updated=$wpdb->query($wpdb->prepare("UPDATE $messages SET sender_id=0,body='',attachment_id=0,attachment_source='erased',metadata=%s,deleted_at=COALESCE(deleted_at,%s) WHERE id=%d AND sender_id=%d",(string)wp_json_encode(['erased'=>true]),$now,$id,$uid));if($updated!==1)throw new RuntimeException('privacy_message_update_failed');$removed=SN_Message_Search::remove_message($id);if(is_wp_error($removed))throw new RuntimeException($removed->get_error_code());}if($wpdb->query('COMMIT')===false)throw new RuntimeException('privacy_message_commit_failed');}
        catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('privacy_message_batch_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);return self::erase_retry(__('A message-erasure batch could not be committed.','sabri-network'));}
        foreach(array_values(array_unique($attachments))as $attachment)SN_Private_Files::delete($attachment,$uid);return['items_removed'=>true,'items_retained'=>true,'messages'=>[__('Eligible message bodies were anonymized; held evidence remains untouched.','sabri-network')],'done'=>false];
    }

    private static function erase_update_batch(int $uid): ?array {
        global $wpdb;$updates=SN_DB::table('updates');$views=SN_DB::table('update_views');$rows=$wpdb->get_results($wpdb->prepare("SELECT id,media_id,media_source FROM $updates WHERE user_id=%d ORDER BY id ASC LIMIT %d",$uid,self::BATCH));if(!is_array($rows))return self::erase_retry(__('Update erasure could not enumerate its work and must be retried.','sabri-network'));if(!$rows)return null;$ids=[];$media=[];foreach($rows as $row){$ids[]=(int)$row->id;if((string)$row->media_source==='private'&&(int)$row->media_id>0)$media[]=(int)$row->media_id;}
        if($wpdb->query('START TRANSACTION')===false)return self::erase_retry(__('The update-erasure transaction could not start.','sabri-network'));
        try{$ph=implode(',',array_fill(0,count($ids),'%d'));if($wpdb->query($wpdb->prepare("DELETE FROM $views WHERE update_id IN ($ph)",...$ids))===false)throw new RuntimeException('privacy_update_views_failed');if($wpdb->query($wpdb->prepare("DELETE FROM $updates WHERE id IN ($ph) AND user_id=%d",...array_merge($ids,[$uid])))===false)throw new RuntimeException('privacy_updates_failed');if($wpdb->query('COMMIT')===false)throw new RuntimeException('privacy_update_commit_failed');}
        catch(Throwable $e){$wpdb->query('ROLLBACK');return self::erase_retry(__('An update-erasure batch could not be committed.','sabri-network'));}
        foreach(array_values(array_unique($media))as $attachment)SN_Private_Files::delete($attachment,$uid);return['items_removed'=>true,'items_retained'=>true,'messages'=>[],'done'=>false];
    }

    private static function erase_report_data(int $uid): array {
        global $wpdb;$table=SN_DB::table('reports');$now=current_time('mysql',true);$empty=SN_Safety::evidence_hash([]);$retained=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE legal_hold=1 AND (reporter_id=%d OR reported_user_id=%d)",$uid,$uid));$lock=self::report_user_lock($uid);if(!self::acquire($lock))return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];
        try{if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('report_privacy_transaction_failed');$held=$wpdb->query($wpdb->prepare("UPDATE $table SET reporter_id=0,client_uuid=NULL,updated_at=%s,version=version+1 WHERE reporter_id=%d AND legal_hold=1",$now,$uid));if($held===false)throw new RuntimeException('held_reporter_minimization_failed');$reporter=$wpdb->query($wpdb->prepare("UPDATE $table SET reporter_id=0,client_uuid=NULL,request_fingerprint='',details='',evidence='[]',evidence_hash=%s,updated_at=%s,version=version+1 WHERE reporter_id=%d AND legal_hold=0",$empty,$now,$uid));if($reporter===false)throw new RuntimeException('reporter_redaction_failed');$rows=$wpdb->get_results($wpdb->prepare("SELECT id,target_type,target_ref,target_key FROM $table WHERE reported_user_id=%d AND legal_hold=0 FOR UPDATE",$uid));if(!is_array($rows))throw new RuntimeException('reported_user_query_failed');$reported=0;foreach($rows as $row){$is_user=(string)$row->target_type==='user';$ref=$is_user?'erased-user:'.(int)$row->id:(string)$row->target_ref;$key=$is_user?self::target_key('user',$ref):(string)$row->target_key;$changed=$wpdb->query($wpdb->prepare("UPDATE $table SET reported_user_id=0,target_ref=%s,target_key=%s,appeal_reason='',appealed_at=NULL,appeal_decision_reason='',appeal_decided_by=0,appeal_decided_at=NULL,decision_reason='',decision_by=0,decision_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND reported_user_id=%d AND legal_hold=0",$ref,$key,$now,(int)$row->id,$uid));if($changed!==1)throw new RuntimeException('reported_user_redaction_failed');$reported++;}if($wpdb->query('COMMIT')===false)throw new RuntimeException('report_privacy_commit_failed');return['redacted'=>(int)$reporter+$reported,'retained'=>$retained,'held_reporter_minimized'=>(int)$held,'failed'=>false];}
        catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('report_privacy_erasure_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];}finally{self::release($lock);}
    }

    private static function erase_relational_state(int $uid): array {
        global $wpdb;$now=current_time('mysql',true);$conversations=SN_DB::table('conversations');$members=SN_DB::table('members');$owned=array_values(array_filter(array_map('absint',$wpdb->get_col($wpdb->prepare("SELECT id FROM $conversations WHERE owner_id=%d AND type<>'direct' AND status='active' ORDER BY id ASC",$uid))?:[])));$attachments=array_values(array_filter(array_map('absint',$wpdb->get_col($wpdb->prepare("SELECT a.id FROM ".SN_DB::table('attachments')." a WHERE a.owner_id=%d AND a.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM ".SN_DB::table('messages')." m INNER JOIN ".SN_DB::table('reports')." r ON r.message_id=m.id AND r.legal_hold=1 WHERE m.attachment_source='private' AND m.attachment_id=a.id)",$uid))?:[])));
        if($wpdb->query('START TRANSACTION')===false)return self::erase_retry(__('The relational privacy-erasure transaction could not start.','sabri-network'));
        try{$wpdb->get_results($wpdb->prepare("SELECT id,conversation_id,role FROM $members WHERE user_id=%d FOR UPDATE",$uid));$conversation_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT conversation_id FROM $members WHERE user_id=%d",$uid))?:[]);if($conversation_ids){$ph=implode(',',array_fill(0,count($conversation_ids),'%d'));if($wpdb->query($wpdb->prepare("UPDATE $conversations SET status='archived',updated_at=%s WHERE type='direct' AND id IN ($ph)",$now,...$conversation_ids))===false)throw new RuntimeException('privacy_direct_conversation_archive_failed');}self::must_delete(SN_DB::table('typing'),['user_id'=>$uid],['%d']);self::must_delete(SN_DB::table('presence'),['user_id'=>$uid],['%d']);if($owned){$ph=implode(',',array_fill(0,count($owned),'%d'));if($wpdb->query($wpdb->prepare("DELETE FROM $members WHERE user_id=%d AND conversation_id NOT IN ($ph)",...array_merge([$uid],$owned)))===false)throw new RuntimeException('privacy_membership_delete_failed');}else self::must_delete($members,['user_id'=>$uid],['%d']);$call_ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT call_id FROM '.SN_DB::table('call_members').' WHERE user_id=%d',$uid))?:[]);if($call_ids){$ph=implode(',',array_fill(0,count($call_ids),'%d'));$direct=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT c.id FROM '.SN_DB::table('calls')." c INNER JOIN $conversations cv ON cv.id=c.conversation_id AND cv.type='direct' WHERE c.id IN ($ph)",...$call_ids))?:[]);if($direct){$dph=implode(',',array_fill(0,count($direct),'%d'));if($wpdb->query($wpdb->prepare('UPDATE '.SN_DB::table('calls')." SET status='ended',active_key=NULL,ended_at=COALESCE(ended_at,%s) WHERE id IN ($dph) AND status IN ('ringing','active')",$now,...$direct))===false)throw new RuntimeException('privacy_direct_call_end_failed');}}self::must_delete(SN_DB::table('call_members'),['user_id'=>$uid],['%d']);if($wpdb->update(SN_DB::table('calls'),['initiator_id'=>0],['initiator_id'=>$uid],['%d'],['%d'])===false)throw new RuntimeException('privacy_call_initiator_anonymize_failed');if($wpdb->query($wpdb->prepare('DELETE FROM '.SN_DB::table('contacts').' WHERE user_id=%d OR contact_user_id=%d',$uid,$uid))===false)throw new RuntimeException('privacy_contacts_delete_failed');if($wpdb->query($wpdb->prepare('DELETE FROM '.SN_DB::table('follows').' WHERE follower_id=%d OR followed_id=%d',$uid,$uid))===false)throw new RuntimeException('privacy_follows_delete_failed');if($wpdb->query($wpdb->prepare('DELETE FROM '.SN_DB::table('blocks').' WHERE user_id=%d OR blocked_user_id=%d',$uid,$uid))===false)throw new RuntimeException('privacy_blocks_delete_failed');self::must_delete(SN_DB::table('reactions'),['user_id'=>$uid],['%d']);self::must_delete(SN_DB::table('update_views'),['viewer_id'=>$uid],['%d']);self::must_delete(SN_DB::table('notifications'),['user_id'=>$uid],['%d']);self::must_delete(SN_DB::table('signals'),['from_user_id'=>$uid],['%d']);self::must_delete(SN_DB::table('signals'),['to_user_id'=>$uid],['%d']);if($wpdb->update(SN_DB::table('audit_log'),['actor_id'=>0,'context'=>'{}'],['actor_id'=>$uid],['%d','%s'],['%d'])===false)throw new RuntimeException('privacy_audit_anonymize_failed');if($wpdb->query('COMMIT')===false)throw new RuntimeException('privacy_relational_commit_failed');}
        catch(Throwable $e){$wpdb->query('ROLLBACK');return self::erase_retry(__('Relational File-17 privacy erasure could not be committed.','sabri-network'));}
        foreach($attachments as $attachment)SN_Private_Files::delete($attachment,$uid);delete_user_meta($uid,'sn_privacy');$messages=[];$retained=false;if($owned){$retained=true;$messages[]=sprintf(__('%d active non-direct conversation(s) remain because ownership must be transferred first.','sabri-network'),count($owned));}return['items_removed'=>true,'items_retained'=>$retained,'messages'=>$messages,'done'=>true];
    }

    private static function resolve_target(WP_REST_Request $request,int $reporter): array|WP_Error {
        global $wpdb;$type=sanitize_key((string)$request->get_param('target_type'));$reported=absint($request->get_param('reported_user_id'));$conversation=absint($request->get_param('conversation_id'));$message=absint($request->get_param('message_id'));$space=absint($request->get_param('space_id'));$call=absint($request->get_param('call_id'));$listing=mb_substr(sanitize_text_field((string)$request->get_param('listing_context')),0,self::LISTING_REF_MAX);
        if($type===''){if($message>0)$type='message';elseif($call>0)$type='call';elseif($space>0)$type='space';elseif($listing!=='')$type='listing_context';elseif($conversation>0)$type='conversation';elseif($reported>0)$type='user';}
        if(!in_array($type,['user','conversation','message','space','call','listing_context'],true))return new WP_Error('invalid_report_target','Select a valid report target.',['status'=>400]);
        $primary=['conversation'=>$conversation,'message'=>$message,'space'=>$space,'call'=>$call,'listing_context'=>$listing!==''?1:0];foreach($primary as $other=>$value){if($other===$type||!$value)continue;if($type==='message'&&$other==='conversation')continue;if($type==='call'&&$other==='conversation')continue;return new WP_Error('ambiguous_report_target','A report must bind to exactly one canonical target.',['status'=>400]);}
        if($type==='message'){$row=$message>0?$wpdb->get_row($wpdb->prepare('SELECT id,conversation_id,sender_id FROM '.SN_DB::table('messages').' WHERE id=%d',$message)):null;if(!$row||!SN_DB::is_member((int)$row->conversation_id,$reporter)||($conversation>0&&$conversation!==(int)$row->conversation_id))return self::not_found();$conversation=(int)$row->conversation_id;if($reported>0&&$reported!==(int)$row->sender_id)return new WP_Error('invalid_reported_user','The reported user does not match the reported message.',['status'=>400]);$reported=(int)$row->sender_id;return self::target('message',(string)$message,$reported,$conversation,$message);}
        if($type==='conversation'){if($conversation<=0||!SN_DB::is_member($conversation,$reporter))return self::not_found();if($reported>0&&(!self::valid_other_user($reported,$reporter)||!SN_DB::is_member($conversation,$reported)))return new WP_Error('invalid_reported_user','The reported user is not a valid member of this conversation.',['status'=>400]);return self::target('conversation',(string)$conversation,$reported,$conversation,0);}
        if($type==='user'){if($reported<=0||!self::valid_other_user($reported,$reporter))return new WP_Error('invalid_reported_user','Select a valid reported user.',['status'=>400]);return self::target('user',(string)$reported,$reported,0,0);}
        if($type==='space'){$row=$space>0?$wpdb->get_row($wpdb->prepare('SELECT s.id,s.visibility,s.state,m.status member_status FROM '.SN_DB::table('spaces')." s LEFT JOIN ".SN_DB::table('space_members')." m ON m.space_id=s.id AND m.user_id=%d AND m.status='active' WHERE s.id=%d",$reporter,$space)):null;if(!$row||(string)$row->state==='deletion_requested'||(!$row->member_status&&!in_array((string)$row->visibility,['public','discoverable_private'],true)))return self::not_found();if($reported>0){if(!self::valid_other_user($reported,$reporter))return new WP_Error('invalid_reported_user','Select a valid reported user.',['status'=>400]);$member=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1",$space,$reported));if(!$member)return new WP_Error('invalid_reported_user','The reported user is not an active member of this space.',['status'=>400]);}return self::target('space',(string)$space,$reported,0,0);}
        if($type==='call'){$row=$call>0?$wpdb->get_row($wpdb->prepare('SELECT id,conversation_id FROM '.SN_DB::table('calls').' WHERE id=%d',$call)):null;if(!$row||!(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('call_members').' WHERE call_id=%d AND user_id=%d LIMIT 1',$call,$reporter)))return self::not_found();$conversation=(int)$row->conversation_id;if($reported>0){if(!self::valid_other_user($reported,$reporter))return new WP_Error('invalid_reported_user','Select a valid reported user.',['status'=>400]);if(!(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('call_members').' WHERE call_id=%d AND user_id=%d LIMIT 1',$call,$reported)))return new WP_Error('invalid_reported_user','The reported user was not a participant in this call.',['status'=>400]);}return self::target('call',(string)$call,$reported,$conversation,0);}
        if($listing===''||!(bool)apply_filters('sn_network_listing_report_context_authorized',false,$reporter,$listing,$request))return self::not_found();if($reported>0&&!self::valid_other_user($reported,$reporter))return new WP_Error('invalid_reported_user','Select a valid reported user.',['status'=>400]);return self::target('listing_context',$listing,$reported,0,0);
    }

    private static function allowed_categories(): array{return['spam','fraud','harassment','threat','hate','impersonation','fake_doctor','medical_misinformation','medical_harm','sexual_content','child_safety','minor_safety','illegal_products','illegal_content','copyright','malware','stolen_account','privacy'];}
    private static function is_high_risk_category(string $category): bool{return in_array(sanitize_key($category),['fraud','threat','medical_harm','child_safety','minor_safety','illegal_content','malware','stolen_account'],true);}
    private static function target(string $type,string $ref,int $reported,int $conversation,int $message): array{return['type'=>$type,'ref'=>$ref,'key'=>self::target_key($type,$ref),'reported_user_id'=>$reported,'conversation_id'=>$conversation,'message_id'=>$message];}
    private static function target_key(string $type,string $ref): string{return hash('sha256',sanitize_key($type).':'.trim(wp_unslash($ref)));}
    private static function request_fingerprint(string $target_key,string $category,string $details,string $evidence_hash): string{return hash('sha256',(string)wp_json_encode(['target_key'=>strtolower(trim($target_key)),'category'=>sanitize_key($category),'details'=>trim($details),'evidence_hash'=>strtolower(trim($evidence_hash))],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
    private static function reconcile_duplicate(object $row,string $key,string $fingerprint): WP_REST_Response|WP_Error {if(!hash_equals((string)$row->target_key,$key)||!(string)$row->request_fingerprint||!hash_equals((string)$row->request_fingerprint,$fingerprint))return new WP_Error('report_idempotency_conflict','This report identifier was already used with different report semantics.',['status'=>409]);return rest_ensure_response(['reported'=>true,'id'=>(int)$row->id,'status'=>(string)$row->status,'retention_until'=>(string)$row->retention_until,'version'=>(int)$row->version,'duplicate'=>true]);}
    private static function sanitize_evidence($value): array{if(!is_array($value))return[];$clean=[];foreach(array_slice($value,0,20,true)as$key=>$item){$key=sanitize_key((string)$key);if($key!==''&&is_scalar($item))$clean[$key]=mb_substr(sanitize_text_field((string)$item),0,500);}ksort($clean,SORT_STRING);return$clean;}
    private static function valid_other_user(int $target,int $actor): bool{return$target>0&&$target!==$actor&&(bool)get_user_by('id',$target);}
    private static function with_locks(array $locks,callable $callback){$locks=array_values(array_unique(array_filter(array_map('strval',$locks))));sort($locks,SORT_STRING);$held=[];try{foreach($locks as$lock){if(!self::acquire($lock))return new WP_Error('sn_report_busy','The safety record is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return$callback();}finally{foreach(array_reverse($held)as$lock)self::release($lock);}}
    private static function acquire(string $lock): bool{global$wpdb;return(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT))===1;}
    private static function release(string $lock): void{global$wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    private static function must_delete(string $table,array $where,array $format): void{global$wpdb;if($wpdb->delete($table,$where,$format)===false)throw new RuntimeException('privacy_delete_failed');}
    private static function privacy_lock(int $uid): string{return'sn:f17:privacy:'.$uid;}
    private static function report_user_lock(int $uid): string{return'sn:f17:report-user:'.$uid;}
    private static function erase_done(): array{return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];}
    private static function erase_retry(string $message): array{return['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];}
    private static function not_found(): WP_Error{return new WP_Error('not_found','The requested Network item is unavailable.',['status'=>404]);}
    private static function database_error(): WP_Error{return new WP_Error('database_error','The Network request could not be completed.',['status'=>500]);}
}
