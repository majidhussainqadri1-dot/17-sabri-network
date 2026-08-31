#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path

root=Path('sabri-network')

def replace(path, old, new, count=1):
    p=root/path
    text=p.read_text(encoding='utf-8')
    found=text.count(old)
    if found < count:
        raise SystemExit(f'{path}: expected >= {count} occurrences, found {found}: {old[:120]!r}')
    p.write_text(text.replace(old,new,count),encoding='utf-8')

# R2.1: repeat File-00/account authority after all pre-dispatch locks/caches.
p=Path('includes/class-sn-runtime-boundary-policy.php')
replace(p,
"        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch_access_gate'], -30000, 3);\n",
"        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch_access_gate'], -30000, 3);\n        add_filter('rest_pre_dispatch', [self::class, 'final_identity_gate'], PHP_INT_MAX, 3);\n")
replace(p,
"    public static function reconcile_search_epoch(): void {\n",
"""    /** Final action-time identity check after all File-17 pre-dispatch locks and caches. */
    public static function final_identity_gate($result, WP_REST_Server $server, WP_REST_Request $request) {
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $result;

        // SN_Policy::access() clears the process-local File-00 assertion cache before
        // evaluating eligibility/suspension, so a state change during GET_LOCK or
        // idempotency processing cannot reach the mutation callback on stale truth.
        $access = SN_Policy::access();
        if (is_wp_error($access)) return $access;

        if (str_starts_with($route, '/sabri-network/v2/admin/high-risk-actions')) {
            $admin = SN_REST::admin_access();
            if (is_wp_error($admin) || $admin !== true) {
                return is_wp_error($admin) ? $admin : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
            }
        }
        return $result;
    }

    public static function reconcile_search_epoch(): void {
""")

# R2.2: completed idempotency records dedupe by default without replaying stale private payloads.
p=Path('includes/class-sn-two-plan-contract-firewall.php')
replace(p,"self::existing_result($fresh, $request_hash, $scope_key)","self::existing_result($fresh, $request_hash, $scope_key, $request)")
replace(p,"self::existing_result($existing, $request_hash, $scope_key)","self::existing_result($existing, $request_hash, $scope_key, $request)")
replace(p,"self::existing_result($race, $request_hash, $scope_key)","self::existing_result($race, $request_hash, $scope_key, $request)")
replace(p,
"    private static function existing_result(object $existing, string $request_hash, string $scope_key) {\n",
"    private static function existing_result(object $existing, string $request_hash, string $scope_key, WP_REST_Request $request) {\n")
replace(p,
"""        $plain = SN_Communication_Crypto::decrypt((string) $existing->response_cipher, 'two-plan-idempotency|'.$scope_key);
""",
"""        // rest_pre_dispatch runs before the route callback's object-level authorization.
        // Never decrypt/replay a previously successful private response merely because
        // the same account still owns the idempotency key. Membership, role, block,
        // guardian or object state may have changed since the original mutation.
        $authorized = apply_filters('sn_network_idempotency_replay_authorized', false, $request, (int)$existing->actor_id, $scope_key);
        if ($authorized !== true) {
            return new WP_REST_Response([
                'idempotent_replay' => true,
                'refetch_required' => true,
            ], max(200, min(299, (int)$existing->response_code)));
        }

        $plain = SN_Communication_Crypto::decrypt((string) $existing->response_cipher, 'two-plan-idempotency|'.$scope_key);
""")

# Permanent ninth-fresh regression suite.
test=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
test.parent.mkdir(parents=True,exist_ok=True)
test.write_text(r'''<?php
/** File 17 ninth fresh 40-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

// Round 2 — action-time File-00 truth and idempotent replay disclosure boundary.
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$firewall = $read('includes/class-sn-two-plan-contract-firewall.php');
$check(str_contains($boundary, "add_filter('rest_pre_dispatch', [self::class, 'final_identity_gate'], PHP_INT_MAX, 3)"), 'Round 2: final identity gate must execute after every File-17 pre-dispatch lock/cache layer.');
$finalPos = strpos($boundary, 'public static function final_identity_gate');
$nextPos = $finalPos === false ? false : strpos($boundary, 'public static function reconcile_search_epoch', $finalPos);
$segment = $finalPos === false ? '' : substr($boundary, $finalPos, ($nextPos === false ? strlen($boundary) : $nextPos) - $finalPos);
$check(str_contains($segment, 'SN_Policy::access()') && !str_contains($segment, 'if ($result !== null) return $result;'), 'Round 2: final identity gate must revalidate even when an earlier pre-dispatch layer produced a cached result.');
$check(str_contains($segment, 'SN_REST::admin_access()'), 'Round 2: high-risk admin mutations must also revalidate administrator authority at the final action-time gate.');
$check(substr_count($firewall, 'self::existing_result(') >= 3 && substr_count($firewall, '$scope_key, $request)') >= 3, 'Round 2: every idempotency replay path must carry the current request into the replay authorization boundary.');
$check(str_contains($firewall, "apply_filters('sn_network_idempotency_replay_authorized', false") && str_contains($firewall, "'refetch_required' => true"), 'Round 2: completed idempotency records must default to dedupe-only/refetch-required rather than stale private-response disclosure.');
$authPos = strpos($firewall, "apply_filters('sn_network_idempotency_replay_authorized'");
$decryptPos = strpos($firewall, 'SN_Communication_Crypto::decrypt', $authPos === false ? 0 : $authPos);
$check($authPos !== false && $decryptPos !== false && $authPos < $decryptPos && str_contains($firewall, '$authorized !== true'), 'Round 2: cached response decryption must be unreachable without strict current replay authorization.');

if ($fail) {
    fwrite(STDERR, "Ninth fresh 40-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Ninth fresh 40-round contracts: PASS ($checks checks)\n";
''',encoding='utf-8')

# Wire the permanent suite into the exact inventory gate.
quality=root/'tools/quality-check.sh'
text=quality.read_text(encoding='utf-8')
old="  eighth-fresh/eighth-fresh-ten-round-contracts.php\n)"
new="  eighth-fresh/eighth-fresh-ten-round-contracts.php\n  ninth-fresh/ninth-fresh-forty-round-contracts.php\n)"
if old not in text:
    raise SystemExit('quality-check suite anchor missing')
quality.write_text(text.replace(old,new,1),encoding='utf-8')

# The inventory increases by exactly one permanent PHP suite.
for rel in [
    'QA-INVENTORY.txt',
    'SYSTEM-STATUS.txt',
    'tests/eighth-fresh/eighth-fresh-ten-round-contracts.php',
    'tests/two-plan-completion-contracts.php',
]:
    p=root/rel
    text=p.read_text(encoding='utf-8')
    if '55 PHP review suites' in text:
        p.write_text(text.replace('55 PHP review suites','56 PHP review suites'),encoding='utf-8')
PY

php -l sabri-network/includes/class-sn-runtime-boundary-policy.php
php -l sabri-network/includes/class-sn-two-plan-contract-firewall.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
