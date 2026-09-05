<?php
/** Round-7 final privacy retry/completion-truth hardening for extension erasers. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_R7_Privacy_Hardening {
    private const BATCH = 100;

    public static function register(): void {
        // Run after all domain-specific privacy overrides but before the existing
        // priority-9999 File-17 wrapper. This layer replaces only callbacks whose
        // current completion/retry truth was proven unsafe in Round 7.
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_erasers'], 9800);
    }

    public static function override_erasers(array $erasers): array {
        $map = [
            'sabri-meet' => 'erase_meet',
            'sabri-network-message-receipts' => 'erase_message_receipts',
            'sabri-network-message-organization' => 'erase_message_organization',
            'sabri-network-two-plan' => 'erase_two_plan',
        ];
        foreach ($map as $key => $method) {
            if (isset($erasers[$key])) $erasers[$key]['callback'] = [self::class, $method];
        }
        return $erasers;
    }

    /** Repair the historical Meet callback's done=true operational-failure receipt. */
    public static function erase_meet(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        if ((bool)apply_filters('sn_network_retention_prevents_erasure', false, $uid)) {
            return [
                'items_removed'=>false,
                'items_retained'=>true,
                'messages'=>[__('Sabri Meet data is retained under an approved legal or safety hold.','sabri-network')],
                'done'=>true,
            ];
        }
        $result = SN_Meet::privacy_erase($email, $page);
        if (!is_array($result)) return self::retry('Sabri Meet erasure returned an invalid result and must be retried.');
        if (empty($result['items_removed']) && !empty($result['items_retained'])) {
            $text = strtolower(implode(' ', array_map('strval', (array)($result['messages'] ?? []))));
            if (str_contains($text, 'must be retried') || str_contains($text, 'could not start') || str_contains($text, 'erasure failed')) {
                $result['done'] = false;
            }
        }
        return $result;
    }

    public static function erase_message_receipts(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $table = SN_DB::table('message_receipts');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d ORDER BY id ASC LIMIT 500", $uid));
        if (!is_array($ids)) return self::retry('Message receipts could not be read safely and must be retried.');
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (!$ids) return self::done();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($placeholders) AND user_id=%d", ...array_merge($ids, [$uid])));
        if ($deleted === false) return self::retry('Message receipts could not be erased and must be retried.');
        $remaining = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id=%d", $uid));
        return [
            'items_removed'=>$deleted > 0,
            'items_retained'=>$remaining > 0,
            'messages'=>[],
            'done'=>$remaining === 0,
        ];
    }

    public static function erase_message_organization(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $tables = [
            SN_DB::table('message_folder_items'),
            SN_DB::table('message_folders'),
            SN_DB::table('message_stars'),
            SN_DB::table('message_hides'),
        ];
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Message-organization erasure could not start and must be retried.');
        $removed = 0;
        try {
            foreach ($tables as $table) {
                $changed = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE user_id=%d LIMIT %d", $uid, self::BATCH));
                if ($changed === false) throw new RuntimeException('message_organization_erase_failed');
                $removed += (int)$changed;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_organization_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_organization_privacy_erase_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);
            return self::retry('Message-organization erasure could not be committed and must be retried.');
        }
        $remaining = 0;
        foreach ($tables as $table) {
            $remaining += (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id=%d", $uid));
        }
        return [
            'items_removed'=>$removed > 0,
            'items_retained'=>$remaining > 0,
            'messages'=>[],
            'done'=>$remaining === 0,
        ];
    }

    public static function erase_two_plan(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $scheduled = SN_DB::table('scheduled_messages');
        $requests = SN_DB::table('message_requests');
        $votes = SN_DB::table('poll_votes');
        $reports = SN_DB::table('reports');
        $vote_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pv.id FROM $votes pv WHERE pv.user_id=%d AND NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1) ORDER BY pv.id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        if (!is_array($vote_ids)) return self::retry('Two-Plan poll-vote erasure could not be read safely.');
        $vote_ids = array_values(array_filter(array_map('absint', $vote_ids)));
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Two-Plan privacy erasure could not start.');
        $removed = 0;
        try {
            $changed = $wpdb->query($wpdb->prepare("DELETE FROM $scheduled WHERE sender_id=%d AND status IN ('pending','cancelled','failed') LIMIT %d", $uid, self::BATCH));
            if ($changed === false) throw new RuntimeException('two_plan_scheduled_erase_failed');
            $removed += (int)$changed;
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE $requests SET body_cipher='',reason='',updated_at=%s WHERE requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>'' LIMIT %d",
                current_time('mysql', true),
                $uid,
                self::BATCH
            ));
            if ($changed === false) throw new RuntimeException('two_plan_request_erase_failed');
            $removed += (int)$changed;
            foreach ($vote_ids as $id) {
                $changed = $wpdb->delete($votes, ['id'=>$id,'user_id'=>$uid], ['%d','%d']);
                if ($changed !== 1) throw new RuntimeException('two_plan_poll_vote_erase_failed');
                $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('two_plan_privacy_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('two_plan_privacy_erase_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);
            return self::retry('Two-Plan privacy erasure could not be committed.');
        }
        $more_scheduled = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $scheduled WHERE sender_id=%d AND status IN ('pending','cancelled','failed') LIMIT 1", $uid));
        $more_requests = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $requests WHERE requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>'' LIMIT 1", $uid));
        $more_votes = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $votes pv WHERE pv.user_id=%d AND NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1) LIMIT 1", $uid));
        $held = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $votes pv WHERE pv.user_id=%d AND EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)", $uid));
        return [
            'items_removed'=>$removed > 0,
            'items_retained'=>$held > 0 || $more_scheduled || $more_requests || $more_votes,
            'messages'=>$held > 0 ? ['Some communication poll votes are retained under an active safety/legal hold.'] : [],
            'done'=>!$more_scheduled && !$more_requests && !$more_votes,
        ];
    }

    private static function retry(string $message): array {
        return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];
    }

    private static function done(): array {
        return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    }
}
