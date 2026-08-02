<?php
/** Independent fourth-pass adversarial review of negative age paths and completion truth. */
declare(strict_types=1);
$root = dirname(__DIR__); $repo = dirname($root); $failures=[]; $checks=0;
function fr4a_check(bool $condition,string $message):void{global $failures,$checks;$checks++;if(!$condition)$failures[]=$message;}
function fr4a_content(string $path):string{$v=file_get_contents($path);if($v===false)throw new RuntimeException('Missing '.$path);return $v;}
$policy=fr4a_content($root.'/includes/class-sn-policy.php');
$db=fr4a_content($root.'/includes/class-sn-db.php');
$rest=fr4a_content($root.'/includes/class-sn-rest.php');
$completeness=fr4a_content($repo.'/CODING-COMPLETENESS.md');
$publicAttachment = substr($db, strpos($db, "if ((string) \$update->privacy === 'public'"), 220);
$publicUpdate = substr($rest, strpos($rest, "if ((string) \$row->privacy === 'public')"), 180);
fr4a_check(!str_contains($publicAttachment, '!SN_Policy::is_minor'), 'Unknown age must not pass public attachment access through a not-minor shortcut.');
fr4a_check(!str_contains($publicUpdate, '!SN_Policy::is_minor'), 'Unknown age must not pass public update visibility through a not-minor shortcut.');
fr4a_check(str_contains($policy, "return self::age_state(\$user_id) === 'adult';"), 'Verified-adult helper must require the explicit adult state.');
fr4a_check(str_contains($policy, 'return !self::has_verified_adult_age($user_id);'), 'Protective defaults must include both minor and unknown states.');
fr4a_check(str_contains($rest, "if (\$age_state === 'unknown')") && strpos($rest, "if (\$age_state === 'unknown')") < strpos($rest, "if (\$age_state === 'minor'"), 'Directory must reject unknown age before optional minor discovery.');
fr4a_check(str_contains($rest, "return new WP_Error('owner_ineligible'") && str_contains($rest, '!SN_Policy::has_verified_adult_age($target_id)'), 'Unknown-age members must not receive conversation ownership.');
fr4a_check(str_contains($rest, 'return self::database_error();') && str_contains($rest, 'message_read_pointer_update_failed'), 'A failed read-pointer write must not return a false success response.');
fr4a_check(str_contains($completeness, 'native recipient/device message-receipt persistence'), 'Completeness evidence must record recipient receipts as implemented.');
fr4a_check(str_contains($completeness, 'indexed server-side message search'), 'Completeness evidence must record indexed message search as implemented.');
fr4a_check(str_contains($completeness, 'transactional outbox/inbox'), 'Completeness evidence must record reliable event delivery as implemented.');
foreach (['Spaces governance','General multi-device presence','Advanced message operations','Operational completion'] as $requiredGap) {
    fr4a_check(str_contains($completeness, $requiredGap), "Completeness evidence must disclose missing scope: $requiredGap");
}
fr4a_check(!str_contains($completeness, 'Coding completion: 100%'), 'Completeness evidence must not claim 100% coding.');
if($failures){fwrite(STDERR,"Fourth-review adversarial failures (".count($failures)."/$checks):\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "Fourth-review adversarial contracts: PASS ($checks checks)\n";
