<?php
/** Review rounds 18–19 — temporary membership and mentorship boundary hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_B {
    public static function register(): void { add_action('rest_api_init',[self::class,'routes'],1950); }
    public static function routes(): void {
        register_rest_route('sabri-network/v2','/future/temporary-memberships',['methods'=>'POST','callback'=>[self::class,'grant_temporary_membership'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/mentorships',['methods'=>'POST','callback'=>[self::class,'create_mentorship'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/mentorships/(?P<id>\d+)',['methods'=>'POST','callback'=>[self::class,'decide_mentorship'],'permission_callback'=>[SN_REST::class,'access']],true);
        register_rest_route('sabri-network/v2','/future/mentorships/(?P<id>\d+)/end',['methods'=>'POST','callback'=>[self::class,'end_mentorship'],'permission_callback'=>[SN_REST::class,'access']]);
    }
    public static function grant_temporary_membership(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$actor=get_current_user_id();$user=absint($r->get_param('user_id'));$space=absint($r->get_param('space_id'));
        if($user<=0||$space<=0||!get_user_by('id',$user)||SN_Policy::is_suspended($user))return self::error('sn_temp_member_invalid','Select an eligible active account.',400);
        if(SN_DB::is_blocked($actor,$user))return self::error('sn_temp_member_blocked','Temporary membership is unavailable for this relationship.',403);
        $ban=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status IN ('banned','blocked') LIMIT 1",$space,$user));if($ban)return self::error('sn_temp_member_space_banned','The account is not eligible to join this space.',403);
        if(SN_Policy::age_state($user)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$user,$actor,'temporary_space_membership'))return self::error('sn_guardian_approval_required','Guardian approval is required for temporary membership.',403);
        if(!(bool)apply_filters('sn_network_space_temporary_membership_allowed',true,$space,$user,$actor))return self::error('sn_temp_member_policy_denied','Temporary membership is not permitted by current space policy.',403);
        return SN_Future_Superset::grant_temporary_membership($r);
    }
    public static function create_mentorship(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $mentor=get_current_user_id();$student=absint($r->get_param('student_id'));$conversation=absint($r->get_param('conversation_id'));
        if($student<=0||$student===$mentor||!get_user_by('id',$student)||SN_Policy::is_suspended($student)||SN_Policy::is_suspended($mentor)||SN_DB::is_blocked($mentor,$student))return self::error('sn_mentorship_invalid','Select an eligible student.',400);
        if(!has_filter('sn_network_mentor_eligible')||!(bool)apply_filters('sn_network_mentor_eligible',false,$mentor,$student))return self::error('sn_mentor_eligibility_unavailable','Mentorship requires an approved mentor-eligibility assertion.',503);
        if($conversation&&(!SN_DB::is_member($conversation,$mentor)||!SN_DB::is_member($conversation,$student)))return self::error('sn_mentorship_conversation_invalid','Both accounts must be current conversation members.',409);
        if(SN_Policy::age_state($student)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$student,$mentor,'mentorship'))return self::error('sn_guardian_approval_required','Guardian approval is required.',403);
        return SN_Future_Superset::create_mentorship($r);
    }
    public static function decide_mentorship(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$student=get_current_user_id();$id=absint($r['id']);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE id=%d AND feature_id='F17-FUT-14' AND scope_id=%d AND state='pending' LIMIT 1",$id,$student));if(!$row)return self::not_found();$data=self::decode($row);if(is_wp_error($data))return $data;$mentor=absint($data['mentor_id']??$row->owner_id);$conversation=absint($data['conversation_id']??0);$decision=sanitize_key((string)$r->get_param('decision'));
        if($decision==='accept'){
            if(SN_Policy::is_suspended($mentor)||SN_Policy::is_suspended($student)||SN_DB::is_blocked($mentor,$student))return self::error('sn_mentorship_no_longer_eligible','Mentorship eligibility changed before acceptance.',409);
            if(!has_filter('sn_network_mentor_eligible')||!(bool)apply_filters('sn_network_mentor_eligible',false,$mentor,$student))return self::error('sn_mentor_eligibility_unavailable','Current mentor eligibility could not be verified.',503);
            if($conversation&&(!SN_DB::is_member($conversation,$mentor)||!SN_DB::is_member($conversation,$student)))return self::error('sn_mentorship_conversation_invalid','The linked conversation is no longer shared.',409);
            if(SN_Policy::age_state($student)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$student,$mentor,'mentorship_accept'))return self::error('sn_guardian_approval_required','Current guardian approval is required.',403);
        }
        return SN_Future_Superset::decide_mentorship($r);
    }
    public static function end_mentorship(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$actor=get_current_user_id();$id=absint($r['id']);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE id=%d AND feature_id='F17-FUT-14' AND state IN ('pending','active') LIMIT 1",$id));if(!$row)return self::not_found();$data=self::decode($row);if(is_wp_error($data))return $data;$mentor=absint($data['mentor_id']??$row->owner_id);$student=absint($data['student_id']??$row->scope_id);if(!in_array($actor,[$mentor,$student],true))return self::not_found();$ok=$wpdb->update($wpdb->prefix.'sn_future_records',['state'=>'ended','updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],['id'=>$id,'version'=>(int)$row->version]);if($ok!==1)return self::error('sn_mentorship_conflict','Mentorship changed before it could be ended.',409);SN_DB::audit('future_mentorship_ended','future_record',$id,'success',['mentor_id'=>$mentor,'student_id'=>$student],$actor);return rest_ensure_response(['id'=>$id,'state'=>'ended']);
    }
    private static function decode(object $row):array|WP_Error{$plain=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($plain))return $plain;$d=json_decode($plain,true);return is_array($d)?$d:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}
    private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $code,string $message,int $status):WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
}
