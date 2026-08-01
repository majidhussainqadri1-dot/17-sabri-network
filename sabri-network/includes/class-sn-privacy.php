<?php
defined('ABSPATH') || exit;

/** WordPress personal-data export and erasure integration for File 17. */
final class SN_Privacy {
    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
    }

    public static function exporters(array $exporters): array {
        $exporters['sabri-network'] = [
            'exporter_friendly_name' => __('Sabri Network and Messages', 'sabri-network'),
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    public static function erasers(array $erasers): array {
        $erasers['sabri-network'] = [
            'eraser_friendly_name' => __('Sabri Network and Messages', 'sabri-network'),
            'callback' => [self::class, 'erase'],
        ];
        return $erasers;
    }

    public static function export(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $user_id = (int) $user->ID;
        $limit = 100;
        $offset = max(0, $page - 1) * $limit;
        $data = [];

        $messages = $wpdb->get_results($wpdb->prepare(
            'SELECT id,conversation_id,message_type,body,edited_at,deleted_at,created_at FROM ' . SN_DB::table('messages') . ' WHERE sender_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ));
        foreach ($messages as $message) {
            $data[] = [
                'group_id' => 'sabri-network-messages',
                'group_label' => __('Network messages', 'sabri-network'),
                'item_id' => 'message-' . (int) $message->id,
                'data' => [
                    ['name' => __('Conversation ID', 'sabri-network'), 'value' => (int) $message->conversation_id],
                    ['name' => __('Message type', 'sabri-network'), 'value' => (string) $message->message_type],
                    ['name' => __('Message', 'sabri-network'), 'value' => (string) $message->body],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $message->created_at],
                    ['name' => __('Edited', 'sabri-network'), 'value' => (string) $message->edited_at],
                    ['name' => __('Deleted', 'sabri-network'), 'value' => (string) $message->deleted_at],
                ],
            ];
        }

        $updates = $wpdb->get_results($wpdb->prepare(
            'SELECT id,body,privacy,expires_at,created_at FROM ' . SN_DB::table('updates') . ' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ));
        foreach ($updates as $update) {
            $data[] = [
                'group_id' => 'sabri-network-updates',
                'group_label' => __('Network updates', 'sabri-network'),
                'item_id' => 'update-' . (int) $update->id,
                'data' => [
                    ['name' => __('Update', 'sabri-network'), 'value' => (string) $update->body],
                    ['name' => __('Visibility', 'sabri-network'), 'value' => (string) $update->privacy],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $update->created_at],
                    ['name' => __('Expires', 'sabri-network'), 'value' => (string) $update->expires_at],
                ],
            ];
        }

        $contacts = $wpdb->get_results($wpdb->prepare(
            'SELECT id,user_id,contact_user_id,requested_by,status,created_at,updated_at FROM ' . SN_DB::table('contacts') . ' WHERE user_id=%d OR contact_user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $user_id,
            $user_id,
            $limit,
            $offset
        ));
        foreach ($contacts as $contact) {
            $other_id = (int) $contact->user_id === $user_id ? (int) $contact->contact_user_id : (int) $contact->user_id;
            $data[] = [
                'group_id' => 'sabri-network-contacts',
                'group_label' => __('Network contacts', 'sabri-network'),
                'item_id' => 'contact-' . (int) $contact->id,
                'data' => [
                    ['name' => __('Other member ID', 'sabri-network'), 'value' => $other_id],
                    ['name' => __('Requested by this account', 'sabri-network'), 'value' => (int) $contact->requested_by === $user_id ? __('Yes', 'sabri-network') : __('No', 'sabri-network')],
                    ['name' => __('Status', 'sabri-network'), 'value' => (string) $contact->status],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $contact->created_at],
                    ['name' => __('Updated', 'sabri-network'), 'value' => (string) $contact->updated_at],
                ],
            ];
        }

        $memberships = $wpdb->get_results($wpdb->prepare(
            'SELECT conversation_id,role,last_read_message_id,is_muted,is_archived,joined_at,left_at FROM ' . SN_DB::table('members') . ' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ));
        foreach ($memberships as $membership) {
            $data[] = [
                'group_id' => 'sabri-network-memberships',
                'group_label' => __('Network conversation memberships', 'sabri-network'),
                'item_id' => 'membership-' . (int) $membership->conversation_id,
                'data' => [
                    ['name' => __('Conversation ID', 'sabri-network'), 'value' => (int) $membership->conversation_id],
                    ['name' => __('Role', 'sabri-network'), 'value' => (string) $membership->role],
                    ['name' => __('Last read message ID', 'sabri-network'), 'value' => (int) $membership->last_read_message_id],
                    ['name' => __('Muted', 'sabri-network'), 'value' => (int) $membership->is_muted ? __('Yes', 'sabri-network') : __('No', 'sabri-network')],
                    ['name' => __('Archived', 'sabri-network'), 'value' => (int) $membership->is_archived ? __('Yes', 'sabri-network') : __('No', 'sabri-network')],
                    ['name' => __('Joined', 'sabri-network'), 'value' => (string) $membership->joined_at],
                    ['name' => __('Left', 'sabri-network'), 'value' => (string) $membership->left_at],
                ],
            ];
        }

        $calls = $wpdb->get_results($wpdb->prepare(
            'SELECT c.id,c.conversation_id,c.call_type,c.status,c.created_at,c.started_at,c.ended_at,cm.status member_status,cm.joined_at,cm.left_at FROM ' . SN_DB::table('calls') . ' c INNER JOIN ' . SN_DB::table('call_members') . ' cm ON cm.call_id=c.id WHERE cm.user_id=%d ORDER BY c.id ASC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ));
        foreach ($calls as $call) {
            $data[] = [
                'group_id' => 'sabri-network-calls',
                'group_label' => __('Network calls', 'sabri-network'),
                'item_id' => 'call-' . (int) $call->id,
                'data' => [
                    ['name' => __('Conversation ID', 'sabri-network'), 'value' => (int) $call->conversation_id],
                    ['name' => __('Call type', 'sabri-network'), 'value' => (string) $call->call_type],
                    ['name' => __('Call status', 'sabri-network'), 'value' => (string) $call->status],
                    ['name' => __('Member status', 'sabri-network'), 'value' => (string) $call->member_status],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $call->created_at],
                    ['name' => __('Started', 'sabri-network'), 'value' => (string) $call->started_at],
                    ['name' => __('Ended', 'sabri-network'), 'value' => (string) $call->ended_at],
                ],
            ];
        }

        $reports = $wpdb->get_results($wpdb->prepare(
            'SELECT id,reported_user_id,conversation_id,message_id,category,details,evidence,status,legal_hold,retention_until,anonymized_at,version,created_at,updated_at FROM ' . SN_DB::table('reports') . ' WHERE reporter_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ));
        foreach ($reports as $report) {
            $data[] = [
                'group_id' => 'sabri-network-reports',
                'group_label' => __('Network reports submitted', 'sabri-network'),
                'item_id' => 'report-' . (int) $report->id,
                'data' => [
                    ['name' => __('Reported member ID', 'sabri-network'), 'value' => (int) $report->reported_user_id],
                    ['name' => __('Conversation ID', 'sabri-network'), 'value' => (int) $report->conversation_id],
                    ['name' => __('Message ID', 'sabri-network'), 'value' => (int) $report->message_id],
                    ['name' => __('Category', 'sabri-network'), 'value' => (string) $report->category],
                    ['name' => __('Details', 'sabri-network'), 'value' => (string) $report->details],
                    ['name' => __('Evidence', 'sabri-network'), 'value' => (string) $report->evidence],
                    ['name' => __('Status', 'sabri-network'), 'value' => (string) $report->status],
                    ['name' => __('Legal or safety hold', 'sabri-network'), 'value' => (int) $report->legal_hold ? __('Yes', 'sabri-network') : __('No', 'sabri-network')],
                    ['name' => __('Retention deadline', 'sabri-network'), 'value' => (string) $report->retention_until],
                    ['name' => __('Anonymized', 'sabri-network'), 'value' => (string) $report->anonymized_at],
                    ['name' => __('Record version', 'sabri-network'), 'value' => (int) $report->version],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $report->created_at],
                ],
            ];
        }

        $notifications = $wpdb->get_results($wpdb->prepare(
            'SELECT id,type,title,body,entity_type,entity_id,is_read,created_at FROM ' . SN_DB::table('notifications') . ' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ));
        foreach ($notifications as $notification) {
            $data[] = [
                'group_id' => 'sabri-network-notifications',
                'group_label' => __('Network notifications', 'sabri-network'),
                'item_id' => 'notification-' . (int) $notification->id,
                'data' => [
                    ['name' => __('Type', 'sabri-network'), 'value' => (string) $notification->type],
                    ['name' => __('Title', 'sabri-network'), 'value' => (string) $notification->title],
                    ['name' => __('Body', 'sabri-network'), 'value' => (string) $notification->body],
                    ['name' => __('Related item', 'sabri-network'), 'value' => (string) $notification->entity_type . ':' . (int) $notification->entity_id],
                    ['name' => __('Read', 'sabri-network'), 'value' => (int) $notification->is_read ? __('Yes', 'sabri-network') : __('No', 'sabri-network')],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $notification->created_at],
                ],
            ];
        }

        if ($page === 1) {
            $privacy = SN_Policy::privacy_for($user_id);
            $presence = $wpdb->get_row($wpdb->prepare(
                'SELECT status,last_seen_at,expires_at,updated_at FROM ' . SN_DB::table('presence') . ' WHERE user_id=%d',
                $user_id
            ));
            $preference_data = [
                ['name' => __('Privacy preferences', 'sabri-network'), 'value' => wp_json_encode($privacy)],
            ];
            if ($presence) {
                $preference_data[] = ['name' => __('Presence status', 'sabri-network'), 'value' => (string) $presence->status];
                $preference_data[] = ['name' => __('Last seen', 'sabri-network'), 'value' => (string) $presence->last_seen_at];
                $preference_data[] = ['name' => __('Presence expiry', 'sabri-network'), 'value' => (string) $presence->expires_at];
            }
            $data[] = [
                'group_id' => 'sabri-network-preferences',
                'group_label' => __('Network preferences and presence', 'sabri-network'),
                'item_id' => 'preferences-' . $user_id,
                'data' => $preference_data,
            ];
        }
        $done = max(count($messages), count($updates), count($contacts), count($memberships), count($calls), count($reports), count($notifications)) < $limit;
        return ['data' => $data, 'done' => $done];
    }

    public static function erase(string $email_address, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $user_id = (int) $user->ID;
        if ((bool) apply_filters('sn_network_retention_prevents_erasure', false, $user_id)) {
            return [
                'items_removed' => false,
                'items_retained' => true,
                'messages' => [__('Some Network data is retained under an approved legal or safety hold.', 'sabri-network')],
                'done' => true,
            ];
        }

        $limit = 100;
        $report_erasure = ['redacted' => 0, 'retained' => 0];
        $messages = $wpdb->get_results($wpdb->prepare(
            'SELECT id,attachment_id,attachment_source FROM ' . SN_DB::table('messages') . ' WHERE sender_id=%d ORDER BY id ASC LIMIT %d',
            $user_id,
            $limit
        ));
        foreach ($messages as $message) {
            if ((string) $message->attachment_source === 'private' && (int) $message->attachment_id) {
                SN_Private_Files::delete((int) $message->attachment_id, $user_id);
            }
            $wpdb->update(SN_DB::table('messages'), [
                'sender_id' => 0,
                'body' => '',
                'attachment_id' => 0,
                'attachment_source' => 'erased',
                'metadata' => wp_json_encode(['erased' => true]),
                'deleted_at' => current_time('mysql', true),
            ], ['id' => (int) $message->id]);
        }

        if ($page === 1) {
            $updates = $wpdb->get_results($wpdb->prepare('SELECT id,media_id,media_source FROM ' . SN_DB::table('updates') . ' WHERE user_id=%d', $user_id));
            $update_ids = [];
            foreach ($updates as $update) {
                $update_ids[] = (int) $update->id;
                if ((string) $update->media_source === 'private' && (int) $update->media_id) {
                    SN_Private_Files::delete((int) $update->media_id, $user_id);
                }
            }
            self::delete_ids(SN_DB::table('update_views'), 'update_id', $update_ids);
            $wpdb->delete(SN_DB::table('updates'), ['user_id' => $user_id], ['%d']);

            $conversation_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('members') . ' WHERE user_id=%d', $user_id)));
            if ($conversation_ids) {
                $placeholders = implode(',', array_fill(0, count($conversation_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    'UPDATE ' . SN_DB::table('conversations') . " SET status='archived',updated_at=%s WHERE type='direct' AND id IN ($placeholders)",
                    current_time('mysql', true),
                    ...$conversation_ids
                ));
            }
            $wpdb->delete(SN_DB::table('typing'), ['user_id' => $user_id], ['%d']);
            $wpdb->delete(SN_DB::table('presence'), ['user_id' => $user_id], ['%d']);
            $wpdb->delete(SN_DB::table('members'), ['user_id' => $user_id], ['%d']);
            $wpdb->update(SN_DB::table('conversations'), ['owner_id' => 0, 'status' => 'archived', 'updated_at' => current_time('mysql', true)], ['owner_id' => $user_id], ['%d', '%s', '%s'], ['%d']);

            $call_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT call_id FROM ' . SN_DB::table('call_members') . ' WHERE user_id=%d', $user_id)));
            if ($call_ids) {
                $placeholders = implode(',', array_fill(0, count($call_ids), '%d'));
                $direct_call_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    'SELECT c.id FROM ' . SN_DB::table('calls') . ' c INNER JOIN ' . SN_DB::table('conversations') . " cv ON cv.id=c.conversation_id AND cv.type='direct' WHERE c.id IN ($placeholders)",
                    ...$call_ids
                )));
                if ($direct_call_ids) {
                    $direct_placeholders = implode(',', array_fill(0, count($direct_call_ids), '%d'));
                    $now = current_time('mysql', true);
                    $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('calls') . " SET status='ended',active_key=NULL,ended_at=%s WHERE id IN ($direct_placeholders) AND status IN ('ringing','active')", $now, ...$direct_call_ids));
                    $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('call_members') . " SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id IN ($direct_placeholders) AND status IN ('invited','joined')", $now, ...$direct_call_ids));
                }
            }
            $wpdb->delete(SN_DB::table('call_members'), ['user_id' => $user_id], ['%d']);
            $wpdb->update(SN_DB::table('calls'), ['initiator_id' => 0], ['initiator_id' => $user_id], ['%d'], ['%d']);

            $wpdb->query($wpdb->prepare('DELETE FROM ' . SN_DB::table('contacts') . ' WHERE user_id=%d OR contact_user_id=%d', $user_id, $user_id));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . SN_DB::table('blocks') . ' WHERE user_id=%d OR blocked_user_id=%d', $user_id, $user_id));
            $wpdb->delete(SN_DB::table('reactions'), ['user_id' => $user_id], ['%d']);
            $wpdb->delete(SN_DB::table('update_views'), ['viewer_id' => $user_id], ['%d']);
            $wpdb->delete(SN_DB::table('notifications'), ['user_id' => $user_id], ['%d']);
            $wpdb->delete(SN_DB::table('signals'), ['from_user_id' => $user_id], ['%d']);
            $wpdb->delete(SN_DB::table('signals'), ['to_user_id' => $user_id], ['%d']);
            $report_erasure = SN_Safety::erase_user_report_data($user_id);
            $wpdb->update(SN_DB::table('audit_log'), ['actor_id' => 0, 'context' => '{}'], ['actor_id' => $user_id], ['%d', '%s'], ['%d']);

            $attachments = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . SN_DB::table('attachments') . ' WHERE owner_id=%d AND deleted_at IS NULL', $user_id)));
            foreach ($attachments as $attachment_id) {
                SN_Private_Files::delete($attachment_id, $user_id);
            }
            delete_user_meta($user_id, 'sn_privacy');
            delete_user_meta($user_id, 'sn_about');
        }

        SN_DB::audit('privacy_erasure', 'user', $user_id, 'success', ['batch' => $page], 0);
        $items_retained = (int) $report_erasure['retained'] > 0;
        return [
            'items_removed' => true,
            'items_retained' => $items_retained,
            'messages' => $items_retained
                ? [__('Some abuse-report evidence is retained under an approved legal or safety hold; account identifiers were minimized where permitted.', 'sabri-network')]
                : [],
            'done' => count($messages) < $limit,
        ];
    }

    private static function delete_ids(string $table, string $column, array $ids): void {
        if (!$ids) {
            return;
        }
        global $wpdb;
        $ids = array_values(array_unique(array_map('absint', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE $column IN ($placeholders)", ...$ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
