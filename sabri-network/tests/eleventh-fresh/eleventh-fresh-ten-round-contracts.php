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
if($fail){fwrite(STDERR,"Eleventh fresh failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Eleventh fresh contracts: PASS ($checks checks)\n";
