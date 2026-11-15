<?php
declare(strict_types=1); defined('ABSPATH') || exit;
trait SN_Future_Superset_Part_1 {
    public static function capabilities(): WP_REST_Response {
        $e2ee=(array)apply_filters('sn_network_e2ee_provider_status',[]);
        $device=(array)apply_filters('sn_network_device_verification_provider_status',[]);
        $transparency=(array)apply_filters('sn_network_key_transparency_provider_status',[]);
        $items=[];
        foreach(self::features() as $id=>$feature){
            $gated=false;
            if($id==='F17-FUT-01')
                $gated=empty($e2ee['ready'])||empty($e2ee['audited'])||empty($e2ee['protocol'])||empty($e2ee['audit_ref']);
            if($id==='F17-FUT-02')
                $gated=!has_filter('sn_network_device_public_key_valid')||empty($device['ready'])||empty($device['revocation_propagation'])||empty($device['key_change_warning']);
            if($id==='F17-FUT-03')
                $gated=empty($transparency['ready'])||empty($transparency['signed_checkpoint'])||empty($transparency['consistency_proof'])||empty($transparency['audited']);
            if($id==='F17-FUT-04')$gated=!has_filter('sn_network_step_up_verified');
            if($id==='F17-FUT-07')$gated=has_action('sn_network_notification_requested')===false;
            if($id==='F17-FUT-15')$gated=!has_filter('sn_network_citation_resolve');
            if($id==='F17-FUT-16')$gated=!has_filter('sn_network_case_discussion_professional_allowed');
            if(in_array($id,['F17-FUT-17','F17-FUT-18','F17-FUT-19','F17-FUT-20'],true))
                $gated=!(bool)apply_filters('sn_network_sfu_available',false,get_current_user_id(),0);
            if($id==='F17-FUT-22')
                $gated=!has_filter('sn_network_ai_assistant_result')||!has_filter('sn_network_ai_context_authorized')||!has_filter('sn_network_ai_context_redact');
            if($id==='F17-FUT-23')
                $gated=!has_filter('sn_network_private_semantic_search_result')||!has_filter('sn_network_private_semantic_purge_result');
            if($id==='F17-FUT-24')
                $gated=!has_filter('sn_network_interop_provider_ready')||!has_filter('sn_network_interop_remote_allowed');
            $items[]=['id'=>$id]+$feature+['status'=>$gated?'provider-gated':'available'];
        }
        return rest_ensure_response(['schema_version'=>self::SCHEMA_VERSION,'feature_count'=>self::FEATURE_COUNT,'items'=>$items]);
    }
    public static function set_e2ee_policy(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $c=absint($r->get_param('conversation_id'));$u=get_current_user_id();if(!self::conversation_manager($c,$u))return self::error('forbidden','Conversation management permission is required.',403);$enabled=rest_sanitize_boolean($r->get_param('enabled'));$p=(array)apply_filters('sn_network_e2ee_provider_status',[],$c,$u);if($enabled&&(empty($p['ready'])||empty($p['audited'])||empty($p['protocol'])))return self::error('sn_e2ee_provider_unavailable','Audited E2EE cannot be enabled until an approved audited protocol provider is ready.',503);$data=['enabled'=>$enabled,'protocol'=>$enabled?sanitize_text_field((string)$p['protocol']):'','provider_audit_ref'=>$enabled?sanitize_text_field((string)($p['audit_ref']??'')):''];$id=self::upsert_record('F17-FUT-01',0,'conversation',$c,$data,'e2ee:'.$c,null);return is_wp_error($id)?$id:rest_ensure_response(['conversation_id'=>$c,'policy'=>$data]);
    }
    public static function register_device_key(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $u=get_current_user_id();
        $device=mb_substr(sanitize_text_field((string)$r->get_param('device_id')),0,96);
        $alg=mb_substr(sanitize_key((string)$r->get_param('algorithm')),0,48);
        $key=trim((string)$r->get_param('public_key'));
        if($device===''||$alg===''||$key===''||strlen($key)>16384)return self::error('sn_device_key_invalid','A valid device identifier, algorithm and public key are required.',400);
        if(!has_filter('sn_network_device_public_key_valid')||!(bool)apply_filters('sn_network_device_public_key_valid',false,$alg,$key,$u,$device))
            return self::error('sn_device_key_provider_unavailable','Device-key registration requires an approved key-validation provider.',503);
        $fp=hash('sha256',$alg.'|'.$key);
        // MyISAM/non-transactional tables cannot honor atomic key+log rollback.
        // Verify actual runtime storage engines rather than trusting the schema plan.
        $keyTable=self::device_keys_table();
        $logTable=self::key_log_table();
        $engines=$wpdb->get_results($wpdb->prepare(
            'SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',
            $keyTable,$logTable
        ));
        $verified=[];
        foreach(is_array($engines)?$engines:[] as $engine){
            if(is_object($engine))$verified[(string)$engine->TABLE_NAME]=strtolower((string)$engine->ENGINE);
        }
        if($wpdb->last_error!==''||count($verified)!==2||($verified[$keyTable]??'')!=='innodb'||($verified[$logTable]??'')!=='innodb')
            return self::error('sn_device_key_transactional_storage_required','Device key changes require verified transactional storage for both the key and audit tables.',503);
        // Serializing the global commitment chain prevents two valid rotations
        // from calculating the same predecessor and silently forking the log.
        $lock='sn:f17:device-key-log';
        $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,5));
        if($locked!==1)return self::error('sn_device_key_log_busy','Device key changes are busy; retry safely.',503);
        $inTransaction=false;
        try {
            if($wpdb->query('START TRANSACTION')===false)return self::db_error();
            $inTransaction=true;
            $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::device_keys_table().' WHERE user_id=%d AND device_id=%s FOR UPDATE',$u,$device));
            if($old&&(string)$old->fingerprint===$fp&&(string)$old->state==='active'){
                if($wpdb->query('COMMIT')===false)return self::db_error();
                $inTransaction=false;
                return rest_ensure_response(['device_id'=>$device,'fingerprint'=>self::pretty_fp($fp),'unchanged'=>true]);
            }
            $now=self::now();
            $ok=$old
                ?$wpdb->update(self::device_keys_table(),['algorithm'=>$alg,'public_key'=>$key,'fingerprint'=>$fp,'state'=>'active','updated_at'=>$now,'revoked_at'=>null],['id'=>(int)$old->id])
                :$wpdb->insert(self::device_keys_table(),['user_id'=>$u,'device_id'=>$device,'algorithm'=>$alg,'public_key'=>$key,'fingerprint'=>$fp,'state'=>'active','created_at'=>$now,'updated_at'=>$now]);
            if($ok!==1)return self::error('sn_device_key_write_failed','The device key change did not commit.',503);
            if(!self::append_key_log($u,$device,$old?'rotated':'registered',$fp,$old?(string)$old->fingerprint:''))
                return self::error('sn_device_key_log_failed','The key change was rejected because its audit commitment could not be stored.',503);
            if($wpdb->query('COMMIT')===false)return self::db_error();
            $inTransaction=false;
            return rest_ensure_response(['device_id'=>$device,'fingerprint'=>self::pretty_fp($fp)]);
        } finally {
            if($inTransaction)$wpdb->query('ROLLBACK');
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function list_device_keys(): WP_REST_Response {global $wpdb;$u=get_current_user_id();$rows=$wpdb->get_results($wpdb->prepare('SELECT device_id,algorithm,fingerprint,state,created_at,updated_at FROM '.self::device_keys_table().' WHERE user_id=%d ORDER BY id DESC',$u));return rest_ensure_response(['items'=>array_map(static fn($x)=>['device_id'=>(string)$x->device_id,'algorithm'=>(string)$x->algorithm,'fingerprint'=>self::pretty_fp((string)$x->fingerprint),'state'=>(string)$x->state,'created_at'=>(string)$x->created_at,'updated_at'=>(string)$x->updated_at],is_array($rows)?$rows:[])]);}
    public static function safety_number(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$u=get_current_user_id();$t=absint($r['user_id']);if($t<=0||SN_DB::is_blocked($u,$t)||!SN_DB::share_active_conversation($u,$t))return self::not_found();
        $a=array_map('strval',$wpdb->get_col($wpdb->prepare("SELECT fingerprint FROM ".self::device_keys_table()." WHERE user_id=%d AND state='active' ORDER BY fingerprint",$u)));
        $b=array_map('strval',$wpdb->get_col($wpdb->prepare("SELECT fingerprint FROM ".self::device_keys_table()." WHERE user_id=%d AND state='active' ORDER BY fingerprint",$t)));
        if(!$a||!$b)return self::error('sn_device_keys_incomplete','Both accounts need active device keys.',409);sort($a,SORT_STRING);sort($b,SORT_STRING);
        $participants=[['user_id'=>$u,'fingerprints'=>$a],['user_id'=>$t,'fingerprints'=>$b]];usort($participants,static fn(array $x,array $y):int=>$x['user_id']<=>$y['user_id']);
        $material=[];foreach($participants as $participant)$material[]=$participant['user_id'].':'.implode(',',$participant['fingerprints']);
        $num=hash('sha256','f17-safety-v1|'.implode('|',$material));return rest_ensure_response(['user_id'=>$t,'safety_number'=>strtoupper(implode(' ',str_split(substr($num,0,60),5)))]);
    }
    public static function key_transparency(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $u=get_current_user_id();
        $target=absint($r['user_id']);
        if($target<=0||($target!==$u&&(SN_DB::is_blocked($u,$target)||!SN_DB::share_active_conversation($u,$target))))
            return self::not_found();
        // Keyset pagination avoids hiding the latest changes after an arbitrary 500-row cap.
        // This is LOCAL audit evidence, not a signed checkpoint or independent consistency proof.
        $after=absint($r->get_param('after_id'));
        $limit=max(1,min(200,absint($r->get_param('limit'))?:100));
        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT id,device_id,event,fingerprint,previous_fingerprint,entry_hash,created_at FROM '.self::key_log_table().' WHERE user_id=%d AND id>%d ORDER BY id ASC LIMIT %d',
            $target,$after,$limit+1
        ));
        if($wpdb->last_error!=='')return self::error('sn_key_history_unavailable','Device key audit history is temporarily unavailable.',503);
        $items=is_array($rows)?$rows:[];
        $hasMore=count($items)>$limit;
        $items=array_slice($items,0,$limit);
        $last=$items?end($items):null;
        return rest_ensure_response([
            'user_id'=>$target,'entries'=>$items,'has_more'=>$hasMore,
            'next_after_id'=>$last?(int)$last->id:null,
            'proof_status'=>'local-audit-only','signed_checkpoint_verified'=>false,
            'consistency_proof_verified'=>false
        ]);
    }
    public static function set_conversation_lock(WP_REST_Request $r): WP_REST_Response|WP_Error {$c=absint($r['id']);$u=get_current_user_id();if(!self::conversation_member($c,$u))return self::not_found();$existing=self::active_record('F17-FUT-04',$u,'conversation',$c);if($existing){$old=self::decode($existing);if(!is_wp_error($old)&&!empty($old['enabled'])&&!(bool)apply_filters('sn_network_step_up_verified',false,$u,$c,'conversation_lock',max(60,min(3600,(int)($old['step_up_ttl']??900)))))return self::error('sn_conversation_step_up_required','Fresh step-up verification is required before changing this sensitive conversation lock.',403);}$data=['enabled'=>rest_sanitize_boolean($r->get_param('enabled')),'step_up_ttl'=>max(60,min(3600,absint($r->get_param('step_up_ttl'))?:900))];$id=self::upsert_record('F17-FUT-04',$u,'conversation',$c,$data,'lock:'.$u.':'.$c,null);return is_wp_error($id)?$id:rest_ensure_response($data);}
    public static function pre_dispatch($result,$server,WP_REST_Request $r){if($result!==null)return $result;$route=$r->get_route();if(!str_starts_with($route,'/sabri-network/v2/'))return $result;$u=get_current_user_id();if($u<=0)return $result;$c=0;$m=null;if(preg_match('#/conversations/(\d+)#',$route,$x))$c=(int)$x[1];elseif(preg_match('#/messages/(\d+)#',$route,$x)){$m=self::message_row((int)$x[1]);$c=$m?(int)$m->conversation_id:0;}elseif(preg_match('#/future/team-inbox/(\d+)#',$route,$x))$c=(int)$x[1];if($c<=0&&$route==='/sabri-network/v2/conversations'&&$r->get_method()==='POST'){
            // Creating/restoring a direct conversation can reopen an existing
            // sensitive chat without a numeric conversation ID in the request.
            $type=sanitize_key((string)$r->get_param('type'))?:'direct';
            if($type==='direct'){
                $target=absint($r->get_param('user_id'));
                if($target<=0){
                    $members=(array)$r->get_param('member_ids');
                    $target=absint($members[0]??0);
                }
                if($target>0&&$target!==$u){
                    global $wpdb;
                    $c=(int)$wpdb->get_var($wpdb->prepare(
                        'SELECT id FROM '.SN_DB::table('conversations').' WHERE type=%s AND direct_key=%s LIMIT 1',
                        'direct',SN_DB::direct_key($u,$target)
                    ));
                    if($wpdb->last_error!=='')
                        return self::error('sn_conversation_lock_lookup_unavailable','Sensitive conversation authorization could not be verified.',503);
                }
            }
        }
        if($c<=0&&in_array($route,['/sabri-network/v2/future/e2ee-policy','/sabri-network/v2/future/reminders','/sabri-network/v2/future/citations','/sabri-network/v2/future/case-discussions','/sabri-network/v2/future/ai-assistant','/sabri-network/v2/future/semantic-search','/sabri-network/v2/future/interop'],true))$c=absint($r->get_param('conversation_id'));if($c<=0)return $result;$lock=self::active_record('F17-FUT-04',$u,'conversation',$c);if($lock){$d=self::decode($lock);if(is_wp_error($d))return self::error('sn_conversation_lock_state_unavailable','The sensitive conversation lock state cannot be verified.',503);if(!empty($d['enabled'])&&!(bool)apply_filters('sn_network_step_up_verified',false,$u,$c,'conversation_lock',(int)($d['step_up_ttl']??900)))return self::error('sn_conversation_step_up_required','This sensitive conversation requires fresh step-up verification.',403);}if($r->get_method()==='POST'&&preg_match('#^/sabri-network/v2/messages/\d+$#',$route)&&$m&&self::conversation_member($c,$u)&&!SN_Message_Operations::is_hidden($u,(int)$m->id)&&SN_Policy::can_edit_message($m,$u)){$new=trim(sanitize_textarea_field(wp_unslash((string)$r->get_param('body'))));$old=SN_Message_Body::decrypt_row($m);if(!is_wp_error($old)&&$new!==''&&hash('sha256',$old)!==hash('sha256',$new)){$id=self::capture_message_version($m,$u);if(is_wp_error($id))return $id;$r->set_param('_sn_future_snapshot_id',(int)$id);}}return $result;}
    public static function post_dispatch($response,$server,WP_REST_Request $r){
        // V12.3 F17-FUT-04: both the GET list and POST create/restore replies
        // can contain last-message previews from a pre-existing locked conversation.
        $route=$r->get_route();
        if($route==='/sabri-network/v2/conversations'&&
            in_array($r->get_method(),['GET','POST'],true)&&
            $response instanceof WP_REST_Response&&(int)$response->get_status()<400){
            $data=$response->get_data();
            if(is_array($data)){
                $list=isset($data['conversations'])&&is_array($data['conversations']);
                $single=isset($data['conversation'])&&is_array($data['conversation']);
                if($list||$single){
                    $user=get_current_user_id();
                    if($user>0){
                        $items=$list?$data['conversations']:[$data['conversation']];
                        foreach($items as &$conversation){
                            if(!is_array($conversation))continue;
                            $conversationId=absint($conversation['id']??0);
                            if($conversationId<=0)continue;
                            $lock=self::active_record('F17-FUT-04',$user,'conversation',$conversationId);
                            if(!$lock)continue;
                            $policy=self::decode($lock);
                            if(is_wp_error($policy)){
                                // Returning a partial listing or private restore response is unsafe.
                                return new WP_REST_Response(['code'=>'sn_conversation_lock_state_unavailable','message'=>'Sensitive conversation previews cannot be verified.'],503);
                            }
                            if(empty($policy['enabled']))continue;
                            $ttl=max(60,min(3600,(int)($policy['step_up_ttl']??900)));
                            if((bool)apply_filters('sn_network_step_up_verified',false,$user,$conversationId,'conversation_lock',$ttl))continue;
                            $conversation['last_message']=null;
                            $conversation['description']='';
                            $conversation['title']='Locked conversation';
                            $conversation['avatar']='';
                            $conversation['owner_id']=0;
                            $conversation['updated_at']='';
                            $conversation['unread_count']=0;
                            $conversation['can_post']=false;
                            $conversation['sensitive_locked']=true;
                        }
                        unset($conversation);
                        if($list)$data['conversations']=$items;
                        else $data['conversation']=$items[0];
                        $response->set_data($data);
                    }
                }
            }
        }
        $id=absint($r->get_param('_sn_future_snapshot_id'));
        if($id>0&&method_exists($response,'get_status')&&(int)$response->get_status()>=400){
            global $wpdb;
            $wpdb->delete(self::versions_table(),['id'=>$id],['%d']);
        }
        return $response;
    }
    public static function get_team_inbox(WP_REST_Request $r): WP_REST_Response|WP_Error {$c=absint($r['id']);$u=get_current_user_id();if(!self::conversation_member($c,$u))return self::not_found();$row=self::active_record('F17-FUT-05',0,'conversation',$c);$d=$row?self::decode($row):[];return is_wp_error($d)?$d:rest_ensure_response(['conversation_id'=>$c,'team_inbox'=>$d?:['enabled'=>false,'assignee_id'=>0,'status'=>'open'],'version'=>$row?(int)$row->version:0]);}
    public static function set_team_inbox(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $c=absint($r['id']);$u=get_current_user_id();$a=absint($r->get_param('assignee_id'));
        if($c<=0||!self::conversation_manager($c,$u)||SN_Policy::is_suspended($u))return self::error('forbidden','Conversation management permission is required.',403);
        $expected=$r->get_param('expected_version');
        if(!is_scalar($expected)||!ctype_digit((string)$expected))return self::error('sn_team_precondition_required','Read the current team inbox and send its version before changing assignments.',428);
        $expected=(int)$expected;
        if($a&&(!self::conversation_member($c,$a)||SN_Policy::is_suspended($a)))return self::error('sn_assignment_member_required','Assignee must be an eligible current conversation participant.',409);
        // Serialize concurrent owner/moderator handoffs, never publish partial F17-05/06 state.
        $lock='sn:f17:team:'.$c;
        $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,5));
        if($locked!==1)return self::error('sn_team_lock_unavailable','Team inbox state is busy; retry after refreshing its version.',503);
        $inTransaction=false;
        try {
            if($wpdb->query('START TRANSACTION')===false)return self::db_error();
            $inTransaction=true;
            $conversation=$wpdb->get_row($wpdb->prepare('SELECT status FROM '.SN_DB::table('conversations').' WHERE id=%d FOR UPDATE',$c));
            if(!$conversation||(string)$conversation->status!=='active')return self::error('sn_team_conversation_closed','Only an active conversation can be assigned.',409);
            if(!self::conversation_manager($c,$u)||SN_Policy::is_suspended($u))return self::error('forbidden','Conversation management permission has changed.',403);
            if($a&&(!self::conversation_member($c,$a)||SN_Policy::is_suspended($a)))return self::error('sn_assignment_member_required','Assignee membership or eligibility has changed.',409);
            $current=self::active_record('F17-FUT-05',0,'conversation',$c);
            $version=$current?(int)$current->version:0;
            if($expected!==$version)return self::error('sn_team_stale_version','Team assignment changed. Refresh before retrying.',409);
            $d=['enabled'=>rest_sanitize_boolean($r->get_param('enabled')),'assignee_id'=>$a,'status'=>self::enum((string)$r->get_param('status'),['open','pending','resolved'],'open'),'updated_by'=>$u];
            $team=self::upsert_record('F17-FUT-05',0,'conversation',$c,$d,'team:'.$c,null);
            if(is_wp_error($team))return $team;
            $assignment=self::upsert_record('F17-FUT-06',0,'conversation',$c,['assignee_id'=>$a,'handoff_by'=>$u,'status'=>$d['status'],'expected_team_version'=>$version],'assignment:'.$c,null);
            if(is_wp_error($assignment))return $assignment;
            if(!self::conversation_manager($c,$u)||($a&&!self::conversation_member($c,$a)))return self::error('sn_team_actor_revoked','Membership changed during assignment.',409);
            if($wpdb->query('COMMIT')===false)return self::db_error();
            $inTransaction=false;
            SN_DB::audit('future_team_inbox_updated','conversation',$c,'success',['assignee_id'=>$a,'status'=>$d['status'],'version'=>$version+1],$u);
            return rest_ensure_response(['conversation_id'=>$c,'team_inbox'=>$d,'version'=>$version+1]);
        } finally {
            if($inTransaction)$wpdb->query('ROLLBACK');
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function handoff_team_inbox(WP_REST_Request $r): WP_REST_Response|WP_Error {$r->set_param('enabled',true);return self::set_team_inbox($r);}
    public static function create_reminder(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $u=get_current_user_id();$at=self::future_time((string)$r->get_param('remind_at'),365*DAY_IN_SECONDS);
        if(is_wp_error($at))return $at;
        $c=absint($r->get_param('conversation_id'));$message=absint($r->get_param('message_id'));
        if($c&&(!self::conversation_member($c,$u)||SN_Policy::is_suspended($u)))return self::not_found();
        if($c){global $wpdb;$state=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.SN_DB::table('conversations').' WHERE id=%d',$c));if((string)$state!=='active')return self::not_found();}
        if($message){$m=self::message_row($message);if(!$c||!$m||(int)$m->conversation_id!==$c||$m->deleted_at||SN_Message_Operations::is_hidden($u,$message)||SN_DB::is_blocked($u,(int)$m->sender_id))return self::not_found();}
        $id=self::create_record('F17-FUT-07',$u,'conversation',$c,['message_id'=>$message,'label'=>mb_substr(sanitize_text_field((string)$r->get_param('label')),0,191)],self::client($r,$u,'reminder'),$at);
        if(is_wp_error($id))return $id;
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT state,expires_at,version FROM ".self::records_table()." WHERE id=%d AND feature_id='F17-FUT-07' AND owner_id=%d",$id,$u));
        if(!$row)return self::db_error();
        return new WP_REST_Response(['id'=>$id,'remind_at'=>(string)$row->expires_at,'state'=>(string)$row->state,'version'=>(int)$row->version],(string)$row->state==='active'?201:200);
    }
    public static function cancel_reminder(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$u=get_current_user_id();$id=absint($r['id']);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE id=%d AND feature_id='F17-FUT-07' AND owner_id=%d LIMIT 1",$id,$u));
        if(!$row)return self::not_found();
        if((string)$row->state==='cancelled')return rest_ensure_response(['id'=>$id,'state'=>'cancelled','version'=>(int)$row->version]);
        if((string)$row->state!=='active')return self::error('sn_reminder_already_processing','This reminder cannot be cancelled after dispatch has begun.',409);
        $expected=$r->get_param('expected_version');
        if(!is_scalar($expected)||!ctype_digit((string)$expected)||(int)$expected!==(int)$row->version)return self::error('sn_reminder_stale_version','Refresh the reminder and supply its current version.',409);
        $ok=$wpdb->update(self::records_table(),['state'=>'cancelled','updated_at'=>self::now(),'version'=>(int)$row->version+1],['id'=>$id,'owner_id'=>$u,'state'=>'active','version'=>(int)$row->version]);
        return $ok===1?rest_ensure_response(['id'=>$id,'state'=>'cancelled','version'=>(int)$row->version+1]):self::error('sn_reminder_conflict','Reminder state changed before cancellation.',409);
    }
    public static function reschedule_reminder(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$u=get_current_user_id();$id=absint($r['id']);
        $at=self::future_time((string)$r->get_param('remind_at'),365*DAY_IN_SECONDS);
        if(is_wp_error($at))return $at;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE id=%d AND feature_id='F17-FUT-07' AND owner_id=%d AND state='active' LIMIT 1",$id,$u));
        if(!$row)return self::not_found();
        $expected=$r->get_param('expected_version');
        if(!is_scalar($expected)||!ctype_digit((string)$expected)||(int)$expected!==(int)$row->version)return self::error('sn_reminder_stale_version','Refresh the reminder and supply its current version.',409);
        $payload=self::decode($row);if(is_wp_error($payload))return $payload;
        if(!self::reminder_authorized($row,$payload))return self::error('sn_reminder_access_revoked','Reminder conversation or message access is no longer authorized.',403);
        $ok=$wpdb->update(self::records_table(),['expires_at'=>$at,'updated_at'=>self::now(),'version'=>(int)$row->version+1],['id'=>$id,'owner_id'=>$u,'state'=>'active','version'=>(int)$row->version]);
        return $ok===1?rest_ensure_response(['id'=>$id,'state'=>'active','remind_at'=>$at,'version'=>(int)$row->version+1]):self::error('sn_reminder_conflict','Reminder state changed before rescheduling.',409);
    }
    public static function list_reminders(): WP_REST_Response {return rest_ensure_response(['items'=>self::owned('F17-FUT-07',get_current_user_id())]);}
    public static function save_template(WP_REST_Request $r): WP_REST_Response|WP_Error {$u=get_current_user_id();$title=mb_substr(sanitize_text_field((string)$r->get_param('title')),0,120);$body=mb_substr(sanitize_textarea_field(wp_unslash((string)$r->get_param('body'))),0,8000);if($title===''||$body==='')return self::error('sn_template_invalid','A title and template body are required.',400);if(self::looks_clinical_directive($body)&&!(bool)apply_filters('sn_network_professional_template_allowed',false,$u,$body))return self::error('sn_template_clinical_guard','Clinical directive templates require an approved professional policy.',403);$id=self::create_record('F17-FUT-08',$u,'user',$u,['title'=>$title,'body'=>$body],self::client($r,$u,'template'),null);return is_wp_error($id)?$id:new WP_REST_Response(['id'=>$id,'title'=>$title],201);}
    public static function list_templates(): WP_REST_Response {return rest_ensure_response(['items'=>self::owned('F17-FUT-08',get_current_user_id())]);}
    /**
     * Preview saved text without creating/sending any message. Variables are an
     * explicit allowlist, and no caller-provided HTML is interpreted.
     */
    public static function preview_template(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $u=get_current_user_id();$id=absint($r['id']);
        if($u<=0||SN_Policy::is_suspended($u)||$id<=0)return self::not_found();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE id=%d AND feature_id='F17-FUT-08' AND owner_id=%d AND state='active' LIMIT 1",$id,$u));
        if(!$row)return self::not_found();
        $data=self::decode($row);if(is_wp_error($data))return $data;
        $body=(string)($data['body']??'');
        $inputs=$r->get_param('variables');
        if(!is_array($inputs))$inputs=[];
        $allow=['recipient_name','clinic_name','appointment_date'];
        $replace=[];
        foreach($inputs as $key=>$value){
            if(!is_string($key)||!in_array($key,$allow,true)||!is_scalar($value))return self::error('sn_template_variable_forbidden','Only approved plain-text template variables are permitted.',400);
            $replace['{{'.$key.'}}']=mb_substr(sanitize_text_field((string)$value),0,191);
        }
        $preview=strtr($body,$replace);
        if(preg_match('/{{[^{}]*}}|{{|}}/u',$preview))return self::error('sn_template_variables_unresolved','Provide approved values for all variables before using a saved reply.',409);
        if(mb_strlen($preview)>10000)return self::error('sn_template_preview_too_large','Rendered template is too long.',413);
        if(self::looks_clinical_directive($preview)&&!(bool)apply_filters('sn_network_professional_template_allowed',false,$u,$preview))return self::error('sn_template_clinical_guard','Professional clinical text requires the approved policy.',403);
        SN_DB::audit('future_template_preview','future_record',$id,'success',['rendered_length'=>mb_strlen($preview),'not_sent'=>true],$u);
        return rest_ensure_response(['id'=>$id,'preview'=>$preview,'not_sent'=>true,'requires_manual_review'=>true]);
    }
}
