<?php
/** File 17 fifth fresh 20-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$relationships = $read('includes/class-sn-relationship-runtime-hardening.php');
$spaces6 = $read('includes/class-sn-spaces-part-6.php');
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$round20 = $read('includes/class-sn-round20-correction.php');
$transfer2 = $read('includes/class-sn-file-transfer-part-2.php');
$transfer6 = $read('includes/class-sn-file-transfer-part-6.php');
$smail2 = $read('includes/class-sn-smail-part-2.php');

// Round 3 — group/channel conversation membership must be owned by canonical spaces.
$check(str_contains($relationships, "if (\$type !== 'direct')") && str_contains($relationships, 'space_required'), 'R3: non-direct conversation creation must require a canonical space.');
$check(str_contains($relationships, "SN_DB::table('space_members')") && str_contains($relationships, "status='active'"), 'R3: canonical space membership must be revalidated before resolving its conversation.');
$check(str_contains($relationships, 'space_membership_managed') && str_contains($relationships, 'smail_audience_managed'), 'R3: direct conversation-member mutations must not bypass space or Smail ownership.');
$check(!str_contains($relationships, 'foreach (array_values(array_unique(array_merge([$actor],$members))) as $memberId'), 'R3: arbitrary non-direct member insertion must remain removed from the generic conversation route.');

// Round 4 — space ownership and linked-conversation ownership are one invariant.
$check(str_contains($spaces6, "SN_DB::table('conversations')") && str_contains($spaces6, "['owner_id'=>\$target,'updated_at'=>\$now]"), 'R4: space ownership transfer must update linked conversation owner_id in the same transaction.');
$check(str_contains($boundary, 'space_ownership_managed') && str_contains($boundary, '/owner$'), 'R4: generic conversation ownership transfer must fail closed for space-owned conversations.');

// Round 5 — retention/legal hold must dominate ordinary message deletion.
$check(str_contains($round20, 'message_has_legal_hold($id)') && str_contains($round20, "UnexpectedValueException('legal_hold')"), 'R5: message deletion must recheck legal hold while the retention lock is held.');
$check(str_contains($round20, 'message_legal_hold') && str_contains($round20, "SN_Private_Files::delete(\$attachment, (int)\$row->sender_id)"), 'R5: held messages must fail closed and attachment cleanup must use canonical sender ownership.');

// Round 8 — verified transfer commit and current membership/relationship state.
$check(str_contains($transfer2, "if (\$wpdb->query('COMMIT') === false)") && str_contains($transfer2, 'commit_reconciled'), 'R8: transfer initiation must verify COMMIT and reconcile the exact idempotent session after uncertainty.');
$check(str_contains($transfer6, 'SN_DB::is_member($conversation,(int)$row->sender_id)') && str_contains($transfer6, 'SN_DB::is_member($conversation,$user)'), 'R8: conversation-bound transfer access must recheck current sender and recipient membership.');
$check(substr_count($transfer6, 'SN_Policy::can_contact(') >= 3 && str_contains($transfer6, 'consent or privacy state changed'), 'R8: non-conversation transfer access must recheck current relationship, consent and privacy policy.');

// Round 9 — Smail projection commit truth and exact-version draft lifecycle.
$check(str_contains($smail2, "smail_projection_commit_failed") && str_contains($smail2, "if (\$wpdb->query('COMMIT') === false)"), 'R9: Smail projection must verify the database commit.');
$check(str_contains($smail2, 'draft_version') && str_contains($smail2, 'trash_draft_exact') && str_contains($smail2, 'draft_cleanup_pending'), 'R9: sending from a draft must use exact-version cleanup and preserve a concurrently edited draft.');
$check(str_contains($smail2, 'state_count') && str_contains($smail2, 'commit_reconciled'), 'R9: uncertain Smail commit reconciliation must confirm mailbox-state completeness.');

if ($fail) {
    fwrite(STDERR, "Fifth fresh 20-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Fifth fresh 20-round contracts: PASS ($checks checks)\n";
