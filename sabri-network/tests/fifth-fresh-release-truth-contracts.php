<?php
/** Fifth fresh Round-20 release/package truth contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$quality=$read('tools/quality-check.sh');$package=$read('tools/package.sh');$green=$read('assets/css/brand-green-overrides.css');$loader=$read('includes/class-sn-future24-review-hardening.php');
foreach(['fifth-fresh-twenty-round-contracts.php','fifth-fresh-migration-contracts.php','fifth-fresh-release-truth-contracts.php'] as $suite)$check(str_contains($quality,$suite),'R20: full quality gate must invoke '.$suite);
foreach(['class-sn-fifth-fresh-privacy-hardening.php','class-sn-fifth-fresh-integration-hardening.php','class-sn-fifth-fresh-feature-hardening.php','class-sn-fifth-fresh-knowledge-hardening.php','class-sn-fifth-fresh-migration-hardening.php','class-sn-fifth-fresh-ui-hardening.php','assets/js/fifth-fresh-ui.js','assets/css/brand-green-overrides.css'] as $surface)$check(str_contains($package,$surface),'R20: package gate must require '.$surface);
$check(str_contains($quality,'assets/js/fifth-fresh-ui.js')&&str_contains($package,'assets/js/fifth-fresh-ui.js'),'R20: fifth fresh UI JavaScript must be syntax/package governed.');
$check(str_contains(strtolower($green),'#087a4e'),'R20: exact Sabri Green primary must be release-gated.');
foreach(['SN_Fifth_Fresh_Privacy_Hardening::register()','SN_Fifth_Fresh_Integration_Hardening::register()','SN_Fifth_Fresh_Feature_Hardening::register()','SN_Fifth_Fresh_Knowledge_Hardening::register()','SN_Fifth_Fresh_Migration_Hardening::register()','SN_Fifth_Fresh_UI_Hardening::register()'] as $marker)$check(str_contains($loader,$marker),'R20: runtime loader missing '.$marker);
if($fail){fwrite(STDERR,"Fifth fresh release-truth failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fifth fresh release-truth contracts: PASS ($checks checks)\n";
