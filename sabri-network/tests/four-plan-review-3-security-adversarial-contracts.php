<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$top=(string)file_get_contents($root.'/includes/class-sn-top20-communication.php');
$fail=[];
$markers=[
    'SN_DB::is_member($conversation_id, $actor)',
    'SN_Policy::can_post_to_conversation($conversation, $actor)',
    "SN_Policy::consume_rate_limit('scheduled_message'",
    'idempotency_key CHAR(64) NOT NULL',
    "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
    "SET status=\\'processing\\'",
    'SN_Policy::can_post_to_conversation($conversation,(int)$row->sender_id)',
    'if((int)$message->sender_id!==$actor)',
    'sn_translation_provider_unavailable',
    "apply_filters('sn_network_translate_message',null",
    'SN_DB::is_member((int)$message->conversation_id,$actor)',
    'wp_strip_all_tags((string)$message->body)',
    "'attachment_id'=>0,'attachment_source'=>'none'",
    "SN_DB::audit('message_expired'",
];
foreach($markers as $marker){if(strpos($top,$marker)===false)$fail[]=$marker;}
foreach(['eval(','base64_decode(','shell_exec(','exec(','system(','passthru(','unserialize('] as $forbidden){if(strpos($top,$forbidden)!==false)$fail[]='unsafe primitive '.$forbidden;}
if($fail){fwrite(STDERR,"Four-plan review 3 failed:\n - ".implode("\n - ",$fail)."\n");exit(1);}
echo "Four-plan review 3 security/adversarial: PASS\n";
