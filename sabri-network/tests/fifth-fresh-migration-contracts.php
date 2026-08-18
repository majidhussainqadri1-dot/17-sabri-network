<?php
/** Fifth fresh Round-18 migration/activation regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$m=$read('includes/class-sn-fifth-fresh-migration-hardening.php');
$a=$read('includes/class-sn-activator.php');
$p=$read('includes/class-sn-fifth-fresh-privacy-hardening.php');
$l=$read('includes/class-sn-future24-review-hardening.php');
$check(str_contains($m,"SELECT GET_LOCK(%s,%d)")&&str_contains($m,"SELECT RELEASE_LOCK(%s)"),'R18: schema upgrade must be globally serialized.');
$check(str_contains($m,'restore_version_snapshot')&&str_contains($m,"'status'=>'failed'")&&str_contains($m,'verify_schema()'),'R18: failed migration must restore schema-version truth and require post-install verification.');
$check(str_contains($m,'sn_phone_otps_f17_retired')&&str_contains($m,'RENAME TABLE'),'R18: legacy File-17 OTP evidence must be preserved before the old installer retires its table.');
$check(str_contains($m,"'presence_devices'=>['user_id','device_key','state'")&&str_contains($m,"'transfer_sessions'=>")&&str_contains($m,"'transfer_recipients'=>"),'R18: migration verification must use exact active table/column names.');
$check(str_contains($a,'SN_Fifth_Fresh_Migration_Hardening::upgrade(true)')&&!str_contains($a,'SN_DB::install();'),'R18: activation must use the governed migration transaction rather than a parallel installer chain.');
$check(str_contains($a,'SN_File_Transfer::ensure_page(false)')&&str_contains($a,'SN_Smail::ensure_page(false)'),'R18: activation must satisfy required ensure_page boolean signatures.');
$check(str_contains($p,"'sabri-network-transfers' => 'erase_transfers'")&&str_contains($p,"SN_DB::table('transfer_sessions')")&&str_contains($p,"SN_DB::table('transfer_recipients')"),'R18: transfer privacy override must bind the actual exporter/eraser key and schema.');
$check(!str_contains($p,'SN_File_Transfer::delete_chunks')&&str_contains($p,'SN_File_Transfer::erase_personal_data'),'R18: transfer privacy hardening must use the public canonical eraser rather than calling a private cleanup method.');
$check(str_contains($l,'SN_Fifth_Fresh_Migration_Hardening::register()'),'R18: migration governor must be loaded and registered before init upgrades.');
if($fail){fwrite(STDERR,"Fifth fresh migration contract failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fifth fresh migration contracts: PASS ($checks checks)\n";
