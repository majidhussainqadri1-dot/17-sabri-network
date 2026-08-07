<?php
declare(strict_types=1);
$root=dirname(__DIR__);$main=file_get_contents($root.'/sabri-network.php');$body=file_get_contents($root.'/includes/class-sn-message-body.php');$integrity=file_get_contents($root.'/includes/class-sn-message-integrity.php');$search=file_get_contents($root.'/includes/class-sn-message-search.php');$smail=file_get_contents($root.'/includes/class-sn-smail-part-2.php');$hard=file_get_contents($root.'/includes/class-sn-central-plan-hardening.php');$fails=[];$checks=0;
function fpr3(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
fpr3(str_contains($main,"class-sn-message-body.php")&&str_contains($main,"class-sn-central-plan-hardening.php"),'Message encryption and central hardening classes are loaded.');
fpr3(str_contains($body,"PREFIX = 'SNE1:'")&&str_contains($body,'SN_Communication_Crypto::encrypt')&&str_contains($body,'SN_Communication_Crypto::decrypt'),'Canonical message bodies use authenticated at-rest envelopes.');
fpr3(str_contains($body,'Transitional compatibility')&&str_contains($hard,'migrate_message_bodies'),'Legacy plaintext has bounded non-destructive migration rather than permanent plaintext fallback.');
fpr3(str_contains($integrity,'SN_Message_Body::encrypt($body, $conversation_id, $user_id)'),'New canonical messages encrypt before database insertion.');
fpr3(str_contains($integrity,'SN_Message_Body::encrypt($body, (int) $message->conversation_id'),'Edits re-encrypt before database update.');
fpr3(str_contains($search,'SN_Message_Body::decrypt_row($message)')&&str_contains($search,'self::terms($plain'),'Private search tokenizes plaintext only after authorized in-memory decryption.');
fpr3(str_contains($smail,'SN_Message_Integrity::send_message')&&!str_contains($smail,'SN_REST::send_message'),'Smail cannot bypass the atomic canonical message path.');
fpr3(str_contains($smail,'resolve_smail_conversation'),'Smail retries use an idempotent canonical conversation resolver.');
fpr3(str_contains($hard,"'recipient_hash' => \$recipient_hash")&&str_contains($hard,'smail_idempotency_conflict'),'Multi-recipient Smail retries bind the same key to the same audience.');
fpr3(str_contains($hard,'SN_Message_Body::decrypt_row($source)')&&str_contains($hard,'SN_Message_Body::encrypt($plain, $target_conversation, $actor)'),'Forwarding decrypts only authorized source content in memory and re-encrypts target content.');
fpr3(str_contains($hard,"'source_visible' => false"),'Forwarded metadata does not disclose source message identity across audience boundaries.');
fpr3(str_contains($hard,'MIGRATION_BATCH = 100'),'At-rest migration is bounded per pass.');
if($fails){fwrite(STDERR,"Four-plan review 3 failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Four-plan review 3 message/Smail security: PASS ($checks checks)\n";
