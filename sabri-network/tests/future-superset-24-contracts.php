<?php
/** File 17 — Founder-approved Future Communication Superset 24 contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
function f24(bool $ok,string $msg):void{global $fail,$checks;$checks++;if(!$ok)$fail[]=$msg;}
function txt(string $p):string{$x=file_get_contents($p);if($x===false)throw new RuntimeException('Missing '.$p);return $x;}
$main=txt($root.'/includes/class-sn-future-superset.php');
$p1=txt($root.'/includes/class-sn-future-superset-part-1.php');
$p2=txt($root.'/includes/class-sn-future-superset-part-2.php');
$p3=txt($root.'/includes/class-sn-future-superset-part-3.php');
$core=txt($root.'/includes/class-sn-future-superset-core.php');
$all=$main.$p1.$p2.$p3.$core;
$runtime=txt($root.'/includes/class-sn-two-plan-runtime-hardening.php');
$quality=txt($root.'/tools/quality-check.sh');
$js=txt($root.'/assets/js/future-superset.js');$css=txt($root.'/assets/css/future-superset.css');
f24(str_contains($main,"FEATURE_COUNT=24"),'Future registry must declare 24 features.');
for($i=1;$i<=24;$i++){ $id=sprintf('F17-FUT-%02d',$i); f24(str_contains($all,$id),$id.' must exist.'); }
foreach(['Audited E2EE Mode','Device Key Verification / Safety Numbers','Key Transparency Log','Sensitive Conversation Lock','Delegated / Shared Team Inbox','Conversation Assignment & Handoff','Snooze / Remind Me Later','Saved Replies & Professional Templates','Advanced Message Version History','Bulk Conversation Operations','Saved Searches / Smart Private Views','Expiring QR Community Invitations','Temporary Scoped Membership','Mentor–Student Communication Mode','Scholarly Citation Cards','De-identified Case Discussion Template','Call Waiting Room / Lobby','Hand Raise & Speaker Queue','Breakout Rooms','Co-host / Host Transfer','Call Network Quality Assistant','Opt-in AI Conversation Assistant','Private Semantic Search','Standards-Based Interoperability Gateway'] as $label)f24(str_contains($main,$label),'Missing '.$label);
foreach(['/future/capabilities','/future/e2ee-policy','/future/device-keys','/future/key-transparency','/future/conversation-locks','/future/team-inbox','/future/reminders','/future/templates','/versions','/future/conversations/bulk','/future/smart-views','/future/community-invites','/future/temporary-memberships','/future/mentorships','/future/citations','/future/case-discussions','/lobby','/hand-raise','/breakouts','/host-transfer','/network-quality','/future/ai-assistant','/future/semantic-search','/future/interop'] as $route)f24(str_contains($main,$route),'Missing route '.$route);
f24(str_contains($p1,'sn_network_e2ee_provider_status')&&str_contains($p1,'audited'),'E2EE must fail closed until audited provider evidence.');
f24(str_contains($p1,'sn_network_step_up_verified')&&str_contains($p1,'capture_message_version')&&str_contains($p1,'_sn_future_snapshot_id'),'Conversation lock and edit-version snapshot must be server-side.');
f24(str_contains($core,'sn_network_notification_requested'),'Reminders must delegate delivery to File 19 event transport.');
f24(str_contains($p2,"['file-06','file-12']"),'Citation cards must preserve File 06/File 12 truth ownership.');
f24(str_contains($p2,'sn_network_case_discussion_professional_allowed')&&str_contains($p2,'looks_like_pii'),'Case discussion requires professional gate and de-identification.');
f24(str_contains($p3,'sn_network_breakout_create_result')&&str_contains($p3,'sn_network_call_host_transfer_result'),'Conference-scale features must remain provider gated.');
f24(str_contains($p3,'sn_network_ai_assistant_result')&&str_contains($core,"'ai_owner'=>'file-16'"),'AI authority must remain File 16.');
f24(str_contains($p3,'sn_network_private_semantic_search_result')&&str_contains($p3,"'exported_to_file26'=>false"),'Private semantic search must remain File 17 and not export to File 26.');
f24(str_contains($p3,'sn_network_interop_provider_ready')&&str_contains($p3,'sn_network_interop_remote_allowed'),'Interop must require approved provider and remote destination.');
f24(!str_contains($all,'wp_insert_attachment')&&!str_contains($all,'media_handle_upload'),'Future layer must not create a second/public media backend.');
f24(str_contains($runtime,"require_once SN_DIR . 'includes/class-sn-future-superset.php'")&&str_contains($runtime,'SN_Future_Superset::register()'),'Current runtime hardening must load/register Future-24.');
f24(str_contains($quality,'future-superset-24-contracts.php')&&str_contains($quality,'class-sn-future-superset.php')&&str_contains($quality,'assets/js/future-superset.js')&&str_contains($quality,'assets/css/future-superset.css'),'Quality gate must include Future-24 sources/assets/contracts.');
f24(str_contains($js,'future/capabilities')&&str_contains($js,'future/reminders')&&str_contains($js,'future/templates'),'Future workspace JS must expose usable paths.');
f24(str_contains($css,'prefers-reduced-motion')&&str_contains($css,'44px')&&str_contains($css,'#087a4e'),'Future CSS must preserve accessibility/Sabri Green baselines.');
if($fail){fwrite(STDERR,'Future-24 failures ('.count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Future-24 contracts: PASS ($checks checks)\n";
