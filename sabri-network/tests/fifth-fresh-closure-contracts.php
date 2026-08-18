<?php
/** Fifth fresh closure-status contract. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;$check=static function(bool $ok,string $m)use(&$fail,&$checks){$checks++;if(!$ok)$fail[]=$m;};
$note=(string)file_get_contents($root.'/FIFTH-FRESH-CLOSURE.txt');
$check(str_contains($note,'Defect rounds: R3,R4,R5,R8,R9,R11,R12,R14,R15,R17,R18,R19,R20.'),'Closure defect-round ledger mismatch.');
$check(str_contains($note,'Clean rounds: R1,R2,R6,R7,R10,R13,R16.'),'Closure clean-round ledger mismatch.');
$check(str_contains($note,'Exact-head QA/package, staging, live, DB/migration and operational statuses remain separately evidenced.'),'Closure status boundary missing.');
if($fail){fwrite(STDERR,"Fifth fresh closure failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fifth fresh closure contracts: PASS ($checks checks)\n";
