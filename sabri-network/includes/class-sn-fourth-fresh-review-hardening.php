<?php
/** Fourth fresh review-cycle cross-cutting authorization and mutation hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Review_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        // Runs immediately after the generic File-00/object-membership gate and still
        // before every existing side-effecting rest_pre_dispatch hook (priority 3+).
        add_filter('rest_pre_dispatch', [self::class, 'authorize_before_side_effects'], -29999, 3);
        // Last canonical route owner for defects proven in this fresh review cycle.
        add_action('rest_api_init', [self::class, 'override_routes'], 2100);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/owner', [
            'methods' => 'POST', 'callback' => [self::class, 'transfer_conversation_owner'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/messages', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_messages'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'send_message'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'edit_message'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_message'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/forward', [
            'methods' => 'POST', 'callback' => [self::class, 'forward_message'],
            'permission_callback' => [SN_REST::class, 'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/receipts', [
            ['methods' => 'GET', 'callback' => [SN_Messages::class, 'get_receipts'], 'permission_callback' => [SN_REST::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'record_receipt'], 'permission_callback' => [SN_REST::class, 'access']],
        ], true);
    }

    public static function authorize_before_side_effects($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $result;

        if (str_starts_with($route, '/sabri-network/v2/admin/')) {
            $admin = SN_REST::admin_access();
            if (is_wp_error($admin) || $admin !== true) {
                return is_wp_error($admin) ? $admin : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
            }
        }

        // Reject stale/missing edit/delete versions before Future-24 pre-dispatch can
        // capture a revision snapshot. Generic boundary policy already established
        // current conversation membership before this hook runs.
        if (preg_match('#^/sabri-network/v2/messages/(\d+)$#', $route, $match) && in_array($method, ['POST', 'DELETE'], true)) {
            $version = self::preauthorize_message_version((int) $match[1], absint($request->get_param('expected_version')));
            if (is_wp_error($version)) return $version;
        }

        $space = self::authorize_space_mutation($route, $request, get_current_user_id());
        if (is_wp_error($space)) return $space;
        return $result;
    }

    /** Require a caller-owned idempotency key; never manufacture one after an uncertain client outcome. */
    public static function send_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $client = strtolower(trim((string) $request->get_param('client_id')));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) {
            return new WP_Error('invalid_client_id', 'A caller-supplied message idempotency key is required.', ['status' => 400]);
        }
        $response = SN_Message_Runtime_Hardening::send_message($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        if (is_array($data) && isset($data['message']) && is_array($data['message'])) {
            $data['message']['version'] = self::message_version_by_id(absint($data['message']['id'] ?? 0));
            $response->set_data($data);
        }
        return $response;
    }

    /** The stronger compatibility implementation revalidates both audiences under the committing transaction. */
    public static function forward_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $client = strtolower(trim((string) $request->get_param('client_id')));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) {
            return new WP_Error('sn_forward_client_id_invalid', 'A caller-supplied forwarding idempotency key is required.', ['status' => 400]);
        }
        $response = SN_Compatibility_Hardening::secure_forward_message($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        if (is_array($data) && !empty($data['message_id'])) {
            $data['version'] = self::message_version_by_id(absint($data['message_id']));
            $response->set_data($data);
        }
        return $response;
    }

    public static function get_messages(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $response = SN_Message_Visibility::get_messages($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) return $response;
        $data['messages'] = self::attach_message_versions($data['messages']);
        $response->set_data($data);
        return $response;
    }

    public static function edit_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $expected = absint($request->get_param('expected_version'));
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if ($expected <= 0) return new WP_Error('message_version_required', 'An exact message version is required.', ['status' => 400]);
        if ($body === '' || mb_strlen($body) > 10000) return new WP_Error('invalid_message', 'Enter a valid message within the permitted length.', ['status' => 400]);
        $probe = self::message_row($id);
        if (!$probe) return self::not_found();
        $conversation = (int) $probe->conversation_id;

        return self::with_locks([self::conversation_lock($conversation)], function () use ($wpdb, $id, $actor, $expected, $body, $conversation, $request) {
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $messages = SN_DB::table('messages');
                $members = SN_DB::table('members');
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
                $member = $wpdb->get_row($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", $conversation, $actor));
                if (!$row || !$member || (int) $row->conversation_id !== $conversation || !empty($row->deleted_at)) throw new DomainException('not_found');
                if (self::message_version($row) !== $expected) throw new UnexpectedValueException('version_conflict');
                if (!SN_Policy::can_edit_message($row, $actor)) throw new UnexpectedValueException('edit_forbidden');
                $stored = SN_Message_Body::encrypt($body, $conversation, (int) $row->sender_id);
                if (is_wp_error($stored)) throw new RuntimeException($stored->get_error_code());
                $metadata = self::metadata_with_version($row, $expected + 1);
                $edited_at = current_time('mysql', true);
                $changed = $wpdb->query($wpdb->prepare("UPDATE $messages SET body=%s,metadata=%s,edited_at=%s WHERE id=%d AND deleted_at IS NULL", $stored, $metadata, $edited_at, $id));
                if ($changed !== 1) throw new RuntimeException('message_update_failed');
                $indexed = SN_Message_Search::index_message($id);
                if (is_wp_error($indexed)) throw new RuntimeException($indexed->get_error_code());
                $event = SN_Outbox::enqueue('message.edited', 'message', $id, [
                    'message_id' => $id, 'conversation_id' => $conversation, 'sender_id' => (int) $row->sender_id,
                    'version' => $expected + 1, 'revision_hash' => hash('sha256', $body),
                ], 'message.edited:' . $id . ':v' . ($expected + 1));
                if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_edit_commit_failed');
                SN_DB::audit('message_edited', 'message', $id, 'success', ['conversation_id' => $conversation, 'version' => $expected + 1], $actor);
                do_action('sn_network_event_queued', $event, 'message.edited');
                return rest_ensure_response(['message' => self::format_message(self::message_row($id), $actor)]);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                self::discard_failed_future_snapshot(absint($request->get_param('_sn_future_snapshot_id')), $id);
                if ($e instanceof DomainException) return self::not_found();
                if ($e instanceof UnexpectedValueException && $e->getMessage() === 'version_conflict') return self::version_conflict();
                if ($e instanceof UnexpectedValueException && $e->getMessage() === 'edit_forbidden') return new WP_Error('edit_forbidden', 'This message can no longer be edited.', ['status' => 403]);
                SN_DB::audit('message_atomic_edit_failed', 'message', $id, 'failure', ['reason' => $e->getMessage()], $actor);
                return new WP_Error('message_atomic_edit_failed', 'The message edit could not be committed.', ['status' => 500]);
            }
        });
    }

    public static function delete_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $expected = absint($request->get_param('expected_version'));
        if ($expected <= 0) return new WP_Error('message_version_required', 'An exact message version is required.', ['status' => 400]);
        $probe = self::message_row($id);
        if (!$probe) return self::not_found();
        $conversation = (int) $probe->conversation_id;

        return self::with_locks([self::conversation_lock($conversation)], function () use ($wpdb, $id, $actor, $expected, $conversation) {
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            $attachment = 0;
            try {
                $messages = SN_DB::table('messages');
                $members = SN_DB::table('members');
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
                $member = $wpdb->get_row($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", $conversation, $actor));
                if (!$row || !$member || (int) $row->conversation_id !== $conversation || !empty($row->deleted_at)) throw new DomainException('not_found');
                if (self::message_version($row) !== $expected) throw new UnexpectedValueException('version_conflict');
                if (!SN_Policy::can_delete_message($row, $actor)) throw new UnexpectedValueException('delete_forbidden');
                $attachment = (string) $row->attachment_source === 'private' ? (int) $row->attachment_id : 0;
                $metadata = self::metadata_with_version($row, $expected + 1);
                $deleted_at = current_time('mysql', true);
                $changed = $wpdb->query($wpdb->prepare("UPDATE $messages SET body='',attachment_id=0,attachment_source='erased',metadata=%s,deleted_at=%s WHERE id=%d AND deleted_at IS NULL", $metadata, $deleted_at, $id));
                if ($changed !== 1) throw new RuntimeException('message_delete_failed');
                if ($wpdb->delete(SN_DB::table('reactions'), ['message_id' => $id], ['%d']) === false) throw new RuntimeException('message_reaction_delete_failed');
                $removed = SN_Message_Search::remove_message($id);
                if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
                $event = SN_Outbox::enqueue('message.deleted', 'message', $id, [
                    'message_id' => $id, 'conversation_id' => $conversation, 'sender_id' => (int) $row->sender_id,
                    'deleted_by' => $actor, 'deleted_at' => $deleted_at, 'version' => $expected + 1,
                ], 'message.deleted:' . $id . ':v' . ($expected + 1));
                if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_delete_commit_failed');
                if ($attachment > 0) SN_Private_Files::delete($attachment, $actor);
                SN_DB::audit('message_deleted', 'message', $id, 'success', ['conversation_id' => $conversation, 'version' => $expected + 1], $actor);
                do_action('sn_network_event_queued', $event, 'message.deleted');
                return rest_ensure_response(['deleted' => true, 'version' => $expected + 1]);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                if ($e instanceof DomainException) return self::not_found();
                if ($e instanceof UnexpectedValueException && $e->getMessage() === 'version_conflict') return self::version_conflict();
                if ($e instanceof UnexpectedValueException && $e->getMessage() === 'delete_forbidden') return new WP_Error('delete_forbidden', 'This message can no longer be deleted.', ['status' => 403]);
                SN_DB::audit('message_atomic_delete_failed', 'message', $id, 'failure', ['reason' => $e->getMessage()], $actor);
                return new WP_Error('message_atomic_delete_failed', 'The message deletion could not be committed.', ['status' => 500]);
            }
        });
    }

    /** Serialize receipts against conversation membership changes, then reuse the bounded atomic receipt owner. */
    public static function record_receipt(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        if ($conversation <= 0) return self::not_found();
        return self::with_locks([self::conversation_lock($conversation)], static function () use ($request) {
            if (!SN_DB::is_member(absint($request['id']), get_current_user_id())) return self::not_found();
            return SN_Message_Integrity::record_receipt($request);
        });
    }

    public static function transfer_conversation_owner(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        $target = absint($request->get_param('user_id'));
        $action_id = absint($request->get_param('high_risk_action_id'));
        if ($target <= 0 || $target === $actor || !get_user_by('id', $target) || SN_Policy::is_suspended($target) || !SN_Policy::has_verified_adult_age($target)) {
            return new WP_Error('owner_ineligible', 'Select an active adult conversation member.', ['status' => 403]);
        }
        return self::with_locks([self::conversation_lock($conversation)], function () use ($wpdb, $conversation, $actor, $target, $action_id) {
            $conversations = SN_DB::table('conversations'); $members = SN_DB::table('members');
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE id=%d FOR UPDATE", $conversation));
                if (!$c || (string) $c->type === 'direct' || (string) $c->status !== 'active') throw new DomainException('invalid_conversation');
                if ((int) $c->owner_id !== $actor) throw new UnexpectedValueException('forbidden');
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $members WHERE conversation_id=%d AND user_id IN (%d,%d) FOR UPDATE", $conversation, $actor, $target));
                $map = []; foreach ($rows as $row) if ($row->left_at === null) $map[(int) $row->user_id] = $row;
                if (!isset($map[$actor]) || (string) $map[$actor]->role !== 'owner') throw new UnexpectedValueException('forbidden');
                if (!isset($map[$target])) throw new DomainException('member_required');
                if (SN_Policy::is_suspended($target) || !SN_Policy::has_verified_adult_age($target)) throw new UnexpectedValueException('owner_ineligible');
                $payload = ['conversation_id' => $conversation, 'current_owner_id' => $actor, 'new_owner_id' => $target];
                $claim = SN_High_Risk::claim($action_id, $actor, 'conversation_ownership_transfer', $payload);
                if (is_wp_error($claim)) { $wpdb->query('ROLLBACK'); return $claim; }
                $now = current_time('mysql', true);
                if ($wpdb->query($wpdb->prepare("UPDATE $members SET role='moderator' WHERE id=%d AND role='owner'", (int) $map[$actor]->id)) !== 1) throw new RuntimeException('old_owner_update_failed');
                if ($wpdb->query($wpdb->prepare("UPDATE $members SET role='owner' WHERE id=%d", (int) $map[$target]->id)) !== 1) throw new RuntimeException('new_owner_update_failed');
                if ($wpdb->query($wpdb->prepare("UPDATE $conversations SET owner_id=%d,updated_at=%s WHERE id=%d AND owner_id=%d", $target, $now, $conversation, $actor)) !== 1) throw new RuntimeException('conversation_owner_update_failed');
                $event = SN_Outbox::enqueue('conversation.ownership_transferred', 'conversation', $conversation, ['conversation_id'=>$conversation,'former_owner_id'=>$actor,'new_owner_id'=>$target], 'conversation.ownership_transferred:' . $conversation . ':' . $target . ':' . $now);
                if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                $completed = SN_High_Risk::complete($action_id, $actor, (string) $claim['claim_token'], ['conversation_id'=>$conversation,'new_owner_id'=>$target]);
                if (is_wp_error($completed)) throw new RuntimeException($completed->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('owner_commit_failed');
                SN_DB::audit('conversation_owner_transferred','conversation',$conversation,'success',['from'=>$actor,'to'=>$target,'high_risk_action_id'=>$action_id],$actor);
                do_action('sn_network_event_queued',$event,'conversation.ownership_transferred');
                $forward = new WP_REST_Request('GET','/sabri-network/v2/conversations/'.$conversation); $forward->set_url_params(['id'=>$conversation]);
                return SN_REST::get_conversation($forward);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                return match ($e->getMessage()) {
                    'invalid_conversation' => new WP_Error('invalid_conversation','Ownership cannot be transferred for this conversation.',['status'=>400]),
                    'forbidden' => new WP_Error('forbidden','Only the current conversation owner may execute an approved ownership transfer.',['status'=>403]),
                    'member_required' => new WP_Error('member_required','The new owner must be an active conversation member.',['status'=>409]),
                    'owner_ineligible' => new WP_Error('owner_ineligible','The selected member must remain an active verified adult.',['status'=>403]),
                    default => self::database_error(),
                };
            }
        });
    }

    private static function preauthorize_message_version(int $message_id, int $expected): bool|WP_Error {
        if ($expected <= 0) return new WP_Error('message_version_required', 'An exact message version is required.', ['status' => 400]);
        $row = self::message_row($message_id);
        if (!$row || !empty($row->deleted_at)) return self::not_found();
        return self::message_version($row) === $expected ? true : self::version_conflict();
    }

    private static function attach_message_versions(array $items): array {
        global $wpdb;
        $ids = [];
        foreach ($items as $item) {
            $id = is_array($item) ? absint($item['id'] ?? 0) : (is_object($item) ? absint($item->id ?? 0) : 0);
            if ($id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) return $items;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,metadata FROM ' . SN_DB::table('messages') . " WHERE id IN ($placeholders)", ...$ids));
        $versions = [];
        foreach (is_array($rows) ? $rows : [] as $row) $versions[(int) $row->id] = self::message_version($row);
        foreach ($items as &$item) {
            $id = is_array($item) ? absint($item['id'] ?? 0) : (is_object($item) ? absint($item->id ?? 0) : 0);
            if ($id <= 0 || !isset($versions[$id])) continue;
            if (is_array($item)) $item['version'] = $versions[$id]; else $item->version = $versions[$id];
        }
        unset($item);
        return $items;
    }

    private static function message_version_by_id(int $id): int {
        $row = $id > 0 ? self::message_row($id) : null;
        return $row ? self::message_version($row) : 1;
    }

    private static function message_version(object $row): int {
        $metadata = json_decode((string) ($row->metadata ?? ''), true);
        return max(1, is_array($metadata) ? absint($metadata['_mutation_version'] ?? 1) : 1);
    }

    private static function metadata_with_version(object $row, int $version): string {
        $metadata = json_decode((string) ($row->metadata ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        $metadata['_mutation_version'] = max(1, $version);
        return (string) wp_json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function format_message(?object $row, int $viewer): array {
        if (!$row) return [];
        $sender = SN_Auth::public_user((int) $row->sender_id) ?: ['id'=>0,'name'=>'Unavailable account','avatar'=>SN_URL.'assets/network-default-avatar.svg'];
        $attachment = empty($row->deleted_at) && (int) $row->attachment_id > 0 && (string) $row->attachment_source === 'private' ? SN_Private_Files::formatted((int) $row->attachment_id, $viewer) : null;
        $plain = !empty($row->deleted_at) ? '' : SN_Message_Body::decrypt_row($row);
        $unavailable = is_wp_error($plain);
        return ['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'sender'=>$sender,'message_type'=>(string)$row->message_type,'body'=>$unavailable?'':(string)$plain,'body_unavailable'=>$unavailable,'attachment'=>$attachment,'reply_to'=>(int)$row->reply_to,'edited'=>(bool)$row->edited_at,'deleted'=>(bool)$row->deleted_at,'version'=>self::message_version($row),'created_at'=>(string)$row->created_at];
    }

    private static function discard_failed_future_snapshot(int $snapshot_id, int $message_id): void {
        if ($snapshot_id <= 0) return;
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'sn_future_message_versions', ['id'=>$snapshot_id,'message_id'=>$message_id], ['%d','%d']);
    }

    private static function message_row(int $id): ?object {
        global $wpdb;
        return $id > 0 ? ($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id)) ?: null) : null;
    }

    private static function conversation_lock(int $conversation): string {
        return 'sn:f17:conversation:' . substr(hash('sha256', (string) $conversation), 0, 32);
    }

    private static function with_locks(array $locks, callable $callback) {
        global $wpdb;
        $locks = array_values(array_unique(array_filter($locks))); sort($locks, SORT_STRING); $held = [];
        try {
            foreach ($locks as $lock) {
                $ok = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT));
                if ($ok !== 1) return new WP_Error('sn_realtime_busy', 'The communication state is changing. Retry the request.', ['status'=>409]);
                $held[] = $lock;
            }
            return $callback();
        } finally {
            foreach (array_reverse($held) as $lock) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function authorize_space_mutation(string $route, WP_REST_Request $request, int $actor): bool|WP_Error {
        global $wpdb;
        if (!str_contains($route, '/spaces/') && !str_contains($route, '/space-invites/')) return true;
        if ($actor <= 0) return self::not_found();
        $spaces = SN_DB::table('spaces'); $members = SN_DB::table('space_members'); $invites = SN_DB::table('space_invites');
        if (preg_match('#^/sabri-network/v2/space-invites/(\d+)$#', $route, $match)) {
            $invite = $wpdb->get_row($wpdb->prepare("SELECT id,space_id,inviter_id,invitee_id,status FROM $invites WHERE id=%d", (int) $match[1]));
            if (!$invite || (string) $invite->status !== 'pending') return self::not_found();
            $decision = sanitize_key((string) $request->get_param('decision'));
            if ($decision === 'cancel') {
                if ((int) $invite->inviter_id === $actor) return true;
                return in_array(self::space_role((int) $invite->space_id, $actor, $members), ['owner','administrator'], true) ? true : self::not_found();
            }
            return (int) $invite->invitee_id === $actor ? true : self::not_found();
        }
        if (!preg_match('#^/sabri-network/v2/spaces/(\d+)(?:/|$)#', $route, $match)) return true;
        $space_id = (int) $match[1];
        $space = $wpdb->get_row($wpdb->prepare("SELECT id,owner_user_id,visibility,state FROM $spaces WHERE id=%d", $space_id));
        if (!$space) return self::not_found();
        $role = self::space_role($space_id, $actor, $members); $member = $role !== '';
        if (preg_match('#^/sabri-network/v2/spaces/\d+/join$#', $route)) return ($member || in_array((string)$space->visibility,['public','discoverable_private'],true)) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/leave$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/community-artifacts$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/community-artifacts/\d+/respond$#', $route)) return $member ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:bans|community-artifacts/\d+/moderate)$#', $route)) return in_array($role,['owner','administrator','moderator'],true) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:join-requests/\d+|invites|members/\d+|community-settings)$#', $route)) return in_array($role,['owner','administrator'],true) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/(?:lifecycle)$#', $route) || preg_match('#^/sabri-network/v2/spaces/\d+$#', $route)) return in_array($role,['owner','administrator'],true) ? true : self::not_found();
        if (preg_match('#^/sabri-network/v2/spaces/\d+/transfer$#', $route)) {
            if (current_user_can('manage_options')) return true;
            return (bool) apply_filters('sn_network_can_execute_space_transfer', false, $actor, $space) ? true : self::not_found();
        }
        return true;
    }

    private static function space_role(int $space_id, int $actor, string $members): string {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1", $space_id, $actor));
    }

    private static function version_conflict(): WP_Error { return new WP_Error('message_version_conflict', 'The message changed. Reload the authoritative version and retry.', ['status'=>409]); }
    private static function database_error(): WP_Error { return new WP_Error('database_error', 'The Network request could not be completed safely.', ['status'=>500]); }
    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested communication object is unavailable.', ['status'=>404]); }
}
