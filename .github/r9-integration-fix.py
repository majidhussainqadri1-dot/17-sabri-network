from pathlib import Path

root = Path(__file__).resolve().parents[1]
inc = root / 'sabri-network' / 'includes'

def rep(name, old, new):
    p=inc/name; s=p.read_text(encoding='utf-8')
    if old not in s: raise SystemExit(f'missing R9 anchor: {name}')
    p.write_text(s.replace(old,new,1),encoding='utf-8')

rep('class-sn-seventh-fresh-r13-hardening.php',
"""    public static function file19_ready(): bool {
        $listener = has_action('sn_network_notification_requested') !== false;
        return (bool) apply_filters('sn_network_file19_notification_adapter_ready', $listener);
    }
""",
"""    public static function file19_ready(): bool {
        // A listener may be an observer only. File 19 must explicitly declare that
        // its delivery adapter is ready before File 17 reports a connected fabric.
        $listener = has_action('sn_network_notification_requested') !== false;
        $declared_ready = apply_filters('sn_network_file19_notification_adapter_ready', false);
        return $listener && $declared_ready === true;
    }
""")

p=inc/'class-sn-two-plan-completion.php'; s=p.read_text(encoding='utf-8')
old="'approved_translation_provider'=>has_filter('sn_network_translate_message')"
new="'approved_translation_provider'=>(bool)apply_filters('sn_network_translation_provider_ready',false)"
if old not in s: raise SystemExit('missing translation status anchor')
p.write_text(s.replace(old,new,1),encoding='utf-8')

p=inc/'class-sn-future-superset-part-1.php'; s=p.read_text(encoding='utf-8')
old="""        $provider=(array)apply_filters('sn_network_e2ee_provider_status',[]); $items=[];
        foreach(self::features() as $id=>$f){$status='available'; if($id==='F17-FUT-01'&&(empty($provider['ready'])||empty($provider['audited'])))$status='provider-gated'; if(in_array($id,['F17-FUT-02','F17-FUT-03'],true)&&!has_filter('sn_network_device_public_key_valid'))$status='provider-gated'; if($id==='F17-FUT-22'&&!has_filter('sn_network_ai_assistant_result'))$status='provider-gated'; if($id==='F17-FUT-23'&&!has_filter('sn_network_private_semantic_search_result'))$status='provider-gated'; if(in_array($id,['F17-FUT-17','F17-FUT-18','F17-FUT-19','F17-FUT-20'],true)&&!(bool)apply_filters('sn_network_sfu_available',false,get_current_user_id(),0))$status='provider-gated'; $items[]=['id'=>$id]+$f+['status'=>$status];}
"""
new="""        $provider=(array)apply_filters('sn_network_e2ee_provider_status',[]); $items=[];
        $device_ready=has_filter('sn_network_device_public_key_valid') && apply_filters('sn_network_device_key_provider_ready',false)===true;
        $ai_ready=has_filter('sn_network_ai_assistant_result') && has_filter('sn_network_ai_context_authorized') && has_filter('sn_network_ai_context_redact') && apply_filters('sn_network_ai_provider_ready',false)===true;
        $semantic_ready=has_filter('sn_network_private_semantic_search_result') && apply_filters('sn_network_private_semantic_provider_ready',false)===true;
        foreach(self::features() as $id=>$f){$status='available'; if($id==='F17-FUT-01'&&(empty($provider['ready'])||empty($provider['audited'])))$status='provider-gated'; if(in_array($id,['F17-FUT-02','F17-FUT-03'],true)&&!$device_ready)$status='provider-gated'; if($id==='F17-FUT-22'&&!$ai_ready)$status='provider-gated'; if($id==='F17-FUT-23'&&!$semantic_ready)$status='provider-gated'; if(in_array($id,['F17-FUT-17','F17-FUT-18','F17-FUT-19','F17-FUT-20'],true)&&!(bool)apply_filters('sn_network_sfu_available',false,get_current_user_id(),0))$status='provider-gated'; $items[]=['id'=>$id]+$f+['status'=>$status];}
"""
if old not in s: raise SystemExit('missing capabilities status anchor')
p.write_text(s.replace(old,new,1),encoding='utf-8')

p=inc/'class-sn-fourth-fresh-interop-hardening.php'; s=p.read_text(encoding='utf-8')
old="""        try {
            do_action('sn_network_interop_inbound_accepted', [
                'bridge_id'=>(int)($data['bridge_id']??0),'conversation_id'=>(int)$receipt->scope_id,
                'external_event_id_hash'=>(string)($data['external_event_id_hash']??''),'sanitized_payload'=>$payload,
                'receipt_id'=>(int)$receipt->id,'consumer_idempotency_key'=>'interop-receipt:' . (int)$receipt->id,
            ]);
        } catch (Throwable $e) {
            SN_DB::audit('future_interop_inbound_delivery_failed','conversation',(int)$receipt->scope_id,'failure',['receipt_id'=>(int)$receipt->id,'reason_hash'=>hash('sha256',$e->getMessage())],0);
            return self::error('sn_interop_inbound_retry_required','Inbound acceptance is persisted but local delivery failed; retry the same event identifier.',503);
        }
        $saved = self::set_receipt_state($receipt, 'processed', ['processed_at'=>current_time('mysql',true)]);
"""
new="""        $event = [
            'bridge_id'=>(int)($data['bridge_id']??0),'conversation_id'=>(int)$receipt->scope_id,
            'external_event_id_hash'=>(string)($data['external_event_id_hash']??''),'sanitized_payload'=>$payload,
            'receipt_id'=>(int)$receipt->id,'consumer_idempotency_key'=>'interop-receipt:' . (int)$receipt->id,
        ];
        try {
            do_action('sn_network_interop_inbound_accepted', $event);
            $ack = apply_filters('sn_network_interop_inbound_delivery_result', null, $event, $receipt, $data);
            if ($ack !== true) {
                SN_DB::audit('future_interop_inbound_delivery_unacknowledged','conversation',(int)$receipt->scope_id,'failure',['receipt_id'=>(int)$receipt->id],0);
                return self::error('sn_interop_inbound_retry_required','Inbound acceptance is persisted but the approved consumer did not acknowledge delivery; retry the same event identifier.',503);
            }
        } catch (Throwable $e) {
            SN_DB::audit('future_interop_inbound_delivery_failed','conversation',(int)$receipt->scope_id,'failure',['receipt_id'=>(int)$receipt->id,'reason_hash'=>hash('sha256',$e->getMessage())],0);
            return self::error('sn_interop_inbound_retry_required','Inbound acceptance is persisted but local delivery failed; retry the same event identifier.',503);
        }
        $saved = self::set_receipt_state($receipt, 'processed', ['processed_at'=>current_time('mysql',true)]);
"""
if old not in s: raise SystemExit('missing interop delivery anchor')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# Permanent R9 regression.
test=root/'sabri-network/tests/eighth-fresh/eighth-fresh-ten-round-contracts.php'; s=test.read_text(encoding='utf-8')
if '$interop =' not in s:
    s=s.replace("$integrationPrivacy = $read('includes/class-sn-fifth-fresh-integration-hardening.php');", "$integrationPrivacy = $read('includes/class-sn-fifth-fresh-integration-hardening.php');\n$interop = $read('includes/class-sn-fourth-fresh-interop-hardening.php');\n$futureCapabilities = $read('includes/class-sn-future-superset-part-1.php');\n$twoPlan = $read('includes/class-sn-two-plan-completion.php');\n$realtimeR13 = $read('includes/class-sn-seventh-fresh-r13-hardening.php');")
marker='// Round 9 — external readiness and delivery state require explicit provider acknowledgement.'
if marker not in s:
    block=r'''
// Round 9 — external readiness and delivery state require explicit provider acknowledgement.
$check(str_contains($realtimeR13, "$declared_ready = apply_filters('sn_network_file19_notification_adapter_ready', false)") && str_contains($realtimeR13, 'return $listener && $declared_ready === true;'), 'Round 9: File 19 readiness must be explicitly declared, not inferred from an observer listener.');
$check(str_contains($twoPlan, "sn_network_translation_provider_ready") && !str_contains($twoPlan, "'approved_translation_provider'=>has_filter('sn_network_translate_message')"), 'Round 9: translation status must not equate callback presence with approved-provider readiness.');
$check(str_contains($futureCapabilities, 'sn_network_device_key_provider_ready') && str_contains($futureCapabilities, 'sn_network_ai_provider_ready') && str_contains($futureCapabilities, 'sn_network_private_semantic_provider_ready'), 'Round 9: Future capability availability must require explicit provider readiness.');
$check(str_contains($interop, "sn_network_interop_inbound_delivery_result") && str_contains($interop, '$ack !== true') && str_contains($interop, 'future_interop_inbound_delivery_unacknowledged'), 'Round 9: inbound interoperability receipts must remain retryable until the consumer explicitly acknowledges delivery.');
'''
    s=s.replace('\nif ($fail) {',block+'\nif ($fail) {')
test.write_text(s,encoding='utf-8')
print('R9 integration truth corrections applied')
