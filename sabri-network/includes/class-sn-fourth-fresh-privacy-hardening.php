<?php
/** Fourth fresh cycle: native File-17 legal holds and complete extension privacy lifecycle. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Privacy_Hardening {
    public static function register(): void {
        // SN_Privacy_Runtime_Hardening already guards every File-17 eraser through
        // this filter. Provide the missing native decision instead of depending on
        // an optional external module to discover File-17's own held evidence.
        add_filter('sn_network_retention_prevents_erasure', [self::class, 'native_legal_hold'], 20, 2);
        // Complete the Two-Plan privacy surface: poll votes are personal interaction
        // data and must participate in export/erasure while legal holds remain authoritative.
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'override_two_plan_exporter'], 50);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_two_plan_eraser'], 50);
    }

    public static function native_legal_hold(bool $retained, int $user_id): bool {
        if ($retained || $user_id <= 0) return $retained;
        global $wpdb;
        $reports = SN_DB::table('reports');
        $messages = SN_DB::table('messages');
        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT r.id
             FROM $reports r
             LEFT JOIN $messages m ON m.id=r.message_id
             WHERE r.legal_hold=1
               AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d)
             LIMIT 1",
            $user_id,
            $user_id,
            $user_id
        ));
        return $held > 0;
    }

    public static function override_two_plan_exporter(array $exporters): array {
        if (isset($exporters['sabri-network-two-plan'])) {
            $exporters['sabri-network-two-plan']['callback'] = [self::class, 'export_two_plan'];
        }
        return $exporters;
    }

    public static function override_two_plan_eraser(array $erasers): array {
        if (isset($erasers['sabri-network-two-plan'])) {
            $erasers['sabri-network-two-plan']['callback'] = [self::class, 'erase_two_plan'];
        }
        return $erasers;
    }

    public static function export_two_plan(string $email, int $page = 1): array {
        $base = SN_Two_Plan_Completion::exporter($email, $page);
        $user = get_user_by('email', $email);
        if (!$user) return $base;
        global $wpdb;
        $offset = max(0, $page - 1) * 100;
        $votes = $wpdb->get_results($wpdb->prepare(
            'SELECT id,message_id,option_index,created_at,updated_at FROM ' . SN_DB::table('poll_votes') . ' WHERE user_id=%d ORDER BY id ASC LIMIT 100 OFFSET %d',
            (int)$user->ID,
            $offset
        ));
        foreach (is_array($votes) ? $votes : [] as $vote) {
            $base['data'][] = [
                'group_id' => 'sabri-network-poll-votes',
                'group_label' => 'Communication poll votes',
                'item_id' => 'poll-vote-' . (int)$vote->id,
                'data' => [
                    ['name'=>'Message ID','value'=>(int)$vote->message_id],
                    ['name'=>'Selected option','value'=>(int)$vote->option_index],
                    ['name'=>'Created','value'=>(string)$vote->created_at],
                    ['name'=>'Updated','value'=>(string)$vote->updated_at],
                ],
            ];
        }
        $base['done'] = !empty($base['done']) && count(is_array($votes) ? $votes : []) < 100;
        return $base;
    }

    public static function erase_two_plan(string $email, int $page = 1): array {
        $base = SN_Two_Plan_Completion::eraser($email, $page);
        $user = get_user_by('email', $email);
        if (!$user) return $base;
        global $wpdb;
        $uid = (int)$user->ID;
        $votes = SN_DB::table('poll_votes');
        $reports = SN_DB::table('reports');
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT pv.id FROM $votes pv
             WHERE pv.user_id=%d
               AND NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)
             ORDER BY pv.id ASC LIMIT 100",
            $uid
        )) ?: []);
        $removed = 0;
        foreach ($ids as $id) {
            if ($wpdb->delete($votes, ['id'=>$id,'user_id'=>$uid], ['%d','%d']) === 1) $removed++;
        }
        $held = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $votes pv WHERE pv.user_id=%d AND EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)",
            $uid
        ));
        $base['items_removed'] = !empty($base['items_removed']) || $removed > 0;
        $base['items_retained'] = !empty($base['items_retained']) || $held > 0;
        if ($held > 0) $base['messages'][] = 'Some communication poll votes are retained under an active safety/legal hold.';
        $base['done'] = !empty($base['done']) && count($ids) < 100;
        return $base;
    }
}
