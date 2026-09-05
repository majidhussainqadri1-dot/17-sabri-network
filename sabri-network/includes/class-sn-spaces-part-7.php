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
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;
        $retry=static fn(string $message):array=>['items_removed'=>false,'items_retained'=>true,'messages'=>[__($message,'sabri-network')],'done'=>false];

        $wpdb->last_error='';
        $owners_raw=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::members_table()." WHERE user_id=%d AND role='owner' AND status='active'",$uid));
        if($wpdb->last_error!==''||$owners_raw===null||!is_numeric($owners_raw))return $retry('Space ownership state could not be verified and erasure must be retried.');
        $owners=(int)$owners_raw;
        if($owners>0)return ['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Active space ownership must be transferred before erasure.','sabri-network')],'done'=>true];

        $limit=100;$now=self::now();$wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT m.id,m.space_id,m.version,s.conversation_id FROM '.self::members_table().' m INNER JOIN '.self::spaces_table().' s ON s.id=m.space_id WHERE m.user_id=%d AND m.status=\'active\' ORDER BY m.id ASC LIMIT %d',$uid,$limit));
        if($wpdb->last_error!==''||!is_array($rows))return $retry('Space membership state could not be read and erasure must be retried.');
        if($wpdb->query('START TRANSACTION')===false)return $retry('Space privacy erasure could not start and must be retried.');
        $removed=false;
        try{
            foreach($rows as $row){
                $changed=$wpdb->update(self::members_table(),['status'=>'left','left_at'=>$now,'updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'user_id'=>$uid,'status'=>'active','version'=>(int)$row->version]);
                if($changed!==1)throw new RuntimeException('space_privacy_membership_conflict');
                if((int)$row->conversation_id>0)self::remove_conversation_member((int)$row->conversation_id,$uid,$now);
                $removed=true;
            }
            $q=$wpdb->query($wpdb->prepare("UPDATE ".self::invites_table()." SET status='cancelled',active_key=NULL,cancelled_at=COALESCE(cancelled_at,%s),updated_at=%s,version=version+1 WHERE (invitee_id=%d OR inviter_id=%d) AND status='pending' LIMIT 500",$now,$now,$uid,$uid));if($q===false)throw new RuntimeException('space_privacy_invites_failed');
            $q=$wpdb->query($wpdb->prepare("UPDATE ".self::requests_table()." SET status='cancelled',active_key=NULL,updated_at=%s,version=version+1 WHERE requester_id=%d AND status='pending' LIMIT 500",$now,$uid));if($q===false)throw new RuntimeException('space_privacy_requests_failed');
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_privacy_commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('space_privacy_erase_failed','user',$uid,'failure',['reason'=>$e->getMessage()],$uid);return $retry('Space privacy erasure could not be committed and must be retried.');}

        $wpdb->last_error='';$more_members_raw=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM ".self::members_table()." WHERE user_id=%d AND status='active' LIMIT 1",$uid));$probe_error=$wpdb->last_error!=='';
        $wpdb->last_error='';$more_invites_raw=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM ".self::invites_table()." WHERE (invitee_id=%d OR inviter_id=%d) AND status='pending' LIMIT 1",$uid,$uid));$probe_error=$probe_error||$wpdb->last_error!=='';
        $wpdb->last_error='';$more_requests_raw=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM ".self::requests_table()." WHERE requester_id=%d AND status='pending' LIMIT 1",$uid));$probe_error=$probe_error||$wpdb->last_error!=='';
        if($probe_error)return ['items_removed'=>$removed,'items_retained'=>true,'messages'=>[__('Space privacy completion could not be verified and must be retried.','sabri-network')],'done'=>false];
        $more_members=$more_members_raw!==null;$more_invites=$more_invites_raw!==null;$more_requests=$more_requests_raw!==null;
        return ['items_removed'=>$removed,'items_retained'=>$more_members||$more_invites||$more_requests,'messages'=>[],'done'=>!$more_members&&!$more_invites&&!$more_requests];
    }

    /** Revalidate the target against File-00 at the actual space mutation boundary. */
    private static function communication_eligible(int $user): bool|WP_Error {
        if($user<=0||!get_user_by('id',$user))return self::error('sn_space_member_invalid','The target account is unavailable.',404);
        SN_Membership_Assertions::clear_cache($user);
        $assertion=SN_Membership_Assertions::communication($user);
        if(is_wp_error($assertion)){
            $data=$assertion->get_error_data();$status=is_array($data)?(int)($data['status']??503):503;
            return self::error('sn_space_identity_unavailable','Current File-00 communication eligibility could not be verified.',$status>=500?503:403);
        }
        if(($assertion['eligible']??false)!==true||($assertion['can_message']??false)!==true||($assertion['suspended']??true)===true){
            return self::error('sn_space_member_ineligible','The target account is not currently eligible for File-17 communication.',403);
        }
        return true;
    }

    private static function join_eligibility(?object $space,int $user,bool $invited=false): bool|WP_Error {
        if(!$space)return self::error('sn_space_not_found','The space is unavailable.',404);
        $identity=self::communication_eligible($user);if(is_wp_error($identity))return $identity;
        if(!in_array((string)$space->state,['active','restricted'],true))return self::error('sn_space_not_joinable','This space is not accepting memberships.',409);
        if(self::active_until((string)$space->anti_raid_until)&&!$invited)return self::error('sn_space_anti_raid_join_pause','New joins are temporarily paused.',409);
        $banned=self::is_banned_strict((int)$space->id,$user);if(is_wp_error($banned))return $banned;if($banned||SN_Policy::is_suspended($user))return self::error('sn_space_member_unavailable','This account cannot join the space.',403);
        if(self::member((int)$space->id,$user))return self::error('sn_space_already_member','The account is already an active member.',409);
        if(SN_Policy::requires_protective_age_defaults($user)&&!(bool)apply_filters('sn_network_minor_space_allowed',false,$user,$space))return self::error('sn_space_minor_restricted','This space is not approved for the account age context.',403);
        $count=self::member_count_strict((int)$space->id);if(is_wp_error($count))return $count;if($count>=(int)$space->member_limit)return self::error('sn_space_capacity_reached','The space member limit has been reached.',409);
        return true;
    }
}
