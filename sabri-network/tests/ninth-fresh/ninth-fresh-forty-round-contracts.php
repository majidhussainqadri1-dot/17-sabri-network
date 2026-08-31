<?php
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
