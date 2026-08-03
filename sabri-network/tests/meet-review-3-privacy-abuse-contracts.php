<?php
/** Sabri Meet review 3: privacy, minors, abuse and provider boundaries. */
$root = dirname(__DIR__);
$meet = file_get_contents($root . '/includes/class-sn-meet.php');
$js = file_get_contents($root . '/assets/js/meet.js');
$template = file_get_contents($root . '/templates/meet-app.php');
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$check(str_contains($meet, "\$age_state === 'unknown'"), 'Unknown age must fail closed at meeting admission.');
$check(str_contains($meet, 'SN_Policy::has_guardian_consent'), 'Minor meeting access must require verified guardian consent.');
$check(str_contains($meet, "sn_network_minor_meet_allowed', false"), 'Minor meeting access must require an explicit deny-by-default policy.');
$check(str_contains($meet, 'SN_DB::is_blocked($user_id, (int) $meeting->host_id)'), 'Blocked host/member pairs must not join a meeting.');
$check(str_contains($meet, 'SN_DB::is_blocked($actor_id, $target_id)'), 'Moderators must not invite blocked accounts.');
$check(str_contains($meet, "'meeting_admission_required'"), 'Waiting users must not access admitted participant inventory.');
$check(str_contains($meet, "\$states = \$is_moderator ? ['invited', 'waiting', 'admitted', 'joined', 'left'] : ['admitted', 'joined']"), 'Ordinary participants must not receive waiting/invited/left roster history.');
$check(strpos($meet, "\$output['last_seen_at']") > strpos($meet, 'if ($privileged'), 'Exact participant activity must be limited to self or moderators.');
$check(str_contains($meet, 'privacy_exporters') && str_contains($meet, 'privacy_erasers'), 'Sabri Meet must integrate with WordPress privacy export and erasure.');
$check(str_contains($meet, 'sn_network_retention_prevents_erasure'), 'Approved legal/safety holds must govern meeting erasure.');
$check(str_contains($meet, "'e2ee' => false"), 'The control plane must not claim unproven end-to-end encryption.');
$check(str_contains($meet, "'recording_enabled' => false"), 'Recording must remain disabled without an approved consent workflow.');
$check(str_contains($meet, "sn_network_meet_media_config"), 'Conference media must be supplied only by an approved runtime adapter.');
$check(str_contains($meet, "sn_network_meet_peer_signaling_enabled', false"), 'Peer signaling must be disabled by default.');
$check(!str_contains($js, 'sendBeacon') && !preg_match('/\beval\s*\(|new Function/', $js), 'Client code must not use beacon writes or dynamic execution.');
$check(!preg_match('/console\.(?:log|warn|error|debug)\s*\(/', $js), 'Production client code must not retain console diagnostics.');
$check(str_contains($template, 'role="status" aria-live="polite"'), 'Meeting state must be announced to assistive technology.');
$check(str_contains($template, 'role="alert"'), 'Meeting failures must use an accessible alert region.');
$check(substr_count($meet, "throw new RuntimeException('session_erasure_failed')") === 1, 'Privacy erasure must fail closed when session deletion fails.');
$check(str_contains($meet, "throw new RuntimeException('event_erasure_failed')"), 'Privacy audit anonymization failures must roll back the erasure transaction.');
$check(str_contains($meet, "meet_cleanup_failed"), 'Background cleanup database failures must be observable in the native audit trail.');
$check(str_contains($meet, "\$participant_actions = ['admit', 'deny', 'remove', 'mute', 'lower_hand'"), 'Raised-hand moderation must stay inside the canonical meeting authority.');
if ($failures) {
    fwrite(STDERR, "Sabri Meet review 3 failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Sabri Meet review 3 privacy/abuse contracts: PASS ($checks checks)\n";
