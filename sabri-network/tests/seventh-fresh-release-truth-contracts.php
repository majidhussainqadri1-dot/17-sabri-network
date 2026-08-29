<?php
/** Seventh fresh Round-20 current release/package truth contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};

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
$package=$read('tools/package.sh');
$repoStatus=$read('../STATUS.md');
$repoReadme=$read('../README.md');
$coding=$read('../CODING-COMPLETENESS.md');
$manifest=$read('../MANIFEST.md');

$check($cycle==='FILE17-SEVENTH-FRESH-20-ROUND-2026-08-18','R20: packaged review-cycle identifier must be the seventh fresh cycle.');
foreach([$boundary,$status,$readme,$wpreadme,$repoStatus,$repoReadme,$coding] as $doc)$check(str_contains(strtolower($doc),'seventh fresh'),'R20: every current status/readme surface must identify the seventh fresh cycle.');
$check(!str_contains(strtolower($boundary),'fifth fresh')&&!str_contains(strtolower($boundary),'sixth fresh'),'R20: current candidate boundary must not identify an older cycle as current.');
$check(str_contains($qa,'54 PHP review suites')&&str_contains($qa,'9 JavaScript')&&str_contains($qa,'seventh-fresh-release-truth-contracts.php'),'R20: QA inventory must publish the exact current suite and JavaScript counts.');
$check(str_contains($install,'2.1.0'),'R20: install guide must identify the current 2.1.0 package.');
$check(str_contains($upgrade,'2.1.0')&&str_contains($upgrade,'sn_phone_otps_f17_retired')&&!str_contains($upgrade,'2.0.0 اسے delete کرے گا'),'R20: upgrade guide must describe current 2.1.0 migration and preserved legacy OTP rollback evidence.');
$check(str_contains($security,'2.1.0 API')&&str_contains($security,'dual-control'),'R20: security notes must describe the current API/release and high-risk dual-control safety boundary.');
$check(str_contains($changelog,'Seventh-fresh closure')&&str_contains($changelog,'R20'),'R20: changelog must record the current seventh-cycle closure.');
foreach(['CURRENT-CANDIDATE-BOUNDARY.txt','REVIEW-CYCLE-ID.txt','QA-INVENTORY.txt','NO-LIVE-CLAIM.txt','SYSTEM-STATUS.txt','INSTALLATION-URDU.txt','UPGRADE-URDU.txt'] as $surface)$check(str_contains($package,$surface),'R20: deterministic package must explicitly require current release-truth surface '.$surface);
$check(str_contains($manifest,'File 17 v2.1.0')&&str_contains($manifest,'build/17-sabri-network-and-messages-2.1.0.manifest.sha256')&&str_contains($manifest,'generated manifest'),'R20: repository manifest documentation must point to generated 2.1.0 package integrity truth.');
$check(!is_file($root.'/../CHECKSUMS.sha256'),'R20: obsolete static root checksum snapshot must not masquerade as current package integrity truth.');
foreach([$boundary,$status,$readme,$wpreadme,$repoStatus,$repoReadme,$coding] as $doc)$check(str_contains($doc,'Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔'),'R20: current release truth must retain the explicit no-live-parity statement.');

if($fail){fwrite(STDERR,"Seventh fresh release-truth failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Seventh fresh release-truth contracts: PASS ($checks checks)\n";
