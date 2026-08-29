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

if ($fail) {
    fwrite(STDERR, "Seventh fresh 20-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Seventh fresh 20-round contracts: PASS ($checks checks)\n";