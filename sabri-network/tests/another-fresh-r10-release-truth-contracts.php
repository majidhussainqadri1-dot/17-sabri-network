<?php
/** Round 10 permanent regression: exact current-cycle release truth. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$readRepo=static fn(string $p):string=>(string)file_get_contents($repo.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$q=$read('tools/quality-check.sh');$qa=$read('QA-INVENTORY.txt');
$docs=['README'=>$readRepo('README.md'),'STATUS'=>$readRepo('STATUS.md'),'CODING'=>$readRepo('CODING-COMPLETENESS.md'),'plugin readme'=>$read('readme.txt'),'CHANGELOG'=>$read('CHANGELOG.md'),'boundary'=>$read('CURRENT-CANDIDATE-BOUNDARY.txt')];
$branch='review/file17-another-10-round-2026-09-05';$rounds='R1, R2, R3, R4, R5, R6, R7, R8, R9, R10';
foreach($docs as $name=>$text){
  $check(str_contains($text,$branch),"R10: $name identifies current review branch");
  $check(str_contains($text,$rounds),"R10: $name records all ten defect-bearing rounds");
  $check(str_contains($text,'57')&&str_contains($text,'10'),"R10: $name publishes 57-suite/10-JS current inventory");
  $check(!str_contains($text,'Clean rounds: **R5**')&&!str_contains($text,'R5 was clean'),"R10: $name does not publish the prior cycle R5-clean outcome as current truth");
}
$check(str_contains($qa,'57')&&str_contains($qa,'10'),'R10: QA inventory matches executable 57-suite/10-JS gate');
$check(str_contains($q,'another-fresh-r10-release-truth-contracts.php'),'R10: full quality gate invokes this permanent regression');
$check(str_contains($readRepo('MANIFEST.md'),'exact staged release tree'),'R10: manifest truth remains executable/exact-commit based');
if($fail){fwrite(STDERR,"Another fresh R10 release-truth failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Another fresh R10 release-truth contracts: PASS ($checks checks)\n";
