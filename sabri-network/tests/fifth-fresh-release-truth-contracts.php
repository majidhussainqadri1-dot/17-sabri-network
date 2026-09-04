<?php
/** Fifth fresh Round-20 release/package truth contracts plus current final-cycle UI/release guards. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$readRepo=static fn(string $p):string=>(string)file_get_contents($repo.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$quality=$read('tools/quality-check.sh');$package=$read('tools/package.sh');$green=$read('assets/css/brand-green-overrides.css');$loader=$read('includes/class-sn-future24-review-hardening.php');
foreach(['fifth-fresh-twenty-round-contracts.php','fifth-fresh-migration-contracts.php','fifth-fresh-release-truth-contracts.php'] as $suite)$check(str_contains($quality,$suite),'R20: full quality gate must invoke '.$suite);
foreach(['class-sn-fifth-fresh-privacy-hardening.php','class-sn-fifth-fresh-integration-hardening.php','class-sn-fifth-fresh-feature-hardening.php','class-sn-fifth-fresh-knowledge-hardening.php','class-sn-fifth-fresh-migration-hardening.php','class-sn-fifth-fresh-ui-hardening.php','assets/js/fifth-fresh-ui.js','assets/css/brand-green-overrides.css'] as $surface)$check(str_contains($package,$surface),'R20: package gate must require '.$surface);
$check(str_contains($quality,'assets/js/fifth-fresh-ui.js')&&str_contains($package,'assets/js/fifth-fresh-ui.js'),'R20: fifth fresh UI JavaScript must be syntax/package governed.');
$check(str_contains(strtolower($green),'#087a4e'),'R20: exact Sabri Green primary must be release-gated.');
foreach(['SN_Fifth_Fresh_Privacy_Hardening::register()','SN_Fifth_Fresh_Integration_Hardening::register()','SN_Fifth_Fresh_Feature_Hardening::register()','SN_Fifth_Fresh_Knowledge_Hardening::register()','SN_Fifth_Fresh_Migration_Hardening::register()','SN_Fifth_Fresh_UI_Hardening::register()'] as $marker)$check(str_contains($loader,$marker),'R20: runtime loader missing '.$marker);

$uiOwner=$read('includes/class-sn-fifth-fresh-ui-hardening.php');
$uiJs=$read('assets/js/fifth-fresh-ui.js');
$pluginReadme=$read('readme.txt');
$pluginChangelog=$read('CHANGELOG.md');
$repoReadme=$readRepo('README.md');
$status=$readRepo('STATUS.md');
$coding=$readRepo('CODING-COMPLETENESS.md');
$boundary=$read('CURRENT-CANDIDATE-BOUNDARY.txt');
$currentBranch='review/file17-fresh-10-round-2026-09-04';
$allDefects='R1, R2, R3, R4, R5, R6, R7, R8, R9, R10';

$check(!str_contains($uiOwner,"\$_GET['sn-network-safe']"),'Fresh R10: final UI asset ownership must not trust a raw query-string sentinel.');
$check(str_contains($uiOwner,"get_query_var('sn_network_app')")&&str_contains($uiOwner,"get_query_var('sn_messages_app')")&&str_contains($uiOwner,"get_query_var('sn_meet_app')"),'Fresh R10: final UI assets must use canonical registered File-17 query truth.');
$check(str_contains($uiJs,"getElementById('sntp-modal')")&&str_contains($uiJs,'[data-sntp-close]'),'Fresh R10: accessibility hardening must bind to the active two-plan modal selectors.');
$check(!str_contains($uiJs,'sn-two-plan-modal')&&!str_contains($uiJs,'data-sn-close-modal'),'Fresh R10: obsolete modal selectors must not remain as the focus-restoration owner.');
$check(str_contains($uiJs,'restoreModalFocus')&&str_contains($uiJs,'removedNodes')&&str_contains($uiJs,"node.id === 'sntp-modal'"),'Fresh R10: every active two-plan modal close path must restore focus to the invoking control.');

foreach(['root README'=>$repoReadme,'STATUS'=>$status,'CODING-COMPLETENESS'=>$coding,'plugin readme'=>$pluginReadme,'plugin changelog'=>$pluginChangelog,'candidate boundary'=>$boundary] as $name=>$text){
    $check(str_contains($text,$currentBranch),"Fresh R10: $name must identify the current 4-September review branch.");
    $check(str_contains($text,'54')&&str_contains($text,'10'),"Fresh R10: $name must retain current 54-suite/10-JS release truth.");
    $check(!str_contains($text,'review/file17-next-fresh-10-round-2026-09-03'),"Fresh R10: $name must not present the obsolete next-fresh branch as current truth.");
}
$check(str_contains($repoReadme,$allDefects)&&str_contains($status,$allDefects)&&str_contains($pluginReadme,$allDefects),'Fresh R10: current release/status surfaces must record all ten defect-bearing rounds.');
$check(str_contains($pluginReadme,'f832f7b2d4bb4cf67fc9749e1eb9d3219f5fc0a2')&&str_contains($pluginReadme,'historical evidence only'),'Fresh R10: the prior f832 candidate may remain only as explicitly historical evidence, never current-head proof.');

if($fail){fwrite(STDERR,"Fifth/current release-truth failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fifth/current release-truth contracts: PASS ($checks checks)\n";
