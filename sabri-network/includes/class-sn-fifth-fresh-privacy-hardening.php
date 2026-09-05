<?php
/** Fifth fresh review: progress-safe privacy erasers for File-17 extension domains. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fifth_Fresh_Privacy_Hardening {
    private const BATCH = 100;

    public static function register(): void {
        // Run after all native/extension registrations and the legacy override, but
        // before SN_Privacy_Runtime_Hardening::guard_all_erasers at priority 9999.
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_erasers'], 9500);
    }

    public static function override_erasers(array $erasers): array {
        $map = [
            'sabri-network-future' => 'erase_future',
            'sabri-network-smail' => 'erase_smail',
            'sabri-network-presence-devices' => 'erase_presence',
            'sabri-network-transfers' => 'erase_transfers',
        ];
        foreach ($map as $key => $method) {
            if (isset($erasers[$key])) $erasers[$key]['callback'] = [self::class, $method];
        }
        return $erasers;
    }

    public static function erase_future(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $records = $wpdb->prefix . 'sn_future_records';
        $versions = $wpdb->prefix . 'sn_future_message_versions';
        $messages = SN_DB::table('messages');
        $removed = 0;
        $retained = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $records WHERE owner_id=%d AND feature_id IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')",
            $uid
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') ORDER BY id ASC LIMIT %d",
            $uid, self::BATCH
        ));
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Future-capability erasure could not start.');
        try {
            foreach (is_array($rows) ? $rows : [] as $row) {
                $changed = $wpdb->update($records, [
                    'payload_cipher' => null,
                    'state' => 'erased',
                    'updated_at' => current_time('mysql', true),
                ], ['id'=>(int)$row->id,'owner_id'=>$uid], [null,'%s','%s'], ['%d','%d']);
                if ($changed !== 1) throw new RuntimeException('future_record_erase_conflict');
                $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('future_record_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::retry('Future-capability erasure could not be committed.');
        }

        // Message-version holds are callback-defined, so use a per-user monotonic scan
        // cursor rather than repeatedly selecting the first retained rows forever.
        $cursor_key = 'sn_privacy_future_version_cursor_' . $uid;
        $cursor = max(0, (int)get_option($cursor_key, 0));
        $scan = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.message_id FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d AND v.id>%d ORDER BY v.id ASC LIMIT %d",
            $uid, $cursor, 200
        ));
        foreach (is_array($scan) ? $scan : [] as $version) {
            $vid = (int)$version->id;
            update_option($cursor_key, $vid, false);
            if ((bool)apply_filters('sn_network_message_version_hold', false, (int)$version->message_id, $uid)) {
                $retained++;
                continue;
            }
            $deleted = $wpdb->delete($versions, ['id'=>$vid], ['%d']);
            if ($deleted === false) return self::retry('Message-version privacy erasure must be retried.');
            if ($deleted === 1) $removed++;
        }
        $more_versions = count(is_array($scan) ? $scan : []) === 200;
        if (!$more_versions) delete_option($cursor_key);
        $more_records = (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') LIMIT 1",
            $uid
        ));
        return [
            'items_removed'=>$removed>0,
            'items_retained'=>$retained>0,
            'messages'=>$retained>0 ? ['Governed key-transparency/interoperability or held integrity evidence was retained.'] : [],
            'done'=>!$more_records && !$more_versions,
        ];
    }

    public static function erase_smail(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $states = SN_DB::table('smail_states');
        $drafts = SN_DB::table('smail_drafts');
        $wpdb->last_error = '';
        $state_raw = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $states WHERE user_id=%d ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        if ($wpdb->last_error !== '' || !is_array($state_raw)) return self::retry('Smail state privacy truth could not be read safely.');
        $state_ids = array_map('intval', $state_raw);
        $wpdb->last_error = '';
        $draft_raw = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        if ($wpdb->last_error !== '' || !is_array($draft_raw)) return self::retry('Smail draft privacy truth could not be read safely.');
        $draft_ids = array_map('intval', $draft_raw);
        if (!$state_ids && !$draft_ids) {
            return [
                'items_removed'=>false,
                'items_retained'=>true,
                'messages'=>['Canonical shared messages remain subject to File-17 conversation retention, participant rights and legal-hold policy.'],
                'done'=>true,
            ];
        }
        $now = current_time('mysql', true);
        $empty_hash = hash_hmac('sha256', '', wp_salt('auth') . '|sn-sm-draft-blind-v1');
        $removed = 0;
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Smail privacy erasure could not start.');
        try {
            foreach ($state_ids as $id) {
                $deleted = $wpdb->delete($states, ['id'=>$id,'user_id'=>$uid], ['%d','%d']);
                if ($deleted !== 1) throw new RuntimeException('smail_state_erase_failed');
                $removed++;
            }
            foreach ($draft_ids as $id) {
                $changed = $wpdb->query($wpdb->prepare(
                    "UPDATE $drafts SET encrypted_payload='',payload_hash=%s,deleted_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND owner_id=%d AND deleted_at IS NULL",
                    $empty_hash,
                    $now,
                    $now,
                    $id,
                    $uid
                ));
                if ($changed !== 1) throw new RuntimeException('smail_draft_erase_failed');
                $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('smail_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::retry('Smail privacy erasure could not be committed.');
        }
        $wpdb->last_error = '';
        $more_states_raw = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $states WHERE user_id=%d LIMIT 1", $uid));
        if ($wpdb->last_error !== '') return self::retry('Smail state privacy completion could not be verified safely.');
        $wpdb->last_error = '';
        $more_drafts_raw = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1", $uid));
        if ($wpdb->last_error !== '') return self::retry('Smail draft privacy completion could not be verified safely.');
        $more_states = $more_states_raw !== null;
        $more_drafts = $more_drafts_raw !== null;
        return [
            'items_removed'=>$removed>0,
            'items_retained'=>true,
            'messages'=>['Canonical shared messages remain subject to File-17 conversation retention, participant rights and legal-hold policy.'],
            'done'=>!$more_states&&!$more_drafts,
        ];
    }

    public static function erase_presence(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $deleted = $wpdb->delete(SN_DB::table('presence_devices'), ['user_id'=>(int)$user->ID], ['%d']);
        if ($deleted === false) return self::retry('Presence-device erasure failed and must be retried.');
        return ['items_removed'=>$deleted>0,'items_retained'=>false,'messages'=>[],'done'=>true];
    }

    /** Use File-17's canonical transfer eraser for byte destruction, then remove personal linkage once it is complete. */
    public static function erase_transfers(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        if ((bool)apply_filters('sn_network_retention_prevents_erasure', false, $uid)) {
            return SN_File_Transfer::erase_personal_data($email, $page);
        }
        $base = SN_File_Transfer::erase_personal_data($email, $page);
        if (empty($base['done'])) return $base;

        $sessions = SN_DB::table('transfer_sessions');
        $recipients = SN_DB::table('transfer_recipients');
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,public_id FROM $sessions WHERE sender_id=%d AND status IN ('revoked','expired','rejected') ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        $recipient_rows = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $recipients WHERE user_id=%d ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        )) ?: []);
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Transfer privacy anonymization could not start.');
        $removed = 0;
        try {
            foreach (is_array($rows) ? $rows : [] as $row) {
                $blind = hash('sha256','erased-transfer|' . (int)$row->id . '|' . (string)$row->public_id);
                $changed = $wpdb->query($wpdb->prepare(
                    "UPDATE $sessions SET sender_id=0,conversation_id=0,original_name='',safe_name='',declared_mime='',detected_mime='',expected_sha256='',actual_sha256='',idempotency_key=%s,failure_code='privacy_erased',updated_at=%s,version=version+1 WHERE id=%d AND sender_id=%d AND status IN ('revoked','expired','rejected')",
                    $blind,
                    $now,
                    (int)$row->id,
                    $uid
                ));
                if ($changed !== 1) throw new RuntimeException('transfer_sender_anonymize_failed');
                $removed++;
            }
            foreach ($recipient_rows as $id) {
                $changed = $wpdb->delete($recipients,['id'=>$id,'user_id'=>$uid],['%d','%d']);
                if ($changed === false) throw new RuntimeException('transfer_recipient_erase_failed');
                if ($changed === 1) $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('transfer_privacy_anonymize_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::retry('Transfer privacy anonymization could not be committed.');
        }
        $more_sent = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $sessions WHERE sender_id=%d AND status IN ('revoked','expired','rejected') LIMIT 1",$uid));
        $more_received = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $recipients WHERE user_id=%d LIMIT 1",$uid));
        return [
            'items_removed'=>!empty($base['items_removed']) || $removed>0,
            'items_retained'=>!empty($base['items_retained']),
            'messages'=>(array)($base['messages'] ?? []),
            'done'=>!$more_sent&&!$more_received,
        ];
    }

    private static function retry(string $message): array {
        return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];
    }
    private static function done(): array {
        return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    }
}
