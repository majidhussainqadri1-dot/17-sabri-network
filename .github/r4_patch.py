from pathlib import Path
import re

p=Path('sabri-network/includes/class-sn-two-plan-completion.php')
s=p.read_text(encoding='utf-8')

old="$started=!$already_in_transaction;if($started)$wpdb->query('START TRANSACTION');"
new="$started=!$already_in_transaction;if($started&&$wpdb->query('START TRANSACTION')===false)return self::error('sn_two_plan_transaction_failed','The communication transaction could not be started safely.',503);"
if new not in s:
    if s.count(old)!=1: raise SystemExit('R4 canonical transaction-start target mismatch')
    s=s.replace(old,new,1)

old="$id=absint($request['id']);$index=absint($request['item']);$actor=get_current_user_id();$wpdb->query('START TRANSACTION');"
new="$id=absint($request['id']);$index=absint($request['item']);$actor=get_current_user_id();if($wpdb->query('START TRANSACTION')===false)return self::error('sn_checklist_transaction_failed','The checklist transaction could not be started safely.',503);"
if new not in s:
    if s.count(old)!=1: raise SystemExit('R4 checklist transaction-start target mismatch')
    s=s.replace(old,new,1)

old="private static function message_has_legal_hold(int $message_id): bool {global $wpdb;return(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('reports').' WHERE message_id=%d AND legal_hold=1 LIMIT 1',$message_id));}"
new="private static function message_has_legal_hold(int $message_id): bool|WP_Error {global $wpdb;$wpdb->last_error='';$held=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('reports').' WHERE message_id=%d AND legal_hold=1 LIMIT 1',$message_id));if($wpdb->last_error!=='')return self::error('sn_legal_hold_read_failed','Legal-hold state could not be verified safely.',503);return $held!==null;}"
if new not in s:
    if s.count(old)!=1: raise SystemExit('R4 legal-hold helper target mismatch')
    s=s.replace(old,new,1)

dispatch='''    public static function dispatch_due_scheduled(): void {
        global $wpdb;
        $now=current_time('mysql',true);$table=self::scheduled_table();$stale=gmdate('Y-m-d H:i:s',time()-15*MINUTE_IN_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE deliver_at<=%s AND ((status IN ('pending','failed') AND attempts<5) OR (status='processing' AND updated_at<=%s)) ORDER BY deliver_at ASC,id ASC LIMIT 50",$now,$stale));
        foreach(is_array($rows)?$rows:[] as $row){
            if((string)$row->status==='processing'&&(int)$row->attempts>=5){
                $wpdb->update($table,['status'=>'failed','last_error'=>'stale_processing_max_attempts','updated_at'=>$now],['id'=>(int)$row->id,'status'=>'processing']);
                SN_DB::audit('scheduled_message_reconciliation_required','scheduled_message',(int)$row->id,'failure',['reason'=>'stale_processing_max_attempts'],0);
                continue;
            }
            $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',attempts=attempts+1,updated_at=%s WHERE id=%d AND ((status IN ('pending','failed') AND attempts<5) OR (status='processing' AND updated_at<=%s AND attempts<5))",$now,(int)$row->id,$stale));
            if($claimed!==1)continue;
            $conversation=self::conversation((int)$row->conversation_id);$policy=$conversation?self::post_policy($conversation,(int)$row->sender_id):self::error('sn_schedule_conversation_missing','Conversation unavailable.',404);
            if(is_wp_error($policy)){self::schedule_failed((int)$row->id,$policy->get_error_code());continue;}
            $plain=SN_Communication_Crypto::decrypt((string)$row->body_cipher,'scheduled-message|'.(int)$row->sender_id.'|'.(int)$row->conversation_id);
            if(is_wp_error($plain)){self::schedule_failed((int)$row->id,$plain->get_error_code());continue;}
            $message=self::insert_canonical_message((int)$row->conversation_id,(int)$row->sender_id,(string)$plain,'text',['scheduled'=>true,'scheduled_id'=>(int)$row->id],(string)$row->client_key);
            if(is_wp_error($message)){self::schedule_failed((int)$row->id,$message->get_error_code());continue;}
            $published=$wpdb->update($table,['status'=>'sent','message_id'=>(int)$message,'body_cipher'=>'','last_error'=>'','updated_at'=>$now],['id'=>(int)$row->id,'status'=>'processing']);
            if($published!==1){self::schedule_failed((int)$row->id,'schedule_finalize_failed');SN_DB::audit('scheduled_message_reconciliation_required','scheduled_message',(int)$row->id,'failure',['message_id'=>(int)$message,'reason'=>'schedule_finalize_failed'],0);continue;}
            SN_DB::audit('scheduled_message_sent','message',(int)$message,'success',['scheduled_id'=>(int)$row->id],(int)$row->sender_id);
        }
    }
'''
pattern=r"    public static function dispatch_due_scheduled\(\): void \{.*?\n    \}\n\n    public static function create_poll"
if 'stale_processing_max_attempts' not in s:
    s,n=re.subn(pattern,dispatch+'\n    public static function create_poll',s,count=1,flags=re.S)
    if n!=1: raise SystemExit('R4 scheduled dispatcher target mismatch')

expire='''    public static function expire_messages(): void {
        global $wpdb;$now=current_time('mysql',true);$messages=SN_DB::table('messages');
        $rows=$wpdb->get_results("SELECT * FROM $messages WHERE deleted_at IS NULL AND metadata IS NOT NULL AND metadata<>'' ORDER BY id ASC LIMIT 1000");
        foreach(is_array($rows)?$rows:[] as $row){
            $meta=self::message_meta($row);$expires=(string)($meta['expires_at']??'');if($expires===''||strtotime($expires.' UTC')>time())continue;
            $id=(int)$row->id;$lock='sn:f17:message-retention:'.$id;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,5));
            if($got!==1){SN_DB::audit('message_expiry_deferred','message',$id,'failure',['reason'=>'retention_lock_busy'],0);continue;}
            try{
                if($wpdb->query('START TRANSACTION')===false){SN_DB::audit('message_expiry_failed','message',$id,'failure',['reason'=>'transaction_start_failed'],0);continue;}
                try{
                    $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE",$id));
                    if(!$locked||$locked->deleted_at!==null){$wpdb->query('ROLLBACK');continue;}
                    $locked_meta=self::message_meta($locked);$locked_expires=(string)($locked_meta['expires_at']??'');
                    if($locked_expires===''||strtotime($locked_expires.' UTC')>time()){$wpdb->query('ROLLBACK');continue;}
                    $held=self::message_has_legal_hold($id);if(is_wp_error($held))throw new RuntimeException($held->get_error_code());if($held){$wpdb->query('ROLLBACK');continue;}
                    $attachment=(string)$locked->attachment_source==='private'?(int)$locked->attachment_id:0;
                    if($wpdb->update($messages,['body'=>'','attachment_id'=>0,'attachment_source'=>'expired','metadata'=>(string)wp_json_encode(['expired'=>true,'expired_at'=>$now]),'deleted_at'=>$now],['id'=>$id,'deleted_at'=>null])===false)throw new RuntimeException('expire_update_failed');
                    if($wpdb->delete(SN_DB::table('reactions'),['message_id'=>$id],['%d'])===false)throw new RuntimeException('expire_reactions_failed');
                    $removed=SN_Message_Search::remove_message($id);if(is_wp_error($removed))throw new RuntimeException($removed->get_error_code());
                    $event=SN_Outbox::enqueue('message.expired','message',$id,['message_id'=>$id,'conversation_id'=>(int)$locked->conversation_id,'expired_at'=>$now],'message.expired:'.$id);if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
                    if($wpdb->query('COMMIT')===false)throw new RuntimeException('expire_commit_failed');
                    if($attachment>0)SN_Private_Files::delete($attachment,(int)$locked->sender_id);SN_DB::audit('message_expired','message',$id,'success',[],0);do_action('sn_network_event_queued',$event,'message.expired');
                }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('message_expiry_failed','message',$id,'failure',['reason'=>$e->getMessage()],0);}
            }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        }
    }
'''
pattern=r"    public static function expire_messages\(\): void \{.*?\n    \}\n\n    public static function translate_message"
if 'message_expiry_deferred' not in s:
    s,n=re.subn(pattern,expire+'\n    public static function translate_message',s,count=1,flags=re.S)
    if n!=1: raise SystemExit('R4 expiry worker target mismatch')

p.write_text(s,encoding='utf-8')

p=Path('sabri-network/includes/class-sn-safety-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
old="""    public static function lock_report_mutations($result, WP_REST_Server $server, WP_REST_Request $request) {
        if($result!==null)return$result;$method=strtoupper($request->get_method());if(in_array($method,['GET','HEAD','OPTIONS'],true))return$result;$route=$request->get_route();
        if(!str_contains($route,'/reports')&&!str_contains($route,'/high-risk-actions'))return$result;
        global $wpdb;$id=0;if(preg_match('#/(?:reports|high-risk-actions)/(\\d+)#',$route,$m))$id=(int)$m[1];$lock='sn:f17:safety:'.($id>0?$id:substr(hash('sha256',$route),0,32));$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return new WP_Error('sn_safety_mutation_busy','This safety record is changing. Retry the request.',['status'=>409]);$request->set_param('_sn_safety_lock',$lock);return$result;
    }

    public static function release_report_mutations($response, WP_REST_Server $server, WP_REST_Request $request) {
        $lock=(string)$request->get_param('_sn_safety_lock');if($lock!==''){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$request->set_param('_sn_safety_lock','');}return$response;
    }
"""
new="""    public static function lock_report_mutations($result, WP_REST_Server $server, WP_REST_Request $request) {
        if($result!==null)return$result;$method=strtoupper($request->get_method());if(in_array($method,['GET','HEAD','OPTIONS'],true))return$result;$route=$request->get_route();
        if(!str_contains($route,'/reports')&&!str_contains($route,'/high-risk-actions'))return$result;
        global $wpdb;$id=0;if(preg_match('#/(?:reports|high-risk-actions)/(\\d+)#',$route,$m))$id=(int)$m[1];$lock='sn:f17:safety:'.($id>0?$id:substr(hash('sha256',$route),0,32));$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return new WP_Error('sn_safety_mutation_busy','This safety record is changing. Retry the request.',['status'=>409]);$request->set_param('_sn_safety_lock',$lock);
        $message_id=absint($request->get_param('message_id'));
        if($message_id<=0&&str_contains($route,'/reports/')&&$id>0){$message_id=(int)$wpdb->get_var($wpdb->prepare('SELECT message_id FROM '.SN_DB::table('reports').' WHERE id=%d',$id));}
        if($message_id>0){$retention='sn:f17:message-retention:'.$message_id;$held=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$retention,self::LOCK_TIMEOUT));if($held!==1){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$request->set_param('_sn_safety_lock','');return new WP_Error('sn_message_retention_busy','The retained message state is changing. Retry the request.',['status'=>409]);}$request->set_param('_sn_safety_retention_lock',$retention);}
        return$result;
    }

    public static function release_report_mutations($response, WP_REST_Server $server, WP_REST_Request $request) {
        global $wpdb;$retention=(string)$request->get_param('_sn_safety_retention_lock');if($retention!==''){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$retention));$request->set_param('_sn_safety_retention_lock','');}$lock=(string)$request->get_param('_sn_safety_lock');if($lock!==''){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$request->set_param('_sn_safety_lock','');}return$response;
    }
"""
if '_sn_safety_retention_lock' not in s:
    if s.count(old)!=1: raise SystemExit('R4 safety retention-lock target mismatch')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')
