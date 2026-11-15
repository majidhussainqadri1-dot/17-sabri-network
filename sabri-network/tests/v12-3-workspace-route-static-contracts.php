<?php
/** V12.3 UI-to-effective-route and API-only presentation contract. */
declare(strict_types=1);
$root=dirname(__DIR__);
$core=(string)file_get_contents($root.'/includes/class-sn-future-superset-core.php');
$part=(string)file_get_contents($root.'/includes/class-sn-future-superset-part-1.php');
$js=(string)file_get_contents($root.'/assets/js/future-superset.js');
$workflow=(string)file_get_contents($root.'/../.github/workflows/quality.yml');
$checks=0;$failed=[];
$ok=static function(bool $condition,string $message) use (&$checks,&$failed):void{$checks++;if(!$condition)$failed[]=$message;};
foreach(['snf-lock-form','snf-reminder-form','snf-template-form','snf-team-settings-form','snf-handoff-form','snf-view-form','snf-history-form'] as $id)
    $ok(str_contains($core,$id)&&str_contains($js,$id),"Incomplete form wiring: $id");
$ok(str_contains($part,"'ui_mode'=>in_array"),'Capability response must distinguish workspace from API-only.');
$ok(str_contains($js,'API-only (not a complete interface)'),'API-only capability must not be described as complete UI.');
$ok(str_contains($js,"'future/team-inbox/' + id + '/handoff'"),'Real handoff must use the dedicated reasoned endpoint.');
$ok(str_contains($js,'expected_version: expected'),'Both team settings and handoff must submit current expected_version.');
$ok(str_contains($js,'history-output')&&str_contains($js,'output.textContent'),'Message history must use plain text, never HTML injection.');
$ok(str_contains($js,'future/smart-views'),'Private saved-view UX must invoke the governed API.');
$ok(str_contains($workflow,'v12-3-conversation-lock-provider-runtime-contracts.php'),'The minimal PHP lane must cover lock provider denial.');
if($failed){fwrite(STDERR,"V12.3 workspace failures: ".implode('; ',$failed)."\\n");exit(1);}
echo "V12.3 workspace route and presentation: $checks PASS, 0 FAIL\\n";
