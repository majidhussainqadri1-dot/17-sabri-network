from pathlib import Path
root=Path('sabri-network'); p=root/'includes/class-sn-meet.php'; t=p.read_text(encoding='utf-8')
repls=[
("public static function health(): WP_REST_Response {","public static function health(): WP_REST_Response|WP_Error {"),
("public static function list_meetings(): WP_REST_Response {","public static function list_meetings(): WP_REST_Response|WP_Error {"),
]
for a,b in repls:
    assert a in t; t=t.replace(a,b,1)
old="""            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                $missing[] = $name;
            }"""
new="""            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($wpdb->last_error !== '') {
                return new WP_Error('meet_health_unavailable', 'Sabri Meet storage health could not be verified safely.', ['status' => 503]);
            }
            if ($found !== $table) {
                $missing[] = $name;
            }"""
assert old in t; t=t.replace(old,new,1)
old="""        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.*,p.role participant_role,p.state participant_state FROM ' . self::table('meetings') . ' m INNER JOIN ' . self::table('participants') . ' p ON p.meeting_id=m.id AND p.user_id=%d WHERE m.status IN (\\'scheduled\\',\\'live\\',\\'ended\\',\\'cancelled\\') ORDER BY FIELD(m.status,\\'live\\',\\'scheduled\\',\\'ended\\',\\'cancelled\\'),COALESCE(m.scheduled_start,m.created_at) DESC LIMIT 100',
            $user_id
        ));
        return rest_ensure_response(['meetings' => array_map(fn($row) => self::format_meeting($row, $user_id), $rows)]);"""
new="""        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.*,p.role participant_role,p.state participant_state FROM ' . self::table('meetings') . ' m INNER JOIN ' . self::table('participants') . ' p ON p.meeting_id=m.id AND p.user_id=%d WHERE m.status IN (\\'scheduled\\',\\'live\\',\\'ended\\',\\'cancelled\\') ORDER BY FIELD(m.status,\\'live\\',\\'scheduled\\',\\'ended\\',\\'cancelled\\'),COALESCE(m.scheduled_start,m.created_at) DESC LIMIT 100',
            $user_id
        ));
        if ($wpdb->last_error !== '') {
            return new WP_Error('meetings_unavailable', 'Sabri Meet sessions could not be read safely.', ['status' => 503]);
        }
        return rest_ensure_response(['meetings' => array_map(fn($row) => self::format_meeting($row, $user_id), $rows ?: [])]);"""
assert old in t; t=t.replace(old,new,1)
old="""        $session_rows = $wpdb->get_results($wpdb->prepare(
            \"SELECT user_id,media_state FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND state='joined' AND last_seen_at>=%s ORDER BY id ASC LIMIT 1500\",
            (int) $meeting->id,
            gmdate('Y-m-d H:i:s', time() - self::SESSION_TTL)
        ));
        $media_by_user = [];"""
new="""        if ($wpdb->last_error !== '') {
            return new WP_Error('meet_participants_unavailable', 'The meeting participant roster could not be read safely.', ['status' => 503]);
        }
        $session_rows = $wpdb->get_results($wpdb->prepare(
            \"SELECT user_id,media_state FROM \" . self::table('sessions') . \" WHERE meeting_id=%d AND state='joined' AND last_seen_at>=%s ORDER BY id ASC LIMIT 1500\",
            (int) $meeting->id,
            gmdate('Y-m-d H:i:s', time() - self::SESSION_TTL)
        ));
        if ($wpdb->last_error !== '') {
            return new WP_Error('meet_sessions_unavailable', 'The meeting media-session roster could not be read safely.', ['status' => 503]);
        }
        $rows = $rows ?: [];
        $session_rows = $session_rows ?: [];
        $media_by_user = [];"""
assert old in t; t=t.replace(old,new,1)
old="""        $output = [];
        foreach ($rows as $row) {"""
# Only signal function occurrence after get_signals position
pos=t.index('public static function get_signals')
pos2=t.index(old,pos)
t=t[:pos2]+"""        if ($wpdb->last_error !== '') {
            return new WP_Error('meet_signals_unavailable', 'Meeting signals could not be read safely.', ['status' => 503]);
        }
        $rows = $rows ?: [];
        $output = [];
        foreach ($rows as $row) {"""+t[pos2+len(old):]
p.write_text(t,encoding='utf-8')
q=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; s=q.read_text(encoding='utf-8'); marker='\nif ($fail) {\n'; assert marker in s and '// Round 30 —' not in s
block=r'''

// Round 30 — Sabri Meet collection/health reads fail closed on database uncertainty.
$meet=$read('includes/class-sn-meet.php');
$check(str_contains($meet,'meet_health_unavailable') && str_contains($meet,'Sabri Meet storage health could not be verified safely.'), 'Round 30: health DB uncertainty must not be reported as missing tables/healthy state.');
$check(str_contains($meet,'meetings_unavailable') && str_contains($meet,'meet_participants_unavailable') && str_contains($meet,'meet_sessions_unavailable'), 'Round 30: meeting list and roster DB uncertainty must not become successful empty collections.');
$check(str_contains($meet,'meet_signals_unavailable') && substr_count($meet,'$wpdb->last_error')>=10, 'Round 30: signaling read DB uncertainty must not become a successful empty signal list.');
'''
q.write_text(s.replace(marker,block+marker,1),encoding='utf-8')
