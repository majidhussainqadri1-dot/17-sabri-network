<?php
/**
 * Runtime hardening discovered during File-17 review rounds 41-60.
 *
 * This class only repairs canonical File-17 workflows; it does not create a
 * parallel messaging, calls, identity, search or notification backend.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Rounds41_60_Runtime_Hardening {
    private const EXPIRY_SCAN_BATCH = 1000;
    private const EXPIRY_CURSOR_OPTION = 'sn_round55_expiry_scan_cursor';

    public static function register(): void {
        // The canonical scheduled dispatcher runs at the default priority 10.
        // Reconcile an already-created idempotent message immediately after it
        // in case the schedule-row finalization write failed independently.
        add_action('sn_cleanup_hourly', [self::class, 'reconcile_scheduled_finalization'], 12);

        // Replace the fixed "first 1000 rows" expiry sweep. A persisted cursor
        // guarantees that high-ID disappearing messages cannot starve forever.
        remove_action('sn_cleanup_hourly', [SN_Two_Plan_Completion::class, 'expire_messages']);
        add_action('sn_cleanup_hourly', [self::class, 'expire_messages_cursor'], 10);
    }

    public static function reconcile_scheduled_finalization(): void {
        global $wpdb;
        $scheduled = SN_DB::table('scheduled_messages');
        $messages = SN_DB::table('messages');
        $rows = $wpdb->get_results(
            "SELECT id,sender_id,client_key,status FROM $scheduled WHERE status='processing' ORDER BY updated_at ASC,id ASC LIMIT 100"
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            // SN_Two_Plan_Completion::insert_canonical_message hashes the
            // scheduled client_key once more before storing message.idempotency_key.
            $message_key = hash('sha256', (string) $row->client_key);
            $message_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $messages WHERE idempotency_key=%s LIMIT 1",
                $message_key
            ));
            if ($message_id <= 0) continue;

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $scheduled SET status='sent',message_id=%d,body_cipher='',last_error='',updated_at=%s WHERE id=%d AND status='processing'",
                $message_id,
                current_time('mysql', true),
                (int) $row->id
            ));
            if ($updated === 1) {
                SN_DB::audit('scheduled_message_finalization_reconciled', 'scheduled_message', (int) $row->id, 'success', ['message_id' => $message_id], (int) $row->sender_id);
            } elseif ($updated === false) {
                SN_DB::audit('scheduled_message_finalization_reconcile_failed', 'scheduled_message', (int) $row->id, 'failure', ['message_id' => $message_id], (int) $row->sender_id);
            }
        }
    }

    public static function expire_messages_cursor(): void {
        global $wpdb;
        $messages = SN_DB::table('messages');
        $cursor = max(0, (int) get_option(self::EXPIRY_CURSOR_OPTION, 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $messages WHERE id>%d AND deleted_at IS NULL AND metadata IS NOT NULL AND metadata<>'' ORDER BY id ASC LIMIT %d",
            $cursor,
            self::EXPIRY_SCAN_BATCH
        ));

        // The scan reached the current end. Start the next cycle from the
        // beginning so newly changed low-ID metadata is not ignored forever.
        if (!is_array($rows) || !$rows) {
            update_option(self::EXPIRY_CURSOR_OPTION, 0, false);
            return;
        }

        $last_scanned = $cursor;
        foreach ($rows as $candidate) {
            $message_id = (int) $candidate->id;
            $last_scanned = max($last_scanned, $message_id);
            $wpdb->query('START TRANSACTION');
            $event_id = null;
            $attachment_id = 0;
            $sender_id = 0;
            try {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $messages WHERE id=%d AND deleted_at IS NULL FOR UPDATE",
                    $message_id
                ));
                if (!$row) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }
                $meta = json_decode((string) ($row->metadata ?? ''), true);
                $meta = is_array($meta) ? $meta : [];
                $expires = (string) ($meta['expires_at'] ?? '');
                if ($expires === '' || strtotime($expires . ' UTC') > time() || self::message_has_legal_hold($message_id)) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }

                $attachment_id = (string) $row->attachment_source === 'private' ? (int) $row->attachment_id : 0;
                $sender_id = (int) $row->sender_id;
                $now = current_time('mysql', true);
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE $messages SET body='',attachment_id=0,attachment_source='expired',metadata=%s,deleted_at=%s WHERE id=%d AND deleted_at IS NULL",
                    (string) wp_json_encode(['expired' => true, 'expired_at' => $now]),
                    $now,
                    $message_id
                ));
                if ($updated !== 1) throw new RuntimeException('expire_update_conflict');
                if ($wpdb->delete(SN_DB::table('reactions'), ['message_id' => $message_id], ['%d']) === false) throw new RuntimeException('expire_reactions_failed');
                $removed = SN_Message_Search::remove_message($message_id);
                if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
                $event_id = SN_Outbox::enqueue(
                    'message.expired',
                    'message',
                    $message_id,
                    ['message_id' => $message_id, 'conversation_id' => (int) $row->conversation_id, 'expired_at' => $now],
                    'message.expired:' . $message_id
                );
                if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('expire_commit_failed');

                if ($attachment_id > 0) SN_Private_Files::delete($attachment_id, $sender_id);
                SN_DB::audit('message_expired', 'message', $message_id, 'success', ['cursor_scan' => true], 0);
                do_action('sn_network_event_queued', $event_id, 'message.expired');
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                SN_DB::audit('message_expiry_failed', 'message', $message_id, 'failure', ['reason' => $e->getMessage(), 'cursor_scan' => true], 0);
            }
        }

        if (count($rows) < self::EXPIRY_SCAN_BATCH) {
            update_option(self::EXPIRY_CURSOR_OPTION, 0, false);
        } else {
            update_option(self::EXPIRY_CURSOR_OPTION, $last_scanned, false);
        }
    }

    private static function message_has_legal_hold(int $message_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SN_DB::table('reports') . ' WHERE message_id=%d AND legal_hold=1 LIMIT 1',
            $message_id
        ));
    }
}
