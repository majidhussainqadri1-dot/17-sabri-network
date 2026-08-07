<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$main=file_get_contents($root.'/sabri-network.php');
$top=file_get_contents($root.'/includes/class-sn-top20-communication.php');
$rest=file_get_contents($root.'/includes/class-sn-rest.php');
$fail=[];
foreach([
    "define('SN_VERSION', '2.1.0')",
    "require_once SN_DIR . 'includes/class-sn-top20-communication.php'",
    'SN_Top20_Communication::register()',
    'SN_Top20_Communication::maybe_upgrade()',
    'SN_Top20_Communication::install()',
    "'owner' => 'file-17'",
    "'translation_provider_contract' => 'sn_network_translate_message'",
] as $marker){if(strpos($main,$marker)===false)$fail[]=$marker;}
foreach(['SN_DB::table(\'messages\')','SN_DB::table(\'conversations\')','SN_Policy::can_post_to_conversation','SN_Outbox::enqueue'] as $marker){if(strpos($top,$marker)===false)$fail[]=$marker;}
foreach(['/conversations','/calls','/presence','/updates'] as $marker){if(strpos($rest,$marker)===false)$fail[]='existing canonical route '.$marker;}
foreach(['wp_insert_post(','register_post_type(','CREATE TABLE wp_','wp_insert_attachment'] as $forbidden){if(strpos($top,$forbidden)!==false)$fail[]='parallel/forbidden '.$forbidden;}
if($fail){fwrite(STDERR,"Four-plan review 1 failed:\n - ".implode("\n - ",$fail)."\n");exit(1);} echo "Four-plan review 1 governance: PASS\n";
