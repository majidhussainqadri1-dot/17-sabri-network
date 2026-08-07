<?php
declare(strict_types=1);
$root=dirname(__DIR__);$main=file_get_contents($root.'/sabri-network.php');$activator=file_get_contents($root.'/includes/class-sn-activator.php');$quality=file_get_contents($root.'/tools/quality-check.sh');$package=file_get_contents($root.'/tools/package.sh');$fail=[];
foreach(["Version: 2.1.0","define('SN_VERSION', '2.1.0')",'SN_Top20_Communication::install()'] as $m){if(strpos($main,$m)===false)$fail[]='main '.$m;}
if(strpos($activator,'SN_Top20_Communication::install()')===false)$fail[]='activation schema install';
foreach(['17-sabri-network-and-messages-2.1.0','four-plan-review-1-governance-contracts.php','four-plan-review-2-top20-functional-contracts.php','four-plan-review-3-security-adversarial-contracts.php','four-plan-review-4-release-truth-contracts.php','class-sn-top20-communication.php'] as $m){if(strpos($quality,$m)===false)$fail[]='quality '.$m;}
foreach(['17-sabri-network-and-messages-2.1.0','Version: 2.1.0','class-sn-top20-communication.php'] as $m){if(strpos($package,$m)===false)$fail[]='package '.$m;}
if($fail){fwrite(STDERR,"Four-plan review 4 failed:\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Four-plan review 4 release truth: PASS\n";
