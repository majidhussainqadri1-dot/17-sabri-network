<?php
/** Fresh 20-round Round-16 migration/rollback contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$m=$read('includes/class-sn-fifth-fresh-migration-hardening.php');
$a=$read('includes/class-sn-activator.php');
$o=$read('includes/class-sn-outbox.php');
$check(str_contains($o,"SCHEMA_VERSION = '1.1.0'")&&str_contains($o,'event_contract VARCHAR(160)')&&str_contains($o,'event_schema_version VARCHAR(20)'),'R16-D01 precondition: outbox schema requires the Round-15 contract columns.');
$check(str_contains($m,"'event_outbox'=>['event_uuid','event_key','event_type','event_contract','event_schema_version','status','attempts','version']"),'R16-D01: authoritative migration verification must require both new outbox columns.');
$check(str_contains($m,"sn_plugin_version','') === SN_VERSION && self::verify_schema()"),'R16-D01: same-version fast path remains gated by physical schema verification.');
$check(str_contains($m,'$legacy_otp_renamed = false;')&&str_contains($m,'$legacy_otp_renamed = self::preserve_legacy_otp_table();'),'R16-D02: migration invocation must track whether it physically renamed legacy OTP storage.');
$check(str_contains($m,'restore_legacy_otp_table()')&&str_contains($m,"'rollback_status'=>")&&str_contains($m,'sn_migration_rollback_failed'),'R16-D02: failure path must restore and report physical rollback truth.');
$check(str_contains($m,'legacy_otp_backup_conflict'),'R16-D02: concurrent/pre-existing backup conflict must fail closed without destructive overwrite.');
$check(str_contains($m,'legacy_otp_rollback_source_missing')&&str_contains($m,'legacy_otp_rollback_target_conflict')&&str_contains($m,'legacy_otp_rollback_unverified'),'R16-D02: rollback restoration must verify source, target and final physical state.');
$upgrade=strpos($a,'SN_Fifth_Fresh_Migration_Hardening::upgrade(true)');
$retire=strpos($a,'self::retire_legacy_secrets();');
$flush=strpos($a,'flush_rewrite_rules(false);');
$check($upgrade!==false&&$retire!==false&&$retire>$upgrade,'R16-D03: destructive legacy-secret retirement must occur only after governed migration success.');
$check($flush!==false&&$retire>$flush,'R16-D03: legacy-secret retirement must be the final destructive activation step after readiness checks.');
$prefix=substr($a,0,$upgrade);
$check(!str_contains($prefix,'self::retire_legacy_secrets();'),'R16-D03: no pre-migration destructive retirement may remain.');
if($fail){fwrite(STDERR,"R16 migration/rollback failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "R16 migration/rollback contracts: PASS ($checks checks)\n";
