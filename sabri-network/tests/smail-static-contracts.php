<?php
declare(strict_types=1);
$root = dirname(__DIR__); $main = file_get_contents($root.'/sabri-network.php'); $src = implode("\n", array_map('file_get_contents', array_merge([$root.'/includes/class-sn-smail.php'], glob($root.'/includes/class-sn-smail-part-*.php')))); $tpl = file_get_contents($root.'/templates/smail-app.php'); $js = file_get_contents($root.'/assets/js/smail.js'); $css = file_get_contents($root.'/assets/css/smail.css'); $fails=[];$checks=0;
function smc(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
smc(str_contains($main,'class-sn-smail.php')&&str_contains($main,'SN_Smail::register()')&&str_contains($main,'SN_Smail::install()'),'Smail lifecycle is loaded, registered and installed.');
foreach(['inbox','sent','drafts','starred','archive','spam','trash'] as $box){smc(str_contains($src,"'$box'"),"Mailbox $box is implemented.");}
smc(str_contains($src,'SN_Central_Plan_Hardening::resolve_smail_conversation')&&str_contains($src,'SN_Message_Integrity::send_message'),'Smail reuses retry-safe canonical conversation resolution and the atomic canonical message service.');
smc(!str_contains($src,'SN_REST::send_message'),'Smail cannot bypass the canonical message-integrity route.');
smc(!str_contains($src,'CREATE TABLE')||str_contains($src,'message_id BIGINT UNSIGNED NOT NULL'),'Smail stores message references rather than a second message body truth.');
smc(str_contains($src,'encrypted_payload LONGTEXT'),'Draft payload is encrypted at rest.');
smc(str_contains($src,'SN_Communication_Crypto::encrypt')&&str_contains($src,'SN_Communication_Crypto::decrypt'),'Draft encryption and decryption are explicit.');
smc(str_contains($src,'client_key CHAR(64) NOT NULL')&&str_contains($src,'UNIQUE KEY client_key'),'Smail send is database-idempotent.');
smc(str_contains($src,'SN_Policy::can_contact'),'Every recipient is checked through File-17 contact policy.');
smc(str_contains($src,'SN_Policy::consume_rate_limit'),'Smail send and draft operations are rate-limited.');
smc(str_contains($src,"SN_Outbox::enqueue('smail.sent'"),'Smail emits a reliable factual event.');
smc(str_contains($src,'register_exporter')&&str_contains($src,'register_eraser'),'Smail has privacy export and erasure contracts.');
smc(str_contains($src,'X-Robots-Tag: noindex, noarchive'),'Smail surface is noindex and noarchive.');
smc(str_contains($tpl, "['inbox' => 'Inbox'")&&str_contains($tpl, "'trash' => 'Trash'")&&str_contains($tpl,'data-sm-box'),'All mailbox controls are rendered.');
smc(str_contains($tpl,'maxlength="10000"')&&str_contains($tpl,'maxlength="200"'),'Composer limits are visible and bounded.');
smc(str_contains($js,'crypto.randomUUID')&&str_contains($js,'saveDraft'),'Client supplies idempotency and draft workflow.');
smc(str_contains($css,'min-height:44px'),'Smail interactive targets meet the 44px baseline.');
smc(str_contains($css,'@media(max-width:760px)'),'Smail has a compact mobile layout.');
smc(str_contains($css,'prefers-reduced-motion'),'Smail respects reduced motion.');
smc(str_contains($css,'var(--sabri-primary,#137a46)'),'Smail consumes the current green design token with a safe fallback.');
if($fails){fwrite(STDERR,"Smail static failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Smail static contracts: PASS ($checks checks)\n";
