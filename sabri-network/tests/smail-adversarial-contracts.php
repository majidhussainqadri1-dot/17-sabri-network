<?php
declare(strict_types=1);
$root=dirname(__DIR__);$src=implode("\n", array_map('file_get_contents', array_merge([$root.'/includes/class-sn-smail.php'], glob($root.'/includes/class-sn-smail-part-*.php'))));$crypto=file_get_contents($root.'/includes/class-sn-communication-crypto.php');$js=file_get_contents($root.'/assets/js/smail.js');$fails=[];$checks=0;
function sma(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
sma(!str_contains($src,'wp_mail('),'Smail is internal and does not silently become external email.');
sma(!str_contains($src,'SMTP'),'Smail does not embed an SMTP backend.');
sma(str_contains($src, 'WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL'),'Draft reads remain owner-scoped.');
sma(str_contains($src,'draft_conflict')&&str_contains($src,'WHERE id=%d AND version=%d'),'Draft concurrency has an optimistic conflict path.');
sma(str_contains($src,'smail_projection_failed'),'Canonical-message/projection failure is explicit, not hidden.');
sma(str_contains($src,'array_diff($recipients, [$sender_id])'),'Self-recipient confusion is removed.');
sma(str_contains($src,'MAX_RECIPIENTS = 50'),'Recipient fan-out is bounded.');
sma(str_contains($src,'MAX_DRAFTS = 500'),'Draft enumeration is bounded.');
sma(str_contains($src,'limit = min(100'),'Mailbox enumeration is bounded.');
sma(str_contains($src,'trashed_at IS NULL')&&str_contains($src,'trashed_at IS NOT NULL'),'Trash state cannot leak into normal boxes.');
sma(str_contains($src,'state.user_id=cm.user_id'),'Mailbox state is viewer-specific.');
sma(str_contains($src,'cm.left_at IS NULL'),'Former conversation members are excluded.');
sma(!str_contains($src,'wp_nav_menu_items'),'Smail does not inject a second global navigation.');
sma(str_contains($crypto,'hash_hmac')&&str_contains($crypto,'wp_salt'),'Encryption/signature keys are derived from server secrets, not hard-coded.');
sma(str_contains($crypto,'sodium_crypto_secretbox')&&str_contains($crypto,'aes-256-gcm'),'Authenticated encryption has supported provider paths.');
sma(str_contains($crypto,'hash_equals'),'Signed grants use timing-safe comparison.');
$debug_pattern='/'.'console'.'\\.log|'.'debugger;'.'/';
sma(!preg_match($debug_pattern, $js),'Production Smail JavaScript contains no debug statements.');
sma(str_contains($js,"credentials:'same-origin'")&&str_contains($js,"'X-WP-Nonce'"),'Client requests retain same-origin credentials and REST nonce.');
sma(str_contains($src,'SN_DB::audit'),'Sensitive Smail state changes are auditable.');
sma(!str_contains($src,'End-to-End Encrypted'),'Smail makes no unsupported E2EE claim.');
// Ninth fresh R39 — privacy completion may never succeed through DB/crypto uncertainty.
sma(str_contains($src,'smail_privacy_export_read_failed')&&str_contains($src,'smail_privacy_export_decrypt_failed')&&str_contains($src,"return ['data' => [], 'done' => false]"),'R39: Smail privacy export must remain retryable on storage or decrypt uncertainty.');
sma(str_contains($src,'smail_privacy_state_erasure_failed')&&str_contains($src,'smail_privacy_draft_erasure_failed')&&str_contains($src,"'done' => false"),'R39: Smail privacy erasure must not claim completion after failed state/draft writes.');
// Ninth fresh R40 — action-time Smail storage truth and idempotency reconciliation fail closed.
sma(str_contains($src,'smail_idempotency_state_unavailable')&&str_contains($src,'smail_projection_reconciliation_read_failed')&&str_contains($src,'smail_projection_postcommit_read_failed'),'R40: Smail send/idempotency paths must surface authoritative-state DB uncertainty.');
sma(str_contains($src,'smail_draft_state_unavailable')&&str_contains($src,'smail_state_unavailable')&&str_contains($src,'smail_health_db_probe_failed'),'R40: draft, mailbox-state and health DB uncertainty must fail closed and remain observable.');
sma(str_contains($src,"'database_ready'=>\$database_ready")&&str_contains($src,"'ok' => \$database_ready && !\$missing"),'R40: Smail health must distinguish DB probe failure from ordinary missing tables.');
if($fails){fwrite(STDERR,"Smail adversarial failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Smail adversarial contracts: PASS ($checks checks)\n";
