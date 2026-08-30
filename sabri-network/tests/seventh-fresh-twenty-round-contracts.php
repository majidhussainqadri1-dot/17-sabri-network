<?php
/** File 17 seventh fresh 20-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$message = $read('includes/class-sn-message-runtime-hardening.php');
$search = $read('includes/class-sn-message-search.php');
$attachment = $read('includes/class-sn-attachment-runtime-hardening.php');
$transferPrivacy = $read('includes/class-sn-file-transfer-part-8.php');
$fifthPrivacy = $read('includes/class-sn-fifth-fresh-privacy-hardening.php');
$callRuntime = $read('includes/class-sn-call-runtime-hardening.php');
$smailRuntime = $read('includes/class-sn-smail-runtime-hardening.php');

// R5 — a caller-owned message retry key must also be bound to exact request semantics.
$check(str_contains($message, "'_idempotency_fingerprint'"), 'R5: canonical messages must persist a request-semantic idempotency fingerprint.');
$check(str_contains($message, 'request_semantics(') && str_contains($message, 'idempotency_matches('), 'R5: duplicate reconciliation must compare the retried request with the original message semantics.');
$check(str_contains($message, 'message_idempotency_conflict'), 'R5: a reused key with different request semantics must fail with an explicit conflict.');
$check(str_contains($message, "'attachment_sha256'") && str_contains($message, "'reply_to'"), 'R5: request binding must include attachment identity and reply target, not only the client key.');
$check(!str_contains($message, 'if($existing)return self::reconcile_existing($existing,$user_id,true);'), 'R5: the old payload-blind duplicate path must not remain canonical.');

// R6 — private-search rebuild/index failures must fail closed without skipping rows or erasing the last known-good index first.
$check(str_contains($search, 'public static function backfill(): bool|WP_Error'), 'R6: search backfill must surface failure rather than silently returning void.');
$check(str_contains($search, 'if (is_wp_error($indexed)) return self::backfill_failure($indexed, (int) $row->id);'), 'R6: a failed message index must stop the backfill before its cursor advances past that row.');
$check(str_contains($search, "REBUILDING_OPTION = 'sn_message_search_epoch_rebuilding'") && str_contains($search, 'update_option(self::REBUILDING_OPTION, true, false);'), 'R6: a destructive manual rebuild must enter the same fail-closed rebuilding state used by key-epoch rebuilds.');
$check(str_contains($search, 'search_rebuild_backfill_failed') && str_contains($search, 'update_option(self::REBUILD_ERROR_OPTION, $backfill->get_error_code(), false);'), 'R6: manual rebuild failure must be explicit and persistent until safe retry/completion.');
$decryptPos = strpos($search, '$plain = SN_Message_Body::decrypt_row($message);');
$deletePos = strpos($search, 'token_hash NOT IN', $decryptPos === false ? 0 : $decryptPos);
$check($decryptPos !== false && $deletePos !== false && $decryptPos < $deletePos, 'R6: message plaintext must decrypt and desired tokens must be prepared before stale valid search tokens are reconciled away.');
$check(str_contains($search, "'rebuilding' => \$rebuilding") && str_contains($search, "'error' => \$error"), 'R6: search health must expose rebuilding/error state instead of reporting a partial index healthy.');

// R7 — expensive private-object integrity hashing must occur only after download authorization.
$authPos = strpos($attachment, "if (!is_user_logged_in()) return;");
$noncePos = strpos($attachment, 'wp_verify_nonce', $authPos === false ? 0 : $authPos);
$accessPos = strpos($attachment, 'SN_DB::user_can_access_attachment', $noncePos === false ? 0 : $noncePos);
$hashPos = strpos($attachment, "hash_file('sha256', \$candidate)", $accessPos === false ? 0 : $accessPos);
$check($authPos !== false && $noncePos !== false && $accessPos !== false && $hashPos !== false && $authPos < $noncePos && $noncePos <= $accessPos && $accessPos < $hashPos, 'R7: login, nonce and attachment authorization must precede private-file integrity hashing.');
$check(str_contains($attachment, 'must never become an unauthenticated/unauthorized disk-I/O oracle'), 'R7: the private hashing boundary must document its fail-closed resource-abuse invariant.');

// R8 — privacy erasure must keep terminal transfer sessions attributable until every encrypted chunk is physically gone.
$check(str_contains($transferPrivacy, "s.status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM \$chunks c WHERE c.transfer_id=s.id)"), 'R8: terminal sender transfers with leftover chunk rows must remain in the privacy erasure work queue.');
$check(str_contains($transferPrivacy, 'foreach($sent as $id)') && str_contains($transferPrivacy, 'self::delete_chunks($id)'), 'R8: canonical privacy erasure must retry physical chunk destruction after revoking sender access.');
$check(str_contains($transferPrivacy, '$more_sent=(bool)$wpdb->get_var') && str_contains($transferPrivacy, "EXISTS (SELECT 1 FROM \$chunks c WHERE c.transfer_id=s.id)"), 'R8: erasure completion must remain false while any sender-attributable encrypted chunk ledger remains.');
$baseDonePos = strpos($fifthPrivacy, "if (empty(\$base['done'])) return \$base;");
$anonymizePos = strpos($fifthPrivacy, "sender_id=0", $baseDonePos === false ? 0 : $baseDonePos);
$check($baseDonePos !== false && $anonymizePos !== false && $baseDonePos < $anonymizePos, 'R8: higher-level transfer anonymization must wait for canonical byte-erasure completion.');
$check(str_contains($transferPrivacy, 'higher-level anonymization must not sever the user link'), 'R8: the retryability/linkage invariant must be explicit in the transfer privacy implementation.');

// R9 — call/Meet protected reads, request idempotency and privacy erasure must revalidate current truth.
$check(str_contains($callRuntime, 'validate_meeting_idempotency_reuse') && str_contains($callRuntime, 'sn_meet_idempotency_conflict'), 'R9: meeting idempotency-key reuse must be rejected when the retried meeting semantics differ.');
$check(str_contains($callRuntime, "hash_equals((string)\$row->title, \$title)") && str_contains($callRuntime, "(int)\$row->conversation_id === \$conversation") && str_contains($callRuntime, "(int)\$row->participant_limit === \$limit"), 'R9: meeting idempotency comparison must bind identity to material title/conversation/limit settings.');
$check(str_contains($callRuntime, 'guard_protected_reads') && str_contains($callRuntime, "meetings/([A-Za-z0-9_-]{22,64})/(participants|signals)") && str_contains($callRuntime, "calls/(\\d+)/signals"), 'R9: protected call/Meet GET reads must pass a current authorization boundary.');
$check(str_contains($callRuntime, 'SN_Membership_Assertions::clear_cache($actor)') && str_contains($callRuntime, "SN_DB::is_blocked(\$actor, (int)\$meeting->host_id)"), 'R9: protected Meet reads must revalidate File-00 eligibility and live block state.');
$check(str_contains($callRuntime, 'override_meet_privacy_eraser') && str_contains($callRuntime, 'meet_privacy_erase_retry_safe'), 'R9: the registered Meet privacy eraser must be wrapped by a retry-safe completion boundary.');
$check(str_contains($callRuntime, "\$result['done'] = false") && str_contains($callRuntime, "failed and must be retried"), 'R9: operational Meet erasure failure must keep WordPress privacy retry alive.');

// R10 — Smail retries must require a caller-owned key and bind it to exact mail semantics.
$check(str_contains($smailRuntime, "if(\$client===''||!preg_match") && str_contains($smailRuntime, 'A caller-supplied Smail idempotency key is required.'), 'R10: Smail send must reject a missing caller-owned idempotency key instead of generating a server UUID.');
$check(!str_contains($smailRuntime, "if(\$client==='')\$client=wp_generate_uuid4();"), 'R10: runtime Smail must not silently synthesize an idempotency key.');
$check(str_contains($smailRuntime, 'sort($recipients,SORT_NUMERIC);'), 'R10: recipient order must be canonicalized before idempotency comparison.');
$check(str_contains($smailRuntime, 'idempotency_matches(') && str_contains($smailRuntime, 'smail_idempotency_conflict'), 'R10: duplicate Smail retries must verify request semantics and reject key reuse conflicts.');
$check(str_contains($smailRuntime, "hash_equals((string)\$smail->subject,\$subject)") && str_contains($smailRuntime, 'SN_Message_Body::decrypt_row($message)') && str_contains($smailRuntime, '$stored!==$expected'), 'R10: Smail idempotency binding must cover subject, canonical body and exact recipient set.');

if ($fail) {
    fwrite(STDERR, "Seventh fresh 20-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Seventh fresh 20-round contracts: PASS ($checks checks)\n";
