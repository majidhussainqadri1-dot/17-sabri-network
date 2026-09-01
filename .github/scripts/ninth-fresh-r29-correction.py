from pathlib import Path

root = Path('sabri-network')
meet_path = root / 'includes/class-sn-meet.php'
test_path = root / 'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
t = meet_path.read_text(encoding='utf-8')

# R29 ledger was frozen before this correction batch:
# 1) transactional Meet mutations could report success after COMMIT failure;
# 2) capacity/session count DB uncertainty could be cast to zero;
# 3) moderation side-effect reads/writes could fail while primary state still committed.
unguarded = "$wpdb->query('COMMIT');"
count = t.count(unguarded)
assert count >= 5, f'expected unguarded Meet commits, got {count}'
t = t.replace(unguarded, "if ($wpdb->query('COMMIT') === false) {\n                throw new RuntimeException('transaction_commit_failed');\n            }")

old = '''$active_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table('participants') . " WHERE meeting_id=%d AND state IN ('admitted','joined')",
                    (int) $meeting->id
                ));'''
new = '''$active_count_raw = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table('participants') . " WHERE meeting_id=%d AND state IN ('admitted','joined')",
                    (int) $meeting->id
                ));
                if ($active_count_raw === null && $wpdb->last_error !== '') {
                    throw new RuntimeException('meeting_active_count_unavailable');
                }
                $active_count = (int) $active_count_raw;'''
assert t.count(old) == 2, f'expected two active-count reads, got {t.count(old)}'
t = t.replace(old, new)

old = '''$user_sessions = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",
                    (int) $meeting->id,
                    $user_id
                ));'''
new = '''$user_sessions_raw = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",
                    (int) $meeting->id,
                    $user_id
                ));
                if ($user_sessions_raw === null && $wpdb->last_error !== '') {
                    throw new RuntimeException('meeting_user_session_count_unavailable');
                }
                $user_sessions = (int) $user_sessions_raw;'''
assert old in t
t = t.replace(old, new, 1)

old = '''$all_sessions = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE meeting_id=%d AND state IN ('waiting','joined')",
                    (int) $meeting->id
                ));'''
new = '''$all_sessions_raw = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE meeting_id=%d AND state IN ('waiting','joined')",
                    (int) $meeting->id
                ));
                if ($all_sessions_raw === null && $wpdb->last_error !== '') {
                    throw new RuntimeException('meeting_room_session_count_unavailable');
                }
                $all_sessions = (int) $all_sessions_raw;'''
assert old in t
t = t.replace(old, new, 1)

old = '''$other_sessions = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE meeting_id=%d AND user_id=%d AND id<>%d AND state='joined' AND last_seen_at>=%s",
                (int) $meeting->id,
                $user_id,
                (int) $session->id,
                gmdate('Y-m-d H:i:s', time() - self::SESSION_TTL)
            ));'''
new = '''$other_sessions_raw = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE meeting_id=%d AND user_id=%d AND id<>%d AND state='joined' AND last_seen_at>=%s",
                (int) $meeting->id,
                $user_id,
                (int) $session->id,
                gmdate('Y-m-d H:i:s', time() - self::SESSION_TTL)
            ));
            if ($other_sessions_raw === null && $wpdb->last_error !== '') {
                throw new RuntimeException('meeting_other_session_count_unavailable');
            }
            $other_sessions = (int) $other_sessions_raw;'''
assert old in t
t = t.replace(old, new, 1)

repls = [
('''                    $wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND state IN ('waiting','joined')",
                        $now,
                        $now,
                        (int) $meeting->id
                    ));''', '''                    if ($wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND state IN ('waiting','joined')",
                        $now,
                        $now,
                        (int) $meeting->id
                    )) === false) {
                        throw new RuntimeException('meeting_end_sessions_write_failed');
                    }'''),
('''                    $wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('participants') . " SET state='left',left_at=%s,updated_at=%s,version=version+1 WHERE meeting_id=%d AND state IN ('waiting','admitted','joined')",
                        $now,
                        $now,
                        (int) $meeting->id
                    ));''', '''                    if ($wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('participants') . " SET state='left',left_at=%s,updated_at=%s,version=version+1 WHERE meeting_id=%d AND state IN ('waiting','admitted','joined')",
                        $now,
                        $now,
                        (int) $meeting->id
                    )) === false) {
                        throw new RuntimeException('meeting_end_participants_write_failed');
                    }'''),
('''                    $wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='joined',joined_at=COALESCE(joined_at,%s),left_at=NULL,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",
                        $now,
                        $now,
                        (int) $meeting->id,
                        $target_id
                    ));''', '''                    if ($wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='joined',joined_at=COALESCE(joined_at,%s),left_at=NULL,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",
                        $now,
                        $now,
                        (int) $meeting->id,
                        $target_id
                    )) === false) {
                        throw new RuntimeException('meeting_admit_sessions_write_failed');
                    }'''),
('''                    $wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",
                        $now,
                        $now,
                        (int) $meeting->id,
                        $target_id
                    ));''', '''                    if ($wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",
                        $now,
                        $now,
                        (int) $meeting->id,
                        $target_id
                    )) === false) {
                        throw new RuntimeException('meeting_deny_sessions_write_failed');
                    }'''),
('''                    $wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",
                        $now,
                        $now,
                        (int) $meeting->id,
                        $target_id
                    ));''', '''                    if ($wpdb->query($wpdb->prepare(
                        "UPDATE " . self::table('sessions') . " SET state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",
                        $now,
                        $now,
                        (int) $meeting->id,
                        $target_id
                    )) === false) {
                        throw new RuntimeException('meeting_remove_sessions_write_failed');
                    }'''),
]
for old, new in repls:
    assert old in t, 'expected moderation side-effect block not found'
    t = t.replace(old, new, 1)

active_block = '''                    $active_sessions = $wpdb->get_results($wpdb->prepare(
                        "SELECT id,media_state FROM " . self::table('sessions') . " WHERE meeting_id=%d AND user_id=%d AND state='joined' FOR UPDATE",
                        (int) $meeting->id,
                        $target_id
                    ));'''
assert t.count(active_block) == 2, f'expected two active session reads, got {t.count(active_block)}'
t = t.replace(active_block, active_block + '''
                    if ($active_sessions === null && $wpdb->last_error !== '') {
                        throw new RuntimeException('meeting_active_sessions_read_failed');
                    }''')

meet_path.write_text(t, encoding='utf-8')

tests = test_path.read_text(encoding='utf-8')
marker = "\nif ($fail) {\n"
assert marker in tests
assert '// Round 29 —' not in tests
addition = r'''

// Round 29 — Sabri Meet transactional, capacity and moderation side effects fail closed.
$meet=$read('includes/class-sn-meet.php');
$check(!str_contains($meet,"$wpdb->query('COMMIT');") && substr_count($meet,"query('COMMIT') === false")>=6 && str_contains($meet,'transaction_commit_failed'), 'Round 29: every unguarded Meet transaction must confirm COMMIT before success/audit/notification.');
$check(str_contains($meet,'meeting_active_count_unavailable') && str_contains($meet,'meeting_user_session_count_unavailable') && str_contains($meet,'meeting_room_session_count_unavailable') && str_contains($meet,'meeting_other_session_count_unavailable'), 'Round 29: Meet participant/session capacity reads must not cast DB uncertainty to zero.');
$check(str_contains($meet,'meeting_end_sessions_write_failed') && str_contains($meet,'meeting_end_participants_write_failed') && str_contains($meet,'meeting_admit_sessions_write_failed') && str_contains($meet,'meeting_deny_sessions_write_failed') && str_contains($meet,'meeting_remove_sessions_write_failed'), 'Round 29: moderation must not commit when session/participant side effects fail.');
$check(substr_count($meet,'meeting_active_sessions_read_failed')>=2, 'Round 29: mute/lower-hand must fail closed when active-session snapshots cannot be read.');
'''
tests = tests.replace(marker, addition + marker, 1)
test_path.write_text(tests, encoding='utf-8')
