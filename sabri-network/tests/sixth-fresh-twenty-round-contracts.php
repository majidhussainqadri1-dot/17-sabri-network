<?php
/** File 17 sixth fresh 20-round permanent repository regression contracts, extended with seventh-cycle R3-R4 lock-current authorization regressions. */
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
$loader = $read('includes/class-sn-future24-review-hardening.php');
$migration = $read('includes/class-sn-fifth-fresh-migration-hardening.php');
$ui = $read('includes/class-sn-fifth-fresh-ui-hardening.php');
$quality = $read('tools/quality-check.sh');
$package = $read('tools/package.sh');
$workflow = $read('../.github/workflows/quality.yml');
$relationships = $read('includes/class-sn-relationships.php');
$relationshipRuntime = $read('includes/class-sn-relationship-runtime-hardening.php');
$spaces1 = $read('includes/class-sn-spaces-part-1.php');
$spaces2 = $read('includes/class-sn-spaces-part-2.php');
$spaces3 = $read('includes/class-sn-spaces-part-3.php');
$spaces4 = $read('includes/class-sn-spaces-part-4.php');
$spaces5 = $read('includes/class-sn-spaces-part-5.php');
$spaces6 = $read('includes/class-sn-spaces-part-6.php');
$spaces8 = $read('includes/class-sn-spaces-part-8.php');

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

// Seventh fresh cycle R3 — File 00 assertion snapshots must be refreshed after canonical relationship serialization.
$check(str_contains($relationships, 'SN_Membership_Assertions::clear_cache()') && str_contains($relationships, 'SN_Policy::access()'), 'Seventh R3: canonical pair-lock mutations must refresh File-00 assertions after serialization.');
$check(str_contains($relationshipRuntime, 'SN_Membership_Assertions::clear_cache()') && str_contains($relationshipRuntime, 'SN_Policy::access()'), 'Seventh R3: extended relationship locks must refresh File-00 assertions after serialization.');

// Seventh fresh cycle R4 — space governance must bind authorization to current locked membership state.
$check(str_contains($spaces8, 'assert_manage_locked') && str_contains($spaces8, 'role_can_manage'), 'Seventh R4: lock-current space-management authorization helpers must exist.');
$check(str_contains($spaces1, '$parent_locked = self::space($parent_id, true)') && str_contains($spaces1, 'assert_manage_locked($parent_id, $actor'), 'Seventh R4: child-space creation must revalidate parent governance under lock.');
$check(str_contains($spaces2, 'self::space($id,true)') && str_contains($spaces2, "assert_manage_locked(\$id,\$actor,'settings')"), 'Seventh R4: settings mutation must serialize version and manager authorization.');
$check(substr_count($spaces3, "assert_manage_locked(\$space_id,\$actor,'members')") >= 2, 'Seventh R4: join decisions and invitations must revalidate current manager role under lock.');
$check(str_contains($spaces4, "assert_manage_locked((int)\$invite->space_id,\$actor,'members')"), 'Seventh R4: manager invitation cancellation must revalidate under space lock.');
$check(str_contains($spaces5, "assert_manage_locked(\$space_id,\$actor,'members')") && str_contains($spaces5, "assert_manage_locked(\$space_id,\$actor,'moderation')") && str_contains($spaces5, 'FOR UPDATE'), 'Seventh R4: member and moderation changes must bind authority/hierarchy to locked state.');
$check(str_contains($spaces6, "assert_manage_locked(\$id,\$actor,'lifecycle')"), 'Seventh R4: lifecycle mutation must use current locked manager authority.');

if ($fail) {
    fwrite(STDERR, "Sixth/seventh regression contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Sixth + seventh regression contracts: PASS ($checks checks)\n";
