<?php
/** Forty-round audit: final release identity, documentation and package truth. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;$read=static fn(string $p):string=>(string)file_get_contents($p);$check=static function(bool $ok,string $msg)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$msg;};
$main=$read($root.'/sabri-network.php');$wpreadme=$read($root.'/readme.txt');$pluginreadme=$read($root.'/README.md');$rootreadme=$read($repo.'/README.md');$status=$read($repo.'/STATUS.md');$system=$read($root.'/SYSTEM-STATUS.txt');$complete=$read($repo.'/CODING-COMPLETENESS.md');$audit=$read($repo.'/FORTY-ROUND-AUDIT-2026-08-07.md');$quality=$read($root.'/tools/quality-check.sh');$package=$read($root.'/tools/package.sh');$workflow=$read($repo.'/.github/workflows/quality.yml');
$check(str_contains($main,'Version: 2.0.3')&&str_contains($main,"define('SN_VERSION', '2.0.3')"),'Plugin header and runtime must be immutable 2.0.3.');
$check(str_contains($wpreadme,'Stable tag: 2.0.3')&&str_contains($wpreadme,'= 2.0.3 ='),'WordPress readme must be 2.0.3 aligned.');
$check(str_contains($package,'17-sabri-network-and-messages-2.0.3')&&str_contains($quality,"BASE='17-sabri-network-and-messages-2.0.3'"),'Package and quality gate must target 2.0.3.');
$check(str_contains($workflow,'Upload governed 2.0.3 candidate')&&str_contains($workflow,'file-17-complete-2.0.3-'),'Workflow artifact identity must be 2.0.3.');
foreach(['forty-round-review-1-governance-identity-crypto-contracts.php','forty-round-review-2-transfer-smail-privacy-contracts.php','forty-round-review-3-canonical-safety-resilience-contracts.php','forty-round-review-4-release-truth-contracts.php'] as $suite){$check(str_contains($quality,$suite)&&str_contains($workflow,$suite),"Both quality paths must invoke $suite.");}
$check(str_contains($audit,'**Review rounds performed:** **40**')&&str_contains($audit,'**Rounds in which one or more defects were found and corrected:** **18**')&&str_contains($audit,'**Rounds in which no new defect was found:** **22**'),'Audit ledger must record 40 = 18 defect-bearing + 22 clean rounds.');
$check(str_contains($rootreadme,'Review rounds: **40**')&&str_contains($rootreadme,'**18**')&&str_contains($rootreadme,'**22**'),'Root README must publish the founder-requested round count.');
$check(str_contains($status,'Configured review suites:** **45**')&&str_contains($complete,'Explicit PHP review suites in full gate | **45**'),'Repository status and coding assessment must publish 45 suites.');
$check(str_contains($system,'not staging-accepted')&&str_contains($pluginreadme,'**Staging-Accepted:** pending')&&str_contains($rootreadme,'**Live-Deployed:** not claimed'),'Release documentation must preserve staging/live separation.');
$check(str_contains($main,"'global_search_owner' => 'file-26'")&&str_contains($rootreadme,'File 26 global search/ranking'),'Latest File 26 ownership must be reflected in runtime and documentation.');
$all=$rootreadme."\n".$pluginreadme."\n".$status."\n".$system."\n".$complete;
$check(!preg_match('/(?:100% secure|unhackable|E2EE enabled)/i',$all)&&!preg_match('/(?:is|status:)\s+production-ready/i',$all),'Current release documents must not make a positive unsupported security/production claim.');
$check(substr_count($quality,'forty-round-review-')===4&&substr_count($workflow,'forty-round-review-')===4,'Exactly four dedicated forty-round suite invocations must be explicit in each quality path.');
$check(str_contains($audit,'**Known unresolved repository/current-wave coding defects after round 40:** **0**'),'Final audit must publish zero known unresolved repository/current-wave defects.');
$check(str_contains($package,'includes/class-sn-compatibility-hardening.php')&&str_contains($quality,'includes/class-sn-compatibility-hardening.php'),'Corrective compatibility hardening must be required by package and full quality gate.');
if($checks!==17)$fail[]='Expected 17 checks, got '.$checks;if($fail){fwrite(STDERR,"Forty-round suite 4 failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Forty-round review 4 release truth: PASS ($checks checks)\n";
