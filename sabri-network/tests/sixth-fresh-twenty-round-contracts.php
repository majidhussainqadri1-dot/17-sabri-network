<?php
/** File 17 sixth fresh 20-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$auth = $read('includes/class-sn-auth.php');
$message = $read('includes/class-sn-message-runtime-hardening.php');
$search = $read('includes/class-sn-message-search.php');
$transfer = $read('includes/class-sn-file-transfer-part-2.php');
$smail = $read('includes/class-sn-smail-part-2.php');
$privacy = $read('includes/class-sn-sixth-fresh-privacy-hardening.php');
$r7privacy = $read('includes/class-sn-r7-privacy-hardening.php');
$r8interop = $read('includes/class-sn-r8-interop-finalization-hardening.php');
$r9 = $read('includes/class-sn-r9-runtime-hardening.php');
$knowledge = $read('includes/class-sn-fourth-fresh-knowledge-hardening.php');
$knowledgeC = $read('includes/class-sn-future24-review-hardening-c.php');
$loader = $read('includes/class-sn-future24-review-hardening.php');
$migration = $read('includes/class-sn-fifth-fresh-migration-hardening.php');
$ui = $read('includes/class-sn-fifth-fresh-ui-hardening.php');
$quality = $read('tools/quality-check.sh');
$package = $read('tools/package.sh');
$workflow = $read('../.github/workflows/quality.yml');

// R2 — File 03 presentation filters may not replace File 00/09 phone/verification truth.
$check(str_contains($auth, "'phone' => \$can_see_phone ? mb_substr(sanitize_text_field((string) \$projection['phone'])"), 'R2: public-user phone must be emitted from the canonical File-00 projection, not a presentation filter.');
$check(str_contains($auth, "'verified' => (bool) \$projection['verified']"), 'R2: verification truth must remain the canonical assertion after presentation enrichment.');
$check(str_contains($auth, "\$filtered['about']") && str_contains($auth, "\$filtered['role_label']"), 'R2: File-03 enrichment remains limited to presentation fields where appropriate.');

// R5 — message retries must have a caller-owned stable identity.
$check(str_contains($message, 'A caller-supplied message idempotency key is required.'), 'R5: canonical message send must require a caller-supplied idempotency key.');
$check(!str_contains($message, '?: strtolower(wp_generate_uuid4())'), 'R5: canonical message send must not silently fabricate retry identity.');

// R6 — hidden-for-self messages must stay hidden in private search and context.
$check(substr_count($search, 'SN_Message_Operations::is_hidden($viewer_id') >= 3, 'R6: private search, target context and surrounding context must honor viewer-specific hidden state.');
$check(str_contains($search, '$page_tail = $rows ? (int) end($rows)->id : 0;') && str_contains($search, "'before' => \$page_tail"), 'R6: search pagination must advance from the scanned page tail after visibility filtering.');

// R8 — a transfer idempotency key is bound to the exact request semantics.
$check(str_contains($transfer, 'A caller-supplied transfer idempotency key is required.'), 'R8: transfer initiation must require a caller-supplied retry key.');
$check(str_contains($transfer, 'same_initiation(') && str_contains($transfer, 'transfer_idempotency_conflict'), 'R8: replay must reject a key reused for different transfer semantics.');
$check(str_contains($transfer, '$stored === $requested') && str_contains($transfer, '(int) $row->conversation_id === $conversation_id'), 'R8: duplicate reconciliation must bind exact recipients and conversation.');

// R9 — Smail send retries must also use a caller-owned stable key.
$check(str_contains($smail, 'A caller-supplied Smail idempotency key is required.'), 'R9: Smail send must require caller-supplied idempotency.');
$forbiddenSmailFallback = '$client_id = strtolower(trim((string) $request->get_param(\'client_id\'))) ?: strtolower(wp_generate_uuid4())';
$check(!str_contains($smail, $forbiddenSmailFallback), 'R9: Smail must not fabricate a retry identity.');

// R12 — privacy progress may never jump past a failed deletion.
$check(str_contains($privacy, 'if ($deleted !== 1)') && str_contains($privacy, "return self::retry('Message-version privacy erasure must be retried.')"), 'R12: a failed Future message-version delete must return retryable failure.');
$failedPos = strpos($privacy, 'if ($deleted !== 1)');
$cursorPos = strpos($privacy, 'update_option($cursor_key, $vid, false);', $failedPos === false ? 0 : $failedPos);
$check($failedPos !== false && $cursorPos !== false && $failedPos < $cursorPos, 'R12: the Future privacy cursor must advance only after successful deletion.');
$check(str_contains($loader, "class-sn-sixth-fresh-privacy-hardening.php") && str_contains($loader, 'SN_Sixth_Fresh_Privacy_Hardening::register()'), 'R12: sixth-cycle failure-safe privacy hardening must be loaded and registered.');

// R18 — migration rollback must restore the actual Sabri Meet version option.
$check(str_contains($migration, "'sn_meet_db_version'"), 'R18: migration version snapshot must include the actual Sabri Meet DB-version option.');

// R19 — all standalone Messages surfaces receive exact brand/accessibility hardening.
$check(str_contains($ui, "get_query_var('sn_messages_app')"), 'R19: standalone Messages/communication-settings routes must receive File-17 UI hardening.');

// R20 — release gates must include the entire sixth-cycle correction and every test.
$check(str_contains($quality, 'includes/class-sn-sixth-fresh-privacy-hardening.php'), 'R20: full quality required-source inventory must include sixth-cycle runtime hardening.');
$check(str_contains($quality, 'fifth-fresh-closure-contracts.php') && str_contains($quality, 'fifth-fresh-release-truth-contracts.php') && str_contains($quality, 'sixth-fresh-twenty-round-contracts.php'), 'R20: full quality gate must invoke all late fifth-cycle tests plus the sixth-cycle regression suite.');
$check(str_contains($package, 'includes/class-sn-sixth-fresh-privacy-hardening.php'), 'R20: installable package required-source inventory must include sixth-cycle runtime hardening.');
$check(str_contains($workflow, 'run_test sixth-fresh-twenty-round-contracts.php') && str_contains($workflow, 'run_test fifth-fresh-closure-contracts.php') && str_contains($workflow, 'run_test fifth-fresh-release-truth-contracts.php'), 'R20: PHP 8.1 exact-head workflow must execute the complete current closure set.');
$check(substr_count($workflow, 'sixth-fresh-twenty-round-contracts.php') >= 2, 'R20: sixth-cycle regression suite must run in both minimum and full-quality workflow paths.');

// Next fresh Round 7 — every proven privacy failure remains retryable and bounded.
$check(str_contains($loader, "class-sn-r7-privacy-hardening.php") && str_contains($loader, 'SN_R7_Privacy_Hardening::register()'), 'Next R7: final privacy retry/completion hardening must be loaded and registered.');
$check(str_contains($r7privacy, "add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_erasers'], 9800)"), 'Next R7: privacy callbacks must be replaced after earlier domain overrides but before the priority-9999 global guard.');
foreach (['sabri-meet','sabri-network-message-receipts','sabri-network-message-organization','sabri-network-two-plan'] as $key) {
    $check(str_contains($r7privacy, "'{$key}'"), "Next R7: {$key} must have a failure-safe final eraser callback.");
}
$check(str_contains($r7privacy, "SN_Meet::privacy_erase(\$email, \$page)") && str_contains($r7privacy, "\$result['done'] = false"), 'Next R7: Meet transaction/commit failure receipts must remain retryable instead of terminating erasure.');
$check(str_contains($r7privacy, 'DELETE FROM $table WHERE id IN') && str_contains($r7privacy, 'Message receipts could not be erased and must be retried.'), 'Next R7: message-receipt deletion failure must be an explicit retryable result.');
$check(str_contains($r7privacy, 'DELETE FROM $table WHERE user_id=%d LIMIT %d') && str_contains($r7privacy, 'message_organization_privacy_erase_failed'), 'Next R7: message-organization erasure must use bounded checked deletes under a transaction.');
$check(str_contains($r7privacy, 'two_plan_scheduled_erase_failed') && str_contains($r7privacy, 'two_plan_request_erase_failed') && str_contains($r7privacy, 'two_plan_poll_vote_erase_failed'), 'Next R7: Two-Plan scheduled/request/vote erasure must fail closed on every write domain.');
$check(str_contains($r7privacy, "'done'=>!\$more_scheduled && !\$more_requests && !\$more_votes"), 'Next R7: Two-Plan completion must be derived from committed remaining work, not affected-row arithmetic.');

// Next fresh Round 8 — final knowledge and interoperability routes must preserve the strongest governance/truth contracts.
$check(str_contains($knowledge, 'SN_Future24_Review_Hardening_C::create_citation_card($r)') && str_contains($knowledge, 'SN_Future24_Review_Hardening_C::create_case_discussion($r)'), 'Next R8: final citation/case route owner must delegate to the stronger Future24-C governance contract.');
$check(str_contains($knowledgeC, "if(\$wpdb->query('START TRANSACTION')===false)") && str_contains($knowledgeC, 'sn_case_transaction_failed'), 'Next R8: case-discussion transactional storage must fail closed if START TRANSACTION is not proven.');
$check(str_contains($loader, 'class-sn-r8-interop-finalization-hardening.php') && str_contains($loader, 'SN_R8_Interop_Finalization_Hardening::register()'), 'Next R8: final interoperability sent-receipt truth guard must be loaded and registered.');
$check(str_contains($r8interop, "add_action('rest_api_init', [self::class, 'override_route'], 3400)") && str_contains($r8interop, 'SN_Fourth_Fresh_Interop_Hardening::outbound($request)'), 'Next R8: final outbound route must execute after prior owners while preserving Fourth-Fresh replay/reconciliation behavior.');
$check(str_contains($r8interop, 'receipt_is_durably_sent($receipt_id)') && str_contains($r8interop, "(string)(\$payload['delivery_state'] ?? '') === 'sent'"), 'Next R8: no outbound success may escape without a durably persisted sent receipt.');
$check(str_contains($r8interop, 'sn_interop_reconciliation_required') && str_contains($r8interop, 'future_interop_outbound_local_finalize_failed'), 'Next R8: local receipt-finalization failure must become a reconciliation-required response and corrective audit evidence.');

// Next fresh Round 9 — transaction, Future-erasure and scheduler-recovery truth.
$check(str_contains($loader, 'class-sn-r9-runtime-hardening.php') && str_contains($loader, 'SN_R9_Runtime_Hardening::register()'), 'Next R9: final runtime correction layer must be loaded and registered.');
$check(str_contains($r9, "add_action('rest_api_init', [self::class, 'override_routes'], 3500)"), 'Next R9: transaction routes must be final after earlier Future24 owners.');
$check(str_contains($r9, "'/calls/(?P<id>\\d+)/hand-raise'") && str_contains($r9, 'SN_Future24_Review_Hardening_D::hand_raise($request)'), 'Next R9: final hand-raise transaction entry must be guarded.');
$check(str_contains($r9, "'/calls/(?P<id>\\d+)/speaker-queue'") && str_contains($r9, 'SN_Future24_Review_Hardening_D::manage_speaker_queue($request)'), 'Next R9: final speaker-queue mutation entry must be guarded.');
$check(str_contains($r9, "'/future/templates/(?P<id>\\d+)'") && str_contains($r9, 'SN_Future24_Review_Hardening_N::update_template($request)') && str_contains($r9, 'SN_Future24_Review_Hardening_N::delete_template($request)'), 'Next R9: final template update/delete transaction entries must be guarded.');
$check(str_contains($r9, 'new SN_R6_WPDB_Guard($original)') && str_contains($r9, 'finally') && str_contains($r9, '$wpdb = $original;'), 'Next R9: transaction failure promotion must be request-scoped and restore wpdb.');
$check(str_contains($r9, "add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_future_eraser'], 9700)"), 'Next R9: Future eraser must replace the sixth-cycle callback before the global wrapper.');
$check(str_contains($r9, 'sn_future_device_keys') && str_contains($r9, 'DEVICE_KEY_BATCH') && str_contains($r9, 'device_key_delete_failed'), 'Next R9: final Future eraser must perform bounded checked device-key deletion.');
$check(str_contains($r9, "'done'=>!\$more_keys") && str_contains($r9, 'Append-only key-transparency integrity entries were retained'), 'Next R9: privacy receipt must not finish while device keys remain and must disclose retained transparency history.');
$check(str_contains($r9, "remove_action('sn_cleanup_hourly', [SN_Future24_Review_Hardening_O::class, 'bulk_job_preflight'], 0)") && str_contains($r9, "add_action('sn_cleanup_hourly', [self::class, 'bulk_job_preflight'], 0)"), 'Next R9: unchecked bulk scheduler recovery owner must be replaced.');
$check(str_contains($r9, '$wpdb->query($query) === false') && str_contains($r9, 'future_bulk_recovery_failed'), 'Next R9: bulk scheduler recovery must detect and audit DB failure.');

if ($fail) {
    fwrite(STDERR, "Sixth fresh 20-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Sixth fresh 20-round contracts: PASS ($checks checks)\n";
