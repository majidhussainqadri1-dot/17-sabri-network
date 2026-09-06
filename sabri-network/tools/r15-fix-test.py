from pathlib import Path
p=Path('sabri-network/tests/r15-event-delivery-schema-contracts.php')
p.write_text(r'''<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$out=file_get_contents($root.'/includes/class-sn-outbox.php');
$reg=file_get_contents($root.'/includes/class-sn-event-schema-registry.php');
$loader=file_get_contents($root.'/sabri-network.php');
$fails=[];$checks=0;
function r15(bool $ok,string $msg):void{global $fails,$checks;$checks++;if(!$ok)$fails[]=$msg;}
r15(str_contains($loader,'class-sn-event-schema-registry.php')&&strpos($loader,'class-sn-event-schema-registry.php')<strpos($loader,'class-sn-outbox.php'),'Event schema registry must load before outbox.');
r15(str_contains($out,"SCHEMA_VERSION = '1.1.0'")&&str_contains($out,'event_contract VARCHAR(160)')&&str_contains($out,'event_schema_version VARCHAR(20)'),'Outbox schema persists event contract identity/version.');
r15(str_contains($out,'SN_Event_Schema_Registry::validate_payload($type, $payload)'),'Enqueue must reject unregistered/invalid event schemas before persistence.');
r15(str_contains($out,"event_contract'=>(string)")&&str_contains($out,"schema['contract']")&&str_contains($out,"event_schema_version'=>(string)")&&str_contains($out,"schema['version']"),'Enqueue must persist approved contract/version.');
r15(str_contains($out,"'contract'=>$contract")&&str_contains($out,"'schema_version'=>$schema_version")&&str_contains($out,"schema['owner']")&&str_contains($out,"schema['privacy_class']"),'Dispatch envelope must carry governed schema metadata.');
r15(str_contains($out,"apply_filters('sn_network_outbox_delivery_result',false,$event)")&&!str_contains($out,"apply_filters('sn_network_outbox_delivery_result',true,$event)"),'Outbox acknowledgement must fail closed when no consumer explicitly acknowledges.');
r15(str_contains($out,'event_schema_backfill_failed')&&str_contains($out,'event_schema_contract_mismatch'),'Legacy queued rows must be safely backfilled and contract drift rejected.');
r15(str_contains($out,"has_filter('sn_network_outbox_delivery_result')")&&str_contains($out,'event_schema_registry_count'),'Health must expose acknowledgement adapter and registry readiness.');
r15(str_contains($reg,"apply_filters('sn_network_event_schema_registry'")&&str_contains($reg,'event_schema_unregistered')&&str_contains($reg,'event_schema_invalid'),'Registry must support approved extensions while unknown/invalid schemas fail closed.');
foreach(['owner','version','required_fields','optional_fields','privacy_class','retention','consumers','deprecation_date'] as $field)r15(str_contains($reg,"'$field'"),"Registry metadata missing $field.");
$events=['message.sent','message.edited','message.deleted','message.delivered','message.read','message.expired','message.expiry_changed','message.forwarded','message.mentions_updated','message_request.created','message_request.accepted','message_request.declined','message_request.reported','message_request.cancelled','checklist.item_changed','community_artifact.created','smail.sent','space.created','space.join_request_accepted','space.join_request_rejected','space.invitation_created','space.invitation_accepted','space.invitation_rejected','space.invitation_cancelled','space.member_joined','space.member_left','space.member_removed','space.member_role_changed','space.member_banned','space.lifecycle_changed','space.ownership_transferred','conversation.ownership_transferred','conversation.context_attached','conversation.context_detached','conversation.clinical_context_reference_issued','file-transfer.initiated','file-transfer.ready','file-transfer.revoked','conference.provider_configured'];
foreach($events as $event)r15(str_contains($reg,"'$event' => self::spec("),"Missing governed event schema: $event");
if($fails){fwrite(STDERR,"R15 event-delivery/schema failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "R15 event-delivery/schema contracts: PASS ($checks checks)\n";
''',encoding='utf-8')
