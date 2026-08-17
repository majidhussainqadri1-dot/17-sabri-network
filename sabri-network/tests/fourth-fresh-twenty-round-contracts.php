<?php
/** File 17 fourth fresh 20-round permanent repository regression contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$loader=$read('includes/class-sn-future24-review-hardening.php');
$review=$read('includes/class-sn-fourth-fresh-review-hardening.php');
$search=$read('includes/class-sn-fourth-fresh-search-hardening.php');
$media=$read('includes/class-sn-fourth-fresh-media-hardening.php');
$lifecycle=$read('includes/class-sn-fourth-fresh-lifecycle-hardening.php');
$space=$read('includes/class-sn-fourth-fresh-space-hardening.php');
$realtime=$read('includes/class-sn-fourth-fresh-realtime-hardening.php');
$call=$read('includes/class-sn-fourth-fresh-call-hardening.php');
$smail=$read('includes/class-sn-fourth-fresh-smail-hardening.php');
$transfer=$read('includes/class-sn-fourth-fresh-transfer-hardening.php');
$privacy=$read('includes/class-sn-fourth-fresh-privacy-hardening.php');
$safety=$read('includes/class-sn-fourth-fresh-safety-hardening.php');
$crypto=$read('includes/class-sn-fourth-fresh-crypto-hardening.php');
$knowledge=$read('includes/class-sn-fourth-fresh-knowledge-hardening.php');
$interop=$read('includes/class-sn-fourth-fresh-interop-hardening.php');
$high=$read('includes/class-sn-high-risk.php');

foreach([
 'SN_Fourth_Fresh_Review_Hardening','SN_Fourth_Fresh_Search_Hardening','SN_Fourth_Fresh_Media_Hardening',
 'SN_Fourth_Fresh_Lifecycle_Hardening','SN_Fourth_Fresh_Space_Hardening','SN_Fourth_Fresh_Realtime_Hardening',
 'SN_Fourth_Fresh_Call_Hardening','SN_Fourth_Fresh_Smail_Hardening','SN_Fourth_Fresh_Transfer_Hardening',
 'SN_Fourth_Fresh_Privacy_Hardening','SN_Fourth_Fresh_Safety_Hardening','SN_Fourth_Fresh_Crypto_Hardening',
 'SN_Fourth_Fresh_Knowledge_Hardening','SN_Fourth_Fresh_Interop_Hardening'
] as $class){$check(str_contains($loader,$class.'::register()')&&str_contains($loader,'class-'.strtolower(str_replace('_','-',$class)).'.php'),'Fourth-cycle layer must be required and registered: '.$class);}

$check(str_contains($review,"add_filter('rest_pre_dispatch'")&&str_contains($review,'-29999'),'Round 2 must preserve the early pre-side-effect authorization gate.');
$check(str_contains($high,"'conversation_ownership_transfer'")&&str_contains($review,"SN_High_Risk::claim($action_id, $actor, 'conversation_ownership_transfer'")&&str_contains($review,'SN_High_Risk::complete'),'Round 4 ownership transfer must remain under distinct high-risk claim/completion.');
$check(str_contains($review,'caller-supplied message idempotency key')&&str_contains($review,'expected_version')&&str_contains($review,"'_mutation_version'")&&str_contains($review,'SN_Compatibility_Hardening::secure_forward_message'),'Round 5 caller idempotency, message CAS and transactional forwarding must remain active.');
$check(str_contains($review,"SN_Message_Integrity::record_receipt")&&str_contains($review,'conversation_lock'),'Round 5 receipt writes must remain serialized to conversation membership.');
$check(str_contains($search,'Do not advance past the failed message')&&str_contains($search,"update_option('sn_message_search_backfill_after', $after")&&!str_contains($search,"update_option('sn_message_search_backfill_after', 0, false);\n            return;"),'Round 6 search reconstruction must remain lossless and monotonic.');
$check(str_contains($media,'sn_network_private_media_validation')&&str_contains($media,'sn_network_image_max_pixels')&&str_contains($media,'transcript_cipher'),'Round 7 private media validation and encrypted voice transcript must remain fail-closed.');
$check(str_contains($lifecycle,"/messages/(\\d+)/translate")&&str_contains($lifecycle,'message_version_required')&&str_contains($lifecycle,'SN_Outbox::enqueue')&&str_contains($lifecycle,'message_id'),'Round 8 translation/object, expiry CAS and reminder linkage must remain governed.');
$check(str_contains($space,"sn:f17:space:")&&str_contains($space,'SN_Relationships::pair_lock_name')&&str_contains($space,'SN_Spaces::create_invite'),'Round 9 space governance must remain serialized with relationship-sensitive mutations.');
$check(str_contains($realtime,"/typing")&&str_contains($realtime,'SN_Relationships::pair_lock_name')&&str_contains($realtime,"sn:f17:presence:"),'Round 10 typing and presence-device lifecycle must remain serialized.');
$check(str_contains($call,'-29997')&&str_contains($call,'MAX_MEDIA_TOKEN_TTL')&&str_contains($call,"$features['recording'] = false")&&str_contains($call,'revalidate_media_delivery'),'Round 11 meeting authorization, bounded media credentials and post-provider eligibility must remain active.');
$check(str_contains($smail,'caller-supplied Smail idempotency key')&&substr_count($smail,'draft_version_required')>=2&&str_contains($smail,'version=version+1'),'Round 12 Smail send retry safety and exact draft CAS must remain active.');
$check(str_contains($transfer,'sn_network_transfer_storage_root')&&str_contains($transfer,'get_home_path()')&&str_contains($transfer,"$_SERVER['DOCUMENT_ROOT']")&&str_contains($transfer,'transfer-init'),'Round 13 transfer storage and sender-quota initiation must remain serialized and public-root safe.');
$check(str_contains($privacy,'sn_network_retention_prevents_erasure')&&str_contains($privacy,'r.legal_hold=1'),'Round 14 native legal holds must remain authoritative for File-17 erasure.');
$check(str_contains($safety,"'mass_moderation'")&&str_contains($safety,"'legal_hold_release'")&&str_contains($safety,'high_risk_actions_must_be_separate')&&str_contains($safety,'SN_High_Risk::claim'),'Round 15 report closure and legal-hold release must remain separately dual-controlled.');
$check(str_contains($crypto,'INVALID_SENTINEL')&&str_contains($crypto,'is_link($path)')&&str_contains($crypto,'($perms & 0077)')&&str_contains($crypto,'ctype_xdigit'),'Round 16 communication master-key strength and filesystem hygiene must remain fail-closed.');
$check(str_contains($knowledge,'SN_Message_Operations::is_hidden')&&str_contains($knowledge,'sn_network_citation_source_resolve')&&str_contains($knowledge,'sn_network_case_discussion_deidentified'),'Round 17 AI visibility, canonical citations and case de-identification must remain enforced.');
$check(str_contains($interop,'sn_interop_idempotency_required')&&str_contains($interop,'sn_interop_event_conflict')&&str_contains($interop,'sn_interop_reconciliation_required')&&str_contains($interop,'shutdown_idempotency_hash'),'Round 18 interoperability must bind keys/payloads and preserve uncertain outcomes fail-closed.');
$check(str_contains($interop,'sn_network_interop_outbound_reconcile_result')&&str_contains($interop,'sn_network_interop_kill_switch_reconcile_result')&&str_contains($interop,"'confirmed'")&&str_contains($interop,"'sanitized_payload'"),'Round 18 provider reconciliation and encrypted inbound replay state must remain explicit.');

if($checks!==32)$fail[]='Expected 32 checks, got '.$checks;
if($fail){fwrite(STDERR,"Fourth fresh 20-round contract failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fourth fresh 20-round contracts: PASS ($checks checks)\n";
