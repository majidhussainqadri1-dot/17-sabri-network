<?php
/** Sabri Meet review 1: authorization, state and canonical ownership. */
$root = dirname(__DIR__);
$meet = file_get_contents($root . '/includes/class-sn-meet.php');
$main = file_get_contents($root . '/sabri-network.php');
$activator = file_get_contents($root . '/includes/class-sn-activator.php');
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$check(str_contains($main, "require_once SN_DIR . 'includes/class-sn-meet.php'"), 'Sabri Meet runtime must be loaded by File 17.');
$check(str_contains($main, 'SN_Meet::register()'), 'Sabri Meet hooks must be registered by File 17.');
$check(str_contains($meet, "private const NS = 'sabri-network/v2'"), 'Sabri Meet must remain inside the File-17 REST namespace.');
$check(str_contains($meet, "'/meetings'"), 'Canonical meeting inventory/create route must exist.');
$check(str_contains($meet, "'/meetings/(?P<meeting>"), 'Meeting-scoped routes must use opaque identifiers.');
$check(str_contains($meet, 'SN_Policy::access()'), 'Every meeting route must reuse canonical File-17 access policy.');
$check(str_contains($meet, 'SN_Policy::has_verified_adult_age($user_id)'), 'Meeting hosts must have verified adult identity.');
$check(str_contains($meet, "'idempotency_key_required'"), 'Meeting creation must require an idempotency key.');
$check(str_contains($meet, "random_bytes(24)"), 'Meeting identifiers must use cryptographic randomness.');
$check(str_contains($meet, "'scheduled', 'live'"), 'Meeting lifecycle must distinguish scheduled and live states.');
$check(str_contains($meet, "['end', 'lock', 'unlock', 'promote', 'demote']"), 'High-authority moderation actions must be explicitly host-only.');
$check(str_contains($meet, "['denied', 'removed']"), 'Denied and removed participants must fail closed.');
$check(str_contains($meet, "if (!self::insert_event"), 'Governance mutations must require their durable meeting event.');
$check(str_contains($meet, "'owner' => 'file-17'"), 'Published route registration must preserve File-17 ownership.');
$check(str_contains($meet, "add_rewrite_rule('^calls/"), 'Canonical /calls/{id}/ route must be registered.');
$check(str_contains($activator, 'SN_Meet::register_rewrites()'), 'Activation must materialize Sabri Meet rewrite rules before flushing.');
$check(str_contains($meet, 'disable_canonical'), 'WordPress canonical redirects must be disabled only on valid Meet routes.');
$check(!str_contains($meet, 'End-to-End Encrypted') && !str_contains($meet, '100% Secure'), 'Unsupported encryption or absolute-security claims are forbidden.');
$check(str_contains($meet, '$wpdb->esc_like($table)'), 'Meet health checks must escape SQL LIKE wildcards in table names.');
$check(str_contains($meet, "(string) \$participant->role === 'cohost' && in_array((string) \$participant->state, ['admitted', 'joined'], true)"), 'Inactive co-hosts must not receive optimistic moderation controls.');
if ($failures) {
    fwrite(STDERR, "Sabri Meet review 1 failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Sabri Meet review 1 auth/state contracts: PASS ($checks checks)\n";
