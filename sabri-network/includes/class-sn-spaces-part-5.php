<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_5 {
    public static function change_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $space_id=absint($request['id']);$target=absint($request['user_id']);$actor=get_current_user_id();$action=sanitize_key((string)$request->get_param('action'))?:'role';$now=self::now();
        if(!in_array($action,['role','remove'],true))return self::error('sn_space_member_action_invalid','Select role or remove.',400);
        if(!self::can_manage($space_id,$actor,'members'))return self::error('sn_space_manage_forbidden','Membership management permission is required.',403);
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $space=self::space($space_id,true);$actor_member=self::member($space_id,$actor,true);$target_member=self::member($space_id,$target,true);
            if(!$space||!$actor_member||!$target_member){$wpdb->query('ROLLBACK');return self::error('sn_space_membership_missing','The membership is unavailable.',404);}
            if(!self::can_manage_target((string)$actor_member->role,(string)$target_member->role)){$wpdb->query('ROLLBACK');return self::error('sn_space_hierarchy_forbidden','This role hierarchy change is not permitted.',403);}
            if($action==='remove'){
                $changed=$wpdb->update(self::members_table(),['status'=>'removed','left_at'=>$now,'updated_at'=>$now,'version'=>(int)$target_member->version+1],['id'=>(int)$target_member->id,'status'=>'active','version'=>(int)$target_member->version]);
                if($changed!==1)throw new RuntimeException('space_member_conflict');
                if((int)$space->conversation_id>0)self::remove_conversation_member((int)$space->conversation_id,$target,$now);
                self::record($space_id,$actor,'member_removed','user',$target,self::text((string)$request->get_param('reason'),500),[]);
                $event=SN_Outbox::enqueue('space.member_removed','space',$space_id,['space_id'=>$space_id,'user_id'=>$target],'space.member_removed:'.$space_id.':'.$target.':'.$now);
                if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_member_remove_commit_failed');
                return rest_ensure_response(['status'=>'removed']);
            }
            $raw_role=sanitize_key((string)$request->get_param('role'));if(!in_array($raw_role,self::ROLES,true)){$wpdb->query('ROLLBACK');return self::error('sn_space_role_invalid','Select a valid member role.',400);}$role=$raw_role;
            if($role==='owner'){$wpdb->query('ROLLBACK');return self::error('sn_space_owner_transfer_required','Use the protected ownership transfer workflow.',409);}
            if(self::ROLE_RANK[$role]>=self::ROLE_RANK[(string)$actor_member->role]){$wpdb->query('ROLLBACK');return self::error('sn_space_role_escalation_forbidden','A manager cannot assign an equal or higher role.',403);}
            $changed=$wpdb->update(self::members_table(),['role'=>$role,'updated_at'=>$now,'version'=>(int)$target_member->version+1],['id'=>(int)$target_member->id,'status'=>'active','version'=>(int)$target_member->version]);
            if($changed!==1)throw new RuntimeException('space_member_conflict');
            if((int)$space->conversation_id>0)self::sync_conversation_member((int)$space->conversation_id,$target,$role,$actor,$now);
            self::record($space_id,$actor,'member_role_changed','user',$target,'',['role'=>$role]);
            $event=SN_Outbox::enqueue('space.member_role_changed','space',$space_id,['space_id'=>$space_id,'user_id'=>$target,'role'=>$role],'space.member_role_changed:'.$space_id.':'.$target.':'.$target_member->version.':'.$role);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_member_role_commit_failed');
            return rest_ensure_response(['status'=>'active','role'=>$role]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_member_change_failed','The membership change could not be committed atomically.',500);}
    }

    public static function change_ban(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $space_id=absint($request['id']);$actor=get_current_user_id();$target=absint($request->get_param('user_id'));$action=sanitize_key((string)$request->get_param('action'))?:'ban';
        if(!in_array($action,['ban','unban'],true))return self::error('sn_space_ban_action_invalid','Select ban or unban.',400);
        if(!self::can_manage($space_id,$actor,'moderation'))return self::error('sn_space_moderation_forbidden','Moderation permission is required.',403);
        if(!$target||$target===$actor)return self::error('sn_space_ban_target_invalid','Select another valid member.',400);
        $actor_member=self::member($space_id,$actor);$target_member=self::member($space_id,$target);
        if($target_member&&!self::can_manage_target((string)$actor_member->role,(string)$target_member->role))return self::error('sn_space_hierarchy_forbidden','This role cannot be banned by the current actor.',403);
        $now=self::now();$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::bans_table().' WHERE space_id=%d AND user_id=%d',$space_id,$target));
        if($action==='unban'){
            if(!$existing|| (string)$existing->status!=='active')return rest_ensure_response(['status'=>'inactive']);
            if($wpdb->query('START TRANSACTION')===false)return self::error('sn_space_unban_failed','The unban transaction could not start.',500);
            try{
                $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::bans_table().' WHERE id=%d FOR UPDATE',(int)$existing->id));
                if(!$locked||(string)$locked->status!=='active'){if($wpdb->query('COMMIT')===false)throw new RuntimeException('unban_read_commit_failed');return rest_ensure_response(['status'=>'inactive']);}
                $changed=$wpdb->update(self::bans_table(),['status'=>'revoked','updated_at'=>$now,'version'=>(int)$locked->version+1],['id'=>(int)$locked->id,'status'=>'active','version'=>(int)$locked->version]);
                if($changed!==1)throw new RuntimeException('unban_conflict');
                self::record($space_id,$actor,'member_unbanned','user',$target,self::text((string)$request->get_param('reason'),500),[]);
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('unban_commit_failed');
                return rest_ensure_response(['status'=>'revoked']);
            }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_unban_failed','The unban could not be committed atomically.',500);}
        }
        $expiry=self::future_or_null((string)$request->get_param('expires_at'),365*DAY_IN_SECONDS);if(is_wp_error($expiry))return $expiry;
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $data=['status'=>'active','reason'=>self::text((string)$request->get_param('reason'),500),'banned_by'=>$actor,'expires_at'=>$expiry,'updated_at'=>$now];
            if($existing){$data['version']=(int)$existing->version+1;$ok=$wpdb->update(self::bans_table(),$data,['id'=>(int)$existing->id,'version'=>(int)$existing->version]);if($ok!==1)throw new RuntimeException('ban_conflict');}
            else{$data+=['space_id'=>$space_id,'user_id'=>$target,'created_at'=>$now];if($wpdb->insert(self::bans_table(),$data)===false)throw new RuntimeException('ban_insert_failed');}
            if($target_member){$member_changed=$wpdb->update(self::members_table(),['status'=>'removed','left_at'=>$now,'updated_at'=>$now,'version'=>(int)$target_member->version+1],['id'=>(int)$target_member->id,'status'=>'active','version'=>(int)$target_member->version]);if($member_changed!==1)throw new RuntimeException('ban_member_conflict');}
            $space=self::space($space_id);if($space&&(int)$space->conversation_id>0)self::remove_conversation_member((int)$space->conversation_id,$target,$now);
            self::record($space_id,$actor,'member_banned','user',$target,(string)$data['reason'],['expires_at'=>$expiry]);
            $event=SN_Outbox::enqueue('space.member_banned','space',$space_id,['space_id'=>$space_id,'user_id'=>$target,'expires_at'=>$expiry],'space.member_banned:'.$space_id.':'.$target.':'.$now);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('ban_commit_failed');
            return rest_ensure_response(['status'=>'active','expires_at'=>$expiry]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_ban_failed','The ban could not be committed.',500);}
    }
}
