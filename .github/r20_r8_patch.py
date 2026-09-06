from pathlib import Path
p=Path('sabri-network/includes/class-sn-call-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
# request-scoped lock-truth flag
s=s.replace("    private const LOCK_TIMEOUT = 5;\n", "    private const LOCK_TIMEOUT = 5;\n    private static bool $lock_truth_error = false;\n",1)
# reset flag after route/method gating, before DB discovery
s=s.replace("        $locks = []; global $wpdb; $actor = get_current_user_id();\n", "        $locks = []; global $wpdb; $actor = get_current_user_id();\n        self::$lock_truth_error = false;\n",1)
# meeting lookup checked
old='''            $meeting = $wpdb->get_row($wpdb->prepare("SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s", $public));\n            if ($meeting) {'''
new='''            $wpdb->last_error = '';\n            $meeting = $wpdb->get_row($wpdb->prepare("SELECT id,host_id,conversation_id FROM {$wpdb->prefix}sn_meet_meetings WHERE public_id=%s", $public));\n            if ($wpdb->last_error !== '') return self::lock_truth_error();\n            if ($meeting) {'''
if old not in s: raise SystemExit('meeting read target missing')
s=s.replace(old,new,1)
# call conversation checked
old="""            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));\n            if ($conversation > 0) {"""
new="""            $wpdb->last_error = '';\n            $conversation_raw = $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call));\n            if ($wpdb->last_error !== '') return self::lock_truth_error();\n            $conversation = (int)$conversation_raw;\n            if ($conversation > 0) {"""
if old not in s: raise SystemExit('call read target missing')
s=s.replace(old,new,1)
# abort before lock acquisition if helper discovery failed
old="""        if (!$locks) return $result;\n        $locks = array_values(array_unique($locks)); sort($locks, SORT_STRING); $held=[];"""
new="""        if (self::$lock_truth_error) return self::lock_truth_error();\n        if (!$locks) return $result;\n        $locks = array_values(array_unique($locks)); sort($locks, SORT_STRING); $held=[];"""
if old not in s: raise SystemExit('pre-acquire target missing')
s=s.replace(old,new,1)
# space helper fail-closed flag
old="""        $space = (int)$wpdb->get_var($wpdb->prepare(\n            'SELECT id FROM ' . SN_DB::table('spaces') . ' WHERE conversation_id=%d LIMIT 1',\n            $conversation\n        ));\n        if ($space > 0) $locks[] = 'sn:f17:space:' . substr(hash('sha256', (string)$space), 0, 32);"""
new="""        $wpdb->last_error = '';\n        $space_raw = $wpdb->get_var($wpdb->prepare(\n            'SELECT id FROM ' . SN_DB::table('spaces') . ' WHERE conversation_id=%d LIMIT 1',\n            $conversation\n        ));\n        if ($wpdb->last_error !== '') { self::$lock_truth_error = true; return; }\n        $space = (int)$space_raw;\n        if ($space > 0) $locks[] = 'sn:f17:space:' . substr(hash('sha256', (string)$space), 0, 32);"""
if old not in s: raise SystemExit('space helper target missing')
s=s.replace(old,new,1)
# direct type + peer helper fail-closed flag
old="""        $type = (string)$wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $conversation));\n        if ($type !== 'direct') return;\n        $peer = (int)$wpdb->get_var($wpdb->prepare(\n            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',\n            $conversation,\n            $actor\n        ));\n        if ($peer > 0) $locks[] = SN_Relationships::pair_lock_name($actor, $peer);"""
new="""        $wpdb->last_error = '';\n        $type_raw = $wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $conversation));\n        if ($wpdb->last_error !== '') { self::$lock_truth_error = true; return; }\n        $type = (string)$type_raw;\n        if ($type !== 'direct') return;\n        $wpdb->last_error = '';\n        $peer_raw = $wpdb->get_var($wpdb->prepare(\n            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1',\n            $conversation,\n            $actor\n        ));\n        if ($wpdb->last_error !== '') { self::$lock_truth_error = true; return; }\n        $peer = (int)$peer_raw;\n        if ($peer > 0) $locks[] = SN_Relationships::pair_lock_name($actor, $peer);"""
if old not in s: raise SystemExit('direct helper target missing')
s=s.replace(old,new,1)
# stable error helper
anchor="""    private static function requires_fresh_call_eligibility(string $route, WP_REST_Request $request): bool {"""
helper="""    private static function lock_truth_error(): WP_Error {\n        return new WP_Error('sn_call_lock_truth_unavailable', 'Call or meeting lock-discovery state could not be verified safely. Retry the request.', ['status'=>503]);\n    }\n\n"""
if anchor not in s: raise SystemExit('error helper anchor missing')
s=s.replace(anchor,helper+anchor,1)
p.write_text(s,encoding='utf-8')

# Permanent regression in existing Meet concurrency suite.
p=Path('sabri-network/tests/meet-review-2-concurrency-contracts.php')
t=p.read_text(encoding='utf-8')
if "$callRuntime = file_get_contents($root . '/includes/class-sn-call-runtime-hardening.php');" not in t:
    t=t.replace("$r6 = file_get_contents($root . '/includes/class-sn-r6-transaction-hardening.php');", "$r6 = file_get_contents($root . '/includes/class-sn-r6-transaction-hardening.php');\n$callRuntime = file_get_contents($root . '/includes/class-sn-call-runtime-hardening.php');",1)
marker='if ($failures) {'
insert="""
$check(str_contains($callRuntime, 'private static bool $lock_truth_error = false') && str_contains($callRuntime, 'sn_call_lock_truth_unavailable'), 'Fresh20 R8: call/Meet lock-discovery SQL failure must have a stable fail-closed error state.');
$check(substr_count($callRuntime, "$wpdb->last_error = ''") >= 5 && str_contains($callRuntime, 'if (self::$lock_truth_error) return self::lock_truth_error();'), 'Fresh20 R8: authoritative lock-discovery reads must clear/check DB error state before acquiring an incomplete lock set.');
$check(str_contains($callRuntime, "'sn:f17:space:'") && str_contains($callRuntime, 'SN_Relationships::pair_lock_name($actor, $peer)'), 'Fresh20 R8: fail-closed discovery must preserve canonical space and relationship lock namespaces.');
"""
idx=t.rfind(marker)
if idx<0: raise SystemExit('meet test tail missing')
t=t[:idx]+insert+t[idx:]
p.write_text(t,encoding='utf-8')
