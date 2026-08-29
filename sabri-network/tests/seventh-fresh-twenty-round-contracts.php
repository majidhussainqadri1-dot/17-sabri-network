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

// R5 — a caller-owned message retry key must also be bound to exact request semantics.
$check(str_contains($message, "'_idempotency_fingerprint'"), 'R5: canonical messages must persist a request-semantic idempotency fingerprint.');
$check(str_contains($message, 'request_semantics(') && str_contains($message, 'idempotency_matches('), 'R5: duplicate reconciliation must compare the retried request with the original message semantics.');
$check(str_contains($message, 'message_idempotency_conflict'), 'R5: a reused key with different request semantics must fail with an explicit conflict.');
$check(str_contains($message, "'attachment_sha256'") && str_contains($message, "'reply_to'"), 'R5: request binding must include attachment identity and reply target, not only the client key.');
$check(!str_contains($message, 'if($existing)return self::reconcile_existing($existing,$user_id,true);'), 'R5: the old payload-blind duplicate path must not remain canonical.');

if ($fail) {
    fwrite(STDERR, "Seventh fresh 20-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Seventh fresh 20-round contracts: PASS ($checks checks)\n";