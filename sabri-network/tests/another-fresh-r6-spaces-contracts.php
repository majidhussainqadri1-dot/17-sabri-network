<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fails=[];$checks=0;
$check=static function(bool $ok,string $message)use(&$fails,&$checks):void{$checks++;if(!$ok)$fails[]=$message;};
$part7=(string)file_get_contents($root.'/includes/class-sn-spaces-part-7.php');
$part8=(string)file_get_contents($root.'/includes/class-sn-spaces-part-8.php');
$ledger=(string)file_get_contents(dirname($root).'/FILE17-ANOTHER-10-ROUND-2026-09-05-R6-LEDGER.md');

$check(str_contains($ledger,'R6-D01')&&str_contains($ledger,'R6-D02')&&str_contains($ledger,'R6-D03'),'R6 ledger must retain all frozen defects.');
$check(str_contains($part8,'private static function is_banned_strict')&&str_contains($part8,'sn_space_ban_state_unavailable')&&str_contains($part8,'$wpdb->last_error!==\'\''),'R6: authoritative ban reads must distinguish DB failure from no ban.');
$check(str_contains($part8,'private static function member_count_strict')&&str_contains($part8,'sn_space_capacity_state_unavailable')&&str_contains($part8,'$value===null||!is_numeric($value)'),'R6: authoritative capacity reads must fail closed when COUNT truth is unavailable.');
$check(str_contains($part7,'$banned=self::is_banned_strict')&&str_contains($part7,'if(is_wp_error($banned))return $banned'),'R6: positive membership eligibility must propagate ban-read failure.');
$check(str_contains($part7,'$count=self::member_count_strict')&&str_contains($part7,'if(is_wp_error($count))return $count'),'R6: positive membership eligibility must propagate capacity-read failure.');
$check(substr_count($part7,'$wpdb->last_error=\'\'')>=5&&str_contains($part7,'Space ownership state could not be verified and erasure must be retried.')&&str_contains($part7,'Space membership state could not be read and erasure must be retried.'),'R6: space erasure owner and membership reads must be retryable on DB failure.');
$check(str_contains($part7,'Space privacy completion could not be verified and must be retried.')&&str_contains($part7,"'items_retained'=>true")&&str_contains($part7,"'done'=>false"),'R6: failed completion probes must never report privacy completion.');
$check(str_contains($part7,"'items_retained'=>\$more_members||\$more_invites||\$more_requests")&&str_contains($part7,"'done'=>!\$more_members&&!\$more_invites&&!\$more_requests"),'R6: retained pending space state must prevent done=true.');

if($fails){fwrite(STDERR,"Another fresh R6 spaces failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Another fresh R6 spaces contracts: PASS ($checks checks)\n";
