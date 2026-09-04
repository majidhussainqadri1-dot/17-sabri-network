<?php
declare(strict_types=1);
$root=dirname(__DIR__);$main=file_get_contents($root.'/sabri-network.php');$hard=file_get_contents($root.'/includes/class-sn-central-plan-hardening.php');$css=file_get_contents($root.'/assets/css/brand-green-overrides.css');$fails=[];$checks=0;
$spaces='';for($i=1;$i<=6;$i++)$spaces.=(string)file_get_contents($root.'/includes/class-sn-spaces-part-'.$i.'.php');
$firewall=(string)file_get_contents($root.'/includes/class-sn-two-plan-contract-firewall.php');
function fpr1(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
fpr1(str_contains($main,"'notification_owner' => 'file-19'"),'File 19 is declared as the sole notification owner.');
fpr1(str_contains($main,"'legacy_file17_notification_center' => false"),'File 17 declares no active notification center.');
fpr1(str_contains($hard,"add_filter('sn_network_notification_handled'")&&str_contains($hard,'PHP_INT_MAX'),'The File-19 bridge suppresses File-17 fallback after approved adapters run.');
fpr1(str_contains($hard,"do_action('sn_network_notification_requested'")&&!str_contains($hard,"'body' => sanitize_text_field"),'Fallback emits metadata-only notification facts without message bodies.');
fpr1(str_contains($hard,"register_rest_route('sabri-network/v2', '/notifications'")&&str_contains($hard,"'owner' => 'file-19'"),'Historical notification routes are compatibility-only File-19 projections.');
fpr1(str_contains($css,'#sn-notifications-button')&&str_contains($css,'display: none !important'),'File-17 local notification bell is not rendered as a second global bell.');
fpr1(str_contains($css,'--sn-brand-green')&&str_contains(strtolower($css),'#087a4e'),'Current primary brand is exact Sabri Green #087A4E.');
fpr1(str_contains($css,'--sn-secondary-orange'),'Orange remains only as an explicit secondary accent.');
fpr1(str_contains($main,"'owner' => 'file-17'")&&str_contains($main,"'messages_url' => SN_Messages::messages_url()"),'File 17 remains the canonical communication owner while exposing distinct Network/Messages surfaces.');
fpr1(str_contains($main,'SN_Central_Plan_Hardening::register()')&&str_contains($main,'SN_Central_Plan_Hardening::maybe_upgrade()'),'Central-plan hardening is active for runtime and migration.');
$starts=substr_count($spaces,"\$wpdb->query('START TRANSACTION')");$checked=substr_count($spaces,"\$wpdb->query('START TRANSACTION') === false");
fpr1($starts===10&&$checked===10,'Next R2: all ten active space-governance transaction starts must be explicitly fail-closed.');
fpr1(substr_count($spaces,"sn_space_transaction_failed")===10,'Next R2: each active space mutation must return the stable fail-closed transaction error before writes.');
fpr1(str_contains($firewall,"'state' => 'unreplayable'")&&substr_count($firewall,'self::mark_unreplayable(')>=3,'Next R3: successful mutations with uncachable responses must publish a durable unreplayable terminal state rather than release retry protection.');
fpr1(str_contains($firewall,"if (\$finalized !== 1) self::mark_unreplayable")&&str_contains($firewall,"['scope_key' => \$scope_key, 'state' => 'processing']"),'Next R3: idempotency completion persistence must be checked and transition only from the owned processing state.');
fpr1(str_contains($firewall,"\$existing->state === 'unreplayable'")&&str_contains($firewall,"sn_idempotency_replay_unavailable"),'Next R3: unreplayable completed mutations must fail closed on replay instead of executing again.');
fpr1(str_contains($firewall,"state IN ('complete','unreplayable')")&&!str_contains($firewall,"actor_id=%d AND state='complete'")&&str_contains($firewall,'SELECT COUNT(*) FROM '),'Next R3: cleanup and privacy erasure must cover terminal/relevant actor-owned idempotency rows and publish remaining-row truth.');
if($fails){fwrite(STDERR,"Four-plan review 1 failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Four-plan review 1 governance: PASS ($checks checks)\n";
