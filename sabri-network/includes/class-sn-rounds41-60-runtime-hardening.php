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
    public static function register(): void {
        // The canonical scheduled dispatcher runs at the default priority 10.
        // Reconcile an already-created idempotent message immediately after it
        // in case the schedule-row finalization write failed independently.
        add_action('sn_cleanup_hourly', [self::class, 'reconcile_scheduled_finalization'], 12);
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
}
