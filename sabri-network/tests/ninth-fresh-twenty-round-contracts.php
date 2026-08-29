<?php
/** Ninth fresh 20-round permanent regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($p);
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};

$activator=$read($root.'/includes/class-sn-activator.php');
$check(str_contains($activator,'SN_Fifth_Fresh_Migration_Hardening::upgrade(true)')&&str_contains($activator,'verify_owned_surface')&&str_contains($activator,'SN_Private_Files::ensure_storage()')&&str_contains($activator,'SN_File_Transfer::ensure_storage()'),'R1: activation delegates schema truth and fails closed on owned surfaces/storage.');

$auth=$read($root.'/includes/class-sn-auth.php');
$check(str_contains($auth,'Never let an internal caller accidentally grant')&&str_contains($auth,'can_see_phone')&&str_contains($auth,'phone_projection')&&str_contains($auth,'verification_badge'),'R2: self-only identity and canonical phone/verification remain bounded.');

$spaces=$read($root.'/includes/class-sn-spaces-part-1.php');
$check(str_contains($spaces,'space transaction could not start safely')&&str_contains($spaces,'assert_manage_locked')&&str_contains($spaces,'START TRANSACTION'),'R4: space transaction/action-time authority remains fail closed.');

$ninth=$read($root.'/includes/class-sn-ninth-fresh-hardening.php');
$loader=$read($root.'/includes/class-sn-future24-review-hardening.php');
$check(str_contains($ninth,'override_message_routes')&&str_contains($ninth,'FOR UPDATE')&&str_contains($ninth,'message.edited')&&str_contains($ninth,'message.deleted')&&str_contains($loader,'SN_Ninth_Fresh_Hardening::register()'),'R5: current message edit/delete uses locked ninth hardening and atomic event semantics.');

$voice=$read($root.'/includes/class-sn-fifth-fresh-feature-hardening.php');
$check(str_contains($voice,'SN_Message_Runtime_Hardening::send_message')&&str_contains($voice,'Retry with the same idempotency key')&&str_contains($voice,'_mutation_version'),'R7: voice-note send/finalization remains canonical and retry safe.');

$provider=$read($root.'/includes/class-sn-conference-provider.php');
$check(str_contains($provider,'provider configuration transaction could not start safely')&&str_contains($provider,'SN_High_Risk::claim')&&str_contains($provider,'SN_High_Risk::complete'),'R9: provider governance remains transactionally fail closed.');
$meet=$read($root.'/includes/class-sn-meet.php');
$check(str_contains($meet,'START TRANSACTION')&&str_contains($meet,'meet_commit_failed')&&str_contains($meet,'database_error'),'R9: Sabri Meet transaction start/commit failure remains fail closed.');

$rt=$read($root.'/includes/class-sn-fourth-fresh-realtime-hardening.php');
$check(str_contains($rt,'sn_typing_clear_failed')&&str_contains($rt,'sn_typing_read_failed'),'R10: typing DB failures remain explicit.');
$check(str_contains($rt,'sabri-meet')&&str_contains($rt,'must be retried')&&str_contains($rt,'done'),'R11: Meet erasure failures remain retryable under the correct key.');
$check(str_contains($rt,'guard_smail_replay')&&str_contains($rt,'caller-supplied Smail')&&str_contains($rt,'smail_idempotency_conflict'),'R12: Smail exact caller-owned replay binding remains enforced.');

$cf=$read($root.'/includes/class-sn-cf01-clinical-context.php');
$check(str_contains($cf,'opaque clinical-context reference transaction could not start')&&str_contains($cf,'FOR UPDATE')&&str_contains($cf,'SN_Membership_Assertions::clear_cache')&&substr_count($cf,'sn_cf01_clinical_context_issuer_authorized')>=2&&substr_count($cf,'sn_cf01_clinical_context_consent_authorized')>=2,'R14: CF01 issuance revalidates locked action-time authorization.');

$mig=$read($root.'/includes/class-sn-fifth-fresh-migration-hardening.php');
$check(str_contains($mig,'message_hides')&&str_contains($mig,'message_folders')&&str_contains($mig,'meet_sessions')&&str_contains($mig,'meet_signals'),'R16: central migration verification covers Message Operations and Meet.');

$ui=$read($root.'/assets/js/fifth-fresh-ui.js');
$check(str_contains($ui,'const hasOpenModal = () =>')&&str_contains($ui,'if (hasOpenModal()) return;')&&str_contains($ui,'last modal in the chain'),'R17: replacement-modal focus remains contained.');

$compat=$read($root.'/includes/class-sn-compatibility-hardening.php');
$check(str_contains($compat,'dnd')&&str_contains($compat,'sn_presence_state_invalid')&&str_contains($compat,'Choose online, away, dnd, or offline.'),'R19: legacy presence preserves canonical dnd and rejects unknown states.');

$quality=$read($root.'/tools/quality-check.sh');
$workflow=$read($repo.'/.github/workflows/quality.yml');
$package=$read($root.'/tools/package.sh');
$cycle=trim($read($root.'/REVIEW-CYCLE-ID.txt'));
$qa=$read($root.'/QA-INVENTORY.txt');
$check(str_contains($quality,'ninth-fresh-twenty-round-contracts.php')&&substr_count($workflow,'ninth-fresh-twenty-round-contracts.php')>=2,'R20: quality and both CI paths execute ninth regression coverage.');
$check(str_contains($quality,'includes/class-sn-ninth-fresh-hardening.php')&&str_contains($package,'includes/class-sn-ninth-fresh-hardening.php'),'R20: ninth runtime hardening is an explicit quality/package surface.');
$check($cycle==='FILE17-NINTH-FRESH-20-ROUND-2026-08-29'&&str_contains($qa,'55 PHP review suites'),'R20: current cycle and 55-suite QA truth are synchronized.');

if($fail){fwrite(STDERR,"Ninth fresh failures (".count($fail)."/$checks):
 - ".implode("
 - ",$fail)."
");exit(1);}echo "Ninth fresh 20-round contracts: PASS ($checks checks)
";
