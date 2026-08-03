<?php
/** Sabri Meet review 2: concurrency, idempotency and bounded sessions. */
$root = dirname(__DIR__);
$meet = file_get_contents($root . '/includes/class-sn-meet.php');
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$check(str_contains($meet, 'UNIQUE KEY host_request (host_id,idempotency_key)'), 'Host creation retries must collapse through a unique request key.');
$check(str_contains($meet, 'UNIQUE KEY meeting_user (meeting_id,user_id)'), 'Meeting participant identity must be unique per user.');
$check(str_contains($meet, 'UNIQUE KEY meeting_session (meeting_id,session_hash)'), 'Device sessions must be uniquely scoped to one meeting.');
$check(substr_count($meet, "START TRANSACTION") >= 6, 'High-risk meeting mutations must be transactional.');
$check(str_contains($meet, "WHERE public_id=%s FOR UPDATE"), 'Join and heartbeat paths must lock the meeting row.');
$check(str_contains($meet, "WHERE meeting_id=%d AND user_id=%d FOR UPDATE"), 'Participant authority must be revalidated under row lock.');
$check(str_contains($meet, "session_hash=%s FOR UPDATE"), 'Device session state must be locked before mutation.');
$check(str_contains($meet, "'version' => (int) \$meeting->version + 1"), 'Meeting mutations must advance a version counter.');
$check(str_contains($meet, "['id' => (int) \$meeting->id, 'version' => (int) \$meeting->version]) !== 1"), 'Meeting CAS failure must not silently succeed.');
$check(str_contains($meet, "['id' => (int) \$participant->id, 'version' => (int) \$participant->version]) !== 1"), 'Participant CAS failure must not silently succeed.');
$check(str_contains($meet, '$user_sessions >= 3'), 'Per-user device sessions must be bounded.');
$check(str_contains($meet, '$all_sessions >= ((int) $meeting->participant_limit * 3)'), 'Total device sessions must be bounded relative to participant capacity.');
$check(str_contains($meet, 'SESSION_TTL'), 'Stale meeting sessions must expire independently of cron.');
$check(str_contains($meet, "'meeting_session_expired'"), 'Stale clients must rejoin instead of reviving silently.');
$check(str_contains($meet, "hash_hmac('sha256', \$user_id . ':' . \$session_id"), 'Raw client session identifiers must not be stored.');
$check(str_contains($meet, "consumed_at IS NULL") && str_contains($meet, 'to_user_id=%d'), 'Signal reads and acknowledgements must be recipient-scoped.');
$check(str_contains($meet, "expires_at>%s") && str_contains($meet, 'SIGNAL_TTL'), 'Signaling records must be bounded by expiry.');
$check(!str_contains($meet, 'JSON_SET('), 'Meeting persistence must not depend on vendor-specific JSON mutation.');
$check(str_contains($meet, "WHERE meeting_id=%d AND user_id=%d AND session_hash=%s FOR UPDATE"), 'Leave and heartbeat must lock the exact user device session.');
$check(str_contains($meet, "['id' => (int) \$session->id, 'state' => (string) \$session->state]) !== 1"), 'Leave must compare the observed session state before mutation.');
$check(str_contains($meet, "'duplicate' => true"), 'Repeated leave requests must be idempotent.');
$check(str_contains($meet, "if (\$updated === false)"), 'Signal acknowledgement database failures must not be reported as zero acknowledgements.');
if ($failures) {
    fwrite(STDERR, "Sabri Meet review 2 failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Sabri Meet review 2 concurrency contracts: PASS ($checks checks)\n";
