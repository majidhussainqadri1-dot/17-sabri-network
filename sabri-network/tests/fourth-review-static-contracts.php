<?php
/** Fourth forensic static review: age fail-closed, read-pointer failure and current status truthfulness. */
declare(strict_types=1);
$root = dirname(__DIR__);
$repo = dirname($root);
$failures = [];
$checks = 0;
function fr4_check(bool $condition, string $message): void { global $failures, $checks; $checks++; if (!$condition) $failures[] = $message; }
function fr4_content(string $path): string { $text = file_get_contents($path); if ($text === false) throw new RuntimeException('Missing ' . $path); return $text; }
$policy = fr4_content($root . '/includes/class-sn-policy.php');
$db = fr4_content($root . '/includes/class-sn-db.php');
$rest = fr4_content($root . '/includes/class-sn-rest.php');
$status = fr4_content($root . '/SYSTEM-STATUS.txt');
$readme = fr4_content($root . '/README.md');
$completeness = fr4_content($repo . '/CODING-COMPLETENESS.md');
fr4_check(str_contains($policy, 'function has_verified_adult_age'), 'Canonical verified-adult helper must exist.');
fr4_check(str_contains($policy, 'function requires_protective_age_defaults'), 'Unknown age must have an explicit protective-default helper.');
fr4_check(str_contains($policy, "if (\$target_age_state === 'unknown')"), 'Unknown-age presence must fail closed.');
fr4_check(str_contains($policy, "['phone_visibility', 'last_seen', 'profile_photo', 'groups', 'calls', 'messages', 'updates', 'follows']"), 'Protective privacy defaults must cover every exposed communication preference.');
fr4_check(str_contains($db, "privacy === 'public' && SN_Policy::has_verified_adult_age"), 'Public update attachment delivery must require verified adult age.');
fr4_check(str_contains($rest, 'SN_Policy::requires_protective_age_defaults($user_id)'), 'Privacy updates must protect unknown-age users.');
fr4_check(str_contains($rest, '!SN_Policy::has_verified_adult_age($target_id)'), 'Conversation ownership transfer must require verified adult age.');
fr4_check(str_contains($rest, "\$age_state === 'unknown'"), 'Directory discovery must exclude unknown-age users.');
fr4_check(str_contains($rest, 'return SN_Policy::has_verified_adult_age((int) $row->user_id);'), 'Public updates must be visible only for verified-adult authors.');
fr4_check(str_contains($rest, 'message_read_pointer_update_failed') && str_contains($rest, '$updated === false'), 'Read-pointer database failure must be detected and audited.');
fr4_check(
    str_contains($completeness, '**Coding classification:**') &&
    stripos($completeness, 'repository-owned') !== false &&
    stripos($completeness, 'candidate') !== false &&
    stripos($completeness, 'corrective review') !== false &&
    str_contains($completeness, '**Staging-Accepted:** pending') &&
    str_contains($completeness, '**Live-Deployed:** not claimed') &&
    !preg_match('/(?:production-ready|staging-accepted\s*:\s*(?:yes|complete)|live-deployed\s*:\s*(?:yes|complete))/i', $completeness),
    'Repository evidence must state a current repository-owned corrective-review candidate without claiming staging/live/operational completion.'
);
fr4_check(
    stripos($status, 'repository') !== false &&
    stripos($status, 'not staging-accepted') !== false &&
    stripos($status, 'not live-deployed') !== false &&
    str_contains($readme, '**Coded:**') &&
    str_contains($readme, '2.1.0') &&
    stripos($readme, 'repository candidate') !== false &&
    str_contains($readme, '**Staging-Accepted:** pending') &&
    str_contains($readme, '**Live-Deployed:** not claimed') &&
    !preg_match('/(?:production-ready|staging-accepted\s*:\s*(?:yes|complete)|live-deployed\s*:\s*(?:yes|complete))/i', $status . "\n" . $readme),
    'Installable documentation must semantically preserve the current coded/staging/live boundary without depending on stale exact wording.'
);
if ($failures) { fwrite(STDERR, "Fourth-review static failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n"); exit(1); }
echo "Fourth-review static contracts: PASS ($checks checks)\n";
