from pathlib import Path
import re

root=Path('sabri-network')
meet_path=root/'includes/class-sn-meet.php'
test_path=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
t=meet_path.read_text(encoding='utf-8')

# Frozen R29 ledger: commit confirmation, capacity/session DB uncertainty, and moderation side effects.
unguarded="$wpdb->query('COMMIT');"
assert t.count(unguarded)>=5, f'expected unguarded Meet commits, got {t.count(unguarded)}'
t=t.replace(unguarded,"if ($wpdb->query('COMMIT') === false) {\n                throw new RuntimeException('transaction_commit_failed');\n            }")

# Capacity count reads: preserve each call's indentation and fail closed only on DB uncertainty.
pat=re.compile(r"(?P<indent>\s*)\$active_count = \(int\) \$wpdb->get_var\(\$wpdb->prepare\(\n(?P<body>\s*\"SELECT COUNT\(\*\) FROM \" \. self::table\('participants'\) \. \" WHERE meeting_id=%d AND state IN \('admitted','joined'\)\",\n\s*\(int\) \$meeting->id\n\s*)\)\);",re.M)

def active_repl(m):
    i=m.group('indent')
    inner=i+'    '
    return f"{i}$active_count_raw = $wpdb->get_var($wpdb->prepare(\n{m.group('body')}) );\n{inner}if ($active_count_raw === null && $wpdb->last_error !== '') {{\n{inner}    throw new RuntimeException('meeting_active_count_unavailable');\n{inner}}}\n{i}$active_count = (int) $active_count_raw;"
t,n=pat.subn(active_repl,t)
assert n==2, f'expected two active count reads, got {n}'

replacements=[
("""$user_sessions = (int) $wpdb->get_var($wpdb->prepare(
                    \"SELECT COUNT(*) FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')\",
                    (int) $meeting->id,
                    $user_id
                ));""", """$user_sessions_raw = $wpdb->get_var($wpdb->prepare(
                    \"SELECT COUNT(*) FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')\",
                    (int) $meeting->id,
                    $user_id
                ));
                if ($user_sessions_raw === null && $wpdb->last_error !== '') {
                    throw new RuntimeException('meeting_user_session_count_unavailable');
                }
                $user_sessions = (int) $user_sessions_raw;"""),
("""$all_sessions = (int) $wpdb->get_var($wpdb->prepare(
                    \"SELECT COUNT(*) FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND state IN ('waiting','joined')\",
                    (int) $meeting->id
                ));""", """$all_sessions_raw = $wpdb->get_var($wpdb->prepare(
                    \"SELECT COUNT(*) FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND state IN ('waiting','joined')\",
                    (int) $meeting->id
                ));
                if ($all_sessions_raw === null && $wpdb->last_error !== '') {
                    throw new RuntimeException('meeting_room_session_count_unavailable');
                }
                $all_sessions = (int) $all_sessions_raw;"""),
("""$other_sessions = (int) $wpdb->get_var($wpdb->prepare(
                \"SELECT COUNT(*) FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND user_id=%d AND id<>%d AND state='joined' AND last_seen_at>=%s\",
                (int) $meeting->id,
                $user_id,
                (int) $session->id,
                gmdate('Y-m-d H:i:s', time() - self::SESSION_TTL)
            ));""", """$other_sessions_raw = $wpdb->get_var($wpdb->prepare(
                \"SELECT COUNT(*) FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND user_id=%d AND id<>%d AND state='joined' AND last_seen_at>=%s\",
                (int) $meeting->id,
                $user_id,
                (int) $session->id,
                gmdate('Y-m-d H:i:s', time() - self::SESSION_TTL)
            ));
            if ($other_sessions_raw === null && $wpdb->last_error !== '') {
                throw new RuntimeException('meeting_other_session_count_unavailable');
            }
            $other_sessions = (int) $other_sessions_raw;"""),
]
for old,new in replacements:
    assert old in t, 'R29 count anchor missing'
    t=t.replace(old,new,1)

side_effects=[
("state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND state IN ('waiting','joined')",'meeting_end_sessions_write_failed'),
("state='left',left_at=%s,updated_at=%s,version=version+1 WHERE meeting_id=%d AND state IN ('waiting','admitted','joined')",'meeting_end_participants_write_failed'),
("state='joined',joined_at=COALESCE(joined_at,%s),left_at=NULL,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",'meeting_admit_sessions_write_failed'),
("state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state='waiting'",'meeting_deny_sessions_write_failed'),
("state='left',left_at=%s,last_seen_at=%s WHERE meeting_id=%d AND user_id=%d AND state IN ('waiting','joined')",'meeting_remove_sessions_write_failed'),
]
for needle,err in side_effects:
    qpos=t.find('$wpdb->query($wpdb->prepare(',t.find(needle)-500)
    npos=t.find(needle,qpos)
    end=t.find('));',npos)
    assert qpos>=0 and npos>=0 and end>=0, f'R29 side effect anchor missing {err}'
    block=t[qpos:end+3]
    if block.lstrip().startswith('$wpdb->query'):
        indent=block[:len(block)-len(block.lstrip())]
        expr=block.strip()
        replacement=indent+'if ('+expr[:-1]+' === false) {\n'+indent+"    throw new RuntimeException('"+err+"');\n"+indent+'}'
        t=t[:qpos]+replacement+t[end+3:]

active_sql="SELECT id,media_state FROM "
occ=[]; start=0
while True:
    p=t.find(active_sql,start)
    if p<0: break
    occ.append(p); start=p+1
assert len(occ)>=2
# Insert DB checks after the two $active_sessions get_results statements.
for p in reversed(occ[-2:]):
    start_stmt=t.rfind('$active_sessions = $wpdb->get_results(',0,p)
    end_stmt=t.find('));',p)
    assert start_stmt>=0 and end_stmt>=0
    insert=end_stmt+3
    line_start=t.rfind('\n',0,start_stmt)+1
    indent=t[line_start:start_stmt]
    check="\n"+indent+"if ($active_sessions === null && $wpdb->last_error !== '') {\n"+indent+"    throw new RuntimeException('meeting_active_sessions_read_failed');\n"+indent+"}"
    t=t[:insert]+check+t[insert:]

meet_path.write_text(t,encoding='utf-8')

tests=test_path.read_text(encoding='utf-8'); marker='\nif ($fail) {\n'
assert marker in tests and '// Round 29 —' not in tests
block=r'''

// Round 29 — Sabri Meet transactional, capacity and moderation side effects fail closed.
$meet=$read('includes/class-sn-meet.php');
$check(!str_contains($meet,"$wpdb->query('COMMIT');") && substr_count($meet,"query('COMMIT') === false")>=6 && str_contains($meet,'transaction_commit_failed'), 'Round 29: Meet transactions must confirm COMMIT before success/audit/notification.');
$check(substr_count($meet,'meeting_active_count_unavailable')>=2 && str_contains($meet,'meeting_user_session_count_unavailable') && str_contains($meet,'meeting_room_session_count_unavailable') && str_contains($meet,'meeting_other_session_count_unavailable'), 'Round 29: Meet participant/session capacity reads must not cast DB uncertainty to zero.');
$check(str_contains($meet,'meeting_end_sessions_write_failed') && str_contains($meet,'meeting_end_participants_write_failed') && str_contains($meet,'meeting_admit_sessions_write_failed') && str_contains($meet,'meeting_deny_sessions_write_failed') && str_contains($meet,'meeting_remove_sessions_write_failed'), 'Round 29: moderation must not commit when session/participant side effects fail.');
$check(substr_count($meet,'meeting_active_sessions_read_failed')>=2, 'Round 29: mute/lower-hand must fail closed when active-session snapshots cannot be read.');
'''
test_path.write_text(tests.replace(marker,block+marker,1),encoding='utf-8')
