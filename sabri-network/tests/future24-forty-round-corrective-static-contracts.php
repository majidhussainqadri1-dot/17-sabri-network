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

$plugin = $read('sabri-network.php');
$runtime = $read('includes/class-sn-two-plan-runtime-hardening.php');
$rounds = $read('includes/class-sn-rounds41-60-runtime-hardening.php');
$compat = $read('includes/class-sn-compatibility-hardening.php');
$firewall = $read('includes/class-sn-two-plan-contract-firewall.php');
$presentation = $read('includes/class-sn-two-plan-presentation.php');
$loader = $read('includes/class-sn-future24-review-hardening.php');
$future = $read('includes/class-sn-future-superset.php');
$part1 = $read('includes/class-sn-future-superset-part-1.php');
$part2 = $read('includes/class-sn-future-superset-part-2.php');
$activator = $read('includes/class-sn-activator.php');
$transfer1 = $read('includes/class-sn-file-transfer-part-1.php');
$transfer3 = $read('includes/class-sn-file-transfer-part-3.php');
$transfer4 = $read('includes/class-sn-file-transfer-part-4.php');
$transfer5 = $read('includes/class-sn-file-transfer-part-5.php');
$transfer6 = $read('includes/class-sn-file-transfer-part-6.php');
$transfer7 = $read('includes/class-sn-file-transfer-part-7.php');
$transfer8 = $read('includes/class-sn-file-transfer-part-8.php');
$smail3 = $read('includes/class-sn-smail-part-3.php');
$readme = $read('README.md');
$status = $read('SYSTEM-STATUS.txt');

$assert(!str_contains($plugin, "\$_GET['sn-network-safe']"), 'Safe standalone rendering must only activate through the canonical rewrite query var, never an arbitrary raw query parameter.');
$assert(substr_count($runtime, 'SN_Future_Superset::register();') === 1, 'Runtime hardening must be the single Future-24 registration owner.');
$assert(substr_count($compat, 'SN_Future_Superset::register();') === 0, 'Compatibility hardening must not register Future-24 a second time.');
$assert(str_contains($runtime, "str_starts_with(\$route, '/sabri-network/v2/')"), 'Global REST pre-dispatch hardening must ignore non-File-17 namespaces.');
$assert(str_contains($runtime, '$file_params = $request->get_file_params();') && str_contains($runtime, '$access = SN_Policy::access();'), 'File hashing must revalidate canonical access before touching uploaded bytes.');
$assert(str_contains($compat, "class-sn-rounds41-60-runtime-hardening.php") && str_contains($compat, 'SN_Rounds41_60_Runtime_Hardening::register();'), 'Rounds 41-60 runtime corrections must be loaded and registered.');
$assert(str_contains($rounds, 'reconcile_scheduled_finalization') && str_contains($rounds, "status='processing'") && str_contains($rounds, "hash('sha256', (string) \$row->client_key)"), 'Scheduled sends must reconcile an already-created idempotent message if schedule finalization failed.');
$assert(str_contains($rounds, 'scheduled_message_finalization_reconciled'), 'Scheduled finalization reconciliation must leave an audit trail.');
$assert(str_contains($rounds, "remove_action('sn_cleanup_hourly', [SN_Two_Plan_Completion::class, 'expire_messages'])"), 'The fixed first-page expiry sweep must be replaced by the cursor scanner.');
$assert(str_contains($rounds, 'EXPIRY_SCAN_BATCH') && str_contains($rounds, 'EXPIRY_CURSOR_OPTION') && str_contains($rounds, 'id>%d') && str_contains($rounds, 'FOR UPDATE'), 'Disappearing-message expiry must scan with a persistent cursor and revalidate the locked current row.');
$assert(str_contains($rounds, 'message_has_legal_hold') && str_contains($rounds, 'count($rows) < self::EXPIRY_SCAN_BATCH'), 'Expiry cursor cycles must preserve legal holds and eventually return to the start of the table.');
$assert(str_contains($activator, 'SN_Two_Plan_Completion::install();'), 'Fresh activation must install the current two-plan schema before recording the plugin version.');
$assert(str_contains($activator, 'SN_Future_Superset::install();'), 'Fresh activation must install the Future-24 schema before recording the plugin version.');
$twoPlanInstall = strpos($activator, 'SN_Two_Plan_Completion::install();');
$futureInstall = strpos($activator, 'SN_Future_Superset::install();');
$versionCommit = strpos($activator, "update_option('sn_plugin_version', SN_VERSION, false);");
$assert($twoPlanInstall !== false && $futureInstall !== false && $versionCommit !== false && $twoPlanInstall < $versionCommit && $futureInstall < $versionCommit, 'Activation must not publish the current plugin version before all current schemas are installed.');

// Round 57: verified-user 1 GiB private transfer lifecycle must never report
// success across ambiguous commits or destroy bytes before canonical revocation.
$assert(str_contains($transfer3, 'chunk_commit_failed') && str_contains($transfer3, "if (\$wpdb->query('COMMIT') === false)"), 'Chunk upload must fail closed when the transaction commit fails.');
$assert(str_contains($transfer4, 'transfer_recipient_ready_failed') && str_contains($transfer4, 'transfer_finalize_commit_failed'), 'Transfer finalization must atomically persist recipient readiness and check COMMIT.');
$assert(str_contains($transfer4, 'commit_reconciled'), 'Finalization must reconcile an ambiguous commit by rereading canonical transfer state.');
$assert(str_contains($transfer5, 'revoke_recipients_failed') && str_contains($transfer5, 'revoke_commit_failed'), 'Transfer revocation must check recipient projection and COMMIT.');
$assert(str_contains($transfer6, 'transfer_rejection_persist_failed'), 'Unsafe transfer rejection must persist canonical state before deleting encrypted chunks.');
$rejectUpdate = strpos($transfer6, "status='rejected'");
$rejectDelete = strpos($transfer6, 'self::delete_chunks((int) $row->id)');
$assert($rejectUpdate !== false && $rejectDelete !== false && $rejectUpdate < $rejectDelete, 'Rejected transfer access must be revoked before encrypted chunks are destroyed.');
$assert(str_contains($transfer7, 'file_transfer_chunk_delete_failed') && str_contains($transfer7, 'file_transfer_chunk_row_delete_failed'), 'Chunk cleanup must retain/audit failed byte or ledger-row deletion rather than discarding retry evidence.');
$assert(str_contains($transfer7, "status='expired'") && str_contains($transfer7, 'self::delete_chunks((int) $row->id)'), 'Expiry cleanup must enter a terminal access state before byte purge.');
$assert(str_contains($transfer7, 'EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)'), 'Terminal transfers with leftover encrypted chunks must remain eligible for cleanup retries.');
$assert(str_contains($transfer8, 'PRIVACY_ERASE_PAGE_SIZE') && str_contains($transfer8, "status='revoked'") && str_contains($transfer8, 'self::delete_chunks((int) $row->id)'), 'Privacy erasure must paginate and revoke sender-owned transfer access before byte removal.');
$privacyRevoke = strpos($transfer8, "status='revoked'");
$privacyDelete = strpos($transfer8, 'self::delete_chunks((int) $row->id)');
$assert($privacyRevoke !== false && $privacyDelete !== false && $privacyRevoke < $privacyDelete, 'Privacy erasure must not delete encrypted transfer bytes before canonical revocation.');
$assert(str_contains($transfer8, 'file_transfer_privacy_revoke_failed') && str_contains($transfer8, 'file_transfer_privacy_recipient_erase_failed'), 'Privacy erasure must report failed canonical sender/recipient mutations instead of claiming complete deletion.');

// Round 60: presentation ownership checks must remain callable and use each
// surface's canonical owner marker. The previous review introduced calls to a
// private Messages helper that would have been a runtime fatal on page render.
$assert(!str_contains($transfer1, 'SN_Messages::is_owned_page') && !str_contains($presentation, 'SN_Messages::is_owned_page'), 'Cross-class presentation code must not call the private Messages page-ownership helper.');
$assert(str_contains($transfer1, "'_sn_messages_owned'") && str_contains($transfer1, "'_sn_smail_owned'"), 'Brand scoping must use the canonical Messages and Smail page ownership markers.');
$assert(str_contains($smail3, 'private static function is_owned_page') && str_contains($smail3, 'self::PAGE_OWNER_META'), 'Smail content/cache/URL checks must remain bound to its own page marker.');
$assert(str_contains($readme, '47 PHP review suites') && str_contains($status, '47 explicit PHP review suites'), 'Repository-facing QA documentation must report the actual 47 explicit PHP review suites.');

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

foreach ([
    'scheduled-messages/\\d+$',
    'updates/\\d+/view',
    'future/device-keys/[A-Za-z0-9._:-]+',
    'future/team-inbox/\\d+/notes',
    'future/reminders/\\d+',
    'future/templates/\\d+',
    'future/conversations/bulk/\\d+',
    'future/community-invites/\\d+/revoke',
    'future/mentorships/\\d+/end',
    'calls/\\d+/speaker-queue',
    'calls/\\d+/breakouts/move',
    'calls/\\d+/breakouts/close',
    'calls/\\d+/cohosts',
    'calls/\\d+/host-transfer/confirm',
    'calls/\\d+/host-takeover',
    'future/semantic-search/consent',
    'future/interop/\\d+',
    'future/interop/\\d+/outbound',
] as $routeMarker) {
    $assert(str_contains($firewall, $routeMarker), "Idempotency firewall is missing a current mutation route: {$routeMarker}");
}
$assert(str_contains($firewall, '$access = SN_Policy::access();'), 'Idempotency reservation must revalidate canonical access before creating request-cache state.');

$checks = [
    'includes/class-sn-future24-review-hardening-a.php' => ['smart-views/(?P<id>\\d+)/results', 'community-invites/(?P<id>\\d+)/revoke', 'FOR UPDATE'],
    'includes/class-sn-future24-review-hardening-b.php' => ['sn_network_mentor_eligible', 'guardian_communication_approved', '/end'],
    'includes/class-sn-future24-review-hardening-c.php' => ['sn_network_citation_resolve', 'sn_network_case_discussion_deidentify', 'retention_days'],
    'includes/class-sn-future24-review-hardening-d.php' => ['speaker-queue', 'reorder', "action==='next'", 'bool|WP_Error'],
    'includes/class-sn-future24-review-hardening-e.php' => ['breakouts/move', 'breakouts/close', 'sn_network_sfu_available', 'future_breakouts_expiry_cleanup_deferred', 'provider_confirmed', 'bool|WP_Error'],
    'includes/class-sn-future24-review-hardening-f.php' => ['host-transfer/confirm', 'host-takeover', 'pending_transfer'],
    'includes/class-sn-future24-review-hardening-g.php' => ['telemetry_consent', 'explicit_consent', 'semantic-search/consent', 'exported_to_file26'],
    'includes/class-sn-future24-review-hardening-h.php' => ['interop_inbound', 'quarantine', 'kill_switch', 'event_id', 'sn_interop_bridge_exists', "str_starts_with(\$client,'interop-bridge:')", "['id'=>(int)\$existing->id,'version'=>(int)\$existing->version]"],
    'includes/class-sn-future24-review-hardening-i.php' => ['sn_future_device_keys', 'sn_future_key_log', 'items_retained', 'EXPORT_PAGE_SIZE', 'SHARED_SCAN_PAGE_SIZE', 'OFFSET %d', 'extra_done'],
    'includes/class-sn-future24-review-hardening-j.php' => ['GET_LOCK', 'revoke_device_key', 'signed_checkpoint'],
    'includes/class-sn-future24-review-hardening-k.php' => ['sn_network_team_inbox_delegation_allowed', '/notes', 'assigned_to_me'],
    'includes/class-sn-future24-review-hardening-l.php' => ['expected_version', 'handoff_reason', 'sla_due_at', 'FOR UPDATE'],
    'includes/class-sn-future24-review-hardening-m.php' => ['timezone_required', 'reschedule', 'cancelled_preflight'],
    'includes/class-sn-future24-review-hardening-n.php' => ['ALLOWED_VARIABLES', '/preview', '/versions', 'template_revision', 'START TRANSACTION', 'ROLLBACK', 'COMMIT', 'sn_template_update_failed', 'bool|WP_Error'],
    'includes/class-sn-future24-review-hardening-o.php' => ['future24_mutation', 'BULK_RECOVERY_BATCH', "state='processing'", 'LIMIT $batch'],
];
foreach ($checks as $file => $needles) {
    $source = $read($file);
    foreach ($needles as $needle) $assert(str_contains($source, $needle), "{$file} missing regression marker: {$needle}");
}

$assert(!str_contains($read('includes/class-sn-future24-review-hardening-g.php'), "'query'=>"), 'Semantic search must not log raw private query text.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-h.php'), "'payload'=>\$payload"), 'Interop receipts must not persist raw inbound payloads.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-d.php'), ':true|WP_Error'), 'PHP 8.1 compatibility forbids literal true return types.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-e.php'), ':true|WP_Error'), 'PHP 8.1 compatibility forbids literal true return types.');
$assert(!str_contains($read('includes/class-sn-future24-review-hardening-n.php'), ':true|WP_Error'), 'PHP 8.1 compatibility forbids literal true return types.');

fwrite(STDOUT, "Future-24 fresh forty-round corrective static contracts: PASS\n");
