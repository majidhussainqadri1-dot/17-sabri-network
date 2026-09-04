<?php
/** Next fresh corrective owner for active message-organization mutations. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Next_Message_Operations_Hardening {
    public static function register(): void {
        // Run after the base message-organization routes so these two mutation
        // contracts become the final active owners without creating a second backend.
        add_action('rest_api_init', [self::class, 'override_routes'], 2350);
    }

    public static function override_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/mentions', [
            'methods' => 'POST',
            'callback' => [self::class, 'set_mentions'],
            'permission_callback' => $access,
        ], true);

        // Preserve the existing PATCH behavior while replacing only the unsafe
        // DELETE mutation on this shared route.
        register_rest_route('sabri-network/v2', '/message-folders/(?P<id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => [SN_Message_Operations::class, 'update_folder'],
                'permission_callback' => $access,
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'delete_folder'],
                'permission_callback' => $access,
            ],
        ], true);
    }

    public static function set_mentions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $ids = array_values(array_unique(array_filter(array_map('absint', is_array($request->get_param('user_ids')) ? $request->get_param('user_ids') : []))));
        if (count($ids) > 20) return self::error('sn_mentions_too_many', 'Too many mentions were requested.', 413);
        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_mentions_transaction_failed', 'The mention change could not start safely.', 500);

        try {
            $message = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d FOR UPDATE', $id));
            if (!$message || !SN_DB::is_member((int) $message->conversation_id, $actor)) {
                $wpdb->query('ROLLBACK');
                return self::not_found();
            }
            if ((int) $message->sender_id !== $actor) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_mentions_author_required', 'Only the message author may set mentions.', 403);
            }
            if (!SN_Policy::can_edit_message($message, $actor)) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_mentions_edit_window_closed', 'Mentions can be changed only during the message edit window.', 409);
            }
            if ($message->deleted_at) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_message_deleted', 'Deleted messages cannot mention members.', 409);
            }

            foreach ($ids as $user) {
                if ($user === $actor || !SN_DB::is_member((int) $message->conversation_id, $user)) {
                    $wpdb->query('ROLLBACK');
                    return self::error('sn_mention_member_invalid', 'Every mentioned account must be an active conversation member.', 400);
                }
                if (SN_DB::is_blocked($actor, $user)) {
                    $wpdb->query('ROLLBACK');
                    return self::error('sn_mention_blocked', 'A mentioned account is unavailable.', 403);
                }
            }

            if ($wpdb->delete(SN_DB::table('message_mentions'), ['message_id' => $id]) === false) throw new RuntimeException('mention_reset_failed');
            $now = current_time('mysql', true);
            foreach ($ids as $user) {
                if ($wpdb->insert(SN_DB::table('message_mentions'), [
                    'message_id' => $id,
                    'conversation_id' => (int) $message->conversation_id,
                    'mentioned_user_id' => $user,
                    'mentioned_by' => $actor,
                    'created_at' => $now,
                ]) === false) throw new RuntimeException('mention_insert_failed');
            }

            $event = SN_Outbox::enqueue(
                'message.mentions_updated',
                'message',
                $id,
                ['message_id' => $id, 'conversation_id' => (int) $message->conversation_id, 'mention_count' => count($ids)],
                'message.mentions_updated:'.$id.':'.hash('sha256', implode(',', $ids))
            );
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_mentions_updated', 'message', $id, 'success', ['mention_count' => count($ids)], $actor);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('mention_commit_failed');
            return rest_ensure_response(['message_id' => $id, 'mentioned_user_ids' => $ids]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::error('sn_mentions_failed', 'The mentions could not be committed.', 500);
        }
    }

    public static function delete_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $user = get_current_user_id();
        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_folder_transaction_failed', 'The folder deletion could not start safely.', 500);

        try {
            $folder = $wpdb->get_row($wpdb->prepare('SELECT id FROM '.SN_DB::table('message_folders').' WHERE id=%d AND user_id=%d FOR UPDATE', $id, $user));
            if (!$folder) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_folder_missing', 'The folder is unavailable.', 404);
            }
            if ($wpdb->delete(SN_DB::table('message_folder_items'), ['folder_id' => $id, 'user_id' => $user]) === false) throw new RuntimeException('folder_items_delete_failed');
            if ($wpdb->delete(SN_DB::table('message_folders'), ['id' => $id, 'user_id' => $user]) !== 1) throw new RuntimeException('folder_delete_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('folder_delete_commit_failed');
            return rest_ensure_response(['deleted' => true]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::error('sn_folder_delete_failed', 'The folder could not be deleted.', 500);
        }
    }

    private static function not_found(): WP_Error {
        return self::error('sn_message_operation_not_found', 'The requested message or conversation is unavailable.', 404);
    }

    private static function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
