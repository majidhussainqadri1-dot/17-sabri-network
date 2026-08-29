<?php
/** Eighth fresh 20-round permanent regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($p);
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$realtime=$read($root.'/includes/class-sn-realtime-runtime-hardening.php');
$check(str_contains($realtime,'private static bool $registered = false;')&&str_contains($realtime,'if (self::$registered) return;')&&str_contains($realtime,'self::$registered = true;'),'R1: nested realtime/call hardening registration must be idempotent.');
$relationships=$read($root.'/includes/class-sn-relationships.php');
$check(str_contains($relationships,'$blocked_by_target')&&str_contains($relationships,"relationship_unavailable")&&str_contains($relationships,'$blocked = $viewer_blocked;'),'R3: relationship state must not disclose a target-owned block as viewer-owned unblock state.');
if($fail){fwrite(STDERR,"Eighth fresh failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Eighth fresh 20-round contracts: PASS ($checks checks)\n";
