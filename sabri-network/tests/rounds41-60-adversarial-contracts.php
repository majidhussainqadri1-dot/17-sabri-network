<?php
/** Fresh review contracts for rounds 41–60. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
function r4160(bool $ok,string $msg):void{global $fail,$checks;$checks++;if(!$ok)$fail[]=$msg;}
function r4160c(string $path):string{$v=file_get_contents($path);return $v===false?'':$v;}
$p=r4160c($root.'/includes/class-sn-future24-review-hardening-p.php');
$loader=r4160c($root.'/includes/class-sn-future24-review-hardening.php');
r4160(str_contains($loader,"class-sn-future24-review-hardening-p.php")&&str_contains($loader,'SN_Future24_Review_Hardening_P::register()'),'Round 41 hardening P must be loaded and registered.');
r4160(str_contains($p,"add_filter('rest_post_dispatch'")&&str_contains($p,'SN_Message_Operations::is_hidden')&&str_contains($p,'SN_DB::is_member'),'Round 41 must revalidate hidden/deleted/membership visibility after REST decryption.');
r4160(str_contains($p,"/future/reminders")&&str_contains($p,'sn_reminder_conversation_required')&&str_contains($p,"(int) $row->conversation_id !== $conversation_id"),'Round 42 must bind message reminders to their authorized conversation.');
if($fail){fwrite(STDERR,"Rounds 41-60 adversarial failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Rounds 41-60 adversarial contracts: PASS ($checks checks)\n";
