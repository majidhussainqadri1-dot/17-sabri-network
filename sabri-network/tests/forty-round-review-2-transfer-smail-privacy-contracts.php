<?php
/** Forty-round audit: transfer containment, Smail projections/drafts and privacy export. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$p4=$read('includes/class-sn-file-transfer-part-4.php');$p5=$read('includes/class-sn-file-transfer-part-5.php');$p7=$read('includes/class-sn-file-transfer-part-7.php');$sm1=$read('includes/class-sn-smail-part-1.php');$sm3=$read('includes/class-sn-smail-part-3.php');$sm4=$read('includes/class-sn-smail-part-4.php');$compat=$read('includes/class-sn-compatibility-hardening.php');$main=$read('sabri-network.php');
$check(str_contains($p7,'existing_storage_path')&&str_contains($p7,'transfer_storage_path_escape'),'Transfer storage must enforce realpath containment.');
$check(str_contains($p7,"preg_match('~(^|/)\\.\\.(/|$)~'")&&str_contains($p7,"str_starts_with(\$storage_key,'/')"),'Traversal and absolute storage keys must be rejected.');
$check(str_contains($p4,'self::existing_storage_path((string)$chunk->storage_key)'),'Finalization reads must validate storage containment.');
$check(substr_count($p5,'self::existing_storage_path((string)$chunk->storage_key)')>=2,'Download and scanner reads must validate storage containment.');
$check(str_contains($p5,'catch(Throwable $e)')&&str_contains($p5,'secure_random_unavailable'),'Scanner temp naming must fail closed on entropy failure.');
$check(str_contains($sm1,'SN_Message_Body::decrypt_row($row)')&&str_contains($sm1,"'body_unavailable'"),'Smail mailbox must project authorized plaintext, not stored ciphertext.');
$check(str_contains($sm3,'SN_Communication_Crypto::needs_rotation($cipher)')&&str_contains($sm3,'sn_network_crypto_rotation_deferred'),'Smail drafts must participate in key rotation.');
$check(str_contains($sm3,"hash_hmac('sha256', \$json")&&!str_contains($sm3,"hash('sha256', \$json)"),'Smail draft plaintext fingerprints must be keyed.');
$check(str_contains($sm4,"['name' => 'Subject'")&&str_contains($sm4,"['name' => 'Body'")&&str_contains($sm4,'SN_Communication_Crypto::decrypt'),'Smail privacy export must be user-readable.');
$check(str_contains($compat,'override_privacy_exporter')&&str_contains($compat,'SN_Message_Body::decrypt_row($row)'),'Core File-17 privacy export must decrypt the account owner’s message values.');
$check(str_contains($main,'class-sn-compatibility-hardening.php')&&str_contains($main,'SN_Compatibility_Hardening::register()'),'Corrective compatibility hardening must load deterministically.');
if($checks!==11)$fail[]='Expected 11 checks, got '.$checks;if($fail){fwrite(STDERR,"Forty-round suite 2 failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Forty-round review 2 transfer/Smail/privacy: PASS ($checks checks)\n";
