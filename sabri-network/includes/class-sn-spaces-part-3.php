<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_3 {
    public static function decide_join_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $space_id=absint($request['id']);$target=absint($request['user_id']);$actor=get_current_user_id();$decision=sanitize_key((string)$request->get_param('decision'));
        if(!in_array($decision,['accept','reject'],true))return self::error('sn_join_decision_invalid','Select accept or reject.',400);
        if(!self::can_manage($space_id,$actor,'members'))return self::error('sn_space_manage_forbidden','Membership management permission is required.',403);
        $wpdb->query('START TRANSACTION');
        try{
            $space=self::space($space_id,true);if(!$space)throw new RuntimeException('space_missing');
            $actor_access=self::assert_manage_locked($space_id,$actor,'members');if(is_wp_error($actor_access)){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Current membership management permission is required.',403);}
            $request_row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::requests_table()." WHERE space_id=%d AND requester_id=%d AND status='pending' ORDER BY id DESC LIMIT 1 FOR UPDATE",$space_id,$target));
            if(!$request_row){$wpdb->query('ROLLBACK');return self::error('sn_join_request_missing','The pending join request is unavailable.',404);}
            $elig=self::join_eligibility($space,$target);if(is_wp_error($elig)&&$decision==='accept'){$wpdb->query('ROLLBACK');return $elig;}
            $status=$decision==='accept'?'accepted':'rejected';$now=self::now();
            $changed=$wpdb->update(self::requests_table(),['status'=>$status,'active_key'=>null,'decided_by'=>$actor,'decided_at'=>$now,'updated_at'=>$now,'version'=>(int)$request_row->version+1],['id'=>(int)$request_row->id,'status'=>'pending','version'=>(int)$request_row->version]);
            if($changed!==1)throw new RuntimeException('join_request_conflict');
            if($decision==='accept')self::activate_member($space_id,$target,'member',$actor);
            $event=SN_Outbox::enqueue('space.join_request_'.$status,'space',$space_id,['space_id'=>$space_id,'user_id'=>$target,'decision'=>$status],'space.join_request:'.(int)$request_row->id.':'.$status);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            self::record($space_id,$actor,'join_request_'.$status,'user',$target,'',[]);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('join_decision_commit_failed');
            return rest_ensure_response(['status'=>$status]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_join_decision_failed','The join request decision could not be committed.',500);}
    }

    public static function create_invite(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $space_id=absint($request['id']);$actor=get_current_user_id();$invitee=absint($request->get_param('user_id'));
        if(!self::can_manage($space_id,$actor,'members'))return self::error('sn_space_manage_forbidden','Membership management permission is required.',403);
        if(!$invitee||$invitee===$actor)return self::error('sn_space_invitee_invalid','Select another valid account.',400);
        $contact=SN_Policy::can_contact($actor,$invitee,'group');if(is_wp_error($contact))return $contact;
        if(!SN_Policy::consume_rate_limit('space_invite',$actor.':'.$space_id,30,HOUR_IN_SECONDS))return self::error('sn_space_invite_rate_limited','Invitation sending is temporarily limited.',429);
        $space=self::space($space_id);if(!$space)return self::error('sn_space_not_found','The space is unavailable.',404);
        if(self::active_until((string)$space->invite_pause_until)||self::active_until((string)$space->anti_raid_until))return self::error('sn_space_invites_paused','Space invitations are temporarily paused.',409);
        if(self::is_banned($space_id,$invitee)||SN_Policy::is_suspended($invitee))return self::error('sn_space_invitee_unavailable','The invited account is unavailable.',403);
        $role=self::enum((string)$request->get_param('role'),['editor','member','observer'],'member');
        $raw=wp_generate_uuid4().'.'.wp_generate_password(32,false,false);$hash=hash_hmac('sha256',$raw,wp_salt('auth'));
        $now=self::now();$expires=gmdate('Y-m-d H:i:s',time()+min(30*DAY_IN_SECONDS,max(HOUR_IN_SECONDS,absint($request->get_param('ttl'))?:7*DAY_IN_SECONDS)));
        $wpdb->query('START TRANSACTION');
        try{
            $space=self::space($space_id,true);if(!$space)throw new RuntimeException('space_missing');
            $actor_access=self::assert_manage_locked($space_id,$actor,'members');if(is_wp_error($actor_access)){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Current membership management permission is required.',403);}
            $elig=self::join_eligibility($space,$invitee,true);if(is_wp_error($elig)){$wpdb->query('ROLLBACK');return $elig;}
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::invites_table()." WHERE space_id=%d AND invitee_id=%d AND status='pending' ORDER BY id DESC LIMIT 1 FOR UPDATE",$space_id,$invitee));
            if($old)$wpdb->update(self::invites_table(),['status'=>'cancelled','active_key'=>null,'cancelled_at'=>$now,'updated_at'=>$now,'version'=>(int)$old->version+1],['id'=>(int)$old->id,'status'=>'pending','version'=>(int)$old->version]);
            if($wpdb->insert(self::invites_table(),['invite_uuid'=>wp_generate_uuid4(),'space_id'=>$space_id,'inviter_id'=>$actor,'invitee_id'=>$invitee,'active_key'=>hash('sha256',$space_id.':'.$invitee),'role'=>$role,'status'=>'pending','token_hash'=>$hash,'expires_at'=>$expires,'created_at'=>$now,'updated_at'=>$now])===false)throw new RuntimeException('invite_insert_failed');
            $id=(int)$wpdb->insert_id;self::record($space_id,$actor,'invite_created','invite',$id,'',['invitee_id'=>$invitee,'role'=>$role]);
            $event=SN_Outbox::enqueue('space.invitation_created','space',$space_id,['space_id'=>$space_id,'invite_id'=>$id,'invitee_id'=>$invitee,'expires_at'=>$expires],'space.invitation_created:'.$id);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('invite_commit_failed');
            return new WP_REST_Response(['invite_id'=>$id,'invite_token'=>$raw,'expires_at'=>$expires],201);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_invite_failed','The invitation could not be created.',500);}
    }
}
