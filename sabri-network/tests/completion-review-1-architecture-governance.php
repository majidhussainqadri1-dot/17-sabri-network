<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
function c1(bool $ok,string $m):void{global $fail,$checks;$checks++;if(!$ok)$fail[]=$m;}
function c1src(string $p):string{$v=file_get_contents($p);if($v===false)throw new RuntimeException($p);return $v;}
$main=c1src($root.'/sabri-network.php');$hr=c1src($root.'/includes/class-sn-high-risk.php');$schema=$hr.c1src($root.'/includes/class-sn-spaces.php').c1src($root.'/includes/class-sn-device-presence.php').c1src($root.'/includes/class-sn-message-operations.php').c1src($root.'/includes/class-sn-context-integration.php').c1src($root.'/includes/class-sn-conference-provider.php');
foreach(['SN_High_Risk','SN_Spaces','SN_Device_Presence','SN_Message_Operations','SN_Context_Integration','SN_Conference_Provider'] as $class)c1(str_contains($main,$class.'::register()'),"$class registered");
foreach(['class-sn-high-risk.php','class-sn-spaces.php','class-sn-device-presence.php','class-sn-message-operations.php','class-sn-context-integration.php','class-sn-conference-provider.php'] as $f)c1(str_contains($main,"includes/$f"),"$f loaded");
foreach(['high_risk_actions','high_risk_approvals','spaces','space_members','space_requests','space_controls','presence_devices','message_mentions','message_forwards','conversation_pins','message_stars','message_folders','folder_items','hidden_messages','context_links','conference_providers'] as $t)c1(str_contains($schema,"'$t'"),"$t mapped");
c1(str_contains($hr,"status='executing'")&&str_contains($hr,'execution_by'),'high-risk claim is explicit');
c1(str_contains($hr,'distinct_approver_required'),'dual approval enforces distinct approver');
c1(str_contains($hr,'consume_step_up'),'step-up exists');
c1(str_contains($hr,"['pending', 'approved', 'executing', 'executed', 'rejected', 'expired']"),'executing actions observable');
c1(str_contains($hr,'stale')&&str_contains($hr,"status='approved'"),'stale execution recovery exists');
c1(!preg_match('/(api[_-]?key|secret|password)\s*[=:]\s*[\'\"][A-Za-z0-9+\/=._-]{12,}/i',$main.$hr),'no embedded credential-looking literal');
if($fail){fwrite(STDERR,"Completion review 1 failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Completion review 1 architecture/governance: PASS ($checks checks)\n";
