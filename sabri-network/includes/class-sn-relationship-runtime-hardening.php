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
            $table = SN_DB::table('contacts');
            $pair = SN_DB::contact_pair_key($actor, $target);
            $now = current_time('mysql', true);
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
                    $id = (int) $row->id;
                } else {
                    if ($wpdb->insert($table, $data) === false) throw new RuntimeException('contact_insert_failed');
                    $id = (int) $wpdb->insert_id;
                }
                if ($wpdb->query('COMMIT') === false) {
                    $fresh = SN_DB::contact_record($actor, $target);
                    if (!$fresh || (string)$fresh->status !== 'pending' || (int)$fresh->requested_by !== $actor) throw new RuntimeException('contact_commit_failed');
                    $id = (int) $fresh->id;
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                $fresh = SN_DB::contact_record($actor, $target);
                if ($fresh && (string)$fresh->status === 'pending' && (int)$fresh->requested_by === $actor) return rest_ensure_response(['request_id'=>(int)$fresh->id,'status'=>'pending','duplicate'=>true,'commit_reconciled'=>true]);
                return $e->getMessage() === 'contact_request_conflict' ? new WP_Error('contact_request_conflict','This contact relationship changed before the request was saved.',['status'=>409]) : self::database_error();
            }
            SN_DB::add_notification($target,'contact_request','New contact request','','contact',$id);
            SN_DB::audit('contact_requested','contact',$id,'success',['target_id'=>$target],$actor);
            return rest_ensure_response(['request_id'=>$id,'status'=>'pending']);
        });
    }

    public static function decide_contact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $table = SN_DB::table('contacts');
        $probe = $wpdb->get_row($wpdb->prepare("SELECT user_id,contact_user_id FROM $table WHERE id=%d", $id));
        if (!$probe) return self::not_found();
        $other = (int)$probe->user_id === $actor ? (int)$probe->contact_user_id : (int)$probe->user_id;
        if ($other <= 0) return self::not_found();
        return self::with_locks([SN_Relationships::pair_lock_name($actor, $other)], function () use ($request,$id,$actor,$other,$table,$wpdb) {
            $decision = sanitize_key((string)$request->get_param('decision'));
            if (!in_array($decision, ['accept','decline'], true)) return new WP_Error('invalid_decision','Choose accept or decline.',['status'=>400]);
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id));
                if (!$row || (string)$row->status !== 'pending' || (int)$row->requested_by === $actor || !in_array($actor, [(int)$row->user_id,(int)$row->contact_user_id], true)) throw new DomainException('not_found');
                $requester = (int)$row->requested_by;
                if ($decision === 'accept') {
                    $policy = SN_Policy::can_contact($requester, $actor, 'request');
                    if (is_wp_error($policy)) { $wpdb->query('ROLLBACK'); return $policy; }
                }
                $status = $decision === 'accept' ? 'accepted' : 'declined';
                $now = current_time('mysql', true);
                $updated = $wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,updated_at=%s WHERE id=%d AND status='pending'", $status,$now,$id));
                if ($updated !== 1) throw new RuntimeException('contact_decision_conflict');
                if ($wpdb->query('COMMIT') === false) {
                    $fresh = SN_DB::contact_record($actor, $other);
                    if (!$fresh || (int)$fresh->id !== $id || (string)$fresh->status !== $status) throw new RuntimeException('contact_decision_commit_failed');
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
        $actor = get_current_user_id();
        $target = absint($request->get_param('user_id'));
        if ($target <= 0 || $target === $actor || !get_user_by('id',$target)) return new WP_Error('invalid_user','Select a valid user.',['status'=>400]);
        $raw = $request->get_param('blocked');
        $blocked = $raw === null ? true : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        return self::with_locks([SN_Relationships::pair_lock_name($actor,$target)], function () use ($actor,$target,$blocked,$wpdb) {
            $now = current_time('mysql',true);
            $blocks = SN_DB::table('blocks');
            $contacts = SN_DB::table('contacts');
            $follows = SN_DB::table('follows');
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $contact = $wpdb->get_row($wpdb->prepare("SELECT * FROM $contacts WHERE pair_key=%s FOR UPDATE", SN_DB::contact_pair_key($actor,$target)));
                $wpdb->get_results($wpdb->prepare("SELECT id FROM $follows WHERE (follower_id=%d AND followed_id=%d) OR (follower_id=%d AND followed_id=%d) FOR UPDATE", $actor,$target,$target,$actor));
                if ($blocked) {
                    if ($wpdb->query($wpdb->prepare("INSERT INTO $blocks (user_id,blocked_user_id,created_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE created_at=VALUES(created_at)", $actor,$target,$now)) === false) throw new RuntimeException('block_write_failed');
                    if ($contact && $wpdb->query($wpdb->prepare("UPDATE $contacts SET status='blocked',updated_at=%s WHERE id=%d", $now,(int)$contact->id)) === false) throw new RuntimeException('contact_block_failed');
                    if ($wpdb->query($wpdb->prepare("UPDATE $follows SET status='inactive',updated_at=%s,decided_at=%s,version=version+1 WHERE ((follower_id=%d AND followed_id=%d) OR (follower_id=%d AND followed_id=%d)) AND status IN ('active','pending')", $now,$now,$actor,$target,$target,$actor)) === false) throw new RuntimeException('follow_block_cleanup_failed');
                    $direct = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('conversations')." WHERE type='direct' AND direct_key=%s FOR UPDATE", SN_DB::direct_key($actor,$target)));
                    if ($direct) self::end_active_calls_locked((int)$direct->id,$now);
                } else {
                    if ($wpdb->delete($blocks,['user_id'=>$actor,'blocked_user_id'=>$target],['%d','%d']) === false) throw new RuntimeException('unblock_write_failed');
                    if ($contact && (string)$contact->status === 'blocked' && $wpdb->query($wpdb->prepare("UPDATE $contacts SET status='declined',updated_at=%s WHERE id=%d AND status='blocked'",$now,(int)$contact->id)) === false) throw new RuntimeException('contact_unblock_failed');
                }
                if ($wpdb->query('COMMIT') === false) {
                    $own = (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $blocks WHERE user_id=%d AND blocked_user_id=%d",$actor,$target));
                    if ($own !== $blocked) throw new RuntimeException('block_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                return self::database_error();
            }
            SN_DB::audit($blocked?'user_blocked':'user_unblocked','user',$target,'success',[],$actor);
            return rest_ensure_response(['blocked'=>$blocked]);
        });
    }

    /**
     * Direct conversations are the only conversations created by this endpoint.
     * Group/channel/private-team membership belongs to SN_Spaces. For those types
     * this endpoint resolves the already-created canonical space conversation and
     * never creates a second independent membership graph.
     */
    public static function create_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $type = sanitize_key((string)$request->get_param('type')) ?: 'direct';

        if ($type !== 'direct') {
            $space_id = absint($request->get_param('space_id'));
            if ($space_id <= 0) return new WP_Error('space_required','Group and channel conversations are owned by a File-17 space. Supply its canonical space_id.',['status'=>409]);
            return self::with_locks([self::space_lock($space_id)], function () use ($wpdb,$actor,$space_id,$type) {
                $space = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('spaces').' WHERE id=%d', $space_id));
                if (!$space || !in_array((string)$space->state,['active','restricted','locked'],true) || (int)$space->conversation_id <= 0) return self::not_found();
                $member = $wpdb->get_row($wpdb->prepare("SELECT role FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1",$space_id,$actor));
                if (!$member) return self::not_found();
                $expected = (string)$space->type === 'channel' ? 'channel' : 'group';
                if ($type !== $expected && !($type === 'group' && in_array((string)$space->type,['group','private_team'],true))) {
                    return new WP_Error('conversation_type_mismatch','The requested conversation type does not match the canonical space.',['status'=>409]);
                }
                return self::conversation_response((int)$space->conversation_id,true,false);
            });
        }

        if (!SN_Policy::can_create_conversation($actor,'direct')) return new WP_Error('conversation_type_forbidden','You cannot create this conversation type.',['status'=>403]);
        if (!SN_Policy::consume_rate_limit('conversation_create',(string)$actor,30,HOUR_IN_SECONDS)) return self::rate_limited();
        $members = array_values(array_unique(array_filter(array_map('absint',(array)$request->get_param('member_ids')))));
        $members = array_values(array_diff($members,[$actor]));
        $target = absint($request->get_param('user_id')) ?: ($members[0] ?? 0);
        if ($target <= 0) return new WP_Error('invalid_members','Select a valid Network member.',['status'=>400]);
        $members = [$target];
        $locks = [SN_Relationships::pair_lock_name($actor,$target),'sn:f17:conversation-create:'.substr(hash('sha256',(string)$actor),0,32)];
        return self::with_locks($locks, function () use ($actor,$members,$wpdb) {
            $target = $members[0];
            $policy = SN_Policy::can_contact($actor,$target,'message');
            if (is_wp_error($policy)) return $policy;
            $now = current_time('mysql',true);
            $conversations = SN_DB::table('conversations');
            $memberTable = SN_DB::table('members');
            $directKey = SN_DB::direct_key($actor,$target);
            $id = 0;
            $existing = null;
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE direct_key=%s FOR UPDATE",$directKey));
                if ($existing) {
                    $id = (int)$existing->id;
                    if ($wpdb->query($wpdb->prepare("UPDATE $conversations SET status='active',updated_at=%s WHERE id=%d",$now,$id)) === false) throw new RuntimeException('conversation_restore_failed');
                    foreach ([$actor,$target] as $memberId) {
                        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $memberTable WHERE conversation_id=%d AND user_id=%d FOR UPDATE",$id,$memberId));
                        $role = (int)$existing->owner_id === $memberId ? 'owner' : 'member';
                        $ok = $row ? $wpdb->query($wpdb->prepare("UPDATE $memberTable SET role=%s,left_at=NULL,joined_at=%s WHERE id=%d",$role,$now,(int)$row->id)) : $wpdb->insert($memberTable,['conversation_id'=>$id,'user_id'=>$memberId,'role'=>$role,'joined_at'=>$now]);
                        if ($ok === false) throw new RuntimeException('member_restore_failed');
                    }
                } else {
                    if ($wpdb->insert($conversations,['type'=>'direct','title'=>'','slug'=>'','direct_key'=>$directKey,'owner_id'=>$actor,'description'=>'','privacy'=>'private','status'=>'active','settings'=>'{}','created_at'=>$now,'updated_at'=>$now]) === false) throw new RuntimeException('conversation_insert_failed');
                    $id = (int)$wpdb->insert_id;
                    foreach ([$actor,$target] as $memberId) {
                        if ($wpdb->insert($memberTable,['conversation_id'=>$id,'user_id'=>$memberId,'role'=>$memberId===$actor?'owner':'member','joined_at'=>$now]) === false) throw new RuntimeException('member_insert_failed');
                    }
                }
                if ($wpdb->query('COMMIT') === false) {
                    $fresh = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE id=%d",$id));
                    if (!$fresh || (string)$fresh->status !== 'active') throw new RuntimeException('conversation_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                $race = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE direct_key=%s",$directKey));
                if ($race && (string)$race->status === 'active') return self::conversation_response((int)$race->id,true,true);
                return self::database_error();
            }
            SN_DB::add_notification($target,'conversation_invite','New Network conversation','','conversation',$id);
            SN_DB::audit('conversation_created','conversation',$id,'success',['type'=>'direct','members'=>2],$actor);
            return self::conversation_response($id,(bool)$existing,(bool)$existing);
        });
    }

    /** Space/Smail membership is changed only by its canonical owner workflow. */
    public static function add_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::managed_membership_error(absint($request['id']));
    }

    /** Space/Smail membership is changed only by its canonical owner workflow. */
    public static function remove_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::managed_membership_error(absint($request['id']));
    }

    /** Keep the legacy callback aligned with the later high-risk canonical owner. */
    public static function transfer_owner(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (class_exists('SN_Fourth_Fresh_Review_Hardening')) return SN_Fourth_Fresh_Review_Hardening::transfer_conversation_owner($request);
        return new WP_Error('ownership_transfer_unavailable','The governed ownership-transfer service is unavailable.',['status'=>503]);
    }

    private static function managed_membership_error(int $conversation): WP_Error {
        global $wpdb;
        if ($conversation <= 0) return self::not_found();
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,type,status,settings FROM '.SN_DB::table('conversations').' WHERE id=%d',$conversation));
        if (!$row || (string)$row->status !== 'active' || (string)$row->type === 'direct') return new WP_Error('invalid_conversation','Direct conversation membership cannot be changed.',['status'=>400]);
        $space = $wpdb->get_row($wpdb->prepare('SELECT id FROM '.SN_DB::table('spaces').' WHERE conversation_id=%d LIMIT 1',$conversation));
        if ($space) return new WP_Error('space_membership_managed','Change membership through the canonical space join/invite/member lifecycle; its conversation is synchronized automatically.',['status'=>409]);
        $settings = json_decode((string)$row->settings,true);
        if (is_array($settings) && ($settings['purpose'] ?? '') === 'smail') return new WP_Error('smail_audience_managed','Smail recipient membership is fixed by the governed Smail send workflow.',['status'=>409]);
        return new WP_Error('legacy_group_membership_migration_required','This legacy standalone group must be migrated to a canonical File-17 space before membership can change.',['status'=>409]);
    }

    private static function conversation_response(int $id,bool $existing=false,bool $restored=false): WP_REST_Response|WP_Error {
        $forward = new WP_REST_Request('GET','/sabri-network/v2/conversations/'.$id);
        $forward->set_url_params(['id'=>$id]);
        $response = SN_REST::get_conversation($forward);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        $data['existing'] = $existing;
        $data['restored'] = $restored;
        return rest_ensure_response($data);
    }

    private static function end_active_calls_locked(int $conversation,string $now): void {
        global $wpdb;
        $calls = SN_DB::table('calls');
        $members = SN_DB::table('call_members');
        $signals = SN_DB::table('signals');
        $ids = array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $calls WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE",$conversation)));
        if (!$ids) return;
        $p = implode(',',array_fill(0,count($ids),'%d'));
        if ($wpdb->query($wpdb->prepare("UPDATE $calls SET status='ended',active_key=NULL,ended_at=%s WHERE id IN ($p)",$now,...$ids)) === false) throw new RuntimeException('active_call_block_cleanup_failed');
        if ($wpdb->query($wpdb->prepare("UPDATE $members SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id IN ($p) AND status IN ('invited','joined')",$now,...$ids)) === false) throw new RuntimeException('active_call_block_cleanup_failed');
        if ($wpdb->query($wpdb->prepare("DELETE FROM $signals WHERE call_id IN ($p)",...$ids)) === false) throw new RuntimeException('active_call_block_cleanup_failed');
    }

    private static function revoke_member_calls_locked(int $conversation,int $target,string $now): void {
        global $wpdb;
        $calls = SN_DB::table('calls');
        $cm = SN_DB::table('call_members');
        $signals = SN_DB::table('signals');
        $ids = array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $calls WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE",$conversation)));
        foreach ($ids as $call) {
            if ($wpdb->query($wpdb->prepare("UPDATE $cm SET status='left',left_at=%s WHERE call_id=%d AND user_id=%d AND status IN ('invited','joined')",$now,$call,$target)) === false) throw new RuntimeException('call_membership_revoke_failed');
            if ($wpdb->query($wpdb->prepare("DELETE FROM $signals WHERE call_id=%d AND (from_user_id=%d OR to_user_id=%d)",$call,$target,$target)) === false) throw new RuntimeException('call_signal_revoke_failed');
            $remaining = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $cm WHERE call_id=%d AND status IN ('invited','joined')",$call));
            if ($remaining < 2) {
                if ($wpdb->query($wpdb->prepare("UPDATE $calls SET status='ended',active_key=NULL,ended_at=%s WHERE id=%d",$now,$call)) === false) throw new RuntimeException('call_end_after_member_removal_failed');
                if ($wpdb->query($wpdb->prepare("UPDATE $cm SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id=%d AND status IN ('invited','joined')",$now,$call)) === false || $wpdb->delete($signals,['call_id'=>$call],['%d']) === false) throw new RuntimeException('call_cleanup_after_member_removal_failed');
            }
        }
    }

    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:'.substr(hash('sha256',(string)$id),0,32); }
    private static function space_lock(int $id): string { return 'sn:f17:space:'.substr(hash('sha256',(string)$id),0,32); }

    private static function with_locks(array $locks, callable $callback) {
        global $wpdb;
        $locks = array_values(array_unique(array_filter(array_map('strval',$locks))));
        sort($locks,SORT_STRING);
        $held = [];
        try {
            foreach ($locks as $lock) {
                $ok = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
                if ($ok !== 1) return new WP_Error('relationship_busy','This relationship is changing. Try again.',['status'=>409]);
                $held[] = $lock;
            }
            return $callback();
        } finally {
            foreach (array_reverse($held) as $lock) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    private static function not_found(): WP_Error { return new WP_Error('not_found','The requested Network item is unavailable.',['status'=>404]); }
    private static function rate_limited(): WP_Error { return new WP_Error('rate_limited','Too many requests. Please wait and try again.',['status'=>429]); }
    private static function database_error(): WP_Error { return new WP_Error('database_error','The Network request could not be completed safely.',['status'=>500]); }
}
