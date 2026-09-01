<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Corrective safety/privacy transaction boundary for native File-17 report evidence. */
final class SN_Safety_Runtime_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'lock_report_mutations'], 3, 3);
        add_filter('rest_post_dispatch', [self::class, 'release_report_mutations'], 13, 3);
    }

    public static function erase_user_report_data(int $user_id): array {
        global $wpdb;$table=SN_DB::table('reports');$now=current_time('mysql',true);$empty=SN_Safety::evidence_hash([]);
        $retained_raw=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE legal_hold=1 AND (reporter_id=%d OR reported_user_id=%d)",$user_id,$user_id));
        if(($wpdb->last_error??'')!==''){SN_DB::audit('report_privacy_retention_read_failed','user',$user_id,'failure',['reason'=>(string)$wpdb->last_error],0);return['redacted'=>0,'retained'=>0,'held_reporter_minimized'=>0,'failed'=>true];}
        $retained=(int)$retained_raw;
        $lock='sn:f17:report-user:'.$user_id;$raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if(($wpdb->last_error??'')!==''||$raw===null){SN_DB::audit('report_privacy_lock_unavailable','user',$user_id,'failure',['reason'=>(string)($wpdb->last_error??'')],0);return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];}$got=(int)$raw;if($got!==1)return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];
        try{
            if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('report_privacy_transaction_failed');
            $held=$wpdb->query($wpdb->prepare("UPDATE $table SET reporter_id=0,client_uuid=NULL,updated_at=%s,version=version+1 WHERE reporter_id=%d AND legal_hold=1",$now,$user_id));if($held===false)throw new RuntimeException('held_reporter_minimization_failed');
            $reporter=$wpdb->query($wpdb->prepare("UPDATE $table SET reporter_id=0,client_uuid=NULL,details='',evidence='[]',evidence_hash=%s,updated_at=%s,version=version+1 WHERE reporter_id=%d AND legal_hold=0",$empty,$now,$user_id));if($reporter===false)throw new RuntimeException('reporter_redaction_failed');
            $rows=$wpdb->get_results($wpdb->prepare("SELECT id,conversation_id,message_id,target_key FROM $table WHERE reported_user_id=%d AND legal_hold=0 FOR UPDATE",$user_id));if(!is_array($rows))throw new RuntimeException('reported_user_query_failed');$reported=0;
            foreach($rows as $row){$key=((int)$row->conversation_id===0&&(int)$row->message_id===0)?hash('sha256','erased-user-report:'.(int)$row->id):(string)$row->target_key;$changed=$wpdb->query($wpdb->prepare("UPDATE $table SET reported_user_id=0,target_key=%s,appeal_reason='',appealed_at=NULL,appeal_decision_reason='',appeal_decided_by=0,appeal_decided_at=NULL,decision_reason='',decision_by=0,decision_at=NULL,updated_at=%s,version=version+1 WHERE id=%d AND reported_user_id=%d AND legal_hold=0",$key,$now,(int)$row->id,$user_id));if($changed!==1)throw new RuntimeException('reported_user_redaction_failed');$reported++;}
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('report_privacy_commit_failed');
            return['redacted'=>(int)$reporter+$reported,'retained'=>$retained,'held_reporter_minimized'=>(int)$held,'failed'=>false];
        }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('report_privacy_erasure_failed','user',$user_id,'failure',['reason'=>$e->getMessage()],0);return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];}
        finally{$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(($wpdb->last_error??'')!==''||$released===null)SN_DB::audit('report_privacy_lock_release_failed','user',$user_id,'failure',['reason'=>(string)($wpdb->last_error??'')],0);}
    }

    public static function lock_report_mutations($result, WP_REST_Server $server, WP_REST_Request $request) {
        if($result!==null)return$result;$method=strtoupper($request->get_method());if(in_array($method,['GET','HEAD','OPTIONS'],true))return$result;$route=$request->get_route();
        if(!str_contains($route,'/reports')&&!str_contains($route,'/high-risk-actions'))return$result;
        global $wpdb;$id=0;if(preg_match('#/(?:reports|high-risk-actions)/(\d+)#',$route,$m))$id=(int)$m[1];$lock='sn:f17:safety:'.($id>0?$id:substr(hash('sha256',$route),0,32));$raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if(($wpdb->last_error??'')!==''||$raw===null)return new WP_Error('sn_safety_lock_unavailable','The safety mutation lock service is temporarily unavailable.',['status'=>503]);if((int)$raw!==1)return new WP_Error('sn_safety_mutation_busy','This safety record is changing. Retry the request.',['status'=>409]);$request->set_param('_sn_safety_lock',$lock);return$result;
    }

    public static function release_report_mutations($response, WP_REST_Server $server, WP_REST_Request $request) {
        $lock=(string)$request->get_param('_sn_safety_lock');if($lock!==''){global $wpdb;$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(($wpdb->last_error??'')!==''||$released===null)SN_DB::audit('safety_lock_release_failed','system',0,'failure',['lock_hash'=>substr(hash('sha256',$lock),0,16),'reason'=>(string)($wpdb->last_error??'')],0);$request->set_param('_sn_safety_lock','');}return$response;
    }
}
