<?php
/** Fourth fresh cycle: interoperability replay, payload-binding and uncertain-outcome reconciliation. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Interop_Hardening {
    private const LOCK_TIMEOUT = 5;
    private const FEATURE = 'F17-FUT-24';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2320);
    }

    public static function override_routes(): void {
        $a = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/future/interop/(?P<id>\d+)', [
            'methods'=>'DELETE','callback'=>[self::class,'revoke_bridge'],'permission_callback'=>$a,
        ], true);
        register_rest_route('sabri-network/v2', '/future/interop/(?P<id>\d+)/inbound', [
            'methods'=>'POST','callback'=>[self::class,'inbound'],'permission_callback'=>[SN_Future24_Review_Hardening_H::class,'inbound_permission'],
        ], true);
        register_rest_route('sabri-network/v2', '/future/interop/(?P<id>\d+)/outbound', [
            'methods'=>'POST','callback'=>[self::class,'outbound'],'permission_callback'=>$a,
        ], true);
    }

    public static function outbound(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        $bridge_id = absint($r['id']);
        $message_id = absint($r->get_param('message_id'));
        $idem = self::idempotency_key($r);
        if (is_wp_error($idem)) return $idem;
        if ($bridge_id <= 0 || $message_id <= 0) return self::not_found();
        $operation_key = 'interop-outbound:' . $bridge_id . ':' . $actor . ':' . hash('sha256', $idem);

        return self::with_lock($operation_key, function () use ($wpdb, $r, $actor, $bridge_id, $message_id, $idem, $operation_key) {
            $bridge = self::bridge($bridge_id, true);
            if (!$bridge || !self::manager((int) $bridge->scope_id, $actor)) return self::not_found();
            $bridge_data = self::decode($bridge);
            if (is_wp_error($bridge_data) || !empty($bridge_data['kill_switch'])) return self::error('sn_interop_outbound_disabled','Outbound interoperability is disabled for this bridge.',403);
            if (!SN_Policy::consume_rate_limit('interop_outbound', (string) $bridge_id, 120, MINUTE_IN_SECONDS)) return self::error('sn_interop_rate_limited','Interoperability rate limit exceeded.',429);

            $receipt = self::receipt_by_client($operation_key);
            if ($receipt) {
                $rd = self::decode($receipt);
                if (is_wp_error($rd)) return $rd;
                if ((int) ($rd['message_id'] ?? 0) !== $message_id || !hash_equals((string) ($rd['idempotency_hash'] ?? ''), hash('sha256', $idem))) {
                    return self::error('sn_interop_idempotency_conflict','The same idempotency key was reused for a different outbound operation.',409);
                }
                $state = (string) ($rd['delivery_state'] ?? 'uncertain');
                if ($state === 'sent') return rest_ensure_response(['queued'=>true,'duplicate'=>true,'bridge_id'=>$bridge_id,'message_id'=>$message_id,'receipt_id'=>(int)$receipt->id]);
                if (in_array($state, ['sending','uncertain'], true)) {
                    $reconciled = apply_filters('sn_network_interop_outbound_reconcile_result', null, $bridge_data, $message_id, $actor, [
                        'idempotency_key'=>$idem,'receipt_id'=>(int)$receipt->id,'delivery_state'=>$state,
                    ]);
                    if (is_array($reconciled) && in_array((string) ($reconciled['state'] ?? ''), ['sent','accepted','queued'], true)) {
                        self::set_receipt_state($receipt, 'sent', ['provider_receipt_hash'=>self::provider_hash($reconciled)]);
                        return rest_ensure_response(['queued'=>true,'duplicate'=>true,'reconciled'=>true,'bridge_id'=>$bridge_id,'message_id'=>$message_id,'receipt_id'=>(int)$receipt->id]);
                    }
                    if (!is_array($reconciled) || (string) ($reconciled['state'] ?? '') !== 'not_sent') {
                        return self::error('sn_interop_reconciliation_required','The prior outbound result is uncertain. Provider reconciliation is required before retrying.',409);
                    }
                    self::set_receipt_state($receipt, 'ready', ['reconciled_not_sent'=>true]);
                    $receipt = self::receipt_by_id((int) $receipt->id);
                }
            }

            // Re-check the exact local source immediately before establishing a sending receipt.
            $bridge = self::bridge($bridge_id, true);
            if (!$bridge || !self::manager((int) $bridge->scope_id, $actor)) return self::not_found();
            $bridge_data = self::decode($bridge);
            if (is_wp_error($bridge_data) || !empty($bridge_data['kill_switch'])) return self::error('sn_interop_outbound_disabled','Outbound interoperability is disabled for this bridge.',403);
            $message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $message_id));
            if (!$message || (int) $message->conversation_id !== (int) $bridge->scope_id || $message->deleted_at !== null || SN_Message_Operations::is_hidden($actor, $message_id)) return self::not_found();
            $decision = apply_filters('sn_network_interop_outbound_filter', null, $message_id, $message, $bridge_data, $actor);
            if (!is_array($decision) || empty($decision['allow'])) return self::error('sn_interop_outbound_denied','Outbound bridge policy did not approve this message.',403);

            $meta = [
                'subtype'=>'receipt','direction'=>'outbound','bridge_id'=>$bridge_id,'message_id'=>$message_id,'requester_id'=>$actor,
                'idempotency_hash'=>hash('sha256',$idem),'delivery_state'=>'sending','decision_hash'=>hash('sha256',self::canonical_json($decision)),
                'sending_at'=>current_time('mysql',true),
            ];
            if ($receipt) {
                $saved = self::save_record($receipt, $meta);
                if (is_wp_error($saved)) return $saved;
                $receipt = self::receipt_by_id((int) $receipt->id);
            } else {
                $created = self::create_record($actor, (int) $bridge->scope_id, $meta, $operation_key);
                if (is_wp_error($created)) return $created;
                $receipt = self::receipt_by_id($created);
            }
            if (!$receipt) return self::database_error();

            // A provider must explicitly confirm its outcome. Null/false/WP_Error/ambiguous
            // results are *uncertain*, never a safe signal to retry the external side effect.
            try {
                $provider = apply_filters('sn_network_interop_outbound_result', null, $bridge_data, $decision, $message_id, $actor, [
                    'idempotency_key'=>$idem,'receipt_id'=>(int)$receipt->id,
                ]);
            } catch (Throwable $e) {
                self::set_receipt_state($receipt, 'uncertain', ['provider_error_hash'=>hash('sha256',$e->getMessage())]);
                return self::error('sn_interop_reconciliation_required','The provider outcome is uncertain and must be reconciled before retrying.',503);
            }
            if (is_array($provider) && !empty($provider['confirmed']) && in_array((string) ($provider['state'] ?? ''), ['sent','accepted','queued'], true)) {
                self::set_receipt_state($receipt, 'sent', ['provider_receipt_hash'=>self::provider_hash($provider)]);
                SN_DB::audit('future_interop_outbound','message',$message_id,'success',['bridge_id'=>$bridge_id,'receipt_id'=>(int)$receipt->id,'payload_hash'=>hash('sha256',self::canonical_json($decision))],$actor);
                return rest_ensure_response(['queued'=>true,'bridge_id'=>$bridge_id,'message_id'=>$message_id,'receipt_id'=>(int)$receipt->id]);
            }
            self::set_receipt_state($receipt, 'uncertain', ['provider_error_hash'=>hash('sha256', is_wp_error($provider) ? $provider->get_error_code() : self::canonical_json((array)$provider))]);
            return self::error('sn_interop_reconciliation_required','The provider did not return an explicit confirmed outcome. Reconcile before retrying.',503);
        });
    }

    public static function inbound(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $bridge_id = absint($r['id']);
        $event_id = mb_substr(trim(sanitize_text_field((string) $r->get_param('event_id'))), 0, 191);
        $payload = $r->get_param('payload');
        if ($bridge_id <= 0 || $event_id === '') return self::error('sn_interop_event_id_required','A stable external event identifier is required.',400);
        if (!is_array($payload)) return self::error('sn_interop_payload_invalid','Inbound payload must be a structured object.',400);
        $payload_hash = hash('sha256', self::canonical_json($payload));
        $operation_key = 'interop-inbound:' . $bridge_id . ':' . $event_id;

        return self::with_lock($operation_key, function () use ($bridge_id, $event_id, $payload, $payload_hash, $operation_key) {
            $bridge = self::bridge($bridge_id, true);
            if (!$bridge) return self::not_found();
            $bd = self::decode($bridge);
            if (is_wp_error($bd) || !empty($bd['kill_switch']) || ($bd['direction'] ?? 'outbound') !== 'bidirectional') return self::error('sn_interop_inbound_disabled','Inbound interoperability is disabled for this bridge.',403);
            if (!SN_Policy::consume_rate_limit('interop_inbound', (string) $bridge_id, 120, MINUTE_IN_SECONDS)) return self::error('sn_interop_rate_limited','Interoperability rate limit exceeded.',429);

            $receipt = self::receipt_by_client($operation_key);
            if ($receipt) {
                $rd = self::decode($receipt);
                if (is_wp_error($rd)) return $rd;
                if (!hash_equals((string) ($rd['payload_hash'] ?? ''), $payload_hash)) return self::error('sn_interop_event_conflict','The external event identifier was reused with different payload data.',409);
                $decision = (string) ($rd['decision'] ?? 'deny');
                if ($decision !== 'allow' || (string) ($rd['delivery_state'] ?? '') === 'processed') {
                    return rest_ensure_response(['accepted'=>$decision==='allow','quarantined'=>$decision==='quarantine','duplicate'=>true,'receipt_id'=>(int)$receipt->id]);
                }
                return self::dispatch_inbound_receipt($receipt, $rd, true);
            }

            $decision = apply_filters('sn_network_interop_inbound_filter', null, $payload, $bd, (int) $bridge->scope_id, $bridge_id);
            if (!is_array($decision) || !in_array((string) ($decision['decision'] ?? ''), ['allow','quarantine','deny'], true)) return self::error('sn_interop_filter_unavailable','Inbound interoperability filter is unavailable.',503);
            $state = (string) $decision['decision'];
            $sanitized = is_array($decision['payload'] ?? null) ? $decision['payload'] : [];
            if ($state === 'allow' && !$sanitized) return self::error('sn_interop_payload_unavailable','Approved inbound data must contain a sanitized structured payload.',503);
            $meta = [
                'subtype'=>'receipt','direction'=>'inbound','bridge_id'=>$bridge_id,'external_event_id_hash'=>hash('sha256',$event_id),
                'payload_hash'=>$payload_hash,'decision'=>$state,'reason'=>mb_substr(sanitize_text_field((string)($decision['reason']??'')),0,191),
                'sanitized_payload'=>$state==='allow'?$sanitized:[],'delivery_state'=>$state==='allow'?'pending':'processed',
            ];
            $id = self::create_record(0, (int) $bridge->scope_id, $meta, $operation_key);
            if (is_wp_error($id)) return $id;
            $receipt = self::receipt_by_id($id);
            if (!$receipt) return self::database_error();
            if ($state !== 'allow') {
                SN_DB::audit('future_interop_inbound','conversation',(int)$bridge->scope_id,'failure',['bridge_id'=>$bridge_id,'receipt_id'=>$id,'decision'=>$state],0);
                return new WP_REST_Response(['accepted'=>false,'quarantined'=>$state==='quarantine','receipt_id'=>$id],$state==='quarantine'?202:403);
            }
            return self::dispatch_inbound_receipt($receipt, $meta, false);
        });
    }

    public static function revoke_bridge(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $actor = get_current_user_id();
        $id = absint($r['id']);
        $idem = self::idempotency_key($r);
        if (is_wp_error($idem)) return $idem;
        return self::with_lock('interop-revoke:' . $id, function () use ($actor, $id, $idem) {
            $row = self::bridge($id, false);
            if (!$row) return self::not_found();
            if (!self::manager((int) $row->scope_id, $actor)) return self::not_found();
            $data = self::decode($row);
            if (is_wp_error($data)) return $data;
            $idem_hash = hash('sha256', $idem);
            if (($row->state ?? '') === 'revoked') {
                return hash_equals((string)($data['shutdown_idempotency_hash']??''),$idem_hash)
                    ? rest_ensure_response(['id'=>$id,'state'=>'revoked','duplicate'=>true])
                    : self::error('sn_interop_idempotency_conflict','This bridge shutdown used a different idempotency key.',409);
            }
            if (!empty($data['kill_switch']) && !empty($data['shutdown_idempotency_hash']) && !hash_equals((string)$data['shutdown_idempotency_hash'],$idem_hash)) {
                return self::error('sn_interop_reconciliation_required','A prior bridge shutdown outcome remains uncertain. Reconcile that operation first.',409);
            }

            if (!empty($data['kill_switch']) && ($data['shutdown_state'] ?? '') === 'reconcile_required') {
                $reconcile = apply_filters('sn_network_interop_kill_switch_reconcile_result', null, $id, $data, (int)$row->scope_id, $actor, ['idempotency_key'=>$idem]);
                if (is_array($reconcile) && in_array((string)($reconcile['state']??''),['stopped','revoked'],true)) {
                    return self::finalize_revoke($row, $data, $actor, $idem_hash, true);
                }
                return self::error('sn_interop_reconciliation_required','Provider shutdown remains uncertain; the local bridge stays fail-closed.',409);
            }

            $data['kill_switch'] = true;
            $data['shutdown_state'] = 'provider_pending';
            $data['shutdown_idempotency_hash'] = $idem_hash;
            $saved = self::save_record($row, $data);
            if (is_wp_error($saved)) return $saved;
            $row = self::bridge($id, true);
            if (!$row) return self::not_found();
            $data = self::decode($row);
            try {
                $provider = apply_filters('sn_network_interop_kill_switch_result', null, $id, $data, (int)$row->scope_id, $actor, ['idempotency_key'=>$idem]);
            } catch (Throwable $e) {
                $data['shutdown_state'] = 'reconcile_required'; self::save_record($row, $data);
                return self::error('sn_interop_reconciliation_required','Provider shutdown outcome is uncertain; the local bridge remains disabled.',503);
            }
            if (is_array($provider) && !empty($provider['confirmed']) && in_array((string)($provider['state']??''),['stopped','revoked'],true)) {
                return self::finalize_revoke($row, $data, $actor, $idem_hash, false);
            }
            $data['shutdown_state'] = 'reconcile_required'; self::save_record($row, $data);
            return self::error('sn_interop_reconciliation_required','Provider shutdown was not explicitly confirmed; the local bridge remains fail-closed.',503);
        });
    }

    private static function dispatch_inbound_receipt(object $receipt, array $data, bool $duplicate): WP_REST_Response|WP_Error {
        if (!has_action('sn_network_interop_inbound_accepted')) return self::error('sn_interop_consumer_unavailable','No approved inbound interoperability consumer is registered; the receipt remains pending.',503);
        $payload = is_array($data['sanitized_payload'] ?? null) ? $data['sanitized_payload'] : [];
        if (!$payload) return self::error('sn_interop_payload_unavailable','The sanitized inbound payload is unavailable.',503);
        try {
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
        if (is_wp_error($saved)) return $saved;
        SN_DB::audit('future_interop_inbound','conversation',(int)$receipt->scope_id,'success',['bridge_id'=>(int)($data['bridge_id']??0),'receipt_id'=>(int)$receipt->id,'decision'=>'allow'],0);
        return new WP_REST_Response(['accepted'=>true,'quarantined'=>false,'duplicate'=>$duplicate,'receipt_id'=>(int)$receipt->id],202);
    }

    private static function finalize_revoke(object $row, array $data, int $actor, string $idem_hash, bool $reconciled): WP_REST_Response|WP_Error {
        global $wpdb;
        $data['kill_switch']=true; $data['shutdown_state']='confirmed'; $data['shutdown_idempotency_hash']=$idem_hash;
        $cipher = self::encode($row, $data); if (is_wp_error($cipher)) return $cipher;
        $changed = $wpdb->update($wpdb->prefix . 'sn_future_records', [
            'state'=>'revoked','payload_cipher'=>$cipher,'updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1,
        ], ['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);
        if ($changed !== 1) return self::error('sn_interop_reconcile_required','Provider shutdown is confirmed but local finalization needs reconciliation.',503);
        SN_DB::audit('future_interop_bridge_revoked','conversation',(int)$row->scope_id,'success',['bridge_id'=>(int)$row->id,'reconciled'=>$reconciled],$actor);
        return rest_ensure_response(['id'=>(int)$row->id,'state'=>'revoked','reconciled'=>$reconciled]);
    }

    private static function idempotency_key(WP_REST_Request $r): string|WP_Error {
        $key = strtolower(trim((string)$r->get_header('Idempotency-Key')));
        if ($key === '') $key = strtolower(trim((string)$r->get_param('client_id')));
        return preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$key) ? $key : self::error('sn_interop_idempotency_required','A caller-supplied 8–64 character interoperability idempotency key is required.',400);
    }
    private static function bridge(int $id, bool $active_only): ?object { global $wpdb; $state=$active_only?" AND state='active'":''; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-24' AND scope_type='conversation'$state LIMIT 1",$id)); return is_object($row)?$row:null; }
    private static function manager(int $c,int $u): bool { return $c>0 && SN_DB::is_member($c,$u) && in_array(SN_DB::member_role($c,$u),['owner','moderator'],true); }
    private static function receipt_by_client(string $client): ?object { global $wpdb; $key=hash('sha256',$client); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE feature_id='F17-FUT-24' AND client_key=%s LIMIT 1",$key)); return is_object($row)?$row:null; }
    private static function receipt_by_id(int $id): ?object { global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-24' LIMIT 1",$id)); return is_object($row)?$row:null; }
    private static function create_record(int $owner,int $conversation,array $data,string $client): int|WP_Error { global $wpdb; $dummy=(object)['feature_id'=>self::FEATURE,'owner_id'=>$owner,'scope_type'=>'conversation','scope_id'=>$conversation]; $cipher=self::encode($dummy,$data); if(is_wp_error($cipher))return $cipher; $now=current_time('mysql',true); $key=hash('sha256',$client); $ok=$wpdb->insert($wpdb->prefix.'sn_future_records',['feature_id'=>self::FEATURE,'owner_id'=>$owner,'scope_type'=>'conversation','scope_id'=>$conversation,'state'=>'active','payload_cipher'=>$cipher,'client_key'=>$key,'created_at'=>$now,'updated_at'=>$now,'version'=>1]); if($ok!==false)return (int)$wpdb->insert_id; $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_future_records WHERE feature_id='F17-FUT-24' AND client_key=%s",$key)); return $existing?:self::database_error(); }
    private static function save_record(object $row,array $data): array|WP_Error { global $wpdb; $cipher=self::encode($row,$data); if(is_wp_error($cipher))return $cipher; $ok=$wpdb->update($wpdb->prefix.'sn_future_records',['payload_cipher'=>$cipher,'updated_at'=>current_time('mysql',true),'version'=>(int)$row->version+1],['id'=>(int)$row->id,'version'=>(int)$row->version]); return $ok===1?['version'=>(int)$row->version+1]:self::error('sn_interop_receipt_conflict','Interoperability state changed concurrently.',409); }
    private static function set_receipt_state(object $row,string $state,array $extra=[]): array|WP_Error { $data=self::decode($row); if(is_wp_error($data))return $data; $data['delivery_state']=$state; $data=array_merge($data,$extra); return self::save_record($row,$data); }
    private static function encode(object $row,array $data): string|WP_Error { return SN_Communication_Crypto::encrypt(self::canonical_json($data),'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id); }
    private static function decode(object $row): array|WP_Error { $plain=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id); if(is_wp_error($plain))return $plain; $data=json_decode($plain,true); return is_array($data)?$data:self::database_error(); }
    private static function provider_hash(array $provider): string { $copy=$provider; unset($copy['token'],$copy['secret'],$copy['credential'],$copy['authorization']); return hash('sha256',self::canonical_json($copy)); }
    private static function canonical_json(array $value): string { self::ksort_recursive($value); return (string)wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
    private static function ksort_recursive(array &$value): void { ksort($value); foreach($value as &$item)if(is_array($item))self::ksort_recursive($item); unset($item); }
    private static function with_lock(string $key,callable $cb){ global $wpdb; $name='sn:f17:interop-op:'.substr(hash('sha256',$key),0,40); $got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$name,self::LOCK_TIMEOUT)); if($got!==1)return self::error('sn_interop_busy','An identical interoperability operation is already in progress.',409); try{return $cb();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));} }
    private static function database_error(): WP_Error { return self::error('database_error','Interoperability state could not be stored safely.',500); }
    private static function not_found(): WP_Error { return self::error('not_found','Requested communication object is unavailable.',404); }
    private static function error(string $c,string $m,int $s): WP_Error { return new WP_Error($c,$m,['status'=>$s]); }
}
