<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_6 {
    public static function change_lifecycle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id=absint($request['id']);$actor=get_current_user_id();$next=self::enum((string)$request->get_param('state'),self::STATES,'');
        if($next==='')return self::error('sn_space_state_invalid','Select a valid lifecycle state.',400);
        $expected=absint($request->get_param('version'));$transitions=['active'=>['restricted','locked','archived','closed','deletion_requested'],'restricted'=>['active','locked','archived','closed'],'locked'=>['active','restricted','archived','closed'],'archived'=>['active','closed'],'closed'=>['active','deletion_requested'],'deletion_requested'=>[]];$now=self::now();
        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_space_transaction_failed','The space change could not start safely.',500);
        try{
            $space=self::space($id,true);
            if(!$space||!self::can_manage($id,$actor,'lifecycle')){$wpdb->query('ROLLBACK');return self::error('sn_space_lifecycle_forbidden','Lifecycle permission is required.',403);}
            if(!in_array($next,$transitions[(string)$space->state]??[],true)){$wpdb->query('ROLLBACK');return self::error('sn_space_transition_invalid','This lifecycle transition is not allowed.',409);}
            if($expected!==(int)$space->version){$wpdb->query('ROLLBACK');return self::error('sn_space_version_conflict','The space changed. Reload and retry.',409);}
            $data=['state'=>$next,'locked_reason'=>self::text((string)$request->get_param('reason'),500),'updated_at'=>$now,'version'=>$expected+1];
            if($next==='archived')$data['archived_at']=$now;if($next==='closed')$data['closed_at']=$now;if($next==='deletion_requested')$data['deletion_requested_at']=$now;
            $changed=$wpdb->update(self::spaces_table(),$data,['id'=>$id,'version'=>$expected,'state'=>(string)$space->state]);
            if($changed!==1)throw new RuntimeException('space_lifecycle_conflict');
            self::sync_conversation_status((int)$space->conversation_id,$next,$now);
            self::record($id,$actor,'space_state_'.$next,'space',$id,(string)$data['locked_reason'],[]);
            $event=SN_Outbox::enqueue('space.lifecycle_changed','space',$id,['space_id'=>$id,'state'=>$next,'version'=>$expected+1],'space.lifecycle_changed:'.$id.':'.($expected+1));
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_lifecycle_commit_failed');
            return rest_ensure_response(['state'=>$next,'version'=>$expected+1]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_lifecycle_failed','The lifecycle change could not be committed atomically.',500);}
    }

    public static function transfer_owner(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $space_id=absint($request['id']);$actor=get_current_user_id();$target=absint($request->get_param('user_id'));$action_id=absint($request->get_param('high_risk_action_id'));
        $space=self::space($space_id);if(!$space)return self::error('sn_space_not_found','The space is unavailable.',404);
        if(!current_user_can('manage_options')&&!(bool)apply_filters('sn_network_can_execute_space_transfer',false,$actor,$space))return self::error('sn_space_transfer_executor_forbidden','A distinct authorized executor is required.',403);
        $identity=self::communication_eligible($target);if(is_wp_error($identity))return $identity;
        $current_owner=(int)$space->owner_user_id;
        $target_member=self::member($space_id,$target);if(!$target_member||!in_array((string)$target_member->role,['administrator','moderator','editor','member'],true))return self::error('sn_space_successor_invalid','Select an eligible active successor.',400);
        $payload=['space_id'=>$space_id,'current_owner_id'=>$current_owner,'new_owner_id'=>$target];
        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_space_transaction_failed','The space change could not start safely.',500);
        try{
            $space=self::space($space_id,true);if(!$space||(int)$space->owner_user_id!==$current_owner)throw new RuntimeException('owner_changed');
            // Refresh target identity after the space row is locked so ownership is
            // never transferred on a stale pre-transaction File-00 assertion.
            $identity=self::communication_eligible($target);if(is_wp_error($identity)){$wpdb->query('ROLLBACK');return $identity;}
            $claim=SN_High_Risk::claim($action_id,$actor,'space_ownership_transfer',$payload);
            if(is_wp_error($claim)){$wpdb->query('ROLLBACK');return $claim;}
            $owner_member=self::member($space_id,$current_owner,true);$target_member=self::member($space_id,$target,true);
            if(!$owner_member||!$target_member)throw new RuntimeException('membership_missing');
            $now=self::now();
            if($wpdb->update(self::members_table(),['role'=>'administrator','updated_at'=>$now,'version'=>(int)$owner_member->version+1],['id'=>(int)$owner_member->id,'role'=>'owner','version'=>(int)$owner_member->version])!==1)throw new RuntimeException('owner_role_change_failed');
            if($wpdb->update(self::members_table(),['role'=>'owner','updated_at'=>$now,'version'=>(int)$target_member->version+1],['id'=>(int)$target_member->id,'version'=>(int)$target_member->version])!==1)throw new RuntimeException('successor_role_change_failed');
            if($wpdb->update(self::spaces_table(),['owner_user_id'=>$target,'updated_at'=>$now,'version'=>(int)$space->version+1],['id'=>$space_id,'owner_user_id'=>$current_owner,'version'=>(int)$space->version])!==1)throw new RuntimeException('space_owner_change_failed');
            if((int)$space->conversation_id>0){
                $conversation=$wpdb->get_row($wpdb->prepare('SELECT id,owner_id FROM '.SN_DB::table('conversations').' WHERE id=%d FOR UPDATE',(int)$space->conversation_id));
                if(!$conversation)throw new RuntimeException('space_conversation_missing');
                self::sync_conversation_member((int)$space->conversation_id,$current_owner,'administrator',$actor,$now);
                self::sync_conversation_member((int)$space->conversation_id,$target,'owner',$actor,$now);
                if($wpdb->update(SN_DB::table('conversations'),['owner_id'=>$target,'updated_at'=>$now],['id'=>(int)$space->conversation_id])===false)throw new RuntimeException('space_conversation_owner_sync_failed');
            }
            $event=SN_Outbox::enqueue('space.ownership_transferred','space',$space_id,['space_id'=>$space_id,'former_owner_id'=>$current_owner,'new_owner_id'=>$target],'space.ownership_transferred:'.$space_id.':'.$target.':'.$now);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            $completed=SN_High_Risk::complete($action_id,$actor,(string)$claim['claim_token'],['space_id'=>$space_id,'new_owner_id'=>$target]);if(is_wp_error($completed))throw new RuntimeException($completed->get_error_code());
            self::record($space_id,$actor,'ownership_transferred','user',$target,'',[]);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('transfer_commit_failed');
            return rest_ensure_response(['owner_user_id'=>$target]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_transfer_failed','The ownership transfer could not be committed.',500);}
    }

    public static function governance_log(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();
        if(!self::can_manage($id,$actor,'audit'))return self::error('sn_space_audit_forbidden','Governance audit permission is required.',403);
        $limit=max(1,min(100,absint($request->get_param('limit'))?:50));$after=absint($request->get_param('after'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,actor_id,action,target_type,target_id,reason,scope_hash,created_at FROM '.self::audit_table().' WHERE space_id=%d AND id>%d ORDER BY id ASC LIMIT %d',$id,$after,$limit));
        return rest_ensure_response(['items'=>is_array($rows)?$rows:[]]);
    }

    public static function can_post_for_conversation(int $conversation_id,int $user_id): bool|WP_Error {
        $space=self::space_by_conversation($conversation_id,false);return$space?self::can_post((int)$space->id,$user_id):true;
    }

    public static function assert_post_allowed_in_transaction(int $conversation_id,int $user_id): bool|WP_Error {
        $space=self::space_by_conversation($conversation_id,true);if(!$space)return true;$member=self::member((int)$space->id,$user_id,true);if(!$member)return self::error('sn_space_membership_required','An active space membership is required.',403);return self::can_post_locked($space,$member);
    }

    public static function mark_posted_for_conversation(int $conversation_id,int $user_id,string $now): void {
        global $wpdb;$space=self::space_by_conversation($conversation_id,false);if(!$space)return;$changed=$wpdb->update(self::members_table(),['last_post_at'=>$now,'updated_at'=>$now],['space_id'=>(int)$space->id,'user_id'=>$user_id,'status'=>'active']);if($changed===false)throw new RuntimeException('space_post_timestamp_failed');
    }
}
