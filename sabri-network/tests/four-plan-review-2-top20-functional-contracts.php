<?php
declare(strict_types=1);
$root=dirname(__DIR__);$top=file_get_contents($root.'/includes/class-sn-top20-communication.php');$main=file_get_contents($root.'/sabri-network.php');$fail=[];
$markers=[
 '/scheduled-messages','schedule_message','dispatch_due','MAX_SCHEDULE_DAYS = 90',
 '/polls','poll-vote','create_poll','vote_poll','MAX_POLL_OPTIONS = 12',
 '/checklists','checklist-items','create_checklist','toggle_checklist','MAX_CHECKLIST_ITEMS = 50',
 '/expiry','set_expiry','expire_messages','3600,86400,604800,2592000',
 '/translate','translate_message','sn_network_translate_message','sn_translation_provider_unavailable',
 'scheduled_messages','poll_votes','wp_privacy_personal_data_exporters','wp_privacy_personal_data_erasers'
];
foreach($markers as $marker){if(strpos($top,$marker)===false)$fail[]=$marker;}
foreach(['scheduled_messages_route','polls_route','checklists_route','message_expiry_route','message_translation_route'] as $marker){if(strpos($main,$marker)===false)$fail[]='contract '.$marker;}
if($fail){fwrite(STDERR,"Four-plan review 2 failed:\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Four-plan review 2 Top-20 functions: PASS\n";
