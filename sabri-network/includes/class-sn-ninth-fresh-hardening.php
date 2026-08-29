<?php
/** Ninth-fresh corrective overlays. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Ninth_Fresh_Hardening {
    private const MAX_MESSAGE_CHARS = 10000;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_message_routes'], 1950);
    }

    public static function override_message_routes(): void {
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'edit_message'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_message'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
    }

    public static function edit_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if ($body === '' || mb_strlen($body) > self::MAX_MESSAGE_CHARS) {
            return new WP_Error('invalid_message', 'Enter a valid message within the permitted length.', ['status' => 400]);
        }
        $probe = self::message($id);
        if (!$probe || !SN_DB::is_member((int) $probe->conversation_id, $actor)) return self::not_found();
        if (!SN_Policy::can_edit_message($probe, $actor)) return new WP_Error('edit_forbidden', 'This message can no longer be edited.', ['status' => 403]);
        if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
        $event = null;
        try {
            $messages = SN_DB::table('messages');
            $members = SN_DB::table('members');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
            if (!$row || $row->deleted_at !== null) throw new DomainException('not_found');
            $membership = $wpdb->get_var($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", (int) $row->conversation_id, $actor));
            if (!$membership) throw new DomainException('not_found');
            SN_Membership_Assertions::clear_cache($actor);
            $access = SN_Policy::access();
            if (is_wp_error($access)) throw new UnexpectedValueException($access->get_error_code());
            if (!SN_Policy::can_edit_message($row, $actor)) throw new UnexpectedValueException('edit_forbidden');
            $cipher = SN_Message_Body::encrypt($body, (int) $row->conversation_id, $actor);
            if (is_wp_error($cipher)) throw new RuntimeException($cipher->get_error_code());
            $now = current_time('mysql', true);
            $changed = $wpdb->query($wpdb->prepare("UPDATE $messages SET body=%s,edited_at=%s WHERE id=%d AND sender_id=%d AND deleted_at IS NULL", $cipher, $now, $id, $actor));
            if ($changed !== 1) throw new RuntimeException('message_edit_conflict');
            $indexed = SN_Message_Search::index_message($id);
            if (is_wp_error($indexed)) throw new RuntimeException($indexed->get_error_code());
            $event = SN_Outbox::enqueue('message.edited', 'message', $id, ['message_id' => $id, 'conversation_id' => (int) $row->conversation_id, 'sender_id' => $actor, 'edited_at' => $now], 'message.edited:' . $id . ':' . hash('sha256', $cipher));
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_edited', 'message', $id, 'success', ['conversation_id' => (int) $row->conversation_id], $actor);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_edit_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof DomainException) return self::not_found();
            if ($e instanceof UnexpectedValueException) return new WP_Error($e->getMessage(), 'Current authorization no longer permits this message edit.', ['status' => 403]);
            SN_DB::audit('message_edit_failed', 'message', $id, 'failure', ['reason' => $e->getMessage()], $actor);
            return self::database_error();
        }
        do_action('sn_network_event_queued', $event, 'message.edited');
        return rest_ensure_response(['message' => self::format_message(self::message($id), $actor)]);
    }

    public static function delete_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $probe = self::message($id);
        if (!$probe || !SN_DB::is_member((int) $probe->conversation_id, $actor)) return self::not_found();
        if (!SN_Policy::can_delete_message($probe, $actor)) return new WP_Error('delete_forbidden', 'This message can no longer be deleted.', ['status' => 403]);
        if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
        $attachment_id = 0;
        $event = null;
        try {
            $messages = SN_DB::table('messages');
            $members = SN_DB::table('members');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
            if (!$row || $row->deleted_at !== null) throw new DomainException('not_found');
            $membership = $wpdb->get_var($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", (int) $row->conversation_id, $actor));
            if (!$membership) throw new DomainException('not_found');
            SN_Membership_Assertions::clear_cache($actor);
            $access = SN_Policy::access();
            if (is_wp_error($access)) throw new UnexpectedValueException($access->get_error_code());
            if (!SN_Policy::can_delete_message($row, $actor)) throw new UnexpectedValueException('delete_forbidden');
            $attachment_id = (string) $row->attachment_source === 'private' ? (int) $row->attachment_id : 0;
            $now = current_time('mysql', true);
            $changed = $wpdb->query($wpdb->prepare("UPDATE $messages SET body='',attachment_id=0,attachment_source='erased',deleted_at=%s WHERE id=%d AND sender_id=%d AND deleted_at IS NULL", $now, $id, $actor));
            if ($changed !== 1) throw new RuntimeException('message_delete_conflict');
            if ($wpdb->delete(SN_DB::table('reactions'), ['message_id' => $id], ['%d']) === false) throw new RuntimeException('message_reaction_cleanup_failed');
            $removed = SN_Message_Search::remove_message($id);
            if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
            $event = SN_Outbox::enqueue('message.deleted', 'message', $id, ['message_id' => $id, 'conversation_id' => (int) $row->conversation_id, 'sender_id' => $actor, 'deleted_at' => $now], 'message.deleted:' . $id);
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_deleted', 'message', $id, 'success', ['conversation_id' => (int) $row->conversation_id], $actor);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_delete_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof DomainException) return self::not_found();
            if ($e instanceof UnexpectedValueException) return new WP_Error($e->getMessage(), 'Current authorization no longer permits this message deletion.', ['status' => 403]);
            SN_DB::audit('message_delete_failed', 'message', $id, 'failure', ['reason' => $e->getMessage()], $actor);
            return self::database_error();
        }
        if ($attachment_id > 0) SN_Private_Files::delete($attachment_id, $actor);
        do_action('sn_network_event_queued', $event, 'message.deleted');
        return rest_ensure_response(['deleted' => true]);
    }

    private static function message(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        return $row ?: null;
    }

    private static function format_message(?object $row, int $viewer): array {
        if (!$row) return [];
        global $wpdb;
        $sender = SN_Auth::public_user((int) $row->sender_id) ?: ['id' => 0, 'name' => 'Unavailable account', 'avatar' => SN_URL . 'assets/network-default-avatar.svg'];
        $plain = $row->deleted_at ? '' : SN_Message_Body::decrypt_row($row);
        $unavailable = is_wp_error($plain);
        $attachment = !$row->deleted_at && (int) $row->attachment_id > 0 && (string) $row->attachment_source === 'private' ? SN_Private_Files::formatted((int) $row->attachment_id, $viewer) : null;
        $reactions = $wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total FROM ' . SN_DB::table('reactions') . ' WHERE message_id=%d GROUP BY reaction ORDER BY reaction ASC', (int) $row->id));
        return [
            'id' => (int) $row->id,
            'conversation_id' => (int) $row->conversation_id,
            'sender' => $sender,
            'message_type' => (string) $row->message_type,
            'body' => $unavailable ? '' : (string) $plain,
            'body_unavailable' => $unavailable,
            'attachment' => $attachment,
            'reply_to' => (int) $row->reply_to,
            'reactions' => array_map(static fn($r): array => ['reaction' => (string) $r->reaction, 'count' => (int) $r->total], is_array($reactions) ? $reactions : []),
            'edited' => (bool) $row->edited_at,
            'deleted' => (bool) $row->deleted_at,
            'created_at' => (string) $row->created_at,
        ];
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested message is unavailable.', ['status' => 404]);
    }

    private static function database_error(): WP_Error {
        return new WP_Error('database_error', 'The message mutation could not be committed safely.', ['status' => 500]);
    }
}
