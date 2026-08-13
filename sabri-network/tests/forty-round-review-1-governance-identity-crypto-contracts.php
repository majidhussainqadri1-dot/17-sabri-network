<?php
/** Forty-round audit: rounds 1-10 governance, identity, crypto, verification and presence. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$main=$read('sabri-network.php');$auth=$read('includes/class-sn-auth.php');$crypto=$read('includes/class-sn-communication-crypto.php');$transfer=$read('includes/class-sn-file-transfer-part-6.php');$finalize=$read('includes/class-sn-file-transfer-part-4.php');$presence=$read('includes/class-sn-presence-devices.php');$body=$read('includes/class-sn-message-body.php');
$check(str_contains($main,"'global_search_owner' => 'file-26'")&&str_contains($main,"'global_search_private_messages_exported' => false"),'File 26 must own global search without private-message export.');
$check(strpos($auth,'SN_DB::is_blocked($viewer_id, $target_id)')<strpos($auth,"\$visibility === 'everyone'"),'Block must override phone visibility.');
$check(str_contains($auth,'$blocked')&&str_contains($auth,'$can_see_avatar = !$blocked'),'Profile avatar projection must be block-aware.');
$check(str_contains($crypto,'V2_SODIUM = "SNC3"')&&str_contains($crypto,'V2_OPENSSL = "SNC4"')&&str_contains($crypto,'sn_network_communication_previous_secrets'),'Private crypto must support versioned rotatable keyring formats.');
$check(str_contains($crypto,'current_key_id')&&str_contains($crypto,'needs_rotation')&&str_contains($crypto,'rotate('),'Crypto rotation primitives must be explicit.');
$check(str_contains($transfer,'SN_Policy::identity_authority_available()')&&str_contains($transfer,"sn_network_verified_transfer_user',null")&&str_contains($transfer,'===true'),'Verified transfer eligibility must fail closed to File 00 authority.');
$check(!str_contains($transfer,'sn_phone_verified')&&!str_contains($transfer,'sabri_verified'),'Transfer verification must not use legacy user-meta badges.');
$check(str_contains($finalize,'finally')&&str_contains($finalize,'$cleanup();'),'Scanner plaintext materialization must have unconditional cleanup.');
$check(str_contains($presence,'if($viewer===$target)$response[\'active_devices\']'),'Device count may be disclosed only to self.');
$check(str_contains($presence,"['online','away','dnd','offline']")&&str_contains($presence,"\$state==='offline'?\$now"),'Canonical device presence must represent explicit offline.');
$check(str_contains($body,'SN_Communication_Crypto::needs_rotation')&&str_contains($body,'rotate_row'),'Message bodies must lazily re-encrypt after key rotation.');
$check(str_contains($crypto,'sn_network_crypto_rotation_deferred')&&str_contains($crypto,'read_encrypted_file'),'Encrypted private files must rotate lazily without plaintext logging.');
if($checks!==12)$fail[]='Expected 12 checks, got '.$checks;
if($fail){fwrite(STDERR,"Forty-round suite 1 failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Forty-round review 1 governance/identity/crypto: PASS ($checks checks)\n";
