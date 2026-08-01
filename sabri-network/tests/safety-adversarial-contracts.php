<?php
/** Independent adversarial safety/privacy review; WordPress is intentionally not loaded. */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function safety_adv_check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function safety_adv_content(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: $relative");
    }
    return (string) file_get_contents($path);
}

$safety = safety_adv_content('includes/class-sn-safety.php');
$rest = safety_adv_content('includes/class-sn-rest.php');
$db = safety_adv_content('includes/class-sn-db.php');
$privacy = safety_adv_content('includes/class-sn-privacy.php');
$policy = safety_adv_content('includes/class-sn-policy.php');
$auth = safety_adv_content('includes/class-sn-auth.php');
$js = safety_adv_content('assets/js/network.js');
$all = implode("\n", [$safety, $rest, $db, $privacy, $policy, $auth, $js]);

safety_adv_check(!str_contains($rest, "get_param('legal_hold')") || str_contains($rest, 'admin_update_report'), 'Ordinary report submission must not let users create their own legal hold.');
safety_adv_check(strpos($rest, '$access = SN_Policy::access();') < strpos($rest, "current_user_can('manage_options')"), 'Administrator endpoints must pass File-00 identity and suspension policy before capability checks.');
safety_adv_check(str_contains($rest, 'hash_equals((string) $existing->target_key, $target_key)'), 'A reused idempotency UUID must be bound to the original target.');
safety_adv_check(strpos($rest, "SELECT id,target_key,status,retention_until,version") < strpos($rest, "consume_rate_limit('report_global'"), 'A valid retry must be resolved before consuming another rate-limit hit.');
safety_adv_check(str_contains($rest, "reporter_id=%d AND client_uuid=%s") && str_contains($db, 'UNIQUE KEY reporter_client'), 'Application and database layers must agree on report idempotency scope.');
safety_adv_check(str_contains($safety, 'min(3650, max(30, $days))'), 'Retention adapters must not set unbounded or near-zero retention.');
safety_adv_check(str_contains($safety, 'legal_hold=0 AND anonymized_at IS NULL') && str_contains($safety, 'legal_hold=0 AND status=\'expired\''), 'Legal holds must block both minimization and later deletion.');
safety_adv_check(strpos($safety, "SET reporter_id=0,reported_user_id=0") < strpos($safety, 'DELETE FROM $table'), 'Report data must be minimized before the later deletion stage.');
safety_adv_check(str_contains($safety, 'add_option(self::RETENTION_LOCK') && str_contains($safety, 'release_retention_lock'), 'Concurrent retention workers must use a bounded atomic lock.');
safety_adv_check(str_contains($safety, "reporter_id=0,client_uuid=NULL,updated_at") && str_contains($safety, 'legal_hold=1'), 'Held reports must still minimize the reporter account identifier where permitted.');
safety_adv_check(str_contains($privacy, 'Some abuse-report evidence is retained under an approved legal or safety hold'), 'Privacy output must disclose retained safety evidence honestly.');
safety_adv_check(!preg_match('/\b(?:REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_CLIENT_IP)\b/', $all), 'Report and rate-limit records must not persist raw IP addresses.');
safety_adv_check(str_contains($js, 'submit.disabled = true') && str_contains($js, 'submit.disabled = false'), 'The report UI must prevent duplicate clicks while still allowing a failed retry with the same UUID.');
safety_adv_check(str_contains($rest, 'expected_version') && str_contains($rest, 'WHERE id=%d AND version=%d'), 'Administrator triage must reject stale concurrent decisions.');
safety_adv_check(str_contains($rest, "status === 'expired'") && str_contains($rest, 'invalid_report_status'), 'Administrators must not manually forge the retention-expired state.');
safety_adv_check(str_contains($safety, "hash('sha256', 'expired-report:' . \$id)"), 'Anonymized records must no longer retain the original target correlation key.');
safety_adv_check(str_contains($policy, "\$actor_age_state === 'unknown'") && str_contains($policy, "\$target_age_state === 'unknown'"), 'Unknown age on either side must not be treated as adulthood.');
safety_adv_check(str_contains($auth, "'phone_masked' => \$can_see_phone") && !str_contains($auth, "'phone_masked' => \$phone ?"), 'Masked phone digits must not bypass phone visibility policy.');
safety_adv_check(str_contains($safety, "hash('sha256', 'erased-user-report:' . (int) \$target_row->id)"), 'User-target erasure must replace deterministic target correlation.');
safety_adv_check(str_contains($safety, "START TRANSACTION") && str_contains($safety, "ROLLBACK") && str_contains($safety, "COMMIT"), 'Report erasure must be atomic rather than partially destructive.');
safety_adv_check(str_contains($safety, 'array_is_list($value)') && str_contains($safety, 'ksort($value, SORT_STRING)'), 'Evidence hashing must preserve list order while normalizing map order.');
safety_adv_check(str_contains($safety, "'closed' => ['closed', 'reviewing']"), 'Closed reports must have a bounded transition set.');
safety_adv_check(str_contains($rest, 'report_update_not_changed') && str_contains($rest, 'report_decision_reason_required'), 'No-op or unreasoned triage updates must be rejected.');
safety_adv_check(str_contains($safety, "apply_filters('sn_network_legal_hold_release_authorized', false"), 'Legal-hold release must fail closed by default.');
safety_adv_check(str_contains($rest, "appeal_status='pending'") && str_contains($rest, 'report_already_appealed'), 'Appeals must be versioned and duplicate-resistant.');
safety_adv_check(str_contains($rest, 'appeal_reviewer_conflict') && str_contains($rest, 'report_appeal_pending'), 'Pending appeals must block ordinary triage and enforce reviewer separation.');
safety_adv_check(str_contains($rest, "appeal_status=%s") && str_contains($rest, "status = \$decision === 'overturn' ? 'reviewing'"), 'Overturned appeals must reopen the report through the canonical state.');
safety_adv_check(str_contains($rest, "'evidence_integrity' =>") && str_contains($safety, 'hash_equals(strtolower($expected_hash)'), 'Evidence integrity must be actively verified, not merely stored.');


if ($failures) {
    fwrite(STDERR, "Safety adversarial contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

echo "Safety adversarial contracts: PASS ($checks checks)\n";
