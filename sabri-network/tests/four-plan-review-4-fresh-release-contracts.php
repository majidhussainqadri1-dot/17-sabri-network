<?php
declare(strict_types=1);
$root=dirname(__DIR__);$main=file_get_contents($root.'/sabri-network.php');$readme=file_get_contents($root.'/readme.txt');$arch=file_get_contents($root.'/ARCHITECTURE.md');$quality=file_get_contents($root.'/tools/quality-check.sh');$package=file_get_contents($root.'/tools/package.sh');$repo=file_get_contents(dirname($root).'/README.md');$audit=file_get_contents(dirname($root).'/FOUR-PLAN-FOUR-ROUND-AUDIT-2026-08-07.md');$status=file_get_contents(dirname($root).'/STATUS.md');$fails=[];$checks=0;
function fpr4(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
fpr4(str_contains($main,'Version: 2.0.2')&&str_contains($main,"define('SN_VERSION', '2.0.2')"),'Plugin header/runtime version are both 2.0.2.');
fpr4(str_contains($readme,'Stable tag: 2.0.2')&&str_contains($readme,'= 2.0.2 ='),'Installable readme is version/changelog aligned.');
fpr4(str_contains($package,'17-sabri-network-and-messages-2.0.2')&&str_contains($package,"grep -q 'Version: 2.0.2'"),'Deterministic package contract targets immutable 2.0.2.');
foreach(['four-plan-review-1-governance-contracts.php','four-plan-review-2-transfer-concurrency-contracts.php','four-plan-review-3-message-smail-security-contracts.php','four-plan-review-4-fresh-release-contracts.php'] as $suite){fpr4(str_contains($quality,$suite),"Quality gate invokes $suite.");}
fpr4(str_contains($repo,'Four review rounds')&&str_contains($audit,'Rounds in which defects were found: **4**'),'Root evidence records four independent defect-bearing rounds.');
fpr4(str_contains($status,'Configured review suites:** **41**')||str_contains($status,'Configured review suites:** **41**'),'Repository status records the expanded 41-suite gate.');
fpr4(str_contains($arch,'File 19 is the only notification-center')&&str_contains($arch,'SNE1'),'Architecture reflects latest notification ownership and message confidentiality.');
fpr4(str_contains($repo,'Staging-Accepted:** pending')&&str_contains($repo,'Live-Deployed:** not claimed')&&str_contains($repo,'Operational:** not claimed'),'Repository documentation preserves the seven-status truth boundary.');
fpr4(str_contains($readme,'NEXT/SCALE')&&str_contains($arch,'`NOW`, `NEXT`, and `SCALE`'),'Top-20 roadmap statuses remain explicit rather than falsely promoted to current live scope.');
if($fails){fwrite(STDERR,"Four-plan review 4 failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Four-plan review 4 fresh release: PASS ($checks checks)\n";
