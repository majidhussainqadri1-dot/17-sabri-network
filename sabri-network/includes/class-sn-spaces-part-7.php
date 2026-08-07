<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_7 {
    public static function can_post(int $space_id,int $user_id): bool|WP_Error {
        $space=self::space($space_id);$member=self::member($space_id,$user_id);if(!$space||!$member)return self::error('sn_space_membership_required','An active space membership is required.',403);return self::can_post_locked($space,$member);
    }

    private static function can_post_locked(object $space,object $member): bool|WP_Error {
        if(in_array((string)$space->state,['locked','archived','closed','deletion_requested'],true))return self::error('sn_space_read_only','This space is read-only.',409);
        if((string)$space->state==='restricted'&&!in_array((string)$member->role,['owner','administrator','moderator'],true))return self::error('sn_space_restricted_mode','Only space managers may post while restricted mode is active.',403);
        if(self::active_until((string)$space->anti_raid_until)&&!in_array((string)$member->role,['owner','administrator','moderator'],true))return self::error('sn_space_anti_raid_active','Posting is temporarily restricted during anti-raid mode.',409);
        if((string)$member->role==='observer')return self::error('sn_space_observer_read_only','Observers cannot post.',403);
        if((string)$space->posting_policy==='disabled')return self::error('sn_space_posting_disabled','Posting is disabled.',403);
        if((string)$space->posting_policy==='roles'&&!in_array((string)$member->role,['owner','administrator','moderator','editor'],true))return self::error('sn_space_publisher_role_required','A publishing role is required.',403);
        if((string)$space->posting_policy==='approved'&&!in_array((string)$member->role,['owner','administrator','moderator','editor'],true))return self::error('sn_space_post_approval_required','Posts require moderator approval.',403);
        $joined=strtotime((string)$member->joined_at.' UTC');if((int)$space->new_member_delay_seconds>0&&time()<$joined+(int)$space->new_member_delay_seconds)return self::error('sn_space_new_member_delay','New-member posting delay is active.',429);
        if((int)$space->slow_mode_seconds>0&&$member->last_post_at&&time()<strtotime((string)$member->last_post_at.' UTC')+(int)$space->slow_mode_seconds)return self::error('sn_space_slow_mode','Slow mode is active.',429);
        return true;
    }

    public static function mark_posted(int $space_id,int $user_id): void {global $wpdb;$wpdb->update(self::members_table(),['last_post_at'=>self::now(),'updated_at'=>self::now()],['space_id'=>$space_id,'user_id'=>$user_id,'status'=>'active']);}

    public static function cleanup(): void {
        global $wpdb;$now=self::now();
        $wpdb->query($wpdb->prepare("UPDATE ".self::invites_table()." SET status='expired',active_key=NULL,updated_at=%s,version=version+1 WHERE status='pending' AND expires_at<=%s LIMIT 500",$now,$now));
        $wpdb->query($wpdb->prepare("UPDATE ".self::bans_table()." SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at IS NOT NULL AND expires_at<=%s LIMIT 500",$now,$now));
    }

    public static function register_exporter(array $exporters): array {$exporters['sabri-network-spaces']=['exporter_friendly_name'=>__('Network spaces','sabri-network'),'callback'=>[self::class,'export_data']];return $exporters;}

    public static function register_eraser(array $erasers): array {$erasers['sabri-network-spaces']=['eraser_friendly_name'=>__('Network space memberships','sabri-network'),'callback'=>[self::class,'erase_data']];return $erasers;}

    public static function export_data(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return ['data'=>[],'done'=>true];$limit=100;$offset=max(0,$page-1)*$limit;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT m.space_id,m.role,m.status,m.joined_at,m.left_at,s.name,s.type,s.visibility,s.state FROM '.self::members_table().' m INNER JOIN '.self::spaces_table().' s ON s.id=m.space_id WHERE m.user_id=%d ORDER BY m.id ASC LIMIT %d OFFSET %d',(int)$user->ID,$limit,$offset));$data=[];
        foreach(is_array($rows)?$rows:[] as $r)$data[]=['group_id'=>'sabri-network-spaces','group_label'=>__('Network spaces','sabri-network'),'item_id'=>'space-membership-'.(int)$r->space_id,'data'=>[['name'=>__('Space','sabri-network'),'value'=>(string)$r->name],['name'=>__('Type','sabri-network'),'value'=>(string)$r->type],['name'=>__('Role','sabri-network'),'value'=>(string)$r->role],['name'=>__('Membership state','sabri-network'),'value'=>(string)$r->status],['name'=>__('Joined','sabri-network'),'value'=>(string)$r->joined_at],['name'=>__('Left','sabri-network'),'value'=>(string)$r->left_at]]];
        return ['data'=>$data,'done'=>count($rows)<$limit];
    }

    public static function erase_data(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;$owners=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::members_table()." WHERE user_id=%d AND role='owner' AND status='active'",$uid));
        if($owners>0)return ['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Active space ownership must be transferred before erasure.','sabri-network')],'done'=>true];
        $now=self::now();$changed=$wpdb->query($wpdb->prepare("UPDATE ".self::members_table()." SET status='left',left_at=COALESCE(left_at,%s),updated_at=%s,version=version+1 WHERE user_id=%d AND status='active' LIMIT 500",$now,$now,$uid));
        $wpdb->query($wpdb->prepare("UPDATE ".self::invites_table()." SET status='cancelled',active_key=NULL,cancelled_at=COALESCE(cancelled_at,%s),updated_at=%s,version=version+1 WHERE (invitee_id=%d OR inviter_id=%d) AND status='pending' LIMIT 500",$now,$now,$uid,$uid));
        $wpdb->query($wpdb->prepare("UPDATE ".self::requests_table()." SET status='cancelled',active_key=NULL,updated_at=%s,version=version+1 WHERE requester_id=%d AND status='pending' LIMIT 500",$now,$uid));
        return ['items_removed'=>$changed>0,'items_retained'=>false,'messages'=>[],'done'=>true];
    }

    private static function join_eligibility(?object $space,int $user,bool $invited=false): bool|WP_Error {
        if(!$space)return self::error('sn_space_not_found','The space is unavailable.',404);
        if(!in_array((string)$space->state,['active','restricted'],true))return self::error('sn_space_not_joinable','This space is not accepting memberships.',409);
        if(self::active_until((string)$space->anti_raid_until)&&!$invited)return self::error('sn_space_anti_raid_join_pause','New joins are temporarily paused.',409);
        if(self::is_banned((int)$space->id,$user)||SN_Policy::is_suspended($user))return self::error('sn_space_member_unavailable','This account cannot join the space.',403);
        if(self::member((int)$space->id,$user))return self::error('sn_space_already_member','The account is already an active member.',409);
        if(SN_Policy::requires_protective_age_defaults($user)&&!(bool)apply_filters('sn_network_minor_space_allowed',false,$user,$space))return self::error('sn_space_minor_restricted','This space is not approved for the account age context.',403);
        $count=self::member_count((int)$space->id);if($count>=(int)$space->member_limit)return self::error('sn_space_capacity_reached','The space member limit has been reached.',409);
        return true;
    }
}
