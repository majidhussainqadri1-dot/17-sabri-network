<?php
/** Runtime contracts for fail-closed canonical File-00 age handling. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['fr4_age_filter'] = null;
$GLOBALS['fr4_assertion'] = [
    'eligible' => true,
    'can_message' => true,
    'suspended' => false,
    'guardian_verified' => false,
    'age_state' => 'unknown',
];

function apply_filters(string $hook, $value, ...$args) {
    if ($hook === 'sn_network_user_age_state' && is_string($GLOBALS['fr4_age_filter'])) return $GLOBALS['fr4_age_filter'];
    return $value;
}
function get_user_meta(int $user_id, string $key, bool $single = false) { return $key === 'sn_privacy' ? [] : ''; }
function user_can(int $user_id, string $cap): bool { return false; }
function wp_unslash($value) { return $value; }
final class WP_Error { public function __construct(...$args) {} }
function is_wp_error($value): bool { return $value instanceof WP_Error; }

final class SN_Membership_Assertions {
    public static function available(): bool { return true; }
    public static function communication(int $user_id): array|WP_Error { return $GLOBALS['fr4_assertion']; }
}

final class SN_DB {
    public static bool $contacts = false;
    public static bool $shared = false;
    public static bool $blocked = false;
    public static function are_contacts(int $a, int $b): bool { return self::$contacts; }
    public static function share_active_conversation(int $a, int $b): bool { return self::$shared; }
    public static function is_blocked(int $a, int $b): bool { return self::$blocked; }
    public static function member_role(int $conversation_id, int $user_id): string { return ''; }
    public static function consume_rate_limit(string $bucket, string $subject, int $limit, int $window): bool { return true; }
}

require dirname(__DIR__) . '/includes/class-sn-policy.php';
$checks = 0; $failures = [];
$check = static function(bool $condition, string $message) use (&$checks, &$failures): void { $checks++; if (!$condition) $failures[] = $message; };

// Canonical unknown cannot be promoted by a permissive File-17 extension filter.
$GLOBALS['fr4_assertion']['age_state'] = 'unknown';
$GLOBALS['fr4_age_filter'] = 'adult';
$check(SN_Policy::age_state(10) === 'unknown', 'Canonical unknown age must remain unknown despite a permissive adult filter.');
$check(!SN_Policy::has_verified_adult_age(10), 'Canonical unknown age must not be treated as verified adult.');
$check(SN_Policy::requires_protective_age_defaults(10), 'Canonical unknown age must receive protective defaults.');
$privacy = SN_Policy::privacy_for(10);
foreach (['phone_visibility','last_seen','profile_photo','groups','calls','messages','updates','follows'] as $key) {
    $check(($privacy[$key] ?? '') === 'contacts', "Unknown-age privacy must force $key to contacts.");
}
SN_DB::$contacts = true; SN_DB::$shared = true;
$check(!SN_Policy::can_view_presence(20, 10), 'Unknown-age presence must remain hidden even from contacts/shared conversations.');

// Canonical minor cannot be promoted either.
$GLOBALS['fr4_assertion']['age_state'] = 'minor';
$GLOBALS['fr4_age_filter'] = 'adult';
SN_DB::$contacts = false; SN_DB::$shared = true;
$check(SN_Policy::age_state(10) === 'minor', 'Canonical minor age must remain minor despite a permissive adult filter.');
$check(!SN_Policy::can_view_presence(20, 10), 'Minor presence must not be visible only because a conversation is shared.');
SN_DB::$contacts = true;
$check(SN_Policy::can_view_presence(20, 10), 'Minor presence may follow contacts-only privacy for an accepted contact.');

// A canonical adult may be tightened by an extension filter.
$GLOBALS['fr4_assertion']['age_state'] = 'adult';
$GLOBALS['fr4_age_filter'] = null;
$check(SN_Policy::has_verified_adult_age(10), 'Canonical adult age must be recognized as verified adult.');
$check(!SN_Policy::requires_protective_age_defaults(10), 'Canonical adults must not be forced into protective defaults.');
$GLOBALS['fr4_age_filter'] = 'unknown';
$check(SN_Policy::age_state(10) === 'unknown', 'A restrictive extension may tighten canonical adult age to unknown.');
$check(SN_Policy::requires_protective_age_defaults(10), 'A restrictive age override must restore protective defaults.');

if ($failures) { fwrite(STDERR, "Policy-age runtime failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n"); exit(1); }
echo "Policy-age runtime contracts: PASS ($checks checks)\n";
