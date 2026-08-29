<?php
/** Historical fifth-cycle release/package contracts plus current ninth-cycle release-truth closure guards. */
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

// Ninth fresh R20 — current release evidence must not ship stale eighth-cycle truth.
$cycle=trim($read('REVIEW-CYCLE-ID.txt'));
$boundary=$read('CURRENT-CANDIDATE-BOUNDARY.txt');
$qa=$read('QA-INVENTORY.txt');
$status=$read('SYSTEM-STATUS.txt');
$readme=$read('README.md');
$wpreadme=$read('readme.txt');
$install=$read('INSTALLATION-URDU.txt');
$upgrade=$read('UPGRADE-URDU.txt');
$security=$read('SECURITY.md');
$changelog=$read('CHANGELOG.md');
$repoStatus=$read('../STATUS.md');
$repoReadme=$read('../README.md');
$coding=$read('../CODING-COMPLETENESS.md');
$manifest=$read('../MANIFEST.md');
$check($cycle==='FILE17-NINTH-FRESH-20-ROUND-2026-08-29','Ninth R20: packaged review-cycle identifier must be current.');
foreach([$boundary,$status,$readme,$wpreadme,$repoStatus,$repoReadme,$coding] as $doc)$check(str_contains(strtolower($doc),'ninth fresh'),'Ninth R20: every current status/readme surface must identify the eighth fresh cycle.');
$check(!str_contains(strtolower($boundary),'eighth fresh')&&!str_contains(strtolower($boundary),'seventh fresh')&&!str_contains(strtolower($boundary),'sixth fresh'),'Ninth R20: candidate boundary must not identify an older cycle as current.');
$check(str_contains($qa,'55 PHP review suites')&&str_contains($qa,'9 JavaScript'),'Ninth R20: QA inventory must publish exact current counts.');
$check(str_contains($quality,'ninth-fresh-twenty-round-contracts.php'),'Ninth R20: full quality gate must execute the eighth-cycle permanent regression suite.');
$check(str_contains($install,'2.1.0'),'Ninth R20: install guide must identify current 2.1.0 package.');
$check(str_contains($upgrade,'2.1.0')&&str_contains($upgrade,'sn_phone_otps_f17_retired')&&!str_contains($upgrade,'2.0.0 اسے delete کرے گا'),'Ninth R20: upgrade guide must describe current migration and preserved legacy OTP evidence.');
$check(str_contains($security,'2.1.0 API')&&str_contains($security,'dual-control'),'Ninth R20: security notes must describe current API and dual-control safety boundary.');
$check(str_contains($changelog,'Ninth-fresh closure')&&str_contains($changelog,'R20'),'Ninth R20: changelog must record current cycle closure.');
foreach(['CURRENT-CANDIDATE-BOUNDARY.txt','REVIEW-CYCLE-ID.txt','QA-INVENTORY.txt','NO-LIVE-CLAIM.txt','SYSTEM-STATUS.txt','INSTALLATION-URDU.txt','UPGRADE-URDU.txt'] as $surface)$check(str_contains($package,$surface),'Ninth R20: deterministic package must explicitly require '.$surface);
$check(str_contains($manifest,'File 17 v2.1.0')&&str_contains($manifest,'build/17-sabri-network-and-messages-2.1.0.manifest.sha256')&&str_contains(strtolower($manifest),'generated manifest'),'Ninth R20: repository manifest documentation must point to generated 2.1.0 integrity truth.');
$check(!is_file($root.'/../CHECKSUMS.sha256'),'Ninth R20: obsolete static root checksum snapshot must not masquerade as current package truth.');
foreach([$boundary,$status,$readme,$wpreadme,$repoStatus,$repoReadme,$coding] as $doc)$check(str_contains($doc,'Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔'),'Ninth R20: current release surfaces must retain the explicit no-live-parity statement.');

if($fail){fwrite(STDERR,"Release-truth failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Historical + current release-truth contracts: PASS ($checks checks)\n";
