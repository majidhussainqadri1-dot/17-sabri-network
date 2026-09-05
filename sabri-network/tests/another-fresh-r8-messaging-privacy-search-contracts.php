<?php
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$msg=(string)file_get_contents($root.'/includes/class-sn-message-runtime-hardening.php');
$smail=(string)file_get_contents($root.'/includes/class-sn-fifth-fresh-privacy-hardening.php');
$voice=(string)file_get_contents($root.'/includes/class-sn-fifth-fresh-feature-hardening.php');
$search=(string)file_get_contents($root.'/includes/class-sn-fourth-fresh-search-hardening.php');
$ledger=(string)file_get_contents($repo.'/FILE17-ANOTHER-10-ROUND-2026-09-05-R8-LEDGER.md');

$check(str_contains($ledger,'R8-D01')&&str_contains($ledger,'R8-D02')&&str_contains($ledger,'R8-D03')&&str_contains($ledger,'R8-D04')&&str_contains($ledger,'R8-D05'),'R8 ledger must retain all frozen defects.');
$check(str_contains($msg,"'_request_fingerprint'")&&str_contains($msg,'same_message_request')&&str_contains($msg,'message_idempotency_conflict'),'R8: canonical message retries must bind the idempotency key to material request truth.');
$check(substr_count($msg,'reconcile_existing($')>=2&&substr_count($msg,'$fingerprint,$descriptor')>=2,'R8: both pre-existing and race duplicate paths must use material request verification.');
$check(str_contains($msg,'request_descriptor')&&str_contains($msg,"hash_file('sha256',$tmp)")&&str_contains($msg,'attachment_sha256'),'R8: attachment retries must compare content hash without creating duplicate private bytes.');
$check(str_contains($msg,'recipients_authoritative')&&str_contains($msg,'message_recipient_ledger_unavailable')&&str_contains($msg,"$wpdb->last_error!==''||!is_array($raw)"),'R8: authorization-time recipient enumeration must fail closed on database read failure.');
$check(str_contains($msg,'$others=self::recipients_authoritative')&&str_contains($msg,'if(is_wp_error($others))return $others;'),'R8: contact authorization must propagate recipient-ledger failure.');
$check(str_contains($smail,'Smail state privacy truth could not be read safely.')&&str_contains($smail,'Smail draft privacy truth could not be read safely.'),'R8: final Smail eraser initial reads must remain retryable when database truth is unavailable.');
$check(str_contains($smail,'Smail state privacy completion could not be verified safely.')&&str_contains($smail,'Smail draft privacy completion could not be verified safely.'),'R8: final Smail eraser completion probes must fail closed.');
$check(str_contains($voice,'$was_duplicate = !empty($data[\'duplicate\'])')&&str_contains($voice,'voice_note_idempotency_conflict'),'R8: duplicate voice-note requests must have explicit conflict semantics.');
$check(str_contains($voice,'$existing_transcript !== $transcript')&&str_contains($voice,"'duplicate'=>true"),'R8: finalized duplicate voice-note metadata must be compared and not silently rewritten.');
$check(str_contains($search,'finish_rebuild_read_failed')&&str_contains($search,"$wpdb->last_error !== '' || ($next_raw !== null && !is_numeric($next_raw))")&&str_contains($search,"self::record_error('finish_rebuild_read_failed'"),'R8: private-search completion must not treat a failed next-row read as an empty corpus.');

if($fail){fwrite(STDERR,"Another fresh R8 messaging/privacy/search failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Another fresh R8 messaging/privacy/search contracts: PASS ($checks checks)\n";
