from pathlib import Path

p = Path('sabri-network/includes/class-sn-call-runtime-hardening.php')
s = p.read_text(encoding='utf-8')

def rep(old, new):
    global s
    if old not in s:
        raise SystemExit('missing anchor:\n' + old[:180])
    s = s.replace(old, new, 1)

rep("""            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . SN_DB::table('signals') . ' WHERE id=%d AND call_id=%d AND to_user_id=%d AND consumed_at IS NULL AND created_at>=%s',
                $id, absint($request['id']), get_current_user_id(), $cutoff
            ));
            if (!$row) continue;
""", """            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . SN_DB::table('signals') . ' WHERE id=%d AND call_id=%d AND to_user_id=%d AND consumed_at IS NULL AND created_at>=%s',
                $id, absint($request['id']), get_current_user_id(), $cutoff
            ));
            if (($wpdb->last_error ?? '') !== '') {
                SN_DB::audit('call_signal_read_failed','call',absint($request['id']),'failure',['reason'=>(string)$wpdb->last_error],get_current_user_id());
                return self::signal_error('sn_signal_read_failed');
            }
            if (!$row) continue;
""")

rep("""        $meeting_id = (int)$wpdb->get_var($wpdb->prepare(\"SELECT id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s\", $public));
        if ($meeting_id <= 0) return self::not_found();
""", """        $meeting_id_raw = $wpdb->get_var($wpdb->prepare(\"SELECT id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s\", $public));
        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_meet_state_unavailable');
        $meeting_id = (int)$meeting_id_raw;
        if ($meeting_id <= 0) return self::not_found();
""")

rep("""            $meeting = $wpdb->get_row($wpdb->prepare(
                \"SELECT id,host_id,conversation_id,access_mode,status FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s\",
                (string)$meet_match[1]
            ));
            if (!$meeting || !in_array((string)$meeting->status, ['scheduled','live'], true)) return self::not_found();
""", """            $meeting = $wpdb->get_row($wpdb->prepare(
                \"SELECT id,host_id,conversation_id,access_mode,status FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s\",
                (string)$meet_match[1]
            ));
            if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_meet_state_unavailable');
            if (!$meeting || !in_array((string)$meeting->status, ['scheduled','live'], true)) return self::not_found();
""")

rep("""            $participant = $wpdb->get_row($wpdb->prepare(
                \"SELECT state FROM {$wpdb->prefix}sn_meet_participants WHERE meeting_id=%d AND user_id=%d\",
                (int)$meeting->id,
                $actor
            ));
            if (!$participant || !in_array((string)$participant->state, ['admitted','joined'], true)) return self::not_found();
""", """            $participant = $wpdb->get_row($wpdb->prepare(
                \"SELECT state FROM {$wpdb->prefix}sn_meet_participants WHERE meeting_id=%d AND user_id=%d\",
                (int)$meeting->id,
                $actor
            ));
            if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_meet_state_unavailable');
            if (!$participant || !in_array((string)$participant->state, ['admitted','joined'], true)) return self::not_found();
""")

rep("""        $call = $wpdb->get_row($wpdb->prepare('SELECT conversation_id,status FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call_id));
        if (!$call || !in_array((string)$call->status, ['ringing','active','accepted','connected','reconnecting'], true)
""", """        $call = $wpdb->get_row($wpdb->prepare('SELECT conversation_id,status FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call_id));
        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
        if (!$call || !in_array((string)$call->status, ['ringing','active','accepted','connected','reconnecting'], true)
""")

rep("""        $member = $wpdb->get_row($wpdb->prepare(
            \"SELECT status FROM \" . SN_DB::table('call_members') . \" WHERE call_id=%d AND user_id=%d\",
            $call_id,
            $actor
        ));
        if (!$member || !in_array((string)$member->status, ['invited','joined'], true)) return self::not_found();
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', (int)$call->conversation_id));
""", """        $member = $wpdb->get_row($wpdb->prepare(
            \"SELECT status FROM \" . SN_DB::table('call_members') . \" WHERE call_id=%d AND user_id=%d\",
            $call_id,
            $actor
        ));
        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
        if (!$member || !in_array((string)$member->status, ['invited','joined'], true)) return self::not_found();
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', (int)$call->conversation_id));
        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
""")

rep("""            $peer = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',
                (int)$call->conversation_id,
                $actor
            ));
            if ($peer <= 0 || SN_DB::is_blocked($actor, $peer) || SN_DB::is_blocked($peer, $actor)) return self::not_found();
""", """            $peer = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',
                (int)$call->conversation_id,
                $actor
            ));
            if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
            if ($peer <= 0 || SN_DB::is_blocked($actor, $peer) || SN_DB::is_blocked($peer, $actor)) return self::not_found();
""")

# lock_mutation: fail closed on route state reads and pair-lock derivation uncertainty.
rep("""                $locks[] = self::conversation_lock($conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
""", """                $locks[] = self::conversation_lock($conversation);
                $pair_lock = self::append_direct_pair_lock($locks, $conversation, $actor);
                if (is_wp_error($pair_lock)) return $pair_lock;
""")
rep("""            $meeting = $wpdb->get_row($wpdb->prepare(\"SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s\", $public));
            if ($meeting) {
""", """            $meeting = $wpdb->get_row($wpdb->prepare(\"SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s\", $public));
            if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_meet_state_unavailable');
            if ($meeting) {
""")
rep("""                    $locks[] = self::conversation_lock((int)$meeting->conversation_id);
                    self::append_direct_pair_lock($locks, (int)$meeting->conversation_id, $actor);
""", """                    $locks[] = self::conversation_lock((int)$meeting->conversation_id);
                    $pair_lock = self::append_direct_pair_lock($locks, (int)$meeting->conversation_id, $actor);
                    if (is_wp_error($pair_lock)) return $pair_lock;
""")
rep("""                $locks[] = self::conversation_lock($conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
            }
        } elseif (preg_match('#^/sabri-network/v2/calls/(\\d+)(?:/|$)#', $route, $m)) {
""", """                $locks[] = self::conversation_lock($conversation);
                $pair_lock = self::append_direct_pair_lock($locks, $conversation, $actor);
                if (is_wp_error($pair_lock)) return $pair_lock;
            }
        } elseif (preg_match('#^/sabri-network/v2/calls/(\\d+)(?:/|$)#', $route, $m)) {
""")
rep("""            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
                self::append_direct_pair_lock($locks, $conversation, $actor);
""", """            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));
            if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
            if ($conversation > 0) {
                $locks[] = self::conversation_lock($conversation);
                $pair_lock = self::append_direct_pair_lock($locks, $conversation, $actor);
                if (is_wp_error($pair_lock)) return $pair_lock;
""")

rep("""        foreach ($locks as $lock) {
            $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
            if($ok!==1){self::release($held);return new WP_Error('sn_call_mutation_busy','The call or meeting is changing. Retry the request.',['status'=>409]);}
            $held[]=$lock;
        }
""", """        foreach ($locks as $lock) {
            $raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
            if (($wpdb->last_error ?? '') !== '' || $raw === null) {
                self::release($held);
                return new WP_Error('sn_call_lock_unavailable','The call/meeting lock service is temporarily unavailable.',['status'=>503]);
            }
            if((int)$raw!==1){self::release($held);return new WP_Error('sn_call_mutation_busy','The call or meeting is changing. Retry the request.',['status'=>409]);}
            $held[]=$lock;
        }
""")

rep("""        if (!$row) return true;

        $title = trim(sanitize_text_field((string)$request->get_param('title')));
""", """        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_meet_idempotency_state_unavailable');
        if (!$row) return true;

        $title = trim(sanitize_text_field((string)$request->get_param('title')));
""")

rep("""    private static function append_direct_pair_lock(array &$locks, int $conversation, int $actor): void {
        global $wpdb;
        if ($conversation <= 0 || $actor <= 0) return;
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $conversation));
        if ($type !== 'direct') return;
        $peer = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',
            $conversation,
            $actor
        ));
        if ($peer > 0) $locks[] = SN_Relationships::pair_lock_name($actor, $peer);
    }
""", """    private static function append_direct_pair_lock(array &$locks, int $conversation, int $actor): bool|WP_Error {
        global $wpdb;
        if ($conversation <= 0 || $actor <= 0) return true;
        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $conversation));
        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
        if ($type !== 'direct') return true;
        $peer = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',
            $conversation,
            $actor
        ));
        if (($wpdb->last_error ?? '') !== '') return self::signal_error('sn_call_state_unavailable');
        if ($peer > 0) $locks[] = SN_Relationships::pair_lock_name($actor, $peer);
        return true;
    }
""")

rep("""    private static function release(array $locks): void { global $wpdb; foreach(array_reverse($locks) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock)); }
""", """    private static function release(array $locks): void {
        global $wpdb;
        foreach(array_reverse($locks) as $lock){
            $released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock));
            if (($wpdb->last_error ?? '') !== '' || $released === null) {
                SN_DB::audit('call_lock_release_failed','system',0,'failure',['lock_hash'=>substr(hash('sha256',(string)$lock),0,16),'reason'=>(string)($wpdb->last_error ?? '')],0);
            }
        }
    }
""")

p.write_text(s, encoding='utf-8')

t = Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php')
t.parent.mkdir(parents=True, exist_ok=True)
content = '''<?php
/** Eleventh fresh 10-round static regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$fail=[];$checks=0;
$read=static function(string $path):string{$v=file_get_contents($path);if($v===false)throw new RuntimeException('Missing '.$path);return $v;};
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$call=$read($root.'/includes/class-sn-call-runtime-hardening.php');
// R1 — call/Meet read truth and advisory-lock uncertainty must fail closed.
$check(str_contains($call,"sn_signal_read_failed"),'R1 classic signal rereads must expose storage uncertainty.');
$check(str_contains($call,"sn_meet_idempotency_state_unavailable"),'R1 meeting idempotency reads must fail closed.');
$check(str_contains($call,"sn_call_lock_unavailable"),'R1 advisory-lock DB uncertainty must not be reported as contention.');
$check(str_contains($call,"append_direct_pair_lock(array &$locks, int $conversation, int $actor): bool|WP_Error"),'R1 pair-lock derivation must propagate DB uncertainty.');
$check(str_contains($call,"call_lock_release_failed"),'R1 lock release failure must remain observable.');
if($fail){fwrite(STDERR,"Eleventh fresh failures (".count($fail)."/$checks):\\n - ".implode("\\n - ",$fail)."\\n");exit(1);}echo "Eleventh fresh contracts: PASS ($checks checks)\\n";
'''
t.write_text(content,encoding='utf-8')
