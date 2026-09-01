<?php
/** Eleventh fresh 10-round static regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$fail=[];$checks=0;
$read=static function(string $path):string{$v=file_get_contents($path);if($v===false)throw new RuntimeException('Missing '.$path);return $v;};
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$call=$read($root.'/includes/class-sn-call-runtime-hardening.php');
// R1 — call/Meet read truth and advisory-lock uncertainty must fail closed.
$check(str_contains($call,"sn_signal_read_failed"),'R1 classic signal rereads must expose storage uncertainty.');
$check(str_contains($call,"sn_meet_idempotency_state_unavailable"),'R1 meeting idempotency reads must fail closed.');
$check(str_contains($call,"sn_call_lock_unavailable"),'R1 advisory-lock DB uncertainty must not be reported as contention.');
$check(str_contains($call,'append_direct_pair_lock(array &$locks, int $conversation, int $actor): bool|WP_Error'),'R1 pair-lock derivation must propagate DB uncertainty.');
$check(str_contains($call,"call_lock_release_failed"),'R1 lock release failure must remain observable.');
// R2 — presence reads, mutations and privacy callbacks must preserve storage uncertainty.
$presence=$read($root.'/includes/class-sn-presence-devices.php');
$check(str_contains($presence,'sn_presence_state_unavailable'),'R2 presence state reads must fail closed on DB uncertainty.');
$check(str_contains($presence,'presence_cleanup_failed'),'R2 presence cleanup failure must remain observable.');
$check(str_contains($presence,'presence_export_read_failed'),'R2 privacy export must not report completion on failed reads.');
$check(str_contains($presence,'presence_erase_failed'),'R2 privacy erasure must not report completion on failed deletes.');
// R3 — relationship state and advisory locks preserve DB uncertainty.
$relationships=$read($root.'/includes/class-sn-relationships.php');
$check(str_contains($relationships,'relationship_storage_unavailable'),'R3 relationship DB uncertainty must fail closed.');
$check(str_contains($relationships,'relationship_lock_unavailable'),'R3 relationship lock service failure must differ from contention.');
$check(str_contains($relationships,'relationship_lock_release_failed'),'R3 relationship lock release failure must be observable.');
$check(str_contains($relationships,'follow_list_read_failed'),'R3 follow-list DB failures must not collapse to empty lists.');
// R4 — safety privacy/mutation locks must preserve storage uncertainty.
$safetyRuntime=$read($root.'/includes/class-sn-safety-runtime-hardening.php');
$check(str_contains($safetyRuntime,'report_privacy_retention_read_failed'),'R4 legal-hold count failures must not masquerade as zero retained reports.');
$check(str_contains($safetyRuntime,'report_privacy_lock_unavailable'),'R4 privacy lock DB failures must be observable.');
$check(str_contains($safetyRuntime,'sn_safety_lock_unavailable'),'R4 safety lock service failure must differ from contention.');
$check(str_contains($safetyRuntime,'safety_lock_release_failed'),'R4 safety lock release failures must remain observable.');
// R5 — high-risk governance must distinguish DB failure from absence/conflict.
$highRisk=$read($root.'/includes/class-sn-high-risk.php');
$check(str_contains($highRisk,'sn_high_risk_storage_unavailable'),'R5 high-risk DB uncertainty must fail closed.');
$check(str_contains($highRisk,'high_risk_list_read_failed'),'R5 high-risk inventory failures must not collapse to empty lists.');
$check(str_contains($highRisk,'step_up_grant_read_failed'),'R5 step-up grant DB failures must not masquerade as expired tokens.');
$check(str_contains($highRisk,'high_risk_cleanup_recovery_failed'),'R5 high-risk cleanup failures must remain observable.');
if($fail){fwrite(STDERR,"Eleventh fresh failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Eleventh fresh contracts: PASS ($checks checks)\n";
