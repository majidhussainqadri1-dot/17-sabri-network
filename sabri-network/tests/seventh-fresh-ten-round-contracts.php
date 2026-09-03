<?php
/** Seventh fresh 10-round permanent regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$q=$read('tools/quality-check.sh');
$p=$read('tools/package.sh');
$r=$read('includes/class-sn-round20-correction.php');
$check(str_contains($r,"assets/js/round20-correction.js"),'R1: Round-20 correction must retain its registered browser runtime asset.');
$check(str_contains($q,'assets/js/round20-correction.js')&&str_contains($q,'includes/class-sn-round20-correction.php'),'R1: source quality gate must require the Round-20 PHP/JS runtime surfaces.');
$check(str_contains($q,'round20-correction.js'),'R1: source quality gate must syntax-check the Round-20 browser runtime.');
$check(str_contains($p,'assets/js/round20-correction.js')&&str_contains($p,'includes/class-sn-round20-correction.php'),'R1: package gate must require the Round-20 PHP/JS runtime surfaces.');
$check(str_contains($p,'round20-correction.js'),'R1: package gate must syntax-check the Round-20 browser runtime.');
if($fail){fwrite(STDERR,"Seventh fresh contract failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Seventh fresh contracts: PASS ($checks checks)\n";
