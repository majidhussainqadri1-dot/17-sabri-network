<?php
/** Review rounds 18–19 — temporary membership and mentorship boundary hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_B {
    private const TEMP_FEATURE='F17-FUT-13';
    private const TEMP_EXPIRY_BATCH=200;

    public static function register(): void {
        add_action('rest_api_init',[self::class,'routes'],1950);
        add_action('sn_cleanup_hourly',[self::class,'expire_temporary_memberships'],5);
    }
    public static function routes(): void {
        register_rest_route('sabri-network/v2','/future/temporary-memberships',['methods'=>'POST','callback'=>[self::class,'grant_temporary_membership'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/mentorships',['methods'=>'POST','callback'=>[self::class,'create_mentorship'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/mentorships/(?P<id>\d+)',['methods'=>'POST','callback'=>[self::class,'decide_mentorship'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/mentorships/(?P<id>\d+)/end',['methods'=>'POST','callback'=>[self::class,'end_mentorship'],'permission_callback'=>[SN_REST::class,'access']]);
    }

    public static function grant_temporary_membership(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor=get_current_user_id();$user=absint($r->get_param('user_id'));$space=absint($r->get_param('space_id'));
        if($user<=0||$space<=0||!get_user_by('id',$user)||SN_Policy::is_suspended($user))return self::error('sn_temp_member_invalid','Select an eligible active account.',400);
        $role=self::enum((string)$r->get_param('role'),['member','observer'],'observer');
        $until=self::future_time((string)$r->get_param('expires_at'),90*DAY_IN_SECONDS);if(is_wp_error($until))return $until;
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $space_row=$wpdb->get_row($wpdb->prepare('SELECT id,owner_user_id,conversation_id,state FROM '.SN_DB::table('spaces').' WHERE id=%d LIMIT 1 FOR UPDATE',$space));
            if(!$space_row||!in_array((string)$space_row->state,['active','restricted'],true))throw new RuntimeException('space_unavailable');
            if(!self::space_manager_locked($space,$actor,$space_row))throw new RuntimeException('forbidden');
            self::assert_temp_eligibility_locked($space,$user,$actor);
            $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE feature_id=%s AND owner_id=%d AND scope_type='space' AND scope_id=%d AND state IN ('active','expiry_pending') ORDER BY id DESC LIMIT 1 FOR UPDATE",self::TEMP_FEATURE,$user,$space));
            $member=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('space_members').' WHERE space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',$space,$user));
            if($member&&in_array((string)$member->status,['banned','blocked'],true))throw new RuntimeException('membership_blocked');
            if($member&&(string)$member->status==='active'&&$member->left_at===null){
                if(!$record)throw new RuntimeException('permanent_conflict');
                $old=self::decode($record);if(is_wp_error($old))throw new RuntimeException('record_invalid');
                $bound=(int)($old['membership_version']??0);
                if($bound>0&&$bound!==(int)$member->version){self::mark_record_locked($record,'superseded');throw new RuntimeException('permanent_conflict');}
            }
            $at=self::now();
            if($member){
                $new_member_version=(int)$member->version+1;
                $changed=$wpdb->update(SN_DB::table('space_members'),['role'=>$role,'status'=>'active','approved_by'=>$actor,'left_at'=>null,'joined_at'=>$at,'updated_at'=>$at,'version'=>$new_member_version],['id'=>(int)$member->id,'version'=>(int)$member->version]);
                if($changed!==1)throw new RuntimeException('member_conflict');$member_id=(int)$member->id;
            }else{
                $inserted=$wpdb->insert(SN_DB::table('space_members'),['space_id'=>$space,'user_id'=>$user,'role'=>$role,'status'=>'active','approved_by'=>$actor,'joined_at'=>$at,'left_at'=>null,'last_post_at'=>null,'version'=>1,'created_at'=>$at,'updated_at'=>$at]);
                if($inserted!==1)throw new RuntimeException('member_write_failed');$member_id=(int)$wpdb->insert_id;$new_member_version=1;
            }
            $conversation_id=(int)$space_row->conversation_id;$conversation_member_id=0;
            if($conversation_id>0){
                $cm=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',$conversation_id,$user));$conversation_role=$role==='observer'?'member':$role;
                if($cm){$changed=$wpdb->update(SN_DB::table('members'),['role'=>$conversation_role,'left_at'=>null,'joined_at'=>$at],['id'=>(int)$cm->id]);if($changed===false)throw new RuntimeException('conversation_member_write_failed');$conversation_member_id=(int)$cm->id;}
                else{$inserted=$wpdb->insert(SN_DB::table('members'),['conversation_id'=>$conversation_id,'user_id'=>$user,'role'=>$conversation_role,'last_read_message_id'=>0,'is_muted'=>0,'is_archived'=>0,'joined_at'=>$at,'left_at'=>null]);if($inserted!==1)throw new RuntimeException('conversation_member_write_failed');$conversation_member_id=(int)$wpdb->insert_id;}
            }
            $payload=['user_id'=>$user,'role'=>$role,'granted_by'=>$actor,'membership_id'=>$member_id,'membership_version'=>$new_member_version,'conversation_id'=>$conversation_id,'conversation_member_id'=>$conversation_member_id,'grant_joined_at'=>$at];
            if($record){$cipher=self::encode($record,$payload);if(is_wp_error($cipher))throw new RuntimeException('record_encrypt_failed');$changed=$wpdb->update(self::records_table(),['payload_cipher'=>$cipher,'state'=>'active','expires_at'=>$until,'updated_at'=>$at,'version'=>(int)$record->version+1],['id'=>(int)$record->id,'version'=>(int)$record->version]);if($changed!==1)throw new RuntimeException('record_conflict');$record_id=(int)$record->id;}
            else{$dummy=(object)['feature_id'=>self::TEMP_FEATURE,'owner_id'=>$user,'scope_type'=>'space','scope_id'=>$space];$cipher=self::encode($dummy,$payload);if(is_wp_error($cipher))throw new RuntimeException('record_encrypt_failed');$client_key=hash('sha256','temporary:'.$space.':'.$user);$inserted=$wpdb->insert(self::records_table(),['feature_id'=>self::TEMP_FEATURE,'owner_id'=>$user,'scope_type'=>'space','scope_id'=>$space,'state'=>'active','payload_cipher'=>$cipher,'client_key'=>$client_key,'expires_at'=>$until,'created_at'=>$at,'updated_at'=>$at,'version'=>1]);if($inserted!==1)throw new RuntimeException('record_write_failed');$record_id=(int)$wpdb->insert_id;}
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');
            SN_DB::audit('future_temporary_membership_granted','space',$space,'success',['target_user_id'=>$user,'record_id'=>$record_id,'membership_version'=>$new_member_version],$actor);
            return new WP_REST_Response(['id'=>$record_id,'space_id'=>$space,'user_id'=>$user,'role'=>$role,'expires_at'=>$until],201);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');$code=$e->getMessage();if($code==='forbidden')return self::error('forbidden','Space management permission is required.',403);if($code==='permanent_conflict')return self::error('sn_temp_member_conflict','Account already has a permanent or independently changed membership.',409);if(in_array($code,['blocked','membership_blocked','space_banned'],true))return self::error('sn_temp_member_blocked','Temporary membership is unavailable for this account.',403);if($code==='guardian')return self::error('sn_guardian_approval_required','Guardian approval is required for temporary membership.',403);if($code==='policy')return self::error('sn_temp_member_policy_denied','Temporary membership is not permitted by current space policy.',403);if($code==='capacity')return self::error('sn_space_full','This space cannot accept another member.',409);return self::error('sn_temp_member_conflict','Temporary membership could not be committed safely.',409);}
    }

    public static function expire_temporary_memberships(): void {
        global $wpdb;$now=self::now();
        $wpdb->query($wpdb->prepare("UPDATE ".self::records_table()." SET state='expiry_pending',updated_at=%s,version=version+1 WHERE feature_id=%s AND state='active' AND expires_at IS NOT NULL AND expires_at<=%s",$now,self::TEMP_FEATURE,$now));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id FROM ".self::records_table()." WHERE feature_id=%s AND state='expiry_pending' AND expires_at IS NOT NULL AND expires_at<=%s ORDER BY id ASC LIMIT %d",self::TEMP_FEATURE,$now,self::TEMP_EXPIRY_BATCH));
        foreach(is_array($rows)?$rows:[] as $row)self::expire_temp_record((int)$row->id);
    }
    private static function expire_temp_record(int $id): void {
        global $wpdb;try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE id=%d AND feature_id=%s AND state='expiry_pending' LIMIT 1 FOR UPDATE",$id,self::TEMP_FEATURE));if(!$record){$wpdb->query('ROLLBACK');return;}
            $data=self::decode($record);if(is_wp_error($data))throw new RuntimeException('record_invalid');$space=(int)$record->scope_id;$user=(int)($data['user_id']??$record->owner_id);$membership_id=(int)($data['membership_id']??0);$bound_version=(int)($data['membership_version']??0);$at=self::now();
            $member=$membership_id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('space_members').' WHERE id=%d AND space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',$membership_id,$space,$user)):$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('space_members').' WHERE space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',$space,$user));
            $owned=$member&&(string)$member->status==='active'&&$member->left_at===null;if($owned&&$bound_version>0)$owned=(int)$member->version===$bound_version;if($owned&&$bound_version===0)$owned=(int)$member->approved_by===(int)($data['granted_by']??0)&&(string)$member->role===(string)($data['role']??'');
            if(!$owned){self::mark_record_locked($record,'superseded');if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');return;}
            $changed=$wpdb->update(SN_DB::table('space_members'),['status'=>'expired','left_at'=>$at,'updated_at'=>$at,'version'=>(int)$member->version+1],['id'=>(int)$member->id,'status'=>'active','version'=>(int)$member->version]);if($changed!==1)throw new RuntimeException('member_conflict');
            $conversation_id=(int)($data['conversation_id']??0);if($conversation_id<=0)$conversation_id=(int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM '.SN_DB::table('spaces').' WHERE id=%d',$space));
            if($conversation_id>0){$cm=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',$conversation_id,$user));if($cm){$changed=$wpdb->update(SN_DB::table('members'),['left_at'=>$at],['id'=>(int)$cm->id,'left_at'=>null]);if($changed===false)throw new RuntimeException('conversation_member_conflict');}}
            self::mark_record_locked($record,'expired');if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');SN_DB::audit('future_temporary_membership_expired','space',$space,'success',['target_user_id'=>$user,'record_id'=>$id],0);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('future_temporary_membership_expiry_failed','future_record',$id,'failure',['reason'=>$e->getMessage()],0);}
    }

    private static function assert_temp_eligibility_locked(int $space,int $user,int $actor): void {
        global $wpdb;$now=self::now();if(SN_Policy::is_suspended($user))throw new RuntimeException('blocked');
        $blocked=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('blocks').' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1 FOR UPDATE',$actor,$user,$user,$actor));if($blocked)throw new RuntimeException('blocked');
        $ban_table=$wpdb->prefix.'sn_space_bans';$banned=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ban_table} WHERE space_id=%d AND user_id=%d AND status='active' AND (expires_at IS NULL OR expires_at>%s) LIMIT 1 FOR UPDATE",$space,$user,$now));if($banned)throw new RuntimeException('space_banned');
        if(SN_Policy::age_state($user)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$user,$actor,'temporary_space_membership'))throw new RuntimeException('guardian');
        if(!(bool)apply_filters('sn_network_space_temporary_membership_allowed',true,$space,$user,$actor))throw new RuntimeException('policy');if(!(bool)apply_filters('sn_network_space_capacity_allows',true,$space,$user))throw new RuntimeException('capacity');
    }
    private static function space_manager_locked(int $space,int $user,object $space_row): bool {global $wpdb;if((int)$space_row->owner_user_id===$user||user_can($user,'manage_options'))return true;$role=(string)$wpdb->get_var($wpdb->prepare("SELECT role FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status='active' AND left_at IS NULL LIMIT 1 FOR UPDATE",$space,$user));return in_array($role,['owner','administrator','moderator'],true);}
    private static function mark_record_locked(object $record,string $state): void {global $wpdb;$changed=$wpdb->update(self::records_table(),['state'=>$state,'updated_at'=>self::now(),'version'=>(int)$record->version+1],['id'=>(int)$record->id,'version'=>(int)$record->version]);if($changed!==1)throw new RuntimeException('record_conflict');}
    private static function records_table(): string {global $wpdb;return $wpdb->prefix.'sn_future_records';}
    private static function decode(object $row):array|WP_Error{$plain=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($plain))return $plain;$d=json_decode($plain,true);return is_array($d)?$d:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}
    private static function encode(object $row,array $data):string|WP_Error{$json=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($json))return self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);return SN_Communication_Crypto::encrypt($json,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);}
    private static function future_time(string $value,int $max):string|WP_Error{$ts=strtotime($value);return(!$ts||$ts<=time()||$ts>time()+$max)?self::error('sn_future_time_invalid','Choose a valid future time within the permitted window.',400):gmdate('Y-m-d H:i:s',$ts);}
    private static function enum(string $value,array $allowed,string $default):string{$value=sanitize_key($value);return in_array($value,$allowed,true)?$value:$default;}
    private static function now():string{return current_time('mysql',true);}

    public static function create_mentorship(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$mentor=get_current_user_id();$student=absint($r->get_param('student_id'));$conversation=absint($r->get_param('conversation_id'));
        if($student<=0||$student===$mentor||!get_user_by('id',$student)||SN_Policy::is_suspended($student)||SN_Policy::is_suspended($mentor)||SN_DB::is_blocked($mentor,$student))return self::error('sn_mentorship_invalid','Select an eligible student.',400);
        if(!has_filter('sn_network_mentor_eligible')||!(bool)apply_filters('sn_network_mentor_eligible',false,$mentor,$student))return self::error('sn_mentor_eligibility_unavailable','Mentorship requires an approved mentor-eligibility assertion.',503);
        if($conversation&&(!SN_DB::is_member($conversation,$mentor)||!SN_DB::is_member($conversation,$student)))return self::error('sn_mentorship_conversation_invalid','Both accounts must be current conversation members.',409);
        if(SN_Policy::age_state($student)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$student,$mentor,'mentorship'))return self::error('sn_guardian_approval_required','Guardian approval is required.',403);
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $latest=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE feature_id='F17-FUT-14' AND owner_id=%d AND scope_type='user' AND scope_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE",$mentor,$student));
            $blocked=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('blocks').' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1 FOR UPDATE',$mentor,$student,$student,$mentor));if($blocked||SN_Policy::is_suspended($mentor)||SN_Policy::is_suspended($student))throw new RuntimeException('ineligible');
            if(!has_filter('sn_network_mentor_eligible')||!(bool)apply_filters('sn_network_mentor_eligible',false,$mentor,$student))throw new RuntimeException('mentor_unverified');
            if($conversation){$m1=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',$conversation,$mentor));$m2=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',$conversation,$student));if(!$m1||!$m2)throw new RuntimeException('conversation_changed');}
            if(SN_Policy::age_state($student)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$student,$mentor,'mentorship'))throw new RuntimeException('guardian');
            if($latest&&in_array((string)$latest->state,['pending','active'],true)){
                $existing=self::decode($latest);if(is_wp_error($existing))throw new RuntimeException('record_invalid');$same=(int)($existing['conversation_id']??0)===$conversation;
                if((string)$latest->state==='pending'&&$same){if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');return new WP_REST_Response(['id'=>(int)$latest->id,'status'=>'pending','idempotent'=>true],200);}
                throw new RuntimeException('existing_live');
            }
            $generation=$latest?(int)$latest->id:0;$at=self::now();$payload=['mentor_id'=>$mentor,'student_id'=>$student,'conversation_id'=>$conversation,'mode'=>'study','generation_after_id'=>$generation];$dummy=(object)['feature_id'=>'F17-FUT-14','owner_id'=>$mentor,'scope_type'=>'user','scope_id'=>$student];$cipher=self::encode($dummy,$payload);if(is_wp_error($cipher))throw new RuntimeException('record_encrypt_failed');$client_key=hash('sha256','mentorship-v2:'.$mentor.':'.$student.':after:'.$generation);
            $inserted=$wpdb->insert(self::records_table(),['feature_id'=>'F17-FUT-14','owner_id'=>$mentor,'scope_type'=>'user','scope_id'=>$student,'state'=>'pending','payload_cipher'=>$cipher,'client_key'=>$client_key,'expires_at'=>null,'created_at'=>$at,'updated_at'=>$at,'version'=>1]);if($inserted!==1)throw new RuntimeException('record_write_failed');$id=(int)$wpdb->insert_id;
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');SN_DB::audit('future_mentorship_created','future_record',$id,'success',['mentor_id'=>$mentor,'student_id'=>$student,'generation_after_id'=>$generation],$mentor);return new WP_REST_Response(['id'=>$id,'status'=>'pending'],201);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');$code=$e->getMessage();if($code==='mentor_unverified')return self::error('sn_mentor_eligibility_unavailable','Current mentor eligibility could not be verified.',503);if($code==='conversation_changed')return self::error('sn_mentorship_conversation_invalid','The linked conversation is no longer shared.',409);if($code==='guardian')return self::error('sn_guardian_approval_required','Current guardian approval is required.',403);if($code==='ineligible')return self::error('sn_mentorship_no_longer_eligible','Mentorship eligibility changed before creation.',409);if($code==='existing_live')return self::error('sn_mentorship_conflict','A pending or active mentorship already exists for this pair.',409);return self::error('sn_mentorship_conflict','Mentorship could not be created safely.',409);}
    }
    public static function decide_mentorship(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$student=get_current_user_id();$id=absint($r['id']);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE id=%d AND feature_id='F17-FUT-14' AND scope_id=%d AND state='pending' LIMIT 1",$id,$student));if(!$row)return self::not_found();$data=self::decode($row);if(is_wp_error($data))return $data;$mentor=absint($data['mentor_id']??$row->owner_id);$conversation=absint($data['conversation_id']??0);$decision=sanitize_key((string)$r->get_param('decision'));
        if($decision==='accept'){if(SN_Policy::is_suspended($mentor)||SN_Policy::is_suspended($student)||SN_DB::is_blocked($mentor,$student))return self::error('sn_mentorship_no_longer_eligible','Mentorship eligibility changed before acceptance.',409);if(!has_filter('sn_network_mentor_eligible')||!(bool)apply_filters('sn_network_mentor_eligible',false,$mentor,$student))return self::error('sn_mentor_eligibility_unavailable','Current mentor eligibility could not be verified.',503);if($conversation&&(!SN_DB::is_member($conversation,$mentor)||!SN_DB::is_member($conversation,$student)))return self::error('sn_mentorship_conversation_invalid','The linked conversation is no longer shared.',409);if(SN_Policy::age_state($student)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$student,$mentor,'mentorship_accept'))return self::error('sn_guardian_approval_required','Current guardian approval is required.',403);}
        return SN_Future_Superset::decide_mentorship($r);
    }
    public static function end_mentorship(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$actor=get_current_user_id();$id=absint($r['id']);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE id=%d AND feature_id='F17-FUT-14' AND state IN ('pending','active') LIMIT 1",$id));if(!$row)return self::not_found();$data=self::decode($row);if(is_wp_error($data))return $data;$mentor=absint($data['mentor_id']??$row->owner_id);$student=absint($data['student_id']??$row->scope_id);if(!in_array($actor,[$mentor,$student],true))return self::not_found();$ok=$wpdb->update($wpdb->prefix.'sn_future_records',['state'=>'ended','updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],['id'=>$id,'version'=>(int)$row->version]);if($ok!==1)return self::error('sn_mentorship_conflict','Mentorship changed before it could be ended.',409);SN_DB::audit('future_mentorship_ended','future_record',$id,'success',['mentor_id'=>$mentor,'student_id'=>$student],$actor);return rest_ensure_response(['id'=>$id,'state'=>'ended']);
    }
    private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $code,string $message,int $status):WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
}
