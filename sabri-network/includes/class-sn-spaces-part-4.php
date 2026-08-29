<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_4 {
    public static function decide_invite(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id=absint($request['id']);$actor=get_current_user_id();$decision=sanitize_key((string)$request->get_param('decision'));
        if(!in_array($decision,['accept','reject','cancel'],true))return self::error('sn_invite_decision_invalid','Select accept, reject or cancel.',400);
        if($wpdb->query('START TRANSACTION')===false)return self::error('sn_invite_decision_failed','The invitation transaction could not start safely.',500);
        try{
            $invite=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::invites_table().' WHERE id=%d FOR UPDATE',$id));
            if(!$invite||(string)$invite->status!=='pending'){ $wpdb->query('ROLLBACK');return self::error('sn_invite_missing','The pending invitation is unavailable.',404);}
            if($decision==='cancel'){
                if((int)$invite->inviter_id!==$actor){
                    $space=self::space((int)$invite->space_id,true);
                    $manager=$space?self::assert_manage_locked((int)$invite->space_id,$actor,'members'):self::error('sn_space_not_found','The space is unavailable.',404);
                    if(is_wp_error($manager)){$wpdb->query('ROLLBACK');return self::error('sn_invite_cancel_forbidden','Only the inviter or a space manager may cancel.',403);}
                }
                $status='cancelled';
            }else{
                if((int)$invite->invitee_id!==$actor){$wpdb->query('ROLLBACK');return self::error('sn_invite_recipient_required','Only the invited recipient may accept or reject.',403);}
                $status=$decision==='accept'?'accepted':'rejected';
            }
            if(strtotime((string)$invite->expires_at.' UTC')<=time()){
                $expired=$wpdb->update(self::invites_table(),['status'=>'expired','active_key'=>null,'updated_at'=>self::now(),'version'=>(int)$invite->version+1],['id'=>$id,'status'=>'pending','version'=>(int)$invite->version]);
                if($expired!==1)throw new RuntimeException('invite_expiry_conflict');
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('invite_expiry_commit_failed');
                return self::error('sn_invite_expired','The invitation expired.',410);
            }
            if($decision==='accept'){
                $contact=SN_Policy::can_contact((int)$invite->inviter_id,$actor,'group');if(is_wp_error($contact)){$wpdb->query('ROLLBACK');return $contact;}
                $space=self::space((int)$invite->space_id,true);$elig=self::join_eligibility($space,$actor,true);if(is_wp_error($elig)){$wpdb->query('ROLLBACK');return $elig;}
                self::activate_member((int)$invite->space_id,$actor,(string)$invite->role,(int)$invite->inviter_id);
            }
            $now=self::now();$changed=$wpdb->update(self::invites_table(),['status'=>$status,'active_key'=>null,'decided_at'=>$decision==='cancel'?null:$now,'cancelled_at'=>$decision==='cancel'?$now:null,'updated_at'=>$now,'version'=>(int)$invite->version+1],['id'=>$id,'status'=>'pending','version'=>(int)$invite->version]);
            if($changed!==1)throw new RuntimeException('invite_conflict');
            self::record((int)$invite->space_id,$actor,'invite_'.$status,'invite',$id,'',[]);
            $event=SN_Outbox::enqueue('space.invitation_'.$status,'space',(int)$invite->space_id,['space_id'=>(int)$invite->space_id,'invite_id'=>$id,'user_id'=>(int)$invite->invitee_id,'status'=>$status],'space.invitation:'.$id.':'.$status);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('invite_decision_commit_failed');
            return rest_ensure_response(['status'=>$status]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_invite_decision_failed','The invitation decision could not be committed.',500);}
    }

    public static function leave_space(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $space_id=absint($request['id']);$user=get_current_user_id();$now=self::now();
        if($wpdb->query('START TRANSACTION')===false)return self::error('sn_space_leave_failed','The membership transaction could not start safely.',500);
        try{
            $space=self::space($space_id,true);$member=self::member($space_id,$user,true);
            if(!$space||!$member){$wpdb->query('ROLLBACK');return self::error('sn_space_membership_missing','No active membership exists.',404);}
            if((string)$member->role==='owner'){$wpdb->query('ROLLBACK');return self::error('sn_space_owner_successor_required','Transfer ownership before leaving.',409);}
            $changed=$wpdb->update(self::members_table(),['status'=>'left','left_at'=>$now,'updated_at'=>$now,'version'=>(int)$member->version+1],['id'=>(int)$member->id,'status'=>'active','version'=>(int)$member->version]);
            if($changed!==1)throw new RuntimeException('space_leave_conflict');
            if((int)$space->conversation_id>0)self::remove_conversation_member((int)$space->conversation_id,$user,$now);
            self::record($space_id,$user,'member_left','user',$user,'',[]);
            $event=SN_Outbox::enqueue('space.member_left','space',$space_id,['space_id'=>$space_id,'user_id'=>$user],'space.member_left:'.$space_id.':'.$user.':'.$now);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_leave_commit_failed');
            return rest_ensure_response(['status'=>'left']);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_leave_failed','The membership could not be closed atomically.',500);}
    }
}
