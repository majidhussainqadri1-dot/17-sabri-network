<?php
/** Eighth fresh 20-round permanent regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($p);
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$realtime=$read($root.'/includes/class-sn-realtime-runtime-hardening.php');
$check(str_contains($realtime,'private static bool $registered = false;')&&str_contains($realtime,'if (self::$registered) return;')&&str_contains($realtime,'self::$registered = true;'),'R1: nested realtime/call hardening registration must be idempotent.');
$relationships=$read($root.'/includes/class-sn-relationships.php');
$check(str_contains($relationships,'$blocked_by_target')&&str_contains($relationships,'relationship_unavailable')&&str_contains($relationships,'$blocked = $viewer_blocked;'),'R3: relationship state must not disclose a target-owned block as viewer-owned unblock state.');
$spaces2=$read($root.'/includes/class-sn-spaces-part-2.php');
$spaces5=$read($root.'/includes/class-sn-spaces-part-5.php');
$check(str_contains($spaces2,"['join','cancel']")&&str_contains($spaces2,'sn_space_join_action_invalid'),'R4: join mutation must reject unknown actions.');
$check(str_contains($spaces5,"['role','remove']")&&str_contains($spaces5,'sn_space_member_action_invalid')&&str_contains($spaces5,"['ban','unban']")&&str_contains($spaces5,'sn_space_ban_action_invalid'),'R4: membership and ban mutations must reject unknown actions.');
$message=$read($root.'/includes/class-sn-message-runtime-hardening.php');
$check(str_contains($message,"return new WP_Error('invalid_message_type'")&&!str_contains($message,"))\$type='text';"),'R5: canonical send must reject an unknown message type instead of silently changing request semantics.');
$visibility=$read($root.'/includes/class-sn-message-visibility.php');
$check(str_contains($visibility,'MAX_VISIBILITY_SCAN_PAGES')&&str_contains($visibility,'$visible = array_merge($eligible, $visible)')&&str_contains($visibility,'$visible = array_merge($visible, $eligible)'),'R6: message paging must scan across viewer-hidden rows in both older and newer directions.');
$check(str_contains($realtime,'sn_presence_state_invalid')&&str_contains($realtime,"['online','away','dnd','offline']"),'R10: explicit invalid presence state must fail closed instead of becoming online.');
$safety=$read($root.'/includes/class-sn-safety-runtime-hardening.php');
$check(str_contains($safety,"\$route === '/sabri-network/v2/report'")&&str_contains($safety,'report_replay_conflict')&&str_contains($safety,'report_idempotency_conflict'),'R13: native report creation must be serialized and exact replay must bind content/evidence, not target alone.');
$integration=$read($root.'/includes/class-sn-fifth-fresh-integration-hardening.php');
$check(str_contains($integration,'enforce_projection_origin_port')&&str_contains($integration,"\$scheme !== 'https'")&&str_contains($integration,'$port !== $home_port')&&str_contains($integration,"isset(\$parts['user'])")&&str_contains($integration,"isset(\$parts['pass'])"),'R14: context projection URLs must enforce exact HTTPS origin including port and reject embedded credentials.');
$migration=$read($root.'/includes/class-sn-fifth-fresh-migration-hardening.php');
$check(str_contains($migration,"'conversation_contexts'=>['conversation_id','provider','provider_object_id','attached_by','version']")&&str_contains($migration,"'cf01_context_refs'=>['conversation_id','reference_uuid','issued_by','status','version']")&&!str_contains($migration,"'conversation_contexts'=>['conversation_id','provider','external_id'")&&!str_contains($migration,"'cf01_context_refs'=>['conversation_id','context_ref'"),'R16: migration verification must match the active context-adapter and CF-01 installer column names.');
$quality=$read($root.'/tools/quality-check.sh');
$workflow=$read(dirname($root).'/.github/workflows/quality.yml');
$check(str_contains($quality,'eighth-fresh-twenty-round-contracts.php')&&substr_count($workflow,'eighth-fresh-twenty-round-contracts.php')>=2,'R20: both the full quality gate and both workflow paths must execute the eighth-cycle regression suite.');
if($fail){fwrite(STDERR,"Eighth fresh failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Eighth fresh 20-round contracts: PASS ($checks checks)\n";
