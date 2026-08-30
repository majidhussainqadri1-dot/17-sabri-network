<?php
/** File 17 seventh fresh review: permanent contracts for R12-R20. */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$spaces2 = $read('includes/class-sn-spaces-part-2.php');
$spaces4 = $read('includes/class-sn-spaces-part-4.php');
$spaces5 = $read('includes/class-sn-spaces-part-5.php');
$spaces9 = $read('includes/class-sn-spaces-part-9.php');

// R12 — space governance must fail closed and mutation inputs must be exact.
$check(str_contains($spaces9, '$inserted=$wpdb->insert(self::audit_table()') && str_contains($spaces9, "if(\$inserted===false)throw new RuntimeException('space_governance_record_failed')"), 'R12: space governance evidence write failure must abort the governed mutation.');
$check(str_contains($spaces2, "START TRANSACTION") && str_contains($spaces2, "space_settings_commit_failed") && str_contains($spaces2, "self::record(\$id,\$actor,'space_settings_updated'"), 'R12: space settings mutation and its governance record must share one transaction.');
$check(str_contains($spaces5, "sn_space_unban_failed") && str_contains($spaces5, "SELECT * FROM '.self::bans_table().' WHERE id=%d FOR UPDATE") && str_contains($spaces5, "unban_commit_failed"), 'R12: unban and its governance record must be transactional and commit-checked.');
$check(str_contains($spaces2, "in_array(\$action,['join','cancel'],true)") && str_contains($spaces2, 'sn_space_join_action_invalid'), 'R12: malformed join actions must not silently execute the join path.');
$check(str_contains($spaces5, "in_array(\$action,['role','remove'],true)") && str_contains($spaces5, 'sn_space_member_action_invalid'), 'R12: malformed member actions must not silently execute role mutation.');
$check(str_contains($spaces5, "if(!in_array(\$raw_role,self::ROLES,true))") && str_contains($spaces5, 'sn_space_role_invalid'), 'R12: invalid member roles must be rejected rather than defaulting to member.');
$check(str_contains($spaces5, "in_array(\$action,['ban','unban'],true)") && str_contains($spaces5, 'sn_space_ban_action_invalid'), 'R12: malformed moderation actions must not silently become bans.');
$check(str_contains($spaces4, '$expired=$wpdb->update') && str_contains($spaces4, "if(\$expired!==1)throw new RuntimeException('invite_expiry_conflict')") && str_contains($spaces4, "invite_expiry_commit_failed"), 'R12: expired invitation state must be persisted and commit-confirmed before returning the expired response.');

if ($fail) {
    fwrite(STDERR, "Seventh fresh late-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Seventh fresh late-round contracts: PASS ($checks checks)\n";
