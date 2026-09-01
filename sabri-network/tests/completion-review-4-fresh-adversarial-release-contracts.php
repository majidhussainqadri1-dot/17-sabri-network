<?php
/** Completion review 4: fresh adversarial, rollback and release-truth contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$failures=[];$checks=0;
$read=static function(string $path):string{$v=file_get_contents($path);if($v===false)throw new RuntimeException('Missing '.$path);return $v;};
$check=static function(bool $ok,string $message)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$message;};
$main=$read($root.'/sabri-network.php');$spaces='';foreach(glob($root.'/includes/class-sn-spaces*.php')?:[] as $space_file)$spaces.=$read($space_file);$presence=$read($root.'/includes/class-sn-presence-devices.php');$ops=$read($root.'/includes/class-sn-message-operations.php');$contexts=$read($root.'/includes/class-sn-context-adapters.php');$risk=$read($root.'/includes/class-sn-high-risk.php');$conference=$read($root.'/includes/class-sn-conference-provider.php');$compat=$read($root.'/includes/class-sn-compatibility-hardening.php');$activator=$read($root.'/includes/class-sn-activator.php');$status=$read($root.'/SYSTEM-STATUS.txt');$readme=$read($root.'/README.md');
$all=$spaces."\n".$presence."\n".$ops."\n".$contexts."\n".$risk."\n".$conference."\n".$compat;
$check(!str_contains($all,'$_REQUEST'),'Completion services must not consume merged raw request input.');
$check(!preg_match('/\$_(?:GET|POST|COOKIE)\s*\[/',$all),'Completion services must use typed REST parameters rather than raw superglobals.');
$check(!str_contains($all,'REPLACE INTO'),'Governance writes must not use destructive REPLACE semantics.');
$check(str_contains($spaces,'version')&&str_contains($spaces,'sn_space_version_conflict'),'Space settings must use optimistic concurrency.');
$check(str_contains($risk,'sn_high_risk_version_conflict')&&str_contains($risk,'sn_high_risk_claim_conflict'),'High-risk approval and execution must detect concurrent changes.');
$check(str_contains($presence,'sn_presence_conflict')&&str_contains($presence,'version'),'Presence heartbeats must detect device-row races.');
$check(str_contains($contexts,'context_attach_commit_failed')&&str_contains($contexts,'context_detach_commit_failed'),'Context operations must expose rollback failure paths.');
$check(str_contains($conference,'provider_commit_failed')&&str_contains($conference,'ROLLBACK'),'Provider governance must expose rollback paths.');
$check(str_contains($compat,'forward_insert_failed')&&str_contains($compat,'forward_commit_failed'),'Corrective forwarding must fail atomically.');
$check(str_contains($compat,'idempotency_key')&&str_contains($compat,"'duplicate' => true"),'Forward retries must return the authoritative existing record.');
$check(str_contains($risk,'token_hash')&&str_contains($risk,'claim_token_hash'),'One-time and execution tokens must persist only as hashes.');
$check(str_contains($presence,'device_key')&&!str_contains($presence,'raw_device_id'),'Raw device identifiers must not be persisted.');
$check(str_contains($contexts,'provider_object_hash'),'Context events must minimize provider identifiers.');
$check(str_contains($conference,'endpoint_origin_hash'),'Provider events must minimize endpoint details.');
$check(str_contains($spaces,'sn_space_hierarchy_forbidden')&&str_contains($spaces,'sn_space_role_escalation_forbidden'),'Role escalation and hierarchy attacks must be rejected.');
$check(str_contains($spaces,'sn_invite_recipient_required')&&str_contains($spaces,'sn_space_successor_invalid'),'Consent and ownership succession must fail closed.');
$check(str_contains($conference,'sn_conference_credentials_audience_invalid')&&str_contains($conference,'sn_conference_credentials_expiry_invalid'),'Credential replay/scope and lifetime attacks must be rejected.');
$check(str_contains($risk,'sn_high_risk_payload_mismatch')&&str_contains($risk,'hash_equals'),'High-risk payload substitution must be rejected.');
$check(str_contains($main,"'high_risk_contract' => 'step-up + distinct approval + distinct execution'"),'Published integration contract must disclose high-risk governance.');
$check(str_contains($activator,'$message_pages = SN_Messages::ensure_pages();')&&str_contains($activator,"$message_pages['messages'] ?? 0")&&str_contains($activator,"$message_pages['settings'] ?? 0")&&str_contains($activator,'Messages or Communication Settings page could not be created safely.'),'Activation must fail closed when either required Messages surface cannot be created.');
$check(
    stripos($status,'repository')!==false &&
    stripos($status,'not staging-accepted')!==false &&
    stripos($status,'not live-deployed')!==false &&
    str_contains($status,'Staging Accepted: ابھی نہیں') &&
    str_contains($status,'Live Deployed: ابھی نہیں'),
    'System status must semantically distinguish repository coding from staging/live acceptance.'
);
$check(
    stripos($readme,'repository status')!==false &&
    stripos($readme,'coding/review candidate')!==false &&
    str_contains($readme,'**Coded:**') &&
    str_contains($readme,'2.1.0') &&
    stripos($readme,'repository candidate')!==false &&
    str_contains($readme,'**Staging-Accepted:** pending') &&
    str_contains($readme,'**Live-Deployed:** not claimed') &&
    str_contains($readme,'**Operational:** not claimed'),
    'README must semantically state the current coded/repository candidate while preserving staging/live/operational separation.'
);
$check(!preg_match('/(?:100% secure|unhackable|E2EE enabled|production-ready)/i',$status.$readme),'Release documentation must not make unsupported security or production claims.');
if($checks!==23)$failures[]='Review contract count changed: expected 23, got '.$checks;
if($failures){fwrite(STDERR,"Completion review 4 failures (".count($failures)."/$checks):\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "Completion review 4 fresh adversarial/release: PASS ($checks checks)\n";
