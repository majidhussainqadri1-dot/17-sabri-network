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
$spaces2=$read($root.'/includes/class-sn-spaces-part-2.php');
$spaces5=$read($root.'/includes/class-sn-spaces-part-5.php');
$check(str_contains($spaces2,"['join','cancel']")&&str_contains($spaces2,'sn_space_join_action_invalid'),'R4: join mutation must reject unknown actions.');
$check(str_contains($spaces5,"['role','remove']")&&str_contains($spaces5,'sn_space_member_action_invalid')&&str_contains($spaces5,"['ban','unban']")&&str_contains($spaces5,'sn_space_ban_action_invalid'),'R4: membership and ban mutations must reject unknown actions.');
$message=$read($root.'/includes/class-sn-message-runtime-hardening.php');
$check(str_contains($message,"return new WP_Error('invalid_message_type'")&&!str_contains($message,"))\$type='text';"),'R5: canonical send must reject an unknown message type instead of silently changing request semantics.');
if($fail){fwrite(STDERR,"Eighth fresh failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Eighth fresh 20-round contracts: PASS ($checks checks)\n";
