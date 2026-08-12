<?php
/** Review round 41 — fail-closed REST message-body visibility after compatibility decryption. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_P {
    public static function register(): void {
        // SN_Central_Plan_Hardening decrypts legacy message bodies at priority 50.
        // Revalidate authoritative visibility afterwards so a formatter cannot revive
        // a deleted/hidden message or disclose a body outside an active membership.
        add_filter('rest_post_dispatch', [self::class, 'redact_unavailable_message_bodies'], 60, 3);
    }

    public static function redact_unavailable_message_bodies($response, WP_REST_Server $server, WP_REST_Request $request) {
        if (!str_starts_with($request->get_route(), '/sabri-network/v2/') || !($response instanceof WP_REST_Response)) {
            return $response;
        }
        $data = $response->get_data();
        if (is_array($data)) {
            $response->set_data(self::redact_payload($data, get_current_user_id()));
        }
        return $response;
    }

    private static function redact_payload(array $payload, int $viewer): array {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::redact_payload($value, $viewer);
            }
        }

        if (!isset($payload['body']) || !is_string($payload['body'])) {
            return $payload;
        }

        $message_id = absint($payload['message_id'] ?? 0);
        if ($message_id <= 0 && isset($payload['conversation_id'], $payload['id'])) {
            $message_id = absint($payload['id']);
        }
        if ($message_id <= 0) {
            return $payload;
        }

        global $wpdb;
        static $cache = [];
        if (!array_key_exists($message_id, $cache)) {
            $cache[$message_id] = $wpdb->get_row($wpdb->prepare(
                'SELECT id,conversation_id,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d',
                $message_id
            ));
        }
        $row = $cache[$message_id];
        if (!$row) {
            return $payload;
        }

        // If the payload supplies a conversation id, it must agree with canonical truth.
        if (isset($payload['conversation_id']) && absint($payload['conversation_id']) !== (int) $row->conversation_id) {
            return self::redacted($payload);
        }

        $unavailable = !empty($row->deleted_at)
            || $viewer <= 0
            || !SN_DB::is_member((int) $row->conversation_id, $viewer)
            || SN_Message_Operations::is_hidden($viewer, $message_id);

        return $unavailable ? self::redacted($payload) : $payload;
    }

    private static function redacted(array $payload): array {
        $payload['body'] = '';
        $payload['body_unavailable'] = true;
        return $payload;
    }
}
