<?php
/** Review rounds 18+ — membership, mentorship, citations and clinical-discussion boundary hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_B {
    public static function register(): void { add_action('rest_api_init',[self::class,'routes'],1950); }
    public static function routes(): void {
        register_rest_route('sabri-network/v2','/future/temporary-memberships',['methods'=>'POST','callback'=>[self::class,'grant_temporary_membership'],'permission_callback'=>[SN_REST::class,'access']],true);
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
    private static function error(string $code,string $message,int $status):WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
}
