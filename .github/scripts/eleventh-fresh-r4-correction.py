from pathlib import Path
p=Path('sabri-network/includes/class-sn-safety-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
def rep(old,new):
 global s
 if old not in s: raise SystemExit('missing anchor: '+old[:160])
 s=s.replace(old,new,1)
rep("""        $retained=(int)$wpdb->get_var($wpdb->prepare(\"SELECT COUNT(*) FROM $table WHERE legal_hold=1 AND (reporter_id=%d OR reported_user_id=%d)\",$user_id,$user_id));
        $lock='sn:f17:report-user:'.$user_id;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];
""","""        $retained_raw=$wpdb->get_var($wpdb->prepare(\"SELECT COUNT(*) FROM $table WHERE legal_hold=1 AND (reporter_id=%d OR reported_user_id=%d)\",$user_id,$user_id));
        if(($wpdb->last_error??'')!==''){SN_DB::audit('report_privacy_retention_read_failed','user',$user_id,'failure',['reason'=>(string)$wpdb->last_error],0);return['redacted'=>0,'retained'=>0,'held_reporter_minimized'=>0,'failed'=>true];}
        $retained=(int)$retained_raw;
        $lock='sn:f17:report-user:'.$user_id;$raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if(($wpdb->last_error??'')!==''||$raw===null){SN_DB::audit('report_privacy_lock_unavailable','user',$user_id,'failure',['reason'=>(string)($wpdb->last_error??'')],0);return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];}$got=(int)$raw;if($got!==1)return['redacted'=>0,'retained'=>$retained,'held_reporter_minimized'=>0,'failed'=>true];
""")
rep("""        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
""","""        finally{$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(($wpdb->last_error??'')!==''||$released===null)SN_DB::audit('report_privacy_lock_release_failed','user',$user_id,'failure',['reason'=>(string)($wpdb->last_error??'')],0);}
""")
rep("""        global $wpdb;$id=0;if(preg_match('#/(?:reports|high-risk-actions)/(\\d+)#',$route,$m))$id=(int)$m[1];$lock='sn:f17:safety:'.($id>0?$id:substr(hash('sha256',$route),0,32));$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return new WP_Error('sn_safety_mutation_busy','This safety record is changing. Retry the request.',['status'=>409]);$request->set_param('_sn_safety_lock',$lock);return$result;
""","""        global $wpdb;$id=0;if(preg_match('#/(?:reports|high-risk-actions)/(\\d+)#',$route,$m))$id=(int)$m[1];$lock='sn:f17:safety:'.($id>0?$id:substr(hash('sha256',$route),0,32));$raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if(($wpdb->last_error??'')!==''||$raw===null)return new WP_Error('sn_safety_lock_unavailable','The safety mutation lock service is temporarily unavailable.',['status'=>503]);if((int)$raw!==1)return new WP_Error('sn_safety_mutation_busy','This safety record is changing. Retry the request.',['status'=>409]);$request->set_param('_sn_safety_lock',$lock);return$result;
""")
rep("""        $lock=(string)$request->get_param('_sn_safety_lock');if($lock!==''){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$request->set_param('_sn_safety_lock','');}return$response;
""","""        $lock=(string)$request->get_param('_sn_safety_lock');if($lock!==''){global $wpdb;$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(($wpdb->last_error??'')!==''||$released===null)SN_DB::audit('safety_lock_release_failed','system',0,'failure',['lock_hash'=>substr(hash('sha256',$lock),0,16),'reason'=>(string)($wpdb->last_error??'')],0);$request->set_param('_sn_safety_lock','');}return$response;
""")
p.write_text(s,encoding='utf-8')
t=Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php');ts=t.read_text(encoding='utf-8');anchor='if($fail){fwrite(STDERR,'
block="""// R4 — safety privacy/mutation locks must preserve storage uncertainty.\n$safetyRuntime=$read($root.'/includes/class-sn-safety-runtime-hardening.php');\n$check(str_contains($safetyRuntime,'report_privacy_retention_read_failed'),'R4 legal-hold count failures must not masquerade as zero retained reports.');\n$check(str_contains($safetyRuntime,'report_privacy_lock_unavailable'),'R4 privacy lock DB failures must be observable.');\n$check(str_contains($safetyRuntime,'sn_safety_lock_unavailable'),'R4 safety lock service failure must differ from contention.');\n$check(str_contains($safetyRuntime,'safety_lock_release_failed'),'R4 safety lock release failures must remain observable.');\n"""
if anchor not in ts: raise SystemExit('missing test footer')
t.write_text(ts.replace(anchor,block+anchor,1),encoding='utf-8')
