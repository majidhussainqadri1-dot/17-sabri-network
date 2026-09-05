<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_8 {
    private static function activate_member(int $space_id,int $user,string $role,int $approved_by): void {
        global $wpdb;$now=self::now();$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::members_table().' WHERE space_id=%d AND user_id=%d FOR UPDATE',$space_id,$user));
        if($row){$changed=$wpdb->update(self::members_table(),['role'=>$role,'status'=>'active','approved_by'=>$approved_by,'joined_at'=>$now,'left_at'=>null,'updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'version'=>(int)$row->version]);if($changed!==1)throw new RuntimeException('membership_activation_conflict');}
        else{if($wpdb->insert(self::members_table(),['space_id'=>$space_id,'user_id'=>$user,'role'=>$role,'status'=>'active','approved_by'=>$approved_by,'joined_at'=>$now,'created_at'=>$now,'updated_at'=>$now])===false)throw new RuntimeException('membership_insert_failed');}
        $space=self::space($space_id);if($space&&(int)$space->conversation_id>0)self::sync_conversation_member((int)$space->conversation_id,$user,$role,$approved_by,$now);
    }

    private static function create_space_conversation(int $space_id,string $type,string $name,string $slug,int $owner,string $now): int {
        global $wpdb;if($type==='community')return 0;$conversation_type=$type==='channel'?'channel':'group';
        $ok=$wpdb->insert(SN_DB::table('conversations'),['type'=>$conversation_type,'title'=>$name,'slug'=>'space-'.$space_id.'-'.$slug,'direct_key'=>null,'owner_id'=>$owner,'parent_id'=>$space_id,'description'=>'','avatar_id'=>0,'privacy'=>'private','status'=>'active','settings'=>(string)wp_json_encode(['space_id'=>$space_id,'space_type'=>$type]),'last_message_id'=>0,'created_at'=>$now,'updated_at'=>$now]);
        if($ok===false)throw new RuntimeException('space_conversation_insert_failed');$conversation_id=(int)$wpdb->insert_id;
        if($wpdb->insert(SN_DB::table('members'),['conversation_id'=>$conversation_id,'user_id'=>$owner,'role'=>'owner','last_read_message_id'=>0,'is_muted'=>0,'is_archived'=>0,'joined_at'=>$now,'left_at'=>null])===false)throw new RuntimeException('space_conversation_owner_failed');return$conversation_id;
    }

    private static function sync_conversation_member(int $conversation_id,int $user,string $space_role,int $approved_by,string $now): void {
        global $wpdb;$role=in_array($space_role,['owner'],true)?'owner':(in_array($space_role,['administrator','moderator','editor'],true)?'moderator':'member');$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d FOR UPDATE',$conversation_id,$user));
        if($row){$changed=$wpdb->update(SN_DB::table('members'),['role'=>$role,'left_at'=>null,'joined_at'=>$now],['id'=>(int)$row->id]);if($changed===false)throw new RuntimeException('conversation_member_sync_failed');}
        else{if($wpdb->insert(SN_DB::table('members'),['conversation_id'=>$conversation_id,'user_id'=>$user,'role'=>$role,'last_read_message_id'=>0,'is_muted'=>0,'is_archived'=>0,'joined_at'=>$now,'left_at'=>null])===false)throw new RuntimeException('conversation_member_insert_failed');}
    }

    private static function remove_conversation_member(int $conversation_id,int $user,string $now): void {global $wpdb;if($wpdb->update(SN_DB::table('members'),['left_at'=>$now],['conversation_id'=>$conversation_id,'user_id'=>$user,'left_at'=>null])===false)throw new RuntimeException('conversation_member_remove_failed');}

    private static function sync_conversation_status(int $conversation_id,string $space_state,string $now): void {global $wpdb;if($conversation_id<=0)return;$status=in_array($space_state,['active','restricted','locked'],true)?'active':(in_array($space_state,['archived'],true)?'archived':'closed');if($wpdb->update(SN_DB::table('conversations'),['status'=>$status,'updated_at'=>$now],['id'=>$conversation_id])===false)throw new RuntimeException('conversation_status_sync_failed');}

    private static function can_create(int $user): bool {return current_user_can('manage_options')||(bool)apply_filters('sn_network_user_can_create_space',false,$user);}

    private static function can_manage(int $space_id,int $user,string $scope): bool {$m=self::member($space_id,$user);if(!$m)return false;$allowed=match($scope){'settings','lifecycle','audit'=>['owner','administrator'],'members'=>['owner','administrator'],'moderation'=>['owner','administrator','moderator'],default=>['owner']};return in_array((string)$m->role,$allowed,true);}

    private static function can_manage_target(string $actor_role,string $target_role): bool {return isset(self::ROLE_RANK[$actor_role],self::ROLE_RANK[$target_role])&&self::ROLE_RANK[$actor_role]>self::ROLE_RANK[$target_role]&&$target_role!=='owner';}

    private static function can_view(object $space,int $viewer): bool {if(self::member((int)$space->id,$viewer))return true;if(in_array((string)$space->state,['closed','deletion_requested'],true))return false;return in_array((string)$space->visibility,['public','discoverable_private'],true);}

    private static function can_see_existence(object $space,int $viewer): bool {
        if (self::can_view($space,$viewer)) return true;
        return (string)$space->visibility === 'invite_only' && self::has_pending_invite((int)$space->id,$viewer);
    }

    private static function has_pending_invite(int $space_id,int $viewer): bool {global $wpdb;$now=self::now();return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".self::invites_table()." WHERE space_id=%d AND invitee_id=%d AND status='pending' AND expires_at>%s LIMIT 1",$space_id,$viewer,$now));}

    private static function is_banned(int $space_id,int $user): bool {global $wpdb;$now=self::now();return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".self::bans_table()." WHERE space_id=%d AND user_id=%d AND status='active' AND (expires_at IS NULL OR expires_at>%s) LIMIT 1",$space_id,$user,$now));}

    /** Authoritative ban read for positive membership transitions; DB failure is retryable, never equivalent to no ban. */
    private static function is_banned_strict(int $space_id,int $user): bool|WP_Error {
        global $wpdb;$now=self::now();$wpdb->last_error='';
        $value=$wpdb->get_var($wpdb->prepare("SELECT id FROM ".self::bans_table()." WHERE space_id=%d AND user_id=%d AND status='active' AND (expires_at IS NULL OR expires_at>%s) LIMIT 1",$space_id,$user,$now));
        if($wpdb->last_error!==''||($value!==null&&!is_numeric($value)))return self::error('sn_space_ban_state_unavailable','Current space ban state could not be verified.',503);
        return $value!==null;
    }

    private static function member_count(int $space_id): int {global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::members_table()." WHERE space_id=%d AND status='active'",$space_id));}

    /** Authoritative capacity read for positive membership transitions; DB failure must fail closed. */
    private static function member_count_strict(int $space_id): int|WP_Error {
        global $wpdb;$wpdb->last_error='';
        $value=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::members_table()." WHERE space_id=%d AND status='active'",$space_id));
        if($wpdb->last_error!==''||$value===null||!is_numeric($value))return self::error('sn_space_capacity_state_unavailable','Current space capacity could not be verified.',503);
        return max(0,(int)$value);
    }

    private static function member(int $space_id,int $user,bool $lock=false): ?object {global $wpdb;$sql=$wpdb->prepare("SELECT * FROM ".self::members_table()." WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1".($lock?' FOR UPDATE':''),$space_id,$user);return $wpdb->get_row($sql)?:null;}

    private static function space(int $id,bool $lock=false): ?object {global $wpdb;$sql=$wpdb->prepare('SELECT * FROM '.self::spaces_table().' WHERE id=%d'.($lock?' FOR UPDATE':''),$id);return $wpdb->get_row($sql)?:null;}

    private static function space_by_conversation(int $conversation_id,bool $lock=false): ?object {global $wpdb;$sql=$wpdb->prepare('SELECT * FROM '.self::spaces_table().' WHERE conversation_id=%d'.($lock?' FOR UPDATE':''),$conversation_id);return$wpdb->get_row($sql)?:null;}
}
