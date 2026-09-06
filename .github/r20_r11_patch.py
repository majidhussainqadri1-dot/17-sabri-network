from pathlib import Path
p=Path('sabri-network/includes/class-sn-fourth-fresh-privacy-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        $held = (int) $wpdb->get_var($wpdb->prepare(
            \"SELECT r.id
             FROM $reports r
             LEFT JOIN $messages m ON m.id=r.message_id
             WHERE r.legal_hold=1
               AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d)
             LIMIT 1\",
            $user_id,
            $user_id,
            $user_id
        ));
        return $held > 0;
"""
new="""        $wpdb->last_error = '';
        $held_raw = $wpdb->get_var($wpdb->prepare(
            \"SELECT r.id
             FROM $reports r
             LEFT JOIN $messages m ON m.id=r.message_id
             WHERE r.legal_hold=1
               AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d)
             LIMIT 1\",
            $user_id,
            $user_id,
            $user_id
        ));
        if ($wpdb->last_error !== '') {
            if (class_exists('SN_DB')) {
                SN_DB::audit('legal_hold_discovery_failed', 'user', $user_id, 'failure', [
                    'query'=>'native_file17_hold',
                ], 0);
            }
            // Retention authorization is a safety boundary: unknown DB truth must
            // preserve data until a successful read proves that no hold exists.
            return true;
        }
        return (int) $held_raw > 0;
"""
if old not in s: raise SystemExit('legal hold target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/sixth-fresh-twenty-round-contracts.php')
t=p.read_text(encoding='utf-8')
anchor="$privacy = $read('includes/class-sn-sixth-fresh-privacy-hardening.php');\n"
if "$privacyFourth = $read('includes/class-sn-fourth-fresh-privacy-hardening.php');" not in t:
    if anchor not in t: raise SystemExit('privacy variable anchor missing')
    t=t.replace(anchor,anchor+"$privacyFourth = $read('includes/class-sn-fourth-fresh-privacy-hardening.php');\n",1)
marker='if ($fail) {'
insert="""
// Fresh20 Round 11 — legal/safety hold discovery must fail closed on DB truth loss.
$check(str_contains($privacyFourth, "$wpdb->last_error = ''") && str_contains($privacyFourth, 'legal_hold_discovery_failed'), 'Fresh20 R11: native legal-hold discovery must clear/check database error state and emit audit evidence.');
$holdErrorPos = strpos($privacyFourth, "if ($wpdb->last_error !== '')");
$holdReturnPos = strpos($privacyFourth, 'return true;', $holdErrorPos === false ? 0 : $holdErrorPos);
$check($holdErrorPos !== false && $holdReturnPos !== false && $holdErrorPos < $holdReturnPos, 'Fresh20 R11: unavailable legal-hold database truth must retain data fail-closed.');
"""
idx=t.rfind(marker)
if idx<0: raise SystemExit('test tail missing')
t=t[:idx]+insert+t[idx:]
p.write_text(t,encoding='utf-8')
