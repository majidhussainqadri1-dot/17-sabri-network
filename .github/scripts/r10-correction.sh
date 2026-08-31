#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path

def replace(path, old, new, count=1):
    p=Path(path); text=p.read_text(encoding='utf-8')
    n=text.count(old)
    if n < count:
        raise SystemExit(f'{path}: expected at least {count} occurrences, found {n}: {old[:100]!r}')
    text=text.replace(old,new,count)
    p.write_text(text,encoding='utf-8')

# 1) Final message retention/legal-hold checks must fail closed on DB errors.
p='sabri-network/includes/class-sn-round20-correction.php'
replace(p,
"""            $message_id = (int)$wpdb->get_var($wpdb->prepare('SELECT message_id FROM ' . SN_DB::table('reports') . ' WHERE id=%d', (int)$m[1]));
            if ($message_id > 0) $locks[] = self::retention_lock($message_id);""",
"""            $message_id = (int)$wpdb->get_var($wpdb->prepare('SELECT message_id FROM ' . SN_DB::table('reports') . ' WHERE id=%d', (int)$m[1]));
            if ($wpdb->last_error !== '') return new WP_Error('sn_legal_hold_verification_failed', 'The legal-hold scope could not be verified safely.', ['status'=>503]);
            if ($message_id > 0) $locks[] = self::retention_lock($message_id);""")
replace(p,
"""            if (self::message_has_legal_hold($id)) throw new UnexpectedValueException('legal_hold');""",
"""            $hold = self::message_has_legal_hold($id);
            if (is_wp_error($hold)) throw new RuntimeException('sn_legal_hold_verification_failed');
            if ($hold) throw new UnexpectedValueException('legal_hold');""")
replace(p,
"""            if ($e instanceof UnexpectedValueException && $e->getMessage()==='delete_forbidden') return new WP_Error('delete_forbidden','This message can no longer be deleted.',['status'=>403]);
            SN_DB::audit('message_atomic_delete_failed'""",
"""            if ($e instanceof UnexpectedValueException && $e->getMessage()==='delete_forbidden') return new WP_Error('delete_forbidden','This message can no longer be deleted.',['status'=>403]);
            if ($e instanceof RuntimeException && $e->getMessage()==='sn_legal_hold_verification_failed') return new WP_Error('sn_legal_hold_verification_failed','The legal-hold state could not be verified safely. Retry the request.',['status'=>503]);
            SN_DB::audit('message_atomic_delete_failed'""")
replace(p,
"""                    if ($expires==='' || strtotime($expires.' UTC')>time() || self::message_has_legal_hold($id)) { $wpdb->query('COMMIT'); continue; }""",
"""                    $hold = self::message_has_legal_hold($id);
                    if (is_wp_error($hold)) throw new RuntimeException('sn_legal_hold_verification_failed');
                    if ($expires==='' || strtotime($expires.' UTC')>time() || $hold) { if ($wpdb->query('COMMIT')===false) throw new RuntimeException('expire_noop_commit_failed'); continue; }""")
replace(p,
"""    private static function message_has_legal_hold(int $id): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('reports').' WHERE message_id=%d AND legal_hold=1 LIMIT 1',$id)); }""",
"""    private static function message_has_legal_hold(int $id): bool|WP_Error {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('reports').' WHERE message_id=%d AND legal_hold=1 LIMIT 1',$id));
        if ($wpdb->last_error !== '') return new WP_Error('sn_legal_hold_verification_failed','The legal-hold state could not be verified safely.',['status'=>503]);
        return (bool)$value;
    }""")

p='sabri-network/includes/class-sn-fourth-fresh-lifecycle-hardening.php'
replace(p,
"""                if (self::legal_hold($id)) throw new UnexpectedValueException('legal_hold');""",
"""                $hold = self::legal_hold($id);
                if (is_wp_error($hold)) throw new RuntimeException('sn_legal_hold_verification_failed');
                if ($hold) throw new UnexpectedValueException('legal_hold');""")
replace(p,
"""                if ($e instanceof UnexpectedValueException && $e->getMessage()==='legal_hold') return new WP_Error('sn_expiry_legal_hold','This message is preserved by a safety/legal hold.',['status'=>409]);
                SN_DB::audit('message_expiry_failed'""",
"""                if ($e instanceof UnexpectedValueException && $e->getMessage()==='legal_hold') return new WP_Error('sn_expiry_legal_hold','This message is preserved by a safety/legal hold.',['status'=>409]);
                if ($e instanceof RuntimeException && $e->getMessage()==='sn_legal_hold_verification_failed') return new WP_Error('sn_legal_hold_verification_failed','The legal-hold state could not be verified safely. Retry the request.',['status'=>503]);
                SN_DB::audit('message_expiry_failed'""")
replace(p,
"""    private static function legal_hold(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('reports') . ' WHERE message_id=%d AND legal_hold=1 LIMIT 1', $id));
    }""",
"""    private static function legal_hold(int $id): bool|WP_Error {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('reports') . ' WHERE message_id=%d AND legal_hold=1 LIMIT 1', $id));
        if ($wpdb->last_error !== '') return new WP_Error('sn_legal_hold_verification_failed','The legal-hold state could not be verified safely.',['status'=>503]);
        return (bool)$value;
    }""")

# 2) Quality inventory: all 10 active JS entry points and nested current regression suite.
p='sabri-network/tools/quality-check.sh'
replace(p,
"assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/js/fifth-fresh-ui.js",
"assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/js/fifth-fresh-ui.js assets/js/round20-correction.js")
replace(p,
"js_files=(assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/js/fifth-fresh-ui.js)",
"js_files=(assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/js/fifth-fresh-ui.js assets/js/round20-correction.js)")
replace(p,
"  sixth-fresh-twenty-round-contracts.php seventh-fresh-twenty-round-contracts.php\n)",
"  sixth-fresh-twenty-round-contracts.php seventh-fresh-twenty-round-contracts.php\n  eighth-fresh/eighth-fresh-ten-round-contracts.php\n)")
replace(p,
"mapfile -t expected_tests < <(find tests -maxdepth 1 -type f -name '*.php' -printf '%f\\n' | LC_ALL=C sort)",
"mapfile -t expected_tests < <(find tests -type f -name '*.php' -printf '%P\\n' | LC_ALL=C sort)")

p='.github/workflows/quality.yml'
replace(p,
"          node --check sabri-network/assets/js/fifth-fresh-ui.js\n          python3 - <<'PY'",
"          node --check sabri-network/assets/js/fifth-fresh-ui.js\n          node --check sabri-network/assets/js/round20-correction.js\n          python3 - <<'PY'",1)

# 3) Permanent R10 contracts.
p='sabri-network/tests/eighth-fresh/eighth-fresh-ten-round-contracts.php'
text=Path(p).read_text(encoding='utf-8')
anchor="\nif ($fail) {\n"
if anchor not in text: raise SystemExit('R10 contract anchor missing')
block=r'''
// Round 10 — final integrated retention, quality-inventory and repository-truth closure.
$round20 = $read('includes/class-sn-round20-correction.php');
$lifecycle = $read('includes/class-sn-fourth-fresh-lifecycle-hardening.php');
$quality = $read('tools/quality-check.sh');
$cycleId = $read('REVIEW-CYCLE-ID.txt');
$qaInventory = $read('QA-INVENTORY.txt');
$systemStatus = $read('SYSTEM-STATUS.txt');
$candidateBoundary = $read('CURRENT-CANDIDATE-BOUNDARY.txt');
$check(str_contains($round20, 'private static function message_has_legal_hold(int $id): bool|WP_Error') && str_contains($round20, '$wpdb->last_error !== \'\'') && str_contains($round20, 'sn_legal_hold_verification_failed'), 'Round 10: final delete/expiry legal-hold verification must fail closed on database errors.');
$check(str_contains($round20, 'if ($wpdb->last_error !== \'\') return new WP_Error(\'sn_legal_hold_verification_failed\'') && str_contains($round20, '_sn_round20_locks'), 'Round 10: admin legal-hold mutation must not bypass the retention lock when the report-scope lookup fails.');
$check(str_contains($lifecycle, 'private static function legal_hold(int $id): bool|WP_Error') && str_contains($lifecycle, 'is_wp_error($hold)') && str_contains($lifecycle, 'sn_legal_hold_verification_failed'), 'Round 10: disappearing-message expiry must fail closed when hold state cannot be read.');
$check(str_contains($quality, 'assets/js/round20-correction.js') && str_contains($quality, 'eighth-fresh/eighth-fresh-ten-round-contracts.php'), 'Round 10: the self-contained quality gate must include the active Round-20 JavaScript and current eighth-fresh regression suite.');
$check(str_contains($quality, "find tests -type f -name '*.php' -printf '%P\\n'"), 'Round 10: review-suite inventory must recursively account for nested permanent suites.');
$check(str_contains($cycleId, 'FILE17-EIGHTH-FRESH-10-ROUND') && str_contains($qaInventory, '10 JavaScript') && str_contains($qaInventory, '54 PHP review suites'), 'Round 10: packaged cycle/QA inventory must describe the current eighth-fresh candidate.');
$check(str_contains($systemStatus, 'Eighth fresh 10-round cycle') && !str_contains($systemStatus, 'current sixth-cycle corrective source') && str_contains($candidateBoundary, 'eighth fresh 10-round cycle'), 'Round 10: packaged repository-state documents must not identify an older review cycle as current.');
'''
Path(p).write_text(text.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')

# Current repository-truth files. Exact immutable SHA/run stay external because embedding
# a commit's own SHA in files would immediately make them stale on the next commit.
Path('sabri-network/REVIEW-CYCLE-ID.txt').write_text('FILE17-EIGHTH-FRESH-10-ROUND-2026-08-31-TO-2026-09-01\n',encoding='utf-8')
Path('sabri-network/QA-INVENTORY.txt').write_text('''Current eighth-fresh quality inventory: 54 PHP review suites including tests/eighth-fresh/eighth-fresh-ten-round-contracts.php, all 10 File-17 JavaScript entry points, PHP 8.1/8.3 syntax and boundary gates, shell/CSS/hygiene checks, source manifest verification, and deterministic package generation. Exact immutable HEAD/run/package digest are external release evidence.\n''',encoding='utf-8')
Path('sabri-network/CURRENT-CANDIDATE-BOUNDARY.txt').write_text('''Repository-only candidate boundary: File 17 runtime 2.1.0, eighth fresh 10-round cycle. This file intentionally does not embed its own commit SHA. The exact immutable head and GitHub Actions run are external release evidence. Staging, live, deployed artifact, DB/schema, migration and operational status remain separately unverified.\n''',encoding='utf-8')
Path('sabri-network/SYSTEM-STATUS.txt').write_text('''Repository coding/review candidate; not staging-accepted, not live-deployed, not production-operational.\nFile 17 — Sabri Network and Messages 2.1.0\nRepository truth is the exact immutable HEAD plus its attached GitHub Actions evidence; prior-cycle documents and runs are historical only.\n\nGoverning audit basis:\n1) Current consolidated central governing plan.\n2) Current File 17 Final Harmonized Master Plan + Founder-approved Future Communication Superset 24.\n3) Live, staging and repository are separate realities.\n\nCoded Candidate: 2.1.0 current eighth-fresh corrective source.\nAutomated QA: 54 PHP review suites, PHP 8.1/8.3 jobs, all 10 JavaScript entry-point syntax checks, shell/CSS/hygiene, source-manifest and deterministic package gates.\nStaging Accepted: ابھی نہیں\nLive Deployed: ابھی نہیں\nOperational: ابھی نہیں\n\nEighth fresh 10-round cycle — 31 August to 1 September 2026:\n- Review method: complete review round -> freeze defect ledger -> correct every proved defect -> permanent regression/retest -> only then next round.\n- Defect rounds: R1, R2, R3, R4, R5, R7, R8, R9, R10.\n- Clean rounds: R6.\n- Permanent current regression: tests/eighth-fresh/eighth-fresh-ten-round-contracts.php.\n- Exact final SHA, workflow run and package digest are external release evidence and are not self-embedded here.\n\nCurrent cycle closure includes fail-closed File-00 authority, complete final REST method composition, full migration/constraint verification, encrypted bounded call signaling, durable delivery/idempotency truth, repository-wide transaction-start failure handling, terminal privacy/legal-hold completion checks, explicit external-provider readiness/acknowledgement, and final legal-hold mutation failure closure.\n\nExternal acceptance still pending:\n- real WordPress/PHP/MySQL fresh-install, upgrade/migration/race/rollback tests;\n- current File 00/02/08/18/19/20/21/24/25/26 and CF-01/CF-04 integration/degraded acceptance;\n- Hostinger staging, cron, approved scanner/private storage/translation/media/AI/interop providers, LiteSpeed, browser/device, RTL/LTR/accessibility;\n- communication-key backup/restore/rotation proof;\n- approved WSS/STUN/TURN/SFU, load/soak and penetration testing;\n- Founder staging sign-off, live deployment, deployed-artifact parity and operational monitoring/support/SLO evidence.\n\nThis status does not claim E2EE certification, staging acceptance, live deployment, production readiness or operational completion.\nExact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔\n''',encoding='utf-8')
PY
