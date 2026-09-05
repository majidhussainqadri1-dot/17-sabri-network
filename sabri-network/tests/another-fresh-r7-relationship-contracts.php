<?php
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$db=(string)file_get_contents($root.'/includes/class-sn-db.php');
$policy=(string)file_get_contents($root.'/includes/class-sn-policy.php');
$rels=(string)file_get_contents($root.'/includes/class-sn-relationships.php');
$runtime=(string)file_get_contents($root.'/includes/class-sn-relationship-runtime-hardening.php');
$presence=(string)file_get_contents($root.'/includes/class-sn-presence-devices.php');
$ledger=(string)file_get_contents($repo.'/FILE17-ANOTHER-10-ROUND-2026-09-05-R7-LEDGER.md');

$check(str_contains($ledger,'R7-D01')&&str_contains($ledger,'R7-D02')&&str_contains($ledger,'R7-D03')&&str_contains($ledger,'R7-D04'),'R7 ledger must retain all frozen defects.');
$check(str_contains($db,'public static function blocked_state')&&str_contains($db,'relationship_block_state_unavailable')&&str_contains($db,'$wpdb->last_error !== \'\''),'R7: authoritative block read must detect DB failure.');
$check(str_contains($db,'return is_wp_error($state) ? true : $state;'),'R7: boolean block wrapper must conservatively deny on unavailable truth.');
$check(substr_count($policy,'SN_DB::blocked_state($actor_id, $target_id)')>=2&&substr_count($policy,'if (is_wp_error($blocked))')>=2,'R7: contact and follow authorization must propagate unavailable block truth.');
$check(str_contains($rels,'$blocked = SN_DB::blocked_state($viewer_id, $target_id);')&&str_contains($rels,'if (is_wp_error($blocked)) return $blocked;'),'R7: relationship state projection must not turn DB failure into unblocked actions.');
$check(str_contains($runtime,'active_call_block_ledger_read_failed')&&str_contains($runtime,'$wpdb->last_error !== \'\' || !is_array($raw_ids)'),'R7: block-triggered active-call cleanup must fail the transaction when the call ledger cannot be read.');
$check(str_contains($presence,'sn_presence_device_count_unavailable')&&str_contains($presence,'$wpdb->last_error!==\'\'||$count_raw===null||!is_numeric($count_raw)'),'R7: new device admission must fail closed when active-device COUNT is unavailable.');
$check(str_contains($presence,"if(\$deleted===false)return['items_removed'=>false,'items_retained'=>true")&&str_contains($presence,"'done'=>false"),'R7: presence-device erasure must remain retryable on delete failure.');

if($fail){fwrite(STDERR,"Another fresh R7 relationship failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Another fresh R7 relationship contracts: PASS ($checks checks)\n";
