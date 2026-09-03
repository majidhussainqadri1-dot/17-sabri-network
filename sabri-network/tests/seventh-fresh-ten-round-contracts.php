<?php
/** Seventh fresh 10-round permanent regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$q=$read('tools/quality-check.sh');
$p=$read('tools/package.sh');
$r=$read('includes/class-sn-round20-correction.php');
$m=$read('includes/class-sn-membership-assertions.php');
$check(str_contains($r,"assets/js/round20-correction.js"),'R1: Round-20 correction must retain its registered browser runtime asset.');
$check(str_contains($q,'assets/js/round20-correction.js')&&str_contains($q,'includes/class-sn-round20-correction.php'),'R1: source quality gate must require the Round-20 PHP/JS runtime surfaces.');
$check(str_contains($q,'round20-correction.js'),'R1: source quality gate must syntax-check the Round-20 browser runtime.');
$check(str_contains($p,'assets/js/round20-correction.js')&&str_contains($p,'includes/class-sn-round20-correction.php'),'R1: package gate must require the Round-20 PHP/JS runtime surfaces.');
$check(str_contains($p,'round20-correction.js'),'R1: package gate must syntax-check the Round-20 browser runtime.');
$check(str_contains($m,'return self::available();'),'R2: canonical File-00 contract functions must establish base identity-authority availability.');
$check(!str_contains($m,'return self::available() && $available !== false;'),'R2: a legacy heuristic false seed must not veto a valid File-00 communication contract.');
$check(str_contains($m,"add_filter('sn_network_identity_authority_available', [self::class, 'filter_authority_available'], PHP_INT_MIN)"),'R2: canonical contract discovery must run first so later filters can still deny availability.');
if($fail){fwrite(STDERR,"Seventh fresh contract failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Seventh fresh contracts: PASS ($checks checks)\n";
