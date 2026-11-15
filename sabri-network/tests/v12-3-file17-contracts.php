<?php
/**
 * V12.3 File-17 static release checks. Repository source evidence only:
 * real providers, DB migrations, accessibility and live parity need staging acceptance.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        fwrite(STDERR, "Missing V12.3 contract source: $path\n");
        exit(1);
    }
    return $content;
};
$checks = 0;
$errors = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$errors): void {
    $checks++;
    if (!$condition) $errors[] = $message;
};
$registry = $read('includes/class-sn-future-superset.php');
$part1 = $read('includes/class-sn-future-superset-part-1.php');
$core = $read('includes/class-sn-future-superset-core.php');
$ui = $read('assets/js/future-superset.js');
$css = strtolower($read('assets/css/brand-green-overrides.css'));
$main = $read('sabri-network.php');
preg_match_all("/'F17-FUT-(\\d{2})'=>/", $registry, $matches);
$actual = array_map('intval', $matches[1]);
sort($actual);
$assert($actual === range(1, 24), 'V12.3 F17-FUT-01..24 registry must be exact, contiguous and unique.');
$assert(str_contains($registry, 'FEATURE_COUNT=24'), '24-feature count must remain explicit.');
$assert(str_contains($main, "define('SN_VERSION', '2.1.0')"), 'Plugin candidate version must remain 2.1.0.');
$assert(str_contains($css, '#087a4e'), 'F17-CEN-02 primary Sabri Green must be present.');
$assert(str_contains($part1, "if(\$id==='F17-FUT-04'&&!has_filter('sn_network_step_up_verified'))"), 'Conversation-lock capability must be provider-gated without step-up.');
$assert(str_contains($part1, 'sn_conversation_step_up_provider_unavailable'), 'Lock activation must refuse missing/unverified step-up provider.');
$assert(str_contains($part1, 'function set_team_inbox(WP_REST_Request $r): WP_REST_Response|WP_Error {global $wpdb;'), 'Team inbox requires a valid WPDB scope.');
$assert(str_contains($part1, "if(\$wpdb->query('START TRANSACTION')===false)"), 'Shared team inbox mutation must start an atomic transaction.');
$assert(str_contains($part1, "if(is_wp_error(\$assignment))"), 'A failed assignment must not be reported as a successful inbox update.');
$assert(str_contains($part1, "if(\$wpdb->query('COMMIT')===false)"), 'Both team inbox writes must commit together.');
$assert(str_contains($part1, "'ui_mode'=>in_array"), 'Feature discovery must distinguish API from workspace UX.');
foreach (['snf-lock-form','snf-reminder-form','snf-template-form','snf-team-form','snf-view-form','snf-history-form'] as $id) {
    $assert(str_contains($core, $id) && str_contains($ui, $id), "Missing matched secure UI form: $id");
}
foreach (['future/team-inbox/','future/smart-views','future/messages/'] as $route) {
    $assert(str_contains($ui, $route), "Missing governed UI route: $route");
}
$assert(str_contains($ui, 'output.textContent'), 'Message version history must display as plain text rather than injected HTML.');
$assert(str_contains($ui, 'Idempotency-Key'), 'Workspace mutations must retain caller-owned idempotency identity.');
if ($errors) {
    fwrite(STDERR, "V12.3 File-17 failures (" . count($errors) . "/$checks):\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "V12.3 File-17 repository contracts: PASS ($checks checks)\n";
