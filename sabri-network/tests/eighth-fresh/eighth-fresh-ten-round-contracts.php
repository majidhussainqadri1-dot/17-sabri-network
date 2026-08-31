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
$migration = $read('includes/class-sn-fifth-fresh-migration-hardening.php');
$callRuntime = $read('includes/class-sn-call-runtime-hardening.php');
$auth = $read('includes/class-sn-auth.php');

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

// Round 3 — migration completion truth must cover every owned schema wave and critical DB constraints.
foreach (['message_receipts','message_mentions','message_pins','message_stars','message_folders','message_folder_items','message_hides','event_outbox','event_inbox','message_requests','scheduled_messages','poll_votes','community_settings','community_artifacts','community_responses','two_plan_idempotency'] as $table) {
    $check(str_contains($migration, "SN_DB::table('{$table}')"), "Round 3: central migration verification must include {$table}.");
}
foreach (['sn_meet_meetings','sn_meet_participants','sn_meet_sessions','sn_meet_signals','sn_meet_events','sn_future_records','sn_future_device_keys','sn_future_key_log','sn_future_message_versions'] as $table) {
    $check(str_contains($migration, $table), "Round 3: central migration verification must include {$table}.");
}
$check(str_contains($migration, "[SN_Two_Plan_Contract_Firewall::class,'install']") && str_contains($migration, "[self::class,'install_r14_schema']"), 'Round 3: late firewall and R14 schemas must execute inside the central migration lock.');
$check(str_contains($migration, "'target_type','target_ref','request_fingerprint','appeal_count'"), 'Round 3: reports schema verification must include every R14 safety column.');
$check(str_contains($migration, "'target_ref_created',false") && str_contains($migration, "private static function index_matches"), 'Round 3: R14 index and critical indexes must be verified before completion.');
foreach (['direct_key','conversation_user','idempotency_key','reporter_client','bucket_subject','action_uuid','space_user','message_user_device','event_key','producer_event','host_request','message_revision'] as $index) {
    $check(str_contains($migration, "'{$index}'"), "Round 3: migration constraint manifest must verify {$index}.");
}
$check(str_contains($migration, "'verification'=>'all-owned-tables-columns-and-critical-indexes-pass'"), 'Round 3: migration state must only publish complete after full schema/constraint verification.');
$check(str_contains($migration, "'sn_two_plan_firewall_schema_version'") && str_contains($migration, "'sn_message_receipts_schema_version'") && str_contains($migration, "'sn_r14_safety_schema_version'"), 'Round 3: rollback snapshot must include late/current schema version markers.');

// Round 4 — call signaling must be encrypted at rest, bounded and credential delivery short-lived.
$check(str_contains($callRuntime, "private const CLASSIC_SIGNAL_TTL = 120") && str_contains($callRuntime, "private const CLASSIC_SIGNAL_PREFIX = 'SNCALLSIG1:'") && str_contains($callRuntime, "private const MEET_SIGNAL_PREFIX = 'SNMEETSIG1:'"), 'Round 4: classic and Meet signal stores must use bounded encrypted-envelope contracts.');
$check(str_contains($callRuntime, "[self::class,'send_classic_signal']") && str_contains($callRuntime, "[self::class,'get_classic_signals']") && str_contains($callRuntime, "[self::class,'send_meet_signal']") && str_contains($callRuntime, "[self::class,'get_meet_signals']"), 'Round 4: final signaling routes must be owned by the protected call runtime.');
$check(substr_count($callRuntime, 'SN_Communication_Crypto::encrypt(') >= 1 && str_contains($callRuntime, 'SN_Communication_Crypto::decrypt('), 'Round 4: stored signal payloads must pass through authenticated encryption/decryption.');
$check(str_contains($callRuntime, "created_at>=%s") && str_contains($callRuntime, 'cleanup_classic_signals'), 'Round 4: classic signaling must not expose or retain the former day-long unconsumed window.');
$check(str_contains($callRuntime, 'rotate_legacy_signal_row') && str_contains($callRuntime, 'AND payload=%s'), 'Round 4: authorized legacy plaintext signals must migrate with compare-and-swap protection.');
$check(str_contains($auth, 'private const MAX_LEGACY_TURN_TTL = 10 * MINUTE_IN_SECONDS') && str_contains($auth, '$expires <= $now + self::MAX_LEGACY_TURN_TTL'), 'Round 4: legacy TURN credentials must reject provider expiries beyond the approved short lifetime.');

if ($fail) {
    fwrite(STDERR, "Eighth fresh 10-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Eighth fresh 10-round contracts: PASS ($checks checks)\n";
