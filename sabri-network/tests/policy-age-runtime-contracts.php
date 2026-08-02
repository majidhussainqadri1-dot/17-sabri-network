<?php
/** Runtime contracts for fail-closed unknown-age handling. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['fr4_meta'] = [];
$GLOBALS['fr4_age_filter'] = null;
function apply_filters(string $hook, $value, ...$args) {
    if ($hook === 'sn_network_user_age_state' && is_string($GLOBALS['fr4_age_filter'])) return $GLOBALS['fr4_age_filter'];
    return $value;
}
function get_user_meta(int $user_id, string $key, bool $single = false) { return $GLOBALS['fr4_meta'][$user_id][$key] ?? ''; }
function user_can(int $user_id, string $cap): bool { return false; }
function wp_unslash($value) { return $value; }
final class WP_Error { public function __construct(...$args) {} }
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
$GLOBALS['fr4_age_filter'] = 'unknown';
$check(SN_Policy::age_state(10) === 'unknown', 'Unknown age state must be preserved.');
$check(!SN_Policy::has_verified_adult_age(10), 'Unknown age must not be treated as verified adult.');
$check(SN_Policy::requires_protective_age_defaults(10), 'Unknown age must receive protective defaults.');
$privacy = SN_Policy::privacy_for(10);
foreach (['phone_visibility','last_seen','profile_photo','groups','calls','messages','updates','follows'] as $key) {
    $check(($privacy[$key] ?? '') === 'contacts', "Unknown-age privacy must force $key to contacts.");
}
SN_DB::$contacts = true; SN_DB::$shared = true;
$check(!SN_Policy::can_view_presence(20, 10), 'Unknown-age presence must remain hidden even from contacts/shared conversations.');
$GLOBALS['fr4_age_filter'] = 'minor';
SN_DB::$contacts = false; SN_DB::$shared = true;
$check(!SN_Policy::can_view_presence(20, 10), 'Minor presence must not be visible only because a conversation is shared.');
SN_DB::$contacts = true;
$check(SN_Policy::can_view_presence(20, 10), 'Minor presence may follow contacts-only privacy for an accepted contact.');
$GLOBALS['fr4_age_filter'] = 'adult';
$check(SN_Policy::has_verified_adult_age(10), 'Adult age state must be recognized as verified adult.');
$check(!SN_Policy::requires_protective_age_defaults(10), 'Verified adults must not be forced into protective defaults.');
if ($failures) { fwrite(STDERR, "Policy-age runtime failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n"); exit(1); }
echo "Policy-age runtime contracts: PASS ($checks checks)\n";
