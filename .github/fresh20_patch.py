from pathlib import Path

p=Path('sabri-network/includes/class-sn-fourth-fresh-review-hardening.php')
s=p.read_text(encoding='utf-8')

# R03-D02/D03: final owner for reaction route, with explicit invalid-input semantics.
route_anchor="""        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'edit_message'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_message'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
"""
route_repl=route_anchor+"""        register_rest_route('sabri-network/v2', '/messages/(?P<id>\\d+)/reaction', [
            'methods' => 'POST', 'callback' => [self::class, 'react_message'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
"""
if route_anchor not in s: raise SystemExit('R03 reaction route anchor missing')
s=s.replace(route_anchor,route_repl,1)

# R03-D01: edit must refresh canonical File-00 access after the serialization boundary is held.
edit_anchor="""            try {
                $messages = SN_DB::table('messages');
                $members = SN_DB::table('members');
"""
edit_repl="""            try {
                SN_Membership_Assertions::clear_cache($actor);
                $fresh_access = SN_Policy::access();
                if (is_wp_error($fresh_access)) { $wpdb->query('ROLLBACK'); return $fresh_access; }
                $messages = SN_DB::table('messages');
                $members = SN_DB::table('members');
"""
if edit_anchor not in s: raise SystemExit('R03 edit refresh anchor missing')
s=s.replace(edit_anchor,edit_repl,1)

method_anchor="""    /** Serialize receipts against conversation membership changes, then reuse the bounded atomic receipt owner. */
    public static function record_receipt(WP_REST_Request $request): WP_REST_Response|WP_Error {
"""
reaction_method="""    /** Serialize reaction changes with message deletion/membership and fail closed on positive eligibility changes. */
    public static function react_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $message_id = absint($request['id']);
        $actor = get_current_user_id();
        $raw = trim(wp_unslash((string) $request->get_param('reaction')));
        $reaction = SN_Policy::sanitize_reaction($raw);
        if ($raw !== '' && $reaction === '') {
            return new WP_Error('invalid_reaction', 'Choose a supported reaction or send an empty reaction to remove your reaction.', ['status'=>400]);
        }
        $probe = self::message_row($message_id);
        if (!$probe) return self::not_found();
        $conversation = (int) $probe->conversation_id;

        return self::with_locks([self::conversation_lock($conversation)], function () use ($wpdb,$message_id,$actor,$reaction,$conversation) {
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $messages = SN_DB::table('messages');
                $members = SN_DB::table('members');
                $conversations = SN_DB::table('conversations');
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $message_id));
                $member = $wpdb->get_row($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", $conversation, $actor));
                $thread = $wpdb->get_row($wpdb->prepare("SELECT id,type,status FROM $conversations WHERE id=%d FOR UPDATE", $conversation));
                if (!$row || !$member || !$thread || (int)$row->conversation_id !== $conversation) throw new DomainException('not_found');

                if ($reaction !== '') {
                    if (!empty($row->deleted_at)) throw new UnexpectedValueException('message_deleted');
                    SN_Membership_Assertions::clear_cache($actor);
                    $fresh_access = SN_Policy::access();
                    if (is_wp_error($fresh_access)) { $wpdb->query('ROLLBACK'); return $fresh_access; }
                    if ((string)$thread->type === 'direct') {
                        $target = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM $members WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE", $conversation, $actor));
                        if ($target <= 0) throw new DomainException('not_found');
                        SN_Membership_Assertions::clear_cache($target);
                        $contact = SN_Policy::can_contact($actor,$target,'message');
                        if (is_wp_error($contact)) { $wpdb->query('ROLLBACK'); return $contact; }
                    }
                }

                $changed = $reaction === ''
                    ? $wpdb->delete(SN_DB::table('reactions'), ['message_id'=>$message_id,'user_id'=>$actor], ['%d','%d'])
                    : $wpdb->replace(SN_DB::table('reactions'), ['message_id'=>$message_id,'user_id'=>$actor,'reaction'=>$reaction,'created_at'=>current_time('mysql',true)]);
                if ($changed === false) throw new RuntimeException('reaction_write_failed');
                $event = SN_Outbox::enqueue('message.reaction_changed','message',$message_id,[
                    'message_id'=>$message_id,'conversation_id'=>$conversation,'actor_id'=>$actor,'reaction'=>$reaction,
                ],'message.reaction_changed:'.$message_id.':'.$actor.':'.hash('sha256',$reaction));
                if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('reaction_commit_failed');
                SN_DB::audit('message_reaction_changed','message',$message_id,'success',['conversation_id'=>$conversation,'removed'=>$reaction===''],$actor);
                do_action('sn_network_event_queued',$event,'message.reaction_changed');
                return rest_ensure_response(['reactions'=>self::message_reactions($message_id)]);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                if ($e instanceof DomainException) return self::not_found();
                if ($e instanceof UnexpectedValueException && $e->getMessage()==='message_deleted') return new WP_Error('message_deleted','Deleted messages cannot receive new reactions.',['status'=>409]);
                SN_DB::audit('message_reaction_failed','message',$message_id,'failure',['reason'=>$e->getMessage()],$actor);
                return new WP_Error('message_reaction_failed','The reaction could not be committed safely.',['status'=>500]);
            }
        });
    }

    private static function message_reactions(int $message_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total FROM '.SN_DB::table('reactions').' WHERE message_id=%d GROUP BY reaction ORDER BY reaction ASC',$message_id));
        return array_map(static fn($row)=>['reaction'=>(string)$row->reaction,'count'=>(int)$row->total],is_array($rows)?$rows:[]);
    }

"""+method_anchor
if method_anchor not in s: raise SystemExit('R03 reaction method anchor missing')
s=s.replace(method_anchor,reaction_method,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
anchor="$msg=$read('includes/class-sn-message-runtime-hardening.php');\n"
if "$reviewFinal=$read('includes/class-sn-fourth-fresh-review-hardening.php');" not in s:
    if anchor not in s: raise SystemExit('R03 regression variable anchor missing')
    s=s.replace(anchor,anchor+"$reviewFinal=$read('includes/class-sn-fourth-fresh-review-hardening.php');\n",1)
marker='// Fresh20 R03 final message mutation regressions.'
if marker not in s:
    insert="""
// Fresh20 R03 final message mutation regressions.
$check(str_contains($reviewFinal,"'/messages/(?P<id>\\d+)/reaction'")&&str_contains($reviewFinal,"'callback' => [self::class, 'react_message']"),'Fresh20 R03: final route precedence must move reactions off the legacy un-serialized owner.');
$check(str_contains($reviewFinal,"new WP_Error('invalid_reaction'")&&str_contains($reviewFinal,'Choose a supported reaction'),'Fresh20 R03: unsupported non-empty reaction input must be rejected rather than interpreted as removal.');
$check(str_contains($reviewFinal,'public static function react_message')&&substr_count($reviewFinal,'FOR UPDATE')>=3&&str_contains($reviewFinal,'reaction_commit_failed'),'Fresh20 R03: reaction mutation must lock current state and prove commit success.');
$editPos=strpos($reviewFinal,'public static function edit_message');
$receiptPos=strpos($reviewFinal,'public static function record_receipt');
$editSlice=substr($reviewFinal,$editPos,$receiptPos-$editPos);
$check(str_contains($editSlice,'SN_Membership_Assertions::clear_cache($actor);')&&str_contains($editSlice,'$fresh_access = SN_Policy::access();'),'Fresh20 R03: final edit mutation must refresh File-00 access after serialization.');
"""
    tail=s.rfind('if($fail){')
    if tail<0: raise SystemExit('R03 regression tail missing')
    s=s[:tail]+insert+s[tail:]
p.write_text(s,encoding='utf-8')
