<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$runner=(string)file_get_contents($root.'/tools/run-php-test.php');
$quality=(string)file_get_contents($root.'/tools/quality-check.sh');
$relationship=(string)file_get_contents($root.'/tests/relationships-adversarial-contracts.php');
$fails=[];$checks=0;
function r18(bool $ok,string $msg):void{global $fails,$checks;$checks++;if(!$ok)$fails[]=$msg;}
r18(str_contains($runner,'error_reporting(E_ALL)'),'Warning-fatal test runner must enable E_ALL.');
r18(str_contains($runner,'set_error_handler')&&str_contains($runner,'throw new ErrorException'),'Warning-fatal test runner must convert PHP runtime diagnostics into failures.');
r18(str_contains($quality,'php "tools/run-php-test.php" "tests/$test_file"'),'Canonical quality runner must execute every PHP regression through the warning-fatal wrapper.');
r18(!str_contains($quality,'do php "tests/$test_file"; done'),'Canonical quality runner must not retain the warning-tolerant direct test loop.');
r18(str_contains($relationship,"str_contains($rels,'\$wpdb->last_error !== \\\'\\\''"),'Relationship regression must compare a literal $wpdb source needle without PHP interpolation.');
if($fails){fwrite(STDERR,"R18 quality-warning failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "R18 quality-warning contracts: PASS ($checks checks)\n";
