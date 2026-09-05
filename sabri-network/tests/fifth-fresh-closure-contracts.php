<?php
/** Fifth fresh closure-status contract plus later release-closure guards. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;$check=static function(bool $ok,string $m)use(&$fail,&$checks){$checks++;if(!$ok)$fail[]=$m;};
$note=(string)file_get_contents($root.'/FIFTH-FRESH-CLOSURE.txt');
$check(str_contains($note,'Defect rounds: R3,R4,R5,R8,R9,R11,R12,R14,R15,R17,R18,R19,R20.'),'Closure defect-round ledger mismatch.');
$check(str_contains($note,'Clean rounds: R1,R2,R6,R7,R10,R13,R16.'),'Closure clean-round ledger mismatch.');
$check(str_contains($note,'Exact-head QA/package, staging, live, DB/migration and operational statuses remain separately evidenced.'),'Closure status boundary missing.');

// Next fresh Round 10 — standalone package and repository-current truth must fail closed.
$package=(string)file_get_contents($root.'/tools/package.sh');
foreach([
    'includes/class-sn-next-message-operations-hardening.php',
    'includes/class-sn-r6-transaction-hardening.php',
    'includes/class-sn-r7-privacy-hardening.php',
    'includes/class-sn-r8-interop-finalization-hardening.php',
    'includes/class-sn-r9-runtime-hardening.php',
] as $surface){
    $check(str_contains($package,$surface),'Next R10: standalone package required-surface inventory is missing '.$surface.'.');
}
$readme=(string)file_get_contents($repo.'/README.md');
$status=(string)file_get_contents($repo.'/STATUS.md');
$coding=(string)file_get_contents($repo.'/CODING-COMPLETENESS.md');
$boundary=(string)file_get_contents($root.'/CURRENT-CANDIDATE-BOUNDARY.txt');
foreach(['README'=>$readme,'STATUS'=>$status,'CODING-COMPLETENESS'=>$coding,'CURRENT-CANDIDATE-BOUNDARY'=>$boundary] as $name=>$text){
    $check(str_contains($text,'review/file17-next-10-round-2026-09-04'),'Next R10: '.$name.' must identify the current next-fresh branch.');
    $check(str_contains($text,'R5'),'Next R10: '.$name.' must retain the current cycle clean-round truth.');
    $check(!str_contains($text,'Current repository state: fresh 10-round corrective cycle completed on `review/file17-fresh-10-round-2026-09-04`'),'Next R10: '.$name.' must not present the prior fresh branch as current repository truth.');
}

if($fail){fwrite(STDERR,"Fifth fresh closure failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fifth fresh closure contracts: PASS ($checks checks)\n";
