<?php
/** Forty-round audit: clean-round regression anchors for canonical communication domains. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$relations=$read('includes/class-sn-relationships.php');$policy=$read('includes/class-sn-policy.php');$spaces='';foreach(glob($root.'/includes/class-sn-spaces*.php')?:[] as $f)$spaces.=$read(substr($f,strlen($root)+1));$integrity=$read('includes/class-sn-message-integrity.php');$search=$read('includes/class-sn-message-search.php');$outbox=$read('includes/class-sn-outbox.php');$safety=$read('includes/class-sn-safety.php');$meet=$read('includes/class-sn-meet.php');$conference=$read('includes/class-sn-conference-provider.php');$contexts=$read('includes/class-sn-context-adapters.php');$risk=$read('includes/class-sn-high-risk.php');
$check(str_contains($relations,'$race = SN_DB::follow_record')&&str_contains($relations,'version=%d'),'Relationship mutations must remain race-aware.');
$check(str_contains($policy,'SN_DB::is_blocked')&&str_contains($policy,'has_guardian_consent'),'Central contact policy must enforce block and guardian state.');
$check(str_contains($spaces,'sn_space_owner_successor_required')&&str_contains($spaces,'ROLE_RANK'),'Space governance must enforce succession and hierarchy.');
$check(str_contains($integrity,'SN_Message_Body::encrypt')&&str_contains($integrity,'SN_Outbox::enqueue'),'Canonical message writes must combine encrypted storage with reliable events.');
$check(str_contains($search,'SN_Message_Body::decrypt_row')&&str_contains($search,'hash_hmac'),'Private message search must authorize/decrypt transiently and index keyed tokens.');
$check(str_contains($outbox,'dead')&&str_contains($outbox,'retry'),'Outbox must retain retry/dead-letter semantics.');
$check(str_contains($safety,'legal_hold')&&str_contains($safety,'appeal'),'Safety lifecycle must retain scoped hold and appeal semantics.');
$check(str_contains($meet,"'e2ee' => false")&&str_contains($meet,"'recording_enabled' => false"),'Meet must not claim unsupported E2EE or recording.');
$check(str_contains($conference,"private const TYPES = ['stun','turn','sfu']")&&str_contains($conference,'MAX_CREDENTIAL_TTL'),'Conference providers must be typed and short-lived.');
$check(str_contains($contexts,"['file08_appointment','file18_marketplace','file21_content']")&&str_contains($contexts,'provider_object_hash'),'Cross-file contexts must stay allowlisted and opaque.');
$check(str_contains($risk,'claim_token_hash')&&str_contains($risk,'payload_hash'),'High-risk execution must remain token/payload bound.');
if($checks!==11)$fail[]='Expected 11 checks, got '.$checks;if($fail){fwrite(STDERR,"Forty-round suite 3 failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Forty-round review 3 canonical safety/resilience: PASS ($checks checks)\n";
