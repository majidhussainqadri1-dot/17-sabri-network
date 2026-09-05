<?php
/** Next fresh Round 8: final interoperability success is conditional on durable sent-receipt truth. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_R8_Interop_Finalization_Hardening {
    public static function register(): void {
        // Later than Future24-H (1998) and Fourth-Fresh interop (2320).
        add_action('rest_api_init', [self::class, 'override_route'], 3400);
    }

    public static function override_route(): void {
        register_rest_route('sabri-network/v2', '/future/interop/(?P<id>\d+)/outbound', [
            'methods' => 'POST',
            'callback' => [self::class, 'outbound'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function outbound(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $response = SN_Fourth_Fresh_Interop_Hardening::outbound($request);
        if (is_wp_error($response)) return $response;
        if (!($response instanceof WP_REST_Response) || $response->get_status() >= 400) return $response;

        $data = $response->get_data();
        $receipt_id = absint(is_array($data) ? ($data['receipt_id'] ?? 0) : 0);
        $message_id = absint(is_array($data) ? ($data['message_id'] ?? $request->get_param('message_id')) : $request->get_param('message_id'));
        $bridge_id = absint($request['id']);
        if ($receipt_id <= 0 || !self::receipt_is_durably_sent($receipt_id)) {
            SN_DB::audit(
                'future_interop_outbound_local_finalize_failed',
                'message',
                $message_id,
                'failure',
                ['bridge_id'=>$bridge_id,'receipt_id'=>$receipt_id,'provider_side_effect_may_be_confirmed'=>true],
                get_current_user_id()
            );
            return new WP_Error(
                'sn_interop_reconciliation_required',
                'Provider delivery may be confirmed, but the local sent receipt is not durably finalized. Reconcile before retrying.',
                ['status'=>503]
            );
        }
        return $response;
    }

    private static function receipt_is_durably_sent(int $receipt_id): bool {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,feature_id,owner_id,scope_type,scope_id,state,payload_cipher FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-24' LIMIT 1",
            $receipt_id
        ));
        if (!$row || (string)$row->state !== 'active') return false;
        $plain = SN_Communication_Crypto::decrypt(
            (string)$row->payload_cipher,
            'future-record|' . (string)$row->feature_id . '|' . (int)$row->owner_id . '|' . (string)$row->scope_type . '|' . (int)$row->scope_id
        );
        if (is_wp_error($plain)) return false;
        $payload = json_decode((string)$plain, true);
        return is_array($payload) && (string)($payload['delivery_state'] ?? '') === 'sent';
    }
}
