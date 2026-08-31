<?php
/** Seventh fresh review R15: final-order privacy lifecycle, hold scoping and erasure completeness. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Seventh_Fresh_R15_Privacy_Hardening {
    private const LOCK_TIMEOUT = 5;
    private const BATCH = 200;

    public static function register(): void {
        // Registered after the call runtime; at the same terminal priority this sees
        // the actual final Meet callback and can no longer be bypassed by replacement.
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'finalize_erasers'], PHP_INT_MAX);
    }

    public static function finalize_erasers(array $erasers): array {
        if (isset($erasers['sabri-network'])) $erasers['sabri-network']['callback'] = [self::class, 'erase_core'];
        if (isset($erasers['sabri-network-future'])) $erasers['sabri-network-future']['callback'] = [self::class, 'erase_future'];
        if (isset($erasers['sabri-network-message-organization'])) $erasers['sabri-network-message-organization']['callback'] = [self::class, 'erase_message_organization'];

        foreach ($erasers as $key => &$entry) {
            $key = (string)$key;
            if (($key !== 'sabri-meet' && !str_starts_with($key, 'sabri-network')) || !isset($entry['callback']) || !is_callable($entry['callback'])) continue;
            $callback = $entry['callback'];
            $entry['callback'] = static fn(string $email, int $page=1): array => self::run_guarded($key, $callback, $email, $page);
        }
        unset($entry);
        return $erasers;
    }

    private static function run_guarded(string $key, callable $callback, string $email, int $page): array {
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;

        // R14 removes only the old report-wide veto. Any independent account-wide
        // retention authority that remains after the full filter chain still wins.
        if ((bool)apply_filters('sn_network_retention_prevents_erasure', false, $uid)) {
            return self::retained(__('File 17 data is retained by an approved account-wide retention authority.', 'sabri-network'));
        }
        $target_hold = self::target_hold_blocks_eraser($key, $uid);
        if (is_wp_error($target_hold)) {
            return self::retry(__('Target-specific legal-hold verification failed; erasure must be retried.', 'sabri-network'));
        }
        if ($target_hold) {
            return self::retained(__('This File 17 data class is directly relevant to an active target-specific legal hold.', 'sabri-network'));
        }

        // Core already owns this exact named lock. Do not nest it; every independent
        // eraser is serialized against core and every other File-17 eraser instead.
        if ($key === 'sabri-network') {
            $result = call_user_func($callback, $email, $page);
            return self::normalize_result($key, $uid, $result);
        }

        $lock = self::privacy_lock($uid);
        if (!self::acquire($lock)) return self::retry(__('Another File 17 privacy eraser is running; retry this page.', 'sabri-network'));
        try {
            $result = call_user_func($callback, $email, $page);
            return self::normalize_result($key, $uid, $result);
        } finally {
            self::release($lock);
        }
    }

    /** Remove version rows while sender attribution still exists, before core zeroes sender_id. */
    public static function erase_core(string $email, int $page=1): array {
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $versions = self::erase_message_versions($uid);
        if (empty($versions['done'])) return $versions;
        $result = SN_Seventh_Fresh_R14_Hardening::erase_core($email, $page);
        if (!empty($versions['items_removed']) && is_array($result)) $result['items_removed'] = true;
        if (!empty($versions['items_retained']) && is_array($result)) {
            $result['items_retained'] = true;
            $result['messages'] = array_values(array_unique(array_merge((array)($result['messages']??[]), (array)($versions['messages']??[]))));
        }
        return is_array($result) ? $result : self::retry(__('Core privacy erasure returned an invalid result.', 'sabri-network'));
    }

    /** Final Future-24 eraser: device keys + owner records + pre-anonymization message versions. */
    public static function erase_future(string $email, int $page=1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $records = $wpdb->prefix . 'sn_future_records';
        $device_keys = $wpdb->prefix . 'sn_future_device_keys';
        $removed = false;
        $retained = false;
        $messages = [];

        $keys = $wpdb->get_col($wpdb->prepare("SELECT id FROM $device_keys WHERE user_id=%d ORDER BY id ASC LIMIT %d", $uid, self::BATCH));
        if (!is_array($keys) || $wpdb->last_error !== '') return self::retry(__('Future device-key erasure could not enumerate its work.', 'sabri-network'));
        foreach (array_map('absint', $keys) as $id) {
            if ($wpdb->delete($device_keys, ['id'=>$id,'user_id'=>$uid], ['%d','%d']) !== 1) return self::retry(__('Future device-key erasure must be retried.', 'sabri-network'));
            $removed = true;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') ORDER BY id ASC LIMIT %d",
            $uid, self::BATCH
        ));
        if (!is_array($rows) || $wpdb->last_error !== '') return self::retry(__('Future-capability erasure could not enumerate its work.', 'sabri-network'));
        if ($rows) {
            if ($wpdb->query('START TRANSACTION') === false) return self::retry(__('Future-capability erasure could not start.', 'sabri-network'));
            try {
                foreach ($rows as $row) {
                    $changed = $wpdb->update($records, ['payload_cipher'=>null,'state'=>'erased','updated_at'=>current_time('mysql',true)], ['id'=>(int)$row->id,'owner_id'=>$uid], [null,'%s','%s'], ['%d','%d']);
                    if ($changed !== 1) throw new RuntimeException('future_record_erase_conflict');
                    $removed = true;
                }
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('future_record_erase_commit_failed');
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                return self::retry(__('Future-capability erasure could not be committed.', 'sabri-network'));
            }
        }

        $versions = self::erase_message_versions($uid);
        if (empty($versions['done'])) return $versions;
        $removed = $removed || !empty($versions['items_removed']);
        $retained = !empty($versions['items_retained']);
        $messages = array_merge($messages, (array)($versions['messages']??[]));

        $governed = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $records WHERE owner_id=%d AND feature_id IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')", $uid
        ));
        if ($wpdb->last_error !== '') return self::retry(__('Future governed-record verification failed.', 'sabri-network'));
        if ($governed > 0) {
            $retained = true;
            $messages[] = __('Governed key-transparency or interoperability integrity records remain under their own retention rules.', 'sabri-network');
        }

        $more_keys = self::exists($device_keys, 'user_id=%d', [$uid]);
        $more_records = self::exists($records, "owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')", [$uid]);
        if (is_wp_error($more_keys) || is_wp_error($more_records)) return self::retry(__('Future erasure completion could not be verified.', 'sabri-network'));
        return ['items_removed'=>$removed,'items_retained'=>$retained,'messages'=>array_values(array_unique($messages)),'done'=>!$more_keys&&!$more_records];
    }

    /** Transactional replacement for the legacy short-circuiting organization eraser. */
    public static function erase_message_organization(string $email, int $page=1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $folders = SN_DB::table('message_folders');
        $items = SN_DB::table('message_folder_items');
        $stars = SN_DB::table('message_stars');
        $hides = SN_DB::table('message_hides');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $folders WHERE user_id=%d ORDER BY id ASC LIMIT %d", $uid, self::BATCH));
        if (!is_array($ids) || $wpdb->last_error !== '') return self::retry(__('Message-organization erasure could not enumerate folders.', 'sabri-network'));
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if ($wpdb->query('START TRANSACTION') === false) return self::retry(__('Message-organization erasure could not start.', 'sabri-network'));
        try {
            foreach ($ids as $id) {
                if ($wpdb->delete($items, ['folder_id'=>$id,'user_id'=>$uid], ['%d','%d']) === false) throw new RuntimeException('folder_item_delete_failed');
                if ($wpdb->delete($folders, ['id'=>$id,'user_id'=>$uid], ['%d','%d']) !== 1) throw new RuntimeException('folder_delete_failed');
            }
            if ($wpdb->delete($stars, ['user_id'=>$uid], ['%d']) === false) throw new RuntimeException('star_delete_failed');
            if ($wpdb->delete($hides, ['user_id'=>$uid], ['%d']) === false) throw new RuntimeException('hide_delete_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('organization_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::retry(__('Message-organization erasure could not be committed.', 'sabri-network'));
        }
        $remaining = self::message_organization_remaining($uid);
        if (is_wp_error($remaining)) return self::retry(__('Message-organization erasure completion could not be verified.', 'sabri-network'));
        return ['items_removed'=>!empty($ids),'items_retained'=>false,'messages'=>[],'done'=>!$remaining];
    }

    private static function erase_message_versions(int $uid): array {
        global $wpdb;
        $versions = $wpdb->prefix . 'sn_future_message_versions';
        $messages = SN_DB::table('messages');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.message_id FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d ORDER BY v.id ASC LIMIT %d",
            $uid, self::BATCH
        ));
        if (!is_array($rows) || $wpdb->last_error !== '') return self::retry(__('Message-version erasure could not enumerate its work.', 'sabri-network'));
        $removed = false; $retained = false;
        foreach ($rows as $row) {
            if ((bool)apply_filters('sn_network_message_version_hold', false, (int)$row->message_id, $uid)) { $retained = true; continue; }
            if ($wpdb->delete($versions, ['id'=>(int)$row->id], ['%d']) !== 1) return self::retry(__('Message-version erasure must be retried.', 'sabri-network'));
            $removed = true;
        }
        $more = (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d AND NOT EXISTS (SELECT 1 FROM ".SN_DB::table('reports')." r WHERE r.message_id=m.id AND r.legal_hold=1) LIMIT 1", $uid
        ));
        if ($wpdb->last_error !== '') return self::retry(__('Message-version erasure completion could not be verified.', 'sabri-network'));
        return ['items_removed'=>$removed,'items_retained'=>$retained,'messages'=>$retained?[__('Held message-version integrity evidence was retained.', 'sabri-network')]:[],'done'=>!$more];
    }

    private static function normalize_result(string $key, int $uid, mixed $result): array {
        if (!is_array($result)) return self::retry(__('A File 17 privacy eraser returned an invalid result.', 'sabri-network'));
        foreach (['items_removed','items_retained','done'] as $required) if (!array_key_exists($required, $result)) return self::retry(__('A File 17 privacy eraser returned an incomplete result.', 'sabri-network'));
        $result['items_removed'] = (bool)$result['items_removed'];
        $result['items_retained'] = (bool)$result['items_retained'];
        $result['done'] = (bool)$result['done'];
        $result['messages'] = array_values(array_unique(array_map('strval', (array)($result['messages']??[]))));
        if ($result['done']) {
            $remaining = self::remaining_for_key($key, $uid);
            if (is_wp_error($remaining)) return self::retry(__('Privacy erasure completion verification failed and must be retried.', 'sabri-network'));
            if ($remaining) {
                $result['done'] = false;
                $result['items_retained'] = true;
                $result['messages'][] = __('Privacy erasure reported completion while erasable user-linked rows still remain; retry is required.', 'sabri-network');
            }
        }
        return $result;
    }

    private static function remaining_for_key(string $key, int $uid): bool|WP_Error {
        global $wpdb;
        switch ($key) {
            case 'sabri-network-message-organization': return self::message_organization_remaining($uid);
            case 'sabri-network-smail':
                $states = self::exists(SN_DB::table('smail_states'), 'user_id=%d', [$uid]);
                if (is_wp_error($states) || $states) return $states;
                return self::exists(SN_DB::table('smail_drafts'), 'owner_id=%d AND deleted_at IS NULL', [$uid]);
            case 'sabri-network-spaces':
                $member = self::exists(SN_DB::table('space_members'), "user_id=%d AND status='active'", [$uid]); if (is_wp_error($member) || $member) return $member;
                $invite = self::exists(SN_DB::table('space_invites'), "(invitee_id=%d OR inviter_id=%d) AND status='pending'", [$uid,$uid]); if (is_wp_error($invite) || $invite) return $invite;
                return self::exists(SN_DB::table('space_join_requests'), "requester_id=%d AND status='pending'", [$uid]);
            case 'sabri-network-transfers':
                $sent = self::exists(SN_DB::table('transfer_sessions'), "sender_id=%d AND (status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM ".SN_DB::table('transfer_chunks')." c WHERE c.transfer_id=".SN_DB::table('transfer_sessions').".id))", [$uid]); if (is_wp_error($sent) || $sent) return $sent;
                return self::exists(SN_DB::table('transfer_recipients'), "user_id=%d AND state<>'erased'", [$uid]);
            case 'sabri-network-presence-devices': return self::exists(SN_DB::table('presence_devices'), 'user_id=%d', [$uid]);
            case 'sabri-network-message-receipts': return self::exists(SN_DB::table('message_receipts'), 'user_id=%d', [$uid]);
            case 'sabri-network-contexts': return self::exists(SN_DB::table('conversation_contexts'), 'attached_by=%d', [$uid]);
            case 'sabri-network-two-plan-idempotency': return self::exists(SN_DB::table('two_plan_idempotency'), "actor_id=%d AND state='complete'", [$uid]);
            case 'sabri-network-future':
                $keys = self::exists($wpdb->prefix.'sn_future_device_keys', 'user_id=%d', [$uid]); if (is_wp_error($keys) || $keys) return $keys;
                return self::exists($wpdb->prefix.'sn_future_records', "owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')", [$uid]);
            default: return false;
        }
    }

    private static function target_hold_blocks_eraser(string $key, int $uid): bool|WP_Error {
        $reports = SN_DB::table('reports');
        if ($key === 'sabri-network-spaces' || $key === 'sabri-network') {
            $space = self::hold_exists(
                "SELECT r.id FROM $reports r INNER JOIN ".SN_DB::table('space_members')." sm ON sm.space_id=CAST(r.target_ref AS UNSIGNED) AND sm.user_id=%d WHERE r.legal_hold=1 AND r.target_type='space' LIMIT 1",
                [$uid]
            );
            if (is_wp_error($space) || $space) return $space;
        }
        if ($key === 'sabri-network') {
            $call = self::hold_exists(
                "SELECT r.id FROM $reports r INNER JOIN ".SN_DB::table('call_members')." cm ON cm.call_id=CAST(r.target_ref AS UNSIGNED) AND cm.user_id=%d WHERE r.legal_hold=1 AND r.target_type='call' LIMIT 1",
                [$uid]
            );
            if (is_wp_error($call) || $call) return $call;
            $conversation = self::hold_exists(
                "SELECT r.id FROM $reports r INNER JOIN ".SN_DB::table('members')." m ON m.conversation_id=CAST(r.target_ref AS UNSIGNED) AND m.user_id=%d AND m.left_at IS NULL WHERE r.legal_hold=1 AND r.target_type='conversation' LIMIT 1",
                [$uid]
            );
            if (is_wp_error($conversation) || $conversation) return $conversation;
        }
        return false;
    }

    private static function hold_exists(string $sql, array $args): bool|WP_Error {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare($sql, ...$args));
        if ($wpdb->last_error !== '') return new WP_Error('privacy_hold_verification_failed', 'Target-specific legal-hold verification failed.');
        return $value !== null;
    }

    private static function message_organization_remaining(int $uid): bool|WP_Error {
        foreach ([SN_DB::table('message_folders'),SN_DB::table('message_stars'),SN_DB::table('message_hides')] as $table) {
            $left = self::exists($table, 'user_id=%d', [$uid]);
            if (is_wp_error($left) || $left) return $left;
        }
        return false;
    }

    private static function exists(string $table, string $where, array $args): bool|WP_Error {
        global $wpdb;
        $sql = "SELECT 1 FROM $table WHERE $where LIMIT 1";
        $value = $wpdb->get_var($wpdb->prepare($sql, ...$args));
        if ($wpdb->last_error !== '') return new WP_Error('privacy_verification_failed', 'A privacy completion query failed.');
        return $value !== null;
    }

    private static function acquire(string $lock): bool { global $wpdb; return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT)) === 1; }
    private static function release(string $lock): void { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    private static function privacy_lock(int $uid): string { return 'sn:f17:privacy:' . $uid; }
    private static function retry(string $message): array { return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false]; }
    private static function retained(string $message): array { return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>true]; }
    private static function done(): array { return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true]; }
}
