<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Corrective owner-boundary layer for relationship mutations.
 * Every externally reachable relationship transition is serialized, revalidated
 * after the lock is held, commit-checked, then confirmed from canonical state
 * before notifications or success are emitted.
 */
final class SN_Relationship_Runtime_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 1750);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/contacts', [
            ['methods' => 'GET', 'callback' => [SN_REST::class, 'get_contacts'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'request_contact'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/contacts/(?P<id>\d+)', [
            'methods' => 'POST', 'callback' => [self::class, 'decide_contact'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/block', [
            'methods' => 'POST', 'callback' => [self::class, 'block_user'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations', [
            ['methods' => 'GET', 'callback' => [SN_REST::class, 'get_conversations'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_conversation'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/members', [
            ['methods' => 'POST', 'callback' => [self::class, 'add_member'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'remove_member'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/owner', [
            'methods' => 'POST', 'callback' => [self::class, 'transfer_owner'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function request_contact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $target = absint($request->get_param('user_id'));
        if ($target <= 0 || $target === $actor || !get_user_by('id', $target)) return new WP_Error('invalid_contact', 'Select a valid Network member.', ['status' => 400]);
        if (!SN_Policy::consume_rate_limit('contact_request', (string) $actor, 20, DAY_IN_SECONDS)) return self::rate_limited();
        return self::with_locks([SN_Relationships::pair_lock_name($actor, $target)], function () use ($actor, $target, $wpdb) {
            $policy = SN_Policy::can_contact($actor, $target, 'request');
            if (is_wp_error($policy)) return $policy;
            $table = SN_DB::table('contacts'); $pair = SN_DB::contact_pair_key($actor, $target); $now = current_time('mysql', true);
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE pair_key=%s FOR UPDATE", $pair));
                if ($row && in_array((string) $row->status, ['accepted','pending','blocked'], true)) {
                    if ($wpdb->query('COMMIT') === false) throw new RuntimeException('contact_read_commit_failed');
                    return rest_ensure_response(['request_id'=>(int)$row->id,'status'=>(string)$row->status,'duplicate'=>true]);
                }
                $data = ['user_id'=>min($actor,$target),'contact_user_id'=>max($actor,$target),'pair_key'=>$pair,'requested_by'=>$actor,'status'=>'pending','created_at'=>$row?(string)$row->created_at:$now,'updated_at'=>$now];
                if ($row) {
                    $written = $wpdb->query($wpdb->prepare("UPDATE $table SET user_id=%d,contact_user_id=%d,requested_by=%d,status='pending',updated_at=%s WHERE id=%d AND status=%s", min($actor,$target),max($actor,$target),$actor,$now,(int)$row->id,(string)$row->status));
                    if ($written !== 1) throw new RuntimeException('contact_request_conflict');
                    $id=(int)$row->id;
                } else {
                    if ($wpdb->insert($table,$data) === false) throw new RuntimeException('contact_insert_failed');
                    $id=(int)$wpdb->insert_id;
                }
                if ($wpdb->query('COMMIT') === false) {
                    $fresh=SN_DB::contact_record($actor,$target);
                    if (!$fresh || (string)$fresh->status!=='pending' || (int)$fresh->requested_by!==$actor) throw new RuntimeException('contact_commit_failed');
                    $id=(int)$fresh->id;
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                $fresh=SN_DB::contact_record($actor,$target);
                if ($fresh && (string)$fresh->status==='pending' && (int)$fresh->requested_by===$actor) return rest_ensure_response(['request_id'=>(int)$fresh->id,'status'=>'pending','duplicate'=>true,'commit_reconciled'=>true]);
                return $e->getMessage()==='contact_request_conflict' ? new WP_Error('contact_request_conflict','This contact relationship changed before the request was saved.',['status'=>409]) : self::database_error();
            }
            SN_DB::add_notification($target,'contact_request','New contact request','','contact',$id);
            SN_DB::audit('contact_requested','contact',$id,'success',['target_id'=>$target],$actor);
            return rest_ensure_response(['request_id'=>$id,'status'=>'pending']);
        });
    }

    public static function decide_contact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id=absint($request['id']); $actor=get_current_user_id(); $table=SN_DB::table('contacts');
        $probe=$wpdb->get_row($wpdb->prepare("SELECT user_id,contact_user_id FROM $table WHERE id=%d",$id));
        if (!$probe) return self::not_found();
        $other=(int)$probe->user_id===$actor?(int)$probe->contact_user_id:(int)$probe->user_id;
        if ($other<=0) return self::not_found();
        return self::with_locks([SN_Relationships::pair_lock_name($actor,$other)], function () use ($request,$id,$actor,$other,$table,$wpdb) {
            $decision=sanitize_key((string)$request->get_param('decision'));
            if (!in_array($decision,['accept','decline'],true)) return new WP_Error('invalid_decision','Choose accept or decline.',['status'=>400]);
            if ($wpdb->query('START TRANSACTION')===false) return self::database_error();
            try {
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id));
                if (!$row || (string)$row->status!=='pending' || (int)$row->requested_by===$actor || !in_array($actor,[(int)$row->user_id,(int)$row->contact_user_id],true)) throw new DomainException('not_found');
                $requester=(int)$row->requested_by;
                if ($decision==='accept') {
                    $policy=SN_Policy::can_contact($requester,$actor,'request');
                    if (is_wp_error($policy)) { $wpdb->query('ROLLBACK'); return $policy; }
                }
                $status=$decision==='accept'?'accepted':'declined'; $now=current_time('mysql',true);
                $updated=$wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,updated_at=%s WHERE id=%d AND status='pending'",$status,$now,$id));
                if ($updated!==1) throw new RuntimeException('contact_decision_conflict');
                if ($wpdb->query('COMMIT')===false) {
                    $fresh=SN_DB::contact_record($actor,$other);
                    if (!$fresh || (int)$fresh->id!==$id || (string)$fresh->status!==$status) throw new RuntimeException('contact_decision_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                if ($e instanceof DomainException) return self::not_found();
                return new WP_Error('contact_decision_conflict','This contact request changed before the decision was saved.',['status'=>409]);
            }
            SN_DB::add_notification($requester,'contact_'.$status,$status==='accepted'?'Contact request accepted':'Contact request declined','','contact',$id);
            SN_DB::audit('contact_'.$status,'contact',$id,'success',[],$actor);
            return rest_ensure_response(['request_id'=>$id,'status'=>$status]);
        });
    }

    public static function block_user(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor=get_current_user_id(); $target=absint($request->get_param('user_id'));
        if ($target<=0 || $target===$actor || !get_user_by('id',$target)) return new WP_Error('invalid_user','Select a valid user.',['status'=>400]);
        $raw=$request->get_param('blocked'); $blocked=$raw===null?true:filter_var($raw,FILTER_VALIDATE_BOOLEAN); $lock=SN_Relationships::pair_lock_name($actor,$target);
        return self::with_locks([$lock], function () use ($actor,$target,$blocked,$wpdb) {
            $now=current_time('mysql',true); $blocks=SN_DB::table('blocks'); $contacts=SN_DB::table('contacts'); $follows=SN_DB::table('follows');
            if ($wpdb->query('START TRANSACTION')===false) return self::database_error();
            try {
                $contact=$wpdb->get_row($wpdb->prepare("SELECT * FROM $contacts WHERE pair_key=%s FOR UPDATE",SN_DB::contact_pair_key($actor,$target)));
                $wpdb->get_results($wpdb->prepare("SELECT id FROM $follows WHERE (follower_id=%d AND followed_id=%d) OR (follower_id=%d AND followed_id=%d) FOR UPDATE",$actor,$target,$target,$actor));
                if ($blocked) {
                    $insert=$wpdb->query($wpdb->prepare("INSERT INTO $blocks (user_id,blocked_user_id,created_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE created_at=VALUES(created_at)",$actor,$target,$now));
                    if ($insert===false) throw new RuntimeException('block_write_failed');
                    if ($contact && $wpdb->query($wpdb->prepare("UPDATE $contacts SET status='blocked',updated_at=%s WHERE id=%d",$now,(int)$contact->id))===false) throw new RuntimeException('contact_block_failed');
                    if ($wpdb->query($wpdb->prepare("UPDATE $follows SET status='inactive',updated_at=%s,decided_at=%s,version=version+1 WHERE ((follower_id=%d AND followed_id=%d) OR (follower_id=%d AND followed_id=%d)) AND status IN ('active','pending')",$now,$now,$actor,$target,$target,$actor))===false) throw new RuntimeException('follow_block_cleanup_failed');
                    $direct=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('conversations')." WHERE type='direct' AND direct_key=%s FOR UPDATE",SN_DB::direct_key($actor,$target)));
                    if ($direct) self::end_active_calls_locked((int)$direct->id,$now);
                } else {
                    if ($wpdb->delete($blocks,['user_id'=>$actor,'blocked_user_id'=>$target],['%d','%d'])===false) throw new RuntimeException('unblock_write_failed');
                    if ($contact && (string)$contact->status==='blocked' && $wpdb->query($wpdb->prepare("UPDATE $contacts SET status='declined',updated_at=%s WHERE id=%d AND status='blocked'",$now,(int)$contact->id))===false) throw new RuntimeException('contact_unblock_failed');
                }
                if ($wpdb->query('COMMIT')===false) {
                    $own=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $blocks WHERE user_id=%d AND blocked_user_id=%d",$actor,$target));
                    if ($own!==$blocked) throw new RuntimeException('block_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK'); return self::database_error();
            }
            SN_DB::audit($blocked?'user_blocked':'user_unblocked','user',$target,'success',[],$actor);
            return rest_ensure_response(['blocked'=>$blocked]);
        });
    }

    public static function create_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor=get_current_user_id(); $type=sanitize_key((string)$request->get_param('type'))?:'direct';
        if (!SN_Policy::can_create_conversation($actor,$type)) return new WP_Error('conversation_type_forbidden','You cannot create this conversation type.',['status'=>403]);
        if (!SN_Policy::consume_rate_limit('conversation_create',(string)$actor,30,HOUR_IN_SECONDS)) return self::rate_limited();
        $members=array_values(array_unique(array_filter(array_map('absint',(array)$request->get_param('member_ids'))))); $members=array_values(array_diff($members,[$actor]));
        if ($type==='direct') {
            $target=absint($request->get_param('user_id'))?:($members[0]??0); if ($target<=0) return new WP_Error('invalid_members','Select a valid Network member.',['status'=>400]); $members=[$target];
        } else {
            $limit=max(2,(int)apply_filters('sn_network_group_member_limit',256,$type)); if (count($members)<1 || count($members)+1>$limit) return new WP_Error('invalid_members','Select a permitted number of members.',['status'=>400]);
        }
        $locks=[]; foreach ($members as $member) $locks[]=SN_Relationships::pair_lock_name($actor,$member); $locks[]='sn:f17:conversation-create:'.substr(hash('sha256',(string)$actor),0,32);
        return self::with_locks($locks,function () use ($request,$actor,$type,$members,$wpdb) {
            foreach ($members as $target) { $policy=SN_Policy::can_contact($actor,$target,$type==='direct'?'message':'group'); if (is_wp_error($policy)) return $policy; }
            $title=mb_substr(sanitize_text_field((string)$request->get_param('title')),0,191); if ($type!=='direct' && $title==='') return new WP_Error('title_required','A title is required.',['status'=>400]);
            $description=mb_substr(sanitize_textarea_field((string)$request->get_param('description')),0,2000); $privacy=$type==='direct'?'private':sanitize_key((string)$request->get_param('privacy')); if (!in_array($privacy,['private','invite'],true)) $privacy='private';
            $now=current_time('mysql',true); $conversations=SN_DB::table('conversations'); $memberTable=SN_DB::table('members'); $directKey=$type==='direct'?SN_DB::direct_key($actor,$members[0]):null; $id=0;
            if ($wpdb->query('START TRANSACTION')===false) return self::database_error();
            try {
                $existing=$directKey?$wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE direct_key=%s FOR UPDATE",$directKey)):null;
                if ($existing) {
                    $id=(int)$existing->id; if ($wpdb->query($wpdb->prepare("UPDATE $conversations SET status='active',updated_at=%s WHERE id=%d",$now,$id))===false) throw new RuntimeException('conversation_restore_failed');
                    foreach ([$actor,$members[0]] as $memberId) {
                        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $memberTable WHERE conversation_id=%d AND user_id=%d FOR UPDATE",$id,$memberId)); $role=(int)$existing->owner_id===$memberId?'owner':'member';
                        $ok=$row?$wpdb->query($wpdb->prepare("UPDATE $memberTable SET role=%s,left_at=NULL,joined_at=%s WHERE id=%d",$role,$now,(int)$row->id)):$wpdb->insert($memberTable,['conversation_id'=>$id,'user_id'=>$memberId,'role'=>$role,'joined_at'=>$now]); if ($ok===false) throw new RuntimeException('member_restore_failed');
                    }
                } else {
                    if ($wpdb->insert($conversations,['type'=>$type,'title'=>$title,'slug'=>$type==='direct'?'':sanitize_title($title.'-'.wp_generate_uuid4()),'direct_key'=>$directKey,'owner_id'=>$actor,'description'=>$description,'privacy'=>$privacy,'status'=>'active','settings'=>'{}','created_at'=>$now,'updated_at'=>$now])===false) throw new RuntimeException('conversation_insert_failed');
                    $id=(int)$wpdb->insert_id; foreach (array_values(array_unique(array_merge([$actor],$members))) as $memberId) if ($wpdb->insert($memberTable,['conversation_id'=>$id,'user_id'=>$memberId,'role'=>$memberId===$actor?'owner':'member','joined_at'=>$now])===false) throw new RuntimeException('member_insert_failed');
                }
                if ($wpdb->query('COMMIT')===false) {
                    $fresh=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE id=%d",$id)); if (!$fresh || (string)$fresh->status!=='active') throw new RuntimeException('conversation_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                if ($directKey) { $race=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE direct_key=%s",$directKey)); if ($race && (string)$race->status==='active') return self::conversation_response((int)$race->id,true,true); }
                return self::database_error();
            }
            foreach ($members as $memberId) SN_DB::add_notification($memberId,'conversation_invite','New Network conversation','','conversation',$id);
            SN_DB::audit('conversation_created','conversation',$id,'success',['type'=>$type,'members'=>count($members)+1],$actor);
            return self::conversation_response($id,(bool)$existing,(bool)$existing);
        });
    }

    public static function add_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $conversation=absint($request['id']); $actor=get_current_user_id(); $target=absint($request->get_param('user_id'));
        if ($target<=0 || $target===$actor || !get_user_by('id',$target)) return new WP_Error('invalid_member','Select a valid Network member.',['status'=>400]);
        return self::with_locks([self::conversation_lock($conversation),SN_Relationships::pair_lock_name($actor,$target)],function () use ($request,$conversation,$actor,$target,$wpdb) {
            $policy=SN_Policy::can_contact($actor,$target,'group'); if (is_wp_error($policy)) return $policy; $conversations=SN_DB::table('conversations'); $members=SN_DB::table('members'); $now=current_time('mysql',true);
            if ($wpdb->query('START TRANSACTION')===false) return self::database_error();
            try {
                $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE id=%d FOR UPDATE",$conversation)); if (!$c || (string)$c->type==='direct' || (string)$c->status!=='active') throw new DomainException('invalid_conversation');
                $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM $members WHERE conversation_id=%d AND user_id=%d FOR UPDATE",$conversation,$actor)); if (!$a || $a->left_at!==null || !in_array((string)$a->role,['owner','moderator'],true)) throw new UnexpectedValueException('forbidden');
                $limit=max(2,(int)apply_filters('sn_network_group_member_limit',256,(string)$c->type)); $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $members WHERE conversation_id=%d AND left_at IS NULL",$conversation)); if ($count>=$limit) throw new OverflowException('member_limit_reached');
                $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $members WHERE conversation_id=%d AND user_id=%d FOR UPDATE",$conversation,$target)); $role=sanitize_key((string)$request->get_param('role')); if (!in_array($role,['member','moderator'],true)) $role='member'; if ($role==='moderator' && (string)$a->role!=='owner') throw new UnexpectedValueException('forbidden');
                $ok=$existing?$wpdb->query($wpdb->prepare("UPDATE $members SET role=%s,left_at=NULL,joined_at=%s WHERE id=%d",$role,$now,(int)$existing->id)):$wpdb->insert($members,['conversation_id'=>$conversation,'user_id'=>$target,'role'=>$role,'joined_at'=>$now]); if ($ok===false) throw new RuntimeException('member_write_failed');
                if ($wpdb->query('COMMIT')===false) { $fresh=$wpdb->get_row($wpdb->prepare("SELECT role,left_at FROM $members WHERE conversation_id=%d AND user_id=%d",$conversation,$target)); if (!$fresh || $fresh->left_at!==null || (string)$fresh->role!==$role) throw new RuntimeException('member_commit_failed'); }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK'); return match($e->getMessage()){'invalid_conversation'=>new WP_Error('invalid_conversation','Members cannot be added to this conversation.',['status'=>400]),'forbidden'=>new WP_Error('forbidden','Only an authorized conversation owner or moderator may add this member.',['status'=>403]),'member_limit_reached'=>new WP_Error('member_limit_reached','This conversation has reached its member limit.',['status'=>409]),default=>self::database_error()};
            }
            SN_DB::add_notification($target,'conversation_invite','Added to a Network conversation','','conversation',$conversation); SN_DB::audit('member_added','conversation',$conversation,'success',['target_id'=>$target,'role'=>$role],$actor); return rest_ensure_response(['added'=>true,'role'=>$role]);
        });
    }

    public static function remove_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $conversation=absint($request['id']); $actor=get_current_user_id(); $target=absint($request->get_param('user_id'))?:$actor;
        return self::with_locks([self::conversation_lock($conversation)],function () use ($conversation,$actor,$target,$wpdb) {
            $conversations=SN_DB::table('conversations'); $members=SN_DB::table('members'); $now=current_time('mysql',true); if ($wpdb->query('START TRANSACTION')===false) return self::database_error();
            try {
                $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE id=%d FOR UPDATE",$conversation)); if (!$c || (string)$c->type==='direct' || (string)$c->status!=='active') throw new DomainException('invalid_conversation');
                $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $members WHERE conversation_id=%d AND user_id IN (%d,%d) FOR UPDATE",$conversation,$actor,$target)); $map=[]; foreach($rows as $row) if($row->left_at===null)$map[(int)$row->user_id]=$row; $a=$map[$actor]??null; $t=$map[$target]??null; if(!$a||!$t)throw new DomainException('not_found'); $self=$actor===$target;
                if(!$self && !in_array((string)$a->role,['owner','moderator'],true))throw new UnexpectedValueException('forbidden'); if((string)$t->role==='owner')throw new LogicException('owner_removal_forbidden'); if(!$self && (string)$t->role==='moderator' && (string)$a->role!=='owner')throw new UnexpectedValueException('moderator_removal_forbidden');
                $changed=$wpdb->query($wpdb->prepare("UPDATE $members SET left_at=%s,is_muted=0,is_archived=0 WHERE id=%d AND left_at IS NULL",$now,(int)$t->id)); if($changed!==1)throw new RuntimeException('member_leave_failed'); self::revoke_member_calls_locked($conversation,$target,$now);
                if($wpdb->delete(SN_DB::table('typing'),['conversation_id'=>$conversation,'user_id'=>$target],['%d','%d'])===false)throw new RuntimeException('typing_cleanup_failed');
                if($wpdb->query('COMMIT')===false){$fresh=$wpdb->get_row($wpdb->prepare("SELECT left_at FROM $members WHERE id=%d",(int)$t->id));if(!$fresh||$fresh->left_at===null)throw new RuntimeException('member_remove_commit_failed');}
            } catch(Throwable $e){$wpdb->query('ROLLBACK');return match($e->getMessage()){'invalid_conversation'=>new WP_Error('invalid_conversation','Members cannot be removed from this conversation.',['status'=>400]),'not_found'=>self::not_found(),'forbidden'=>new WP_Error('forbidden','You cannot remove this member.',['status'=>403]),'owner_removal_forbidden'=>new WP_Error('owner_removal_forbidden','Transfer ownership before the owner leaves or is removed.',['status'=>409]),'moderator_removal_forbidden'=>new WP_Error('moderator_removal_forbidden','Only the conversation owner may remove a moderator.',['status'=>403]),default=>self::database_error()};}
            SN_DB::audit('member_removed','conversation',$conversation,'success',['target_id'=>$target],$actor);return rest_ensure_response(['removed'=>true]);
        });
    }

    public static function transfer_owner(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $conversation=absint($request['id']); $actor=get_current_user_id(); $target=absint($request->get_param('user_id'));
        if($target<=0||$target===$actor||!get_user_by('id',$target)||SN_Policy::is_suspended($target)||!SN_Policy::has_verified_adult_age($target))return new WP_Error('owner_ineligible','Select an active adult conversation member.',['status'=>403]);
        return self::with_locks([self::conversation_lock($conversation)],function()use($conversation,$actor,$target,$wpdb){$conversations=SN_DB::table('conversations');$members=SN_DB::table('members');$now=current_time('mysql',true);if($wpdb->query('START TRANSACTION')===false)return self::database_error();try{$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE id=%d FOR UPDATE",$conversation));if(!$c||(string)$c->type==='direct'||(string)$c->status!=='active')throw new DomainException('invalid_conversation');$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $members WHERE conversation_id=%d AND user_id IN (%d,%d) FOR UPDATE",$conversation,$actor,$target));$map=[];foreach($rows as $row)if($row->left_at===null)$map[(int)$row->user_id]=$row;if(!isset($map[$actor])||(string)$map[$actor]->role!=='owner')throw new UnexpectedValueException('forbidden');if(!isset($map[$target]))throw new DomainException('member_required');if(SN_Policy::is_suspended($target)||!SN_Policy::has_verified_adult_age($target))throw new UnexpectedValueException('owner_ineligible');if($wpdb->query($wpdb->prepare("UPDATE $members SET role='moderator' WHERE id=%d AND role='owner'",(int)$map[$actor]->id))!==1)throw new RuntimeException('old_owner_update_failed');if($wpdb->query($wpdb->prepare("UPDATE $members SET role='owner' WHERE id=%d",(int)$map[$target]->id))!==1)throw new RuntimeException('new_owner_update_failed');if($wpdb->query($wpdb->prepare("UPDATE $conversations SET owner_id=%d,updated_at=%s WHERE id=%d AND owner_id=%d",$target,$now,$conversation,$actor))!==1)throw new RuntimeException('conversation_owner_update_failed');if($wpdb->query('COMMIT')===false){$fresh=$wpdb->get_var($wpdb->prepare("SELECT owner_id FROM $conversations WHERE id=%d",$conversation));if((int)$fresh!==$target)throw new RuntimeException('owner_commit_failed');}}catch(Throwable $e){$wpdb->query('ROLLBACK');return match($e->getMessage()){'invalid_conversation'=>new WP_Error('invalid_conversation','Ownership cannot be transferred for this conversation.',['status'=>400]),'forbidden'=>new WP_Error('forbidden','Only the current conversation owner may transfer ownership.',['status'=>403]),'member_required'=>new WP_Error('member_required','The new owner must be an active conversation member.',['status'=>409]),'owner_ineligible'=>new WP_Error('owner_ineligible','The selected member must remain an active verified adult.',['status'=>403]),default=>self::database_error()};}SN_DB::add_notification($target,'conversation_owner','Conversation ownership transferred to you','','conversation',$conversation);SN_DB::audit('conversation_owner_transferred','conversation',$conversation,'success',['from'=>$actor,'to'=>$target],$actor);return self::conversation_response($conversation,false,false);});
    }

    private static function conversation_response(int $id,bool $existing=false,bool $restored=false): WP_REST_Response|WP_Error {
        $forward=new WP_REST_Request('GET','/sabri-network/v2/conversations/'.$id);$forward->set_url_params(['id'=>$id]);$response=SN_REST::get_conversation($forward);if(is_wp_error($response))return $response;$data=$response->get_data();$data['existing']=$existing;$data['restored']=$restored;return rest_ensure_response($data);
    }

    private static function end_active_calls_locked(int $conversation,string $now): void {
        global $wpdb;$calls=SN_DB::table('calls');$members=SN_DB::table('call_members');$signals=SN_DB::table('signals');$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $calls WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE",$conversation)));if(!$ids)return;$p=implode(',',array_fill(0,count($ids),'%d'));if($wpdb->query($wpdb->prepare("UPDATE $calls SET status='ended',active_key=NULL,ended_at=%s WHERE id IN ($p)",$now,...$ids))===false)throw new RuntimeException('active_call_block_cleanup_failed');if($wpdb->query($wpdb->prepare("UPDATE $members SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id IN ($p) AND status IN ('invited','joined')",$now,...$ids))===false)throw new RuntimeException('active_call_block_cleanup_failed');if($wpdb->query($wpdb->prepare("DELETE FROM $signals WHERE call_id IN ($p)",...$ids))===false)throw new RuntimeException('active_call_block_cleanup_failed');
    }

    private static function revoke_member_calls_locked(int $conversation,int $target,string $now): void {
        global $wpdb;$calls=SN_DB::table('calls');$cm=SN_DB::table('call_members');$signals=SN_DB::table('signals');$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $calls WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE",$conversation)));foreach($ids as $call){if($wpdb->query($wpdb->prepare("UPDATE $cm SET status='left',left_at=%s WHERE call_id=%d AND user_id=%d AND status IN ('invited','joined')",$now,$call,$target))===false)throw new RuntimeException('call_membership_revoke_failed');if($wpdb->query($wpdb->prepare("DELETE FROM $signals WHERE call_id=%d AND (from_user_id=%d OR to_user_id=%d)",$call,$target,$target))===false)throw new RuntimeException('call_signal_revoke_failed');$remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $cm WHERE call_id=%d AND status IN ('invited','joined')",$call));if($remaining<2){if($wpdb->query($wpdb->prepare("UPDATE $calls SET status='ended',active_key=NULL,ended_at=%s WHERE id=%d",$now,$call))===false)throw new RuntimeException('call_end_after_member_removal_failed');if($wpdb->query($wpdb->prepare("UPDATE $cm SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id=%d AND status IN ('invited','joined')",$now,$call))===false||$wpdb->delete($signals,['call_id'=>$call],['%d'])===false)throw new RuntimeException('call_cleanup_after_member_removal_failed');}}
    }

    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:'.substr(hash('sha256',(string)$id),0,32); }

    private static function with_locks(array $locks,callable $callback) {
        global $wpdb;$locks=array_values(array_unique(array_filter(array_map('strval',$locks))));sort($locks,SORT_STRING);$held=[];try{foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('relationship_busy','This relationship is changing. Try again.',['status'=>409]);$held[]=$lock;}return $callback();}finally{foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    private static function not_found(): WP_Error { return new WP_Error('not_found','The requested Network item is unavailable.',['status'=>404]); }
    private static function rate_limited(): WP_Error { return new WP_Error('rate_limited','Too many requests. Please wait and try again.',['status'=>429]); }
    private static function database_error(): WP_Error { return new WP_Error('database_error','The Network request could not be completed safely.',['status'=>500]); }
}
