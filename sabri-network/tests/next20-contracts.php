<?php
/** Permanent cumulative contracts for the 6 September 2026 next-fresh 20-round cycle. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$activator=$read('includes/class-sn-activator.php');$admin=$read('includes/class-sn-admin.php');$messages=$read('includes/class-sn-messages.php');$transfer=$read('includes/class-sn-file-transfer-part-7.php');$smail=$read('includes/class-sn-smail-part-3.php');$migration=$read('includes/class-sn-fifth-fresh-migration-hardening.php');
$check(str_contains($activator,'wp_update_post([')&&str_contains($activator,'], true);'),'R1 network repair requests WP_Error-aware post update');
$check(str_contains($activator,'File 17 Messages pages could not be created safely.'),'R1 activation verifies both Messages pages');
$check(str_contains($messages,'if (is_wp_error($updated) || (int) $updated !== $page_id) return 0;'),'R1 Messages repair fails closed on wp_update_post error');
$check(str_contains($transfer,'if(is_wp_error($updated)||(int)$updated!==$id)return 0'),'R1 transfer page repair fails closed');
$check(str_contains($smail,"post_status !== 'publish'")&&str_contains($smail,'is_wp_error($updated)'),'R1 Smail page repair verifies published truth');
$check(str_contains($admin,'SN_File_Transfer::ensure_storage()'),'R1 complete repair verifies transfer storage');
$check(str_contains($admin,'$message_pages = SN_Messages::ensure_pages(true);')&&str_contains($admin,'SN_File_Transfer::ensure_page(true) <= 0')&&str_contains($admin,'SN_Smail::ensure_page(true) <= 0'),'R1 complete repair checks all owned page results');
$check(str_contains($migration,'legacy_otp_presence_check_failed')&&str_contains($migration,'legacy_otp_backup_check_failed')&&substr_count($migration,'$wpdb->last_error')>=2,'R1 migration rejects failed legacy table probes');
if($fail){fwrite(STDERR,"Next20 contracts failed (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Next20 contracts: PASS ($checks checks)\n";
