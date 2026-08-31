<?php
/** File 17 eighth fresh 10-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$membership = $read('includes/class-sn-membership-assertions.php');
$policy = $read('includes/class-sn-policy.php');
$routeOwner = $read('includes/class-sn-future24-review-hardening.php');
$keyRoutes = $read('includes/class-sn-future24-review-hardening-j.php');
$reminderRoutes = $read('includes/class-sn-future24-review-hardening-m.php');

// Round 1 — File 00 must be the final fail-closed authority and every high-level
// authorization decision must start from a fresh assertion snapshot.
$finalHooks = [
    'sn_network_identity_authority_available',
    'sn_network_user_can_access',
    'sn_network_user_is_suspended',
    'sn_network_user_age_state',
    'sn_network_guardian_consent_valid',
];
foreach ($finalHooks as $hook) {
    $check(
        preg_match("/add_filter\\('" . preg_quote($hook, '/') . "'.*PHP_INT_MAX/", $membership) === 1,
        "Round 1: File 00 authority hook {$hook} must execute at final priority."
    );
}
$check(!str_contains($membership, 'PHP_INT_MIN'), 'Round 1: the former early-priority File 00 authority registration must not remain.');
$check(str_contains($policy, 'private static function refresh_identity_assertions(int ...$user_ids): void'), 'Round 1: policy must expose one bounded internal assertion-refresh boundary.');
$check(str_contains($policy, 'SN_Membership_Assertions::clear_cache($user_id);'), 'Round 1: the policy refresh boundary must invalidate the File 00 per-user assertion snapshot.');

$requiredFreshBoundaries = [
    'access' => 'self::refresh_identity_assertions($user_id);',
    'can_contact' => 'self::refresh_identity_assertions($actor_id, $target_id);',
    'can_follow' => 'self::refresh_identity_assertions($actor_id, $target_id);',
    'can_create_conversation' => 'self::refresh_identity_assertions($user_id);',
    'can_publish_public_update' => 'self::refresh_identity_assertions($user_id);',
    'can_use_group_calls' => 'self::refresh_identity_assertions($user_id);',
    'can_view_presence' => 'self::refresh_identity_assertions($viewer_id, $target_id);',
];
foreach ($requiredFreshBoundaries as $method => $refresh) {
    $methodPos = strpos($policy, 'function ' . $method . '(');
    $refreshPos = $methodPos === false ? false : strpos($policy, $refresh, $methodPos);
    $nextMethod = $methodPos === false ? false : strpos($policy, '\n    public static function ', $methodPos + 1);
    $withinMethod = $methodPos !== false && $refreshPos !== false && ($nextMethod === false || $refreshPos < $nextMethod);
    $check($withinMethod, "Round 1: {$method} must start from a fresh File 00 assertion snapshot.");
}

// Round 2 — final route ownership must preserve all sibling HTTP methods.
$check(str_contains($routeOwner, "add_action('rest_api_init', [self::class, 'final_route_composition'], 4000)"), 'Round 2: one final route-composition authority must run after historical partial overrides.');
$check(str_contains($routeOwner, "'/messages/(?P<id>\\d+)'" ) && str_contains($routeOwner, "[SN_Fourth_Fresh_Review_Hardening::class, 'edit_message']") && str_contains($routeOwner, "[SN_Round20_Correction::class, 'delete_message']"), 'Round 2: final message mutation route must preserve both POST edit and DELETE methods.');
foreach (['/future/device-keys','/future/mentorships','/future/reminders'] as $path) {
    $pos = strpos($routeOwner, "'{$path}'");
    $next = $pos === false ? false : strpos($routeOwner, "register_rest_route", $pos + 1);
    $segment = $pos === false ? '' : substr($routeOwner, $pos, ($next === false ? strlen($routeOwner) : $next) - $pos);
    $check(str_contains($segment, "'methods' => 'GET'") && str_contains($segment, "'methods' => 'POST'"), "Round 2: {$path} final composition must retain both GET and POST.");
}
$check(str_contains($keyRoutes, "'/future/device-keys',[['methods'=>'GET'") && str_contains($keyRoutes, "['methods'=>'POST'"), 'Round 2: device-key hardening must not erase its GET sibling method.');
$check(str_contains($reminderRoutes, "'/future/reminders',[['methods'=>'GET'") && str_contains($reminderRoutes, "['methods'=>'POST'"), 'Round 2: reminder hardening must not erase its GET sibling method.');

if ($fail) {
    fwrite(STDERR, "Eighth fresh 10-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Eighth fresh 10-round contracts: PASS ($checks checks)\n";
