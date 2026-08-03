<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_10 {
    public static function reserve_post_slot(int $conversation_id, int $user_id): array|WP_Error {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $space = self::space_by_conversation($conversation_id, true);
            if (!$space) {
                $wpdb->query('COMMIT');
                return ['space_id'=>0];
            }
            $member = self::member((int)$space->id, $user_id, true);
            if (!$member) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_space_membership_required','An active space membership is required.',403);
            }
            $allowed = self::can_post_locked($space, $member);
            if (is_wp_error($allowed)) {
                $wpdb->query('ROLLBACK');
                return $allowed;
            }
            $now = self::now();
            $changed = $wpdb->update(self::members_table(), [
                'last_post_at'=>$now,'updated_at'=>$now,'version'=>(int)$member->version+1,
            ], [
                'id'=>(int)$member->id,'status'=>'active','version'=>(int)$member->version,
            ]);
            if ($changed !== 1) throw new RuntimeException('space_post_reservation_conflict');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('space_post_reservation_commit_failed');
            return [
                'space_id'=>(int)$space->id,'member_id'=>(int)$member->id,
                'reserved_at'=>$now,'previous_last_post_at'=>$member->last_post_at ? (string)$member->last_post_at : null,
                'version'=>(int)$member->version+1,
            ];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::error('sn_space_post_reservation_failed','The posting slot could not be reserved safely.',409);
        }
    }

    public static function release_post_slot(array $reservation): void {
        global $wpdb;
        if (empty($reservation['space_id']) || empty($reservation['member_id']) || empty($reservation['reserved_at'])) return;
        $wpdb->update(self::members_table(), [
            'last_post_at'=>$reservation['previous_last_post_at'] ?? null,
            'updated_at'=>self::now(),
            'version'=>(int)$reservation['version']+1,
        ], [
            'id'=>(int)$reservation['member_id'],
            'last_post_at'=>(string)$reservation['reserved_at'],
            'version'=>(int)$reservation['version'],
        ]);
    }
}
