<?php
/** Static regression contracts for the 12-Aug-2026 fresh Future-24 corrective review. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) { fwrite(STDERR, "Missing file: {$path}\n"); exit(1); }
    return (string) file_get_contents($full);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$runtime = $read('includes/class-sn-two-plan-runtime-hardening.php');
$compat = $read('includes/class-sn-compatibility-hardening.php');
$loader = $read('includes/class-sn-future24-review-hardening.php');
$future = $read('includes/class-sn-future-superset.php');
$part1 = $read('includes/class-sn-future-superset-part-1.php');
$part2 = $read('includes/class-sn-future-superset-part-2.php');

$assert(substr_count($runtime, 'SN_Future_Superset::register();') === 1, 'Runtime hardening must be the single Future-24 registration owner.');
$assert(substr_count($compat, 'SN_Future_Superset::register();') === 0, 'Compatibility hardening must not register Future-24 a second time.');

foreach (range('a', 'o') as $suffix) {
    $path = "includes/class-sn-future24-review-hardening-{$suffix}.php";
    $assert(is_file($root . '/' . $path), "Missing corrective hardening {$suffix}.");
    $assert(str_contains($loader, "class-sn-future24-review-hardening-{$suffix}.php"), "Loader does not require corrective hardening {$suffix}.");
    $assert(str_contains($loader, 'SN_Future24_Review_Hardening_' . strtoupper($suffix) . '::register()'), "Loader does not register corrective hardening {$suffix}.");
}

$assert(str_contains($part1, 'sn_network_device_public_key_valid'), 'Device public-key validation must fail closed through an approved provider.');
$assert(str_contains($part1, 'sn_conversation_step_up_required'), 'Sensitive conversation lock must require step-up.');
$assert(str_contains($part1, 'SN_Message_Operations::is_hidden($u,(int)$m->id)'), 'Message-version capture must respect hidden-message state with the canonical user/message argument order.');
$assert(str_contains($future, "state='firing'"), 'Reminder handoff must claim due work before notifying File 19.');
$assert(str_contains($future, 'file17-future-reminder:'), 'Reminder handoff must expose a stable downstream idempotency key.');
$assert(str_contains($part2, "'mark_unread','label','assign','export_request'"), 'Bulk operations must include the approved superset actions.');
$assert(str_contains($part2, "['state'=>'processing'") && str_contains($part2, "['id'=>(int)\$job->id,'state'=>'queued'") && str_contains($part2, "['id'=>(int)\$job->id,'state'=>'processing'"), 'Bulk jobs must claim queued work, process bounded chunks, and use a resumable processing-to-queued state transition.');

$checks = [
    'includes/class-sn-future24-review-hardening-a.php' => ['smart-views/(?P<id>\\d+)/results', 'community-invites/(?P<id>\\d+)/revoke', 'FOR UPDATE'],
    'includes/class-sn-future24-review-hardening-b.php' => ['sn_network_mentor_eligible', 'guardian_communication_approved', '/end'],
    'includes/class-sn-future24-review-hardening-c.php' => ['sn_network_citation_resolve', 'sn_network_case_discussion_deidentify', 'retention_days'],
    'includes/class-sn-future24-review-hardening-d.php' => ['speaker-queue', 'reorder', "action==='next'", 'bool|WP_Error'],
    'includes/class-sn-future24-review-hardening-e.php' => ['breakouts/move', 'breakouts/close', 'sn_network_sfu_available', 'bool|WP_Error'],
    'includes/class-sn-future24-review-hardening-f.php' => ['host-transfer/confirm', 'host-takeover', 'pending_transfer'],
    'includes/class-sn-future24-review-hardening-g.php' => ['telemetry_consent', 'explicit_consent', 'semantic-search/consent', 'exported_to_file26'],
    'includes/class-sn-future24-review-hardening-h.php' => ['interop_inbound', 'quarantine', 'kill_switch', 'event_id'],
    'includes/class-sn-future24-review-hardening-i.php' => ['sn_future_device_keys', 'sn_future_key_log', 'items_retained'],
    'includes/class-sn-future24-review-hardening-j.php' => ['GET_LOCK', 'revoke_device_key', 'signed_checkpoint'],
    'includes/class-sn-future24-review-hardening-k.php' => ['sn_network_team_inbox_delegation_allowed', '/notes', 'assigned_to_me'],
    'includes/class-sn-future24-review-hardening-l.php' => ['expected_version', 'handoff_reason', 'sla_due_at', 'FOR UPDATE'],
    'includes/class-sn-future24-review-hardening-m.php' => ['timezone_required', 'reschedule', 'cancelled_preflight'],
    'includes/class-sn-future24-review-hardening-n.php' => ['ALLOWED_VARIABLES', '/preview', '/versions', 'template_revision', 'bool|WP_Error'],
    'includes/class-sn-future24-review-hardening-o.php' => ['future24_mutation', 'BULK_RECOVERY_BATCH', "state='processing'", 'LIMIT $batch'],
];
foreach ($checks as $file => $needles) {
    $source = $read($file);
    foreach ($needles as $needle) $assert(str_contains($source, $needle), "{$file} missing regression marker: {$needle}");
}

$qr = $read('includes/class-sn-future24-review-hardening-a.php');
$assert(str_contains($qr, 'assert_redeem_eligibility($space,$user,$issuer)'), 'QR redemption must revalidate eligibility inside the locked transition.');
$assert(str_contains($qr, "SN_DB::table('blocks')") && str_contains($qr, 'FOR UPDATE'), 'QR redemption must lock authoritative block/membership state before admission.');
$assert(str_contains($qr, 'in_array((string)$m->status,[\'banned\',\'blocked\'],true)'), 'QR admission must refuse banned or blocked membership rows after locking them.');
$assert(str_contains($qr, '[\'id\'=>(int)$m->id,\'version\'=>(int)$m->version]'), 'QR membership activation must use version-CAS on an existing membership row.');
$assert(str_contains($qr, 'user_can($user,\'manage_options\')'), 'Manager authority must be evaluated for the asserted manager, not the requester.');
$assert(!str_contains($qr, "current_user_can('manage_options')"), 'Arbitrary issuer authority checks must not inherit the current requester capability.');

$assert(!str_contains($read('includes/class-sn-future24-review-hardening-g.php'), "'query'=>"), 'Semantic search must not log raw private query text.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-h.php'), "'payload'=>\$payload"), 'Interop receipts must not persist raw inbound payloads.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-d.php'), ':true|WP_Error'), 'PHP 8.1 compatibility forbids literal true return types.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-e.php'), ':true|WP_Error'), 'PHP 8.1 compatibility forbids literal true return types.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-n.php'), ':true|WP_Error'), 'PHP 8.1 compatibility forbids literal true return types.');

fwrite(STDOUT, "Future-24 fresh forty-round corrective static contracts: PASS\n");
