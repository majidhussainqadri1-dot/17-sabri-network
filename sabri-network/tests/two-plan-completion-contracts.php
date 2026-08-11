<?php
/** File 17 current governing-plan completion contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($p);
$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$main=$read($root.'/sabri-network.php');
$compat=$read($root.'/includes/class-sn-compatibility-hardening.php');
$completion=$read($root.'/includes/class-sn-two-plan-completion.php');
$quality=$read($root.'/tools/quality-check.sh');

$check(str_contains($main,'Version: 2.1.0')&&str_contains($main,"define('SN_VERSION', '2.1.0')"),'File 17 completion release must have an immutable 2.1.0 runtime identity.');
$check(str_contains($compat,"require_once SN_DIR . 'includes/class-sn-two-plan-completion.php'")&&str_contains($compat,'SN_Two_Plan_Completion::register()'),'Completion layer must be loaded through the existing canonical backend.');
$check(str_contains($completion,'message-requests')&&str_contains($completion,"'accept','decline','report','cancel'"),'Unknown-sender message request quarantine and decisions must exist.');
$check(str_contains($completion,'message_request_recipient')&&str_contains($completion,'message_request_sender'),'Message requests must protect both sender and recipient with rate limits.');
$check(str_contains($completion,"SN_Communication_Crypto::encrypt(\$body,'message-request|")&&str_contains($completion,"SN_Communication_Crypto::decrypt((string)\$locked->body_cipher"),'Message-request content must be authenticated-encrypted at rest.');
$check(str_contains($completion,"SN_Policy::can_contact(\$actor, \$recipient, 'request')")&&str_contains($completion,'SN_DB::is_blocked'),'Fresh policy/block checks must govern message-request actions.');
$check(str_contains($completion,'scheduled-messages')&&str_contains($completion,'dispatch_due_scheduled')&&str_contains($completion,"'scheduled'=>true"),'Scheduled messages must have create/cancel/delivery paths.');
$check(str_contains($completion,"'scheduled-message|'" )&&str_contains($completion,'SN_Message_Body::encrypt'),'Scheduled plaintext must be encrypted before storage and canonical delivery.');
$check(str_contains($completion,'/polls')&&str_contains($completion,'poll-vote')&&str_contains($completion,'/checklists')&&str_contains($completion,'toggle_checklist'),'Poll and collaborative-checklist workflows must exist.');
$check(str_contains($completion,"clinical_decision_substitute'=>false"),'Polls/checklists must not become clinical-decision authority.');
$check(str_contains($completion,'/expiry')&&str_contains($completion,'message_has_legal_hold')&&str_contains($completion,'SN_Message_Search::remove_message'),'Disappearing messages must preserve legal holds and remove search projection.');
$check(str_contains($completion,'/translate')&&str_contains($completion,"apply_filters('sn_network_translate_message',null")&&str_contains($completion,"'source_persisted'=>false"),'Translation must be fail-closed and transient through an approved adapter.');
$check(str_contains($completion,'voice-notes')&&str_contains($completion,'playback_speeds')&&str_contains($completion,'transcript_available'),'Voice-note workflow must expose playback/transcript capability metadata.');
$check(str_contains($completion,"register_rest_route('sabri-network/v2', '/updates'")&&str_contains($completion,"SN_Communication_Crypto::encrypt(\$body,'temporary-update|"),'Temporary-update body must use encrypted-at-rest replacement routes.');
$check(str_contains($completion,"'forum_question','ama_session','wiki_page','event'")&&str_contains($completion,'community_health'),'Forum, AMA, wiki, events/cohorts and aggregate community-health features must exist.');
$check(str_contains($completion,'community-settings')&&str_contains($completion,'join_questions')&&str_contains($completion,"['owner','administrator','moderator']"),'Community rules/onboarding and moderator roles must be enforceable.');
$check(str_contains($completion,"'notification_owner'=>'file-19'")&&str_contains($completion,"'global_search_owner'=>'file-26'")&&str_contains($completion,"'identity_owner'=>'file-00/file-02'"),'Canonical cross-file owners must remain explicit.');
$check(str_contains($main,"'file_transfer_max_bytes' => SN_File_Transfer::MAX_FILE_BYTES")&&str_contains($completion,"'message_requests'=>true"),'The completion layer must extend, not replace, the existing 1 GiB canonical transfer system.');
$check(!str_contains($completion,'wp_insert_attachment')&&!str_contains($completion,'media_handle_upload'),'New private communication must not use the public WordPress media library.');
$check(!preg_match('/(?:E2EE enabled|end-to-end encrypted by default|production-ready)/i',$completion),'The completion source must not make unsupported E2EE/production claims.');
$check(str_contains($completion,'register_exporter')&&str_contains($completion,'register_eraser'),'New personal-data domains must participate in privacy export/erasure.');
$check(str_contains($completion,'admin/two-plan-completion')&&str_contains($completion,"'staging_acceptance'=>false")&&str_contains($completion,"'live_deployment'=>false"),'Runtime health must preserve staging/live separation.');
$check(str_contains($quality,'two-plan-completion-contracts.php'),'The explicit quality gate must execute this suite.');
if($checks!==23)$fail[]='Expected 23 checks, got '.$checks;
if($fail){fwrite(STDERR,"Two-plan completion failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Two-plan completion contracts: PASS ($checks checks)\n";