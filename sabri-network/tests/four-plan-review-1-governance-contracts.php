<?php
declare(strict_types=1);
$root=dirname(__DIR__);$main=file_get_contents($root.'/sabri-network.php');$hard=file_get_contents($root.'/includes/class-sn-central-plan-hardening.php');$css=file_get_contents($root.'/assets/css/brand-green-overrides.css');$fails=[];$checks=0;
function fpr1(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
fpr1(str_contains($main,"'notification_owner' => 'file-19'"),'File 19 is declared as the sole notification owner.');
fpr1(str_contains($main,"'legacy_file17_notification_center' => false"),'File 17 declares no active notification center.');
fpr1(str_contains($hard,"add_filter('sn_network_notification_handled'")&&str_contains($hard,'PHP_INT_MAX'),'The File-19 bridge suppresses File-17 fallback after approved adapters run.');
fpr1(str_contains($hard,"do_action('sn_network_notification_requested'")&&!str_contains($hard,"'body' => sanitize_text_field"),'Fallback emits metadata-only notification facts without message bodies.');
fpr1(str_contains($hard,"register_rest_route('sabri-network/v2', '/notifications'")&&str_contains($hard,"'owner' => 'file-19'"),'Historical notification routes are compatibility-only File-19 projections.');
fpr1(str_contains($css,'#sn-notifications-button')&&str_contains($css,'display: none !important'),'File-17 local notification bell is not rendered as a second global bell.');
fpr1(str_contains($css,'--sn-brand-green')&&str_contains($css,'#137a46'),'Current primary brand is green.');
fpr1(str_contains($css,'--sn-secondary-orange'),'Orange remains only as an explicit secondary accent.');
fpr1(str_contains($main,"'owner' => 'file-17'")&&str_contains($main,"'messages_url' => SN_Messages::messages_url()"),'File 17 remains the canonical communication owner while exposing distinct Network/Messages surfaces.');
fpr1(str_contains($main,'SN_Central_Plan_Hardening::register()')&&str_contains($main,'SN_Central_Plan_Hardening::maybe_upgrade()'),'Central-plan hardening is active for runtime and migration.');
if($fails){fwrite(STDERR,"Four-plan review 1 failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Four-plan review 1 governance: PASS ($checks checks)\n";
