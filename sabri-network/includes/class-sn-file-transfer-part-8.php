<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_8 {
    public static function erase_personal_data(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        // Keep the batch size local: trait constants are unavailable on the
        // plugin's PHP 8.1 minimum runtime.
        $page_size = 100;
        $uid = (int) $user->ID;
        $page = max(1, $page);
        $offset = ($page - 1) * $page_size;
        $sessions = $wpdb->get_results($wpdb->prepare(
            'SELECT id,public_id,status,version,revoked_at FROM ' . self::sessions_table() . ' WHERE sender_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $uid,
            $page_size,
            $offset
        ));
        $sessions = is_array($sessions) ? $sessions : [];

        $removed = false;
        $retained = false;
        $messages = [];
        $now = current_time('mysql', true);

        foreach ($sessions as $row) {
            $terminal = in_array((string) $row->status, ['revoked', 'expired', 'rejected'], true);
            if (!$terminal) {
                // Revoke canonical access before touching encrypted bytes. A failed
                // DB mutation must never leave an apparently live transfer whose
                // private object has already been destroyed.
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE " . self::sessions_table() . " SET status='revoked',revoked_at=COALESCE(revoked_at,%s),version=version+1,updated_at=%s WHERE id=%d AND sender_id=%d AND version=%d AND status NOT IN ('revoked','expired','rejected')",
                    $now,
                    $now,
                    (int) $row->id,
                    $uid,
                    (int) $row->version
                ));
                if ($updated !== 1) {
                    $retained = true;
                    $messages[] = 'A private transfer could not be revoked safely during erasure and was retained for retry.';
                    SN_DB::audit('file_transfer_privacy_revoke_failed', 'file_transfer', (int) $row->id, 'failure', ['privacy_page' => $page], $uid);
                    continue;
                }
                $removed = true;
            }

            self::delete_chunks((int) $row->id);
            $remaining_chunks = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::chunks_table() . ' WHERE transfer_id=%d',
                (int) $row->id
            ));
            if ($remaining_chunks > 0) {
                $retained = true;
                $messages[] = 'Some encrypted transfer chunks could not yet be physically removed; access is already revoked and cleanup will retry.';
            } else {
                $removed = true;
            }
        }

        // Recipient-side participation can be erased independently of sender-owned
        // objects. Do it once, check the write result, and never report successful
        // erasure when the canonical relationship mutation failed.
        if ($page === 1) {
            $recipient_update = $wpdb->query($wpdb->prepare(
                "UPDATE " . self::recipients_table() . " SET state='erased',revoked_at=COALESCE(revoked_at,%s),updated_at=%s WHERE user_id=%d AND state<>'erased'",
                $now,
                $now,
                $uid
            ));
            if ($recipient_update === false) {
                $retained = true;
                $messages[] = 'Recipient-side transfer participation could not be erased safely and was retained for retry.';
                SN_DB::audit('file_transfer_privacy_recipient_erase_failed', 'user', $uid, 'failure', [], $uid);
            } elseif ($recipient_update > 0) {
                $removed = true;
            }
        }

        // Integrity/legal-hold/abuse records may remain by policy even after the
        // user-facing transfer access and encrypted bytes are removed.
        $retained = true;
        $messages[] = 'Minimum integrity, legal-hold and abuse evidence may be retained under the approved retention policy.';

        return [
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => array_values(array_unique($messages)),
            'done' => count($sessions) < $page_size,
        ];
    }

    private static function sessions_table(): string { return SN_DB::table('transfer_sessions'); }

    private static function chunks_table(): string { return SN_DB::table('transfer_chunks'); }

    private static function recipients_table(): string { return SN_DB::table('transfer_recipients'); }
}
