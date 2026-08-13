<?php
/** Additional runtime invariants for the File-17 two-plan completion paths. */
declare(strict_types=1);
defined('ABSPATH') || exit;

require_once SN_DIR . 'includes/class-sn-future-superset.php';

final class SN_Two_Plan_Runtime_Hardening {
    private const PROCESSING_LEASE_SECONDS = 900;
    private const EXPIRY_SCAN_BATCH = 250;
    private const EXPIRY_CURSOR_OPTION = 'sn_message_expiry_scan_after';
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 6, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 1600);
        remove_action('sn_cleanup_hourly', [SN_Two_Plan_Completion::class, 'dispatch_due_scheduled']);
        remove_action('sn_cleanup_hourly', [SN_Two_Plan_Completion::class, 'expire_messages']);
        add_action('sn_cleanup_hourly', [self::class, 'recover_stale_scheduled'], 4);
        add_action('sn_cleanup_hourly', [self::class, 'dispatch_due_scheduled'], 6);
        add_action('sn_cleanup_hourly', [self::class, 'expire_messages_cursor'], 12);
        SN_Future_Superset::register();
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return null;
        $method = strtoupper($request->get_method());

        if ($method === 'POST' && preg_match('#^/sabri-network/v2/messages/(\d+)/translate$#', $route, $match)) {
            $actor = get_current_user_id(); $message_id = (int) $match[1];
            if ($actor > 0 && !SN_Policy::consume_rate_limit('message_translate', (string) $actor, 60, HOUR_IN_SECONDS)) {
                return new WP_Error('sn_translation_rate_limited', 'Too many translation requests were made.', ['status' => 429]);
            }
            if (apply_filters('sn_network_translation_provider_authorized', false, $actor, $message_id, (string) $request->get_param('target_language')) !== true) {
                return new WP_Error('sn_translation_provider_unapproved', 'Translation is unavailable until an approved provider is authorized.', ['status' => 503]);
            }
        }

        if ($method === 'POST' && $route === '/sabri-network/v2/updates' && sanitize_key((string) $request->get_param('privacy')) === 'public') {
            return new WP_Error('sn_public_temporary_update_forbidden', 'Temporary updates are limited to private/contact/group audiences and do not replace public publishing.', ['status' => 400]);
        }

        $file_params = $request->get_file_params();
        if ($file_params) {
            $access = SN_Policy::access();
            if (is_wp_error($access)) return $access;
        }

        $hashes = [];
        foreach ($file_params as $key => $file) {
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return new WP_Error('sn_upload_incomplete', 'The uploaded file is incomplete.', ['status' => 400]);
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) return new WP_Error('sn_upload_hash_unavailable', 'The uploaded file cannot be verified safely.', ['status' => 503]);
            $digest = hash_file('sha256', $tmp);
            if (!is_string($digest) || strlen($digest) !== 64) return new WP_Error('sn_upload_hash_unavailable', 'The uploaded file cannot be verified safely.', ['status' => 503]);
            $hashes[(string) $key] = $digest;
        }
        if ($hashes) { ksort($hashes); $request->set_param('_sn_uploaded_file_hashes', $hashes); }
        return null;
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/scheduled-messages/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'cancel_scheduled_idempotently'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function cancel_scheduled_idempotently(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id(); $id = absint($request['id']); $table = SN_DB::table('scheduled_messages');
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $id));
        if (!$row || (int) $row->sender_id !== $actor) return self::not_found();
        if ((string) $row->status === 'cancelled') return rest_ensure_response(['id' => $id, 'status' => 'cancelled', 'duplicate' => true]);
        if (!in_array((string) $row->status, ['pending', 'failed'], true)) return new WP_Error('sn_schedule_not_cancellable', 'This scheduled message can no longer be cancelled.', ['status' => 409]);
        $updated = $wpdb->query($wpdb->prepare("UPDATE $table SET status='cancelled',body_cipher='',updated_at=%s WHERE id=%d AND sender_id=%d AND status IN ('pending','failed')", current_time('mysql', true), $id, $actor));
        if ($updated === false) return new WP_Error('sn_schedule_cancel_failed', 'The scheduled message could not be cancelled safely.', ['status' => 500]);
        if ($updated === 0) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT status FROM ' . $table . ' WHERE id=%d AND sender_id=%d', $id, $actor));
            if ($fresh && (string) $fresh->status === 'cancelled') return rest_ensure_response(['id' => $id, 'status' => 'cancelled', 'duplicate' => true]);
            return new WP_Error('sn_schedule_state_changed', 'The scheduled message changed state before cancellation.', ['status' => 409]);
        }
        SN_DB::audit('scheduled_message_cancelled', 'scheduled_message', $id, 'success', [], $actor);
        return rest_ensure_response(['id' => $id, 'status' => 'cancelled', 'duplicate' => false]);
    }

    /** Reconcile an already-created message before retrying a stale processing lease. */
    public static function recover_stale_scheduled(): void {
        global $wpdb;
        $table = SN_DB::table('scheduled_messages'); $messages = SN_DB::table('messages');
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::PROCESSING_LEASE_SECONDS); $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status='processing' AND updated_at<%s ORDER BY id ASC LIMIT 100", $cutoff));
        foreach (is_array($rows) ? $rows : [] as $row) {
            $client = self::scheduled_client((string) $row->client_key);
            $new_idem = hash('sha256', (int) $row->sender_id . ':' . (int) $row->conversation_id . ':' . $client);
            $legacy_idem = hash('sha256', (string) $row->client_key);
            $message_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $messages WHERE idempotency_key IN (%s,%s) ORDER BY id ASC LIMIT 1", $new_idem, $legacy_idem));
            if ($message_id > 0) {
                $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='sent',message_id=%d,body_cipher='',last_error='',updated_at=%s WHERE id=%d AND status='processing'", $message_id, $now, (int) $row->id));
                if ($changed === 1) SN_DB::audit('scheduled_message_finalization_reconciled', 'scheduled_message', (int) $row->id, 'success', ['message_id' => $message_id], (int) $row->sender_id);
                continue;
            }
            $wpdb->query($wpdb->prepare("UPDATE $table SET status='failed',last_error='processing_lease_expired',updated_at=%s WHERE id=%d AND status='processing'", $now, (int) $row->id));
        }
    }

    /** Safe cron dispatcher: relationship locks + current File00 policy + canonical send + checked finalization. */
    public static function dispatch_due_scheduled(): void {
        global $wpdb;
        $table = SN_DB::table('scheduled_messages'); $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status IN ('pending','failed') AND deliver_at<=%s AND attempts<5 ORDER BY deliver_at ASC,id ASC LIMIT 50", $now));
        foreach (is_array($rows) ? $rows : [] as $row) {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',attempts=attempts+1,updated_at=%s WHERE id=%d AND status IN ('pending','failed')", $now, (int) $row->id));
            if ($claimed !== 1) continue;
            $locks = self::scheduled_locks((int) $row->conversation_id);
            $held = self::acquire_locks($locks);
            if (is_wp_error($held)) { self::schedule_failed((int) $row->id, 'relationship_busy'); continue; }
            try {
                $plain = SN_Communication_Crypto::decrypt((string) $row->body_cipher, 'scheduled-message|' . (int) $row->sender_id . '|' . (int) $row->conversation_id);
                if (is_wp_error($plain)) { self::schedule_failed((int) $row->id, $plain->get_error_code()); continue; }
                $previous = get_current_user_id();
                wp_set_current_user((int) $row->sender_id);
                try {
                    $access = SN_Policy::access();
                    if (is_wp_error($access)) { self::schedule_failed((int) $row->id, $access->get_error_code()); continue; }
                    $request = new WP_REST_Request('POST', '/sabri-network/v2/conversations/' . (int) $row->conversation_id . '/messages');
                    $request->set_param('id', (int) $row->conversation_id); $request->set_param('body', (string) $plain); $request->set_param('message_type', 'text'); $request->set_param('client_id', self::scheduled_client((string) $row->client_key));
                    $result = SN_Message_Runtime_Hardening::send_message($request);
                } finally { wp_set_current_user($previous); }
                if (is_wp_error($result)) { self::schedule_failed((int) $row->id, $result->get_error_code()); continue; }
                $data = $result->get_data(); $message_id = absint($data['message']['id'] ?? 0);
                if ($message_id <= 0) { self::schedule_failed((int) $row->id, 'message_id_missing'); continue; }
                $finalized = $wpdb->query($wpdb->prepare("UPDATE $table SET status='sent',message_id=%d,body_cipher='',last_error='',updated_at=%s WHERE id=%d AND status='processing'", $message_id, current_time('mysql', true), (int) $row->id));
                if ($finalized !== 1) {
                    SN_DB::audit('scheduled_message_finalize_deferred', 'scheduled_message', (int) $row->id, 'failure', ['message_id' => $message_id], (int) $row->sender_id);
                    continue;
                }
                SN_DB::audit('scheduled_message_sent', 'message', $message_id, 'success', ['scheduled_id' => (int) $row->id], (int) $row->sender_id);
            } finally { self::release_locks($held); }
        }
    }

    /** Starvation-free disappearing-message scanner with locked revalidation and legal-hold preservation. */
    public static function expire_messages_cursor(): void {
        global $wpdb;
        $messages = SN_DB::table('messages'); $cursor = max(0, (int) get_option(self::EXPIRY_CURSOR_OPTION, 0)); $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM $messages WHERE id>%d AND deleted_at IS NULL AND metadata IS NOT NULL AND metadata<>'' ORDER BY id ASC LIMIT %d", $cursor, self::EXPIRY_SCAN_BATCH));
        if (!is_array($rows) || !$rows) { update_option(self::EXPIRY_CURSOR_OPTION, 0, false); return; }
        foreach ($rows as $probe) {
            $id = (int) $probe->id; update_option(self::EXPIRY_CURSOR_OPTION, $id, false);
            if ($wpdb->query('START TRANSACTION') === false) break;
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
                if (!$row || $row->deleted_at !== null) { $wpdb->query('COMMIT'); continue; }
                $meta = json_decode((string) $row->metadata, true); $meta = is_array($meta) ? $meta : [];
                $expires = (string) ($meta['expires_at'] ?? '');
                if ($expires === '' || strtotime($expires . ' UTC') > time() || self::message_has_legal_hold($id)) { $wpdb->query('COMMIT'); continue; }
                $attachment = (string) $row->attachment_source === 'private' ? (int) $row->attachment_id : 0;
                $updated = $wpdb->query($wpdb->prepare("UPDATE $messages SET body='',attachment_id=0,attachment_source='expired',metadata=%s,deleted_at=%s WHERE id=%d AND deleted_at IS NULL", (string) wp_json_encode(['expired'=>true,'expired_at'=>$now]), $now, $id));
                if ($updated !== 1) throw new RuntimeException('expire_update_failed');
                if ($wpdb->delete(SN_DB::table('reactions'), ['message_id'=>$id], ['%d']) === false) throw new RuntimeException('expire_reactions_failed');
                $removed = SN_Message_Search::remove_message($id); if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
                $event = SN_Outbox::enqueue('message.expired', 'message', $id, ['message_id'=>$id,'conversation_id'=>(int)$row->conversation_id,'expired_at'=>$now], 'message.expired:' . $id); if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('expire_commit_failed');
                if ($attachment > 0) SN_Private_Files::delete($attachment, (int) $row->sender_id);
                SN_DB::audit('message_expired', 'message', $id, 'success', [], 0); do_action('sn_network_event_queued', $event, 'message.expired');
            } catch (Throwable $e) { $wpdb->query('ROLLBACK'); SN_DB::audit('message_expiry_failed', 'message', $id, 'failure', ['reason'=>$e->getMessage()], 0); }
        }
        if (count($rows) < self::EXPIRY_SCAN_BATCH) update_option(self::EXPIRY_CURSOR_OPTION, 0, false);
    }

    private static function scheduled_client(string $client_key): string { return 'sched-' . substr(hash('sha256', $client_key), 0, 48); }
    private static function scheduled_locks(int $conversation): array {
        global $wpdb; $locks = ['sn:f17:conversation:' . substr(hash('sha256', (string) $conversation), 0, 32)];
        $type = (string) $wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', $conversation));
        if ($type === 'direct') { $ids = array_values(array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 3', $conversation)) ?: [])); if (count($ids) === 2) $locks[] = SN_Relationships::pair_lock_name($ids[0], $ids[1]); }
        return $locks;
    }
    private static function acquire_locks(array $locks): array|WP_Error { global $wpdb; $locks=array_values(array_unique($locks));sort($locks,SORT_STRING);$held=[];foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1){self::release_locks($held);return new WP_Error('relationship_busy','The conversation is changing.',['status'=>409]);}$held[]=$lock;}return $held; }
    private static function release_locks(array $locks): void { global $wpdb; foreach(array_reverse($locks) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    private static function message_has_legal_hold(int $id): bool { global $wpdb; return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('reports') . ' WHERE message_id=%d AND legal_hold=1 LIMIT 1', $id)); }
    private static function schedule_failed(int $id, string $code): void { global $wpdb; $wpdb->query($wpdb->prepare("UPDATE " . SN_DB::table('scheduled_messages') . " SET status='failed',last_error=%s,updated_at=%s WHERE id=%d AND status='processing'", sanitize_key($code), current_time('mysql', true), $id)); }
    private static function not_found(): WP_Error { return new WP_Error('sn_not_found', 'The requested communication object is unavailable.', ['status' => 404]); }
}
