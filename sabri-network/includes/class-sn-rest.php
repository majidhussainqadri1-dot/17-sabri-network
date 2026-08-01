<?php
defined('ABSPATH') || exit;

/** Versioned REST contract for File 17 — Sabri Network and Messages. */
final class SN_REST {
    private const NS = 'sabri-network/v2';
    private const MAX_MESSAGE_CHARS = 10000;
    private const MAX_UPDATE_CHARS = 5000;
    private const MAX_SIGNAL_BYTES = 65536;

    public static function register_routes(): void {
        self::route('/health', 'GET', 'health', '__return_true');
        self::route('/admin/health', 'GET', 'admin_health', [self::class, 'admin_access']);

        register_rest_route(self::NS, '/me', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_me'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'update_me'], 'permission_callback' => [self::class, 'access']],
        ]);
        register_rest_route(self::NS, '/contacts', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_contacts'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'request_contact'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/contacts/(?P<id>\d+)', 'POST', 'decide_contact');

        register_rest_route(self::NS, '/conversations', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_conversations'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_conversation'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/conversations/(?P<id>\d+)', 'GET', 'get_conversation');
        register_rest_route(self::NS, '/conversations/(?P<id>\d+)/messages', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_messages'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'send_message'], 'permission_callback' => [self::class, 'access']],
        ]);
        register_rest_route(self::NS, '/conversations/(?P<id>\d+)/members', [
            ['methods' => 'POST', 'callback' => [self::class, 'add_member'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'remove_member'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/conversations/(?P<id>\d+)/owner', 'POST', 'transfer_conversation_owner');
        self::route('/conversations/(?P<id>\d+)/read', 'POST', 'mark_read');
        self::route('/conversations/(?P<id>\d+)/preferences', 'POST', 'update_conversation_preferences');
        register_rest_route(self::NS, '/conversations/(?P<id>\d+)/typing', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_typing'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'set_typing'], 'permission_callback' => [self::class, 'access']],
        ]);

        register_rest_route(self::NS, '/messages/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'edit_message'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_message'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/messages/(?P<id>\d+)/reaction', 'POST', 'react_message');

        register_rest_route(self::NS, '/updates', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_updates'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_update'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/updates/(?P<id>\d+)/view', 'POST', 'view_update');

        self::route('/notifications', 'GET', 'get_notifications');
        self::route('/notifications/read', 'POST', 'read_notifications');
        register_rest_route(self::NS, '/presence', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_presence'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'heartbeat_presence'], 'permission_callback' => [self::class, 'access']],
        ]);

        register_rest_route(self::NS, '/calls', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_calls'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_call'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/calls/(?P<id>\d+)/status', 'POST', 'update_call_status');
        register_rest_route(self::NS, '/calls/(?P<id>\d+)/signals', [
            ['methods' => 'GET', 'callback' => [self::class, 'get_signals'], 'permission_callback' => [self::class, 'access']],
            ['methods' => 'POST', 'callback' => [self::class, 'send_signal'], 'permission_callback' => [self::class, 'access']],
        ]);
        self::route('/calls/(?P<id>\d+)/signals/ack', 'POST', 'ack_signals');

        self::route('/block', 'POST', 'block_user');
        self::route('/report', 'POST', 'report');
    }

    private static function route(string $path, string $methods, string $callback, $permission = null): void {
        register_rest_route(self::NS, $path, [
            'methods' => $methods,
            'callback' => [self::class, $callback],
            'permission_callback' => $permission ?: [self::class, 'access'],
        ]);
    }

    public static function access(): true|WP_Error {
        return SN_Policy::access();
    }

    public static function admin_access(): true|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('authentication_required', 'Sign in to view Network diagnostics.', ['status' => 401]);
        }
        return current_user_can('manage_options')
            ? true
            : new WP_Error('forbidden', 'Administrator access is required.', ['status' => 403]);
    }

    public static function health(): WP_REST_Response {
        return rest_ensure_response(['ok' => true, 'service' => 'sabri-network-messages']);
    }

    public static function admin_health(): WP_REST_Response {
        global $wpdb;
        $missing = [];
        foreach (self::required_tables() as $name) {
            $table = SN_DB::table($name);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $missing[] = $name;
            }
        }
        $storage = SN_Private_Files::ensure_storage();
        return rest_ensure_response([
            'ok' => empty($missing) && $storage,
            'version' => SN_VERSION,
            'db_version' => SN_DB::DB_VERSION,
            'missing_tables' => $missing,
            'private_storage' => $storage,
            'identity_authority' => self::identity_authority_ready(),
            'notification_adapter' => has_filter('sn_network_notification_handled'),
            'attachment_scanner' => has_filter('sn_network_attachment_scan_result'),
            'sfu_available' => (bool) apply_filters('sn_network_sfu_available', false, get_current_user_id(), 0),
            'time' => gmdate('c'),
        ]);
    }

    public static function get_me(): WP_REST_Response {
        $user_id = get_current_user_id();
        return rest_ensure_response([
            'user' => SN_Auth::public_user($user_id, true),
            'privacy' => SN_Policy::privacy_for($user_id),
            'capabilities' => self::client_capabilities($user_id),
        ]);
    }

    public static function update_me(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('preferences', (string) $user_id, 20, HOUR_IN_SECONDS)) {
            return self::rate_limited();
        }
        $privacy = $request->get_param('privacy');
        if (!is_array($privacy)) {
            return new WP_Error('invalid_privacy', 'Privacy settings are required.', ['status' => 400]);
        }
        $allowed = ['everyone', 'contacts', 'nobody'];
        $clean = [];
        foreach (array_keys(SN_Policy::privacy_for($user_id)) as $key) {
            $value = sanitize_key((string) ($privacy[$key] ?? 'contacts'));
            $clean[$key] = in_array($value, $allowed, true) ? $value : 'contacts';
        }
        if (SN_Policy::is_minor($user_id)) {
            foreach (['phone_visibility', 'last_seen', 'groups', 'calls', 'messages', 'updates'] as $key) {
                $clean[$key] = 'contacts';
            }
        }
        if (!(bool) apply_filters('sn_network_privacy_update_handled', false, $user_id, $clean)) {
            update_user_meta($user_id, 'sn_privacy', $clean);
        }
        SN_DB::audit('privacy_updated', 'user', $user_id);
        return rest_ensure_response(['privacy' => $clean]);
    }

    public static function get_contacts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SN_DB::table('contacts') . ' WHERE user_id=%d OR contact_user_id=%d ORDER BY updated_at DESC LIMIT 300',
            $user_id,
            $user_id
        ));
        $accepted = $incoming = $outgoing = [];
        foreach ($rows as $row) {
            $other_id = (int) $row->user_id === $user_id ? (int) $row->contact_user_id : (int) $row->user_id;
            $user = SN_Auth::public_user($other_id);
            if (!$user) {
                continue;
            }
            $item = ['request_id' => (int) $row->id, 'status' => (string) $row->status, 'user' => $user];
            if ((string) $row->status === 'accepted') {
                $accepted[] = $user;
            } elseif ((string) $row->status === 'pending' && (int) $row->requested_by === $user_id) {
                $outgoing[] = $item;
            } elseif ((string) $row->status === 'pending') {
                $incoming[] = $item;
            }
        }

        $directory = [];
        $search = trim(sanitize_text_field((string) $request->get_param('search')));
        if (mb_strlen($search) >= 3 && SN_Policy::consume_rate_limit('directory_search', (string) $user_id, 30, HOUR_IN_SECONDS)) {
            $directory = self::search_directory($search, $user_id, array_column($accepted, 'id'));
        }
        return rest_ensure_response(['contacts' => $accepted, 'incoming' => $incoming, 'outgoing' => $outgoing, 'directory' => $directory]);
    }

    public static function request_contact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $target_id = absint($request->get_param('user_id'));
        if (!SN_Policy::consume_rate_limit('contact_request', (string) $user_id, 20, DAY_IN_SECONDS)) {
            return self::rate_limited();
        }
        $policy = SN_Policy::can_contact($user_id, $target_id, 'request');
        if (is_wp_error($policy)) {
            return $policy;
        }
        $existing = SN_DB::contact_record($user_id, $target_id);
        if ($existing && in_array((string) $existing->status, ['accepted', 'pending', 'blocked'], true)) {
            return rest_ensure_response(['request_id' => (int) $existing->id, 'status' => (string) $existing->status]);
        }
        $now = current_time('mysql', true);
        $data = [
            'user_id' => min($user_id, $target_id),
            'contact_user_id' => max($user_id, $target_id),
            'pair_key' => SN_DB::contact_pair_key($user_id, $target_id),
            'requested_by' => $user_id,
            'status' => 'pending',
            'created_at' => $existing ? (string) $existing->created_at : $now,
            'updated_at' => $now,
        ];
        $ok = $existing
            ? $wpdb->update(SN_DB::table('contacts'), $data, ['id' => (int) $existing->id])
            : $wpdb->insert(SN_DB::table('contacts'), $data);
        if ($ok === false) {
            $race = SN_DB::contact_record($user_id, $target_id);
            if ($race) {
                return rest_ensure_response(['request_id' => (int) $race->id, 'status' => (string) $race->status, 'duplicate' => true]);
            }
            return self::database_error();
        }
        $id = $existing ? (int) $existing->id : (int) $wpdb->insert_id;
        SN_DB::add_notification($target_id, 'contact_request', 'New contact request', '', 'contact', $id);
        SN_DB::audit('contact_requested', 'contact', $id, 'success', ['target_id' => $target_id]);
        return rest_ensure_response(['request_id' => $id, 'status' => 'pending']);
    }

    public static function decide_contact(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $user_id = get_current_user_id();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('contacts') . ' WHERE id=%d', $id));
        if (!$row || (string) $row->status !== 'pending' || (int) $row->requested_by === $user_id || !in_array($user_id, [(int) $row->user_id, (int) $row->contact_user_id], true)) {
            return self::not_found();
        }
        $decision = sanitize_key((string) $request->get_param('decision'));
        if (!in_array($decision, ['accept', 'decline'], true)) {
            return new WP_Error('invalid_decision', 'Choose accept or decline.', ['status' => 400]);
        }
        $status = $decision === 'accept' ? 'accepted' : 'declined';
        $requester = (int) $row->requested_by;
        if ($decision === 'accept') {
            $policy = SN_Policy::can_contact($requester, $user_id, 'request');
            if (is_wp_error($policy)) {
                return $policy;
            }
        }
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('contacts') . " SET status=%s,updated_at=%s WHERE id=%d AND status='pending'",
            $status,
            current_time('mysql', true),
            $id
        ));
        if ($updated !== 1) {
            return new WP_Error('contact_decision_conflict', 'This contact request has already changed.', ['status' => 409]);
        }
        SN_DB::add_notification($requester, 'contact_' . $status, $status === 'accepted' ? 'Contact request accepted' : 'Contact request declined', '', 'contact', $id);
        SN_DB::audit('contact_' . $status, 'contact', $id);
        return rest_ensure_response(['request_id' => $id, 'status' => $status]);
    }

    public static function get_conversations(): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $c = SN_DB::table('conversations');
        $m = SN_DB::table('members');
        $msg = SN_DB::table('messages');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*,m.is_muted,m.is_archived,lm.body last_body,lm.message_type last_type,lm.sender_id last_sender_id,lm.created_at last_message_at,
                (SELECT COUNT(*) FROM $msg um WHERE um.conversation_id=c.id AND um.id>m.last_read_message_id AND um.sender_id<>%d AND um.deleted_at IS NULL) unread_count
             FROM $c c INNER JOIN $m m ON m.conversation_id=c.id AND m.user_id=%d AND m.left_at IS NULL
             LEFT JOIN $msg lm ON lm.id=c.last_message_id
             WHERE c.status='active' ORDER BY m.is_archived ASC,COALESCE(lm.created_at,c.updated_at) DESC LIMIT 300",
            $user_id,
            $user_id
        ));
        return rest_ensure_response(['conversations' => array_map(fn($row) => self::format_conversation($row, $user_id), $rows)]);
    }

    public static function get_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = absint($request['id']);
        $user_id = get_current_user_id();
        $row = self::conversation_row($id);
        return $row && SN_DB::is_member($id, $user_id)
            ? rest_ensure_response(['conversation' => self::format_conversation($row, $user_id, true)])
            : self::not_found();
    }

    public static function create_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $type = sanitize_key((string) $request->get_param('type')) ?: 'direct';
        if (!SN_Policy::can_create_conversation($user_id, $type)) {
            return new WP_Error('conversation_type_forbidden', 'You cannot create this conversation type.', ['status' => 403]);
        }
        if (!SN_Policy::consume_rate_limit('conversation_create', (string) $user_id, 30, HOUR_IN_SECONDS)) {
            return self::rate_limited();
        }
        $member_ids = array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('member_ids')))));
        $member_ids = array_values(array_diff($member_ids, [$user_id]));
        if ($type === 'direct') {
            $target_id = absint($request->get_param('user_id')) ?: ($member_ids[0] ?? 0);
            $policy = SN_Policy::can_contact($user_id, $target_id, 'message');
            if (is_wp_error($policy)) {
                return $policy;
            }
            $existing = self::direct_conversation_row($user_id, $target_id);
            if ($existing) {
                $was_restored = (string) $existing->status !== 'active' || !SN_DB::is_member((int) $existing->id, $user_id) || !SN_DB::is_member((int) $existing->id, $target_id);
                $restored = self::restore_direct_conversation($existing, $user_id, $target_id);
                if (is_wp_error($restored)) {
                    return $restored;
                }
                return rest_ensure_response(['conversation' => self::format_conversation($existing, $user_id, true), 'existing' => true, 'restored' => $was_restored]);
            }
            $member_ids = [$target_id];
        } else {
            $limit = max(2, (int) apply_filters('sn_network_group_member_limit', 256, $type));
            if (count($member_ids) < 1 || count($member_ids) + 1 > $limit) {
                return new WP_Error('invalid_members', 'Select a permitted number of members.', ['status' => 400]);
            }
            foreach ($member_ids as $target_id) {
                $policy = SN_Policy::can_contact($user_id, $target_id, 'group');
                if (is_wp_error($policy)) {
                    return $policy;
                }
            }
        }

        $now = current_time('mysql', true);
        $direct_key = $type === 'direct' ? SN_DB::direct_key($user_id, $member_ids[0]) : null;
        $title = mb_substr(sanitize_text_field((string) $request->get_param('title')), 0, 191);
        if ($type !== 'direct' && $title === '') {
            return new WP_Error('title_required', 'A title is required.', ['status' => 400]);
        }
        $description = mb_substr(sanitize_textarea_field((string) $request->get_param('description')), 0, 2000);
        $privacy = $type === 'direct' ? 'private' : sanitize_key((string) $request->get_param('privacy'));
        if (!in_array($privacy, ['private', 'invite'], true)) {
            $privacy = 'private';
        }

        $conversation_id = 0;
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->insert(SN_DB::table('conversations'), [
                'type' => $type,
                'title' => $title,
                'slug' => $type === 'direct' ? '' : sanitize_title($title . '-' . wp_generate_uuid4()),
                'direct_key' => $direct_key,
                'owner_id' => $user_id,
                'description' => $description,
                'privacy' => $privacy,
                'status' => 'active',
                'settings' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]) === false) {
                throw new RuntimeException('conversation_insert_failed');
            }
            $conversation_id = (int) $wpdb->insert_id;
            $all_members = array_values(array_unique(array_merge([$user_id], $member_ids)));
            foreach ($all_members as $member_id) {
                if ($wpdb->insert(SN_DB::table('members'), [
                    'conversation_id' => $conversation_id,
                    'user_id' => $member_id,
                    'role' => $member_id === $user_id ? 'owner' : 'member',
                    'joined_at' => $now,
                ]) === false) {
                    throw new RuntimeException('member_insert_failed');
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($conversation_id > 0) {
                $wpdb->delete(SN_DB::table('members'), ['conversation_id' => $conversation_id], ['%d']);
                $wpdb->delete(SN_DB::table('conversations'), ['id' => $conversation_id], ['%d']);
            }
            if ($direct_key) {
                $existing = self::direct_conversation_row($user_id, $member_ids[0]);
                if ($existing) {
                    $restored = self::restore_direct_conversation($existing, $user_id, $member_ids[0]);
                    return is_wp_error($restored) ? $restored : rest_ensure_response(['conversation' => self::format_conversation($existing, $user_id, true), 'existing' => true]);
                }
            }
            return self::database_error();
        }

        foreach ($member_ids as $member_id) {
            SN_DB::add_notification($member_id, 'conversation_invite', 'New Network conversation', '', 'conversation', $conversation_id);
        }
        SN_DB::audit('conversation_created', 'conversation', $conversation_id, 'success', ['type' => $type, 'members' => count($all_members)]);
        $row = self::conversation_row($conversation_id);
        return rest_ensure_response(['conversation' => self::format_conversation($row, $user_id, true)]);
    }

    public static function add_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $actor_id = get_current_user_id();
        $actor_role = SN_DB::member_role($conversation_id, $actor_id);
        if (!in_array($actor_role, ['owner', 'moderator'], true)) {
            return new WP_Error('forbidden', 'Only a conversation owner or moderator may add members.', ['status' => 403]);
        }
        $conversation = self::conversation_row($conversation_id);
        if (!$conversation || (string) $conversation->type === 'direct') {
            return new WP_Error('invalid_conversation', 'Members cannot be added to this conversation.', ['status' => 400]);
        }
        $target_id = absint($request->get_param('user_id'));
        $limit = max(2, (int) apply_filters('sn_network_group_member_limit', 256, (string) $conversation->type));
        $existing = self::member_row($conversation_id, $target_id);
        if ((!$existing || $existing->left_at) && count(self::conversation_member_ids($conversation_id)) >= $limit) {
            return new WP_Error('member_limit_reached', 'This conversation has reached its member limit.', ['status' => 409]);
        }
        $policy = SN_Policy::can_contact($actor_id, $target_id, 'group');
        if (is_wp_error($policy)) {
            return $policy;
        }
        $requested_role = sanitize_key((string) $request->get_param('role'));
        $role = $requested_role === 'moderator' && $actor_role === 'owner' ? 'moderator' : 'member';
        $now = current_time('mysql', true);
        $ok = $existing
            ? $wpdb->update(SN_DB::table('members'), ['role' => $role, 'left_at' => null, 'joined_at' => $now], ['id' => (int) $existing->id])
            : $wpdb->insert(SN_DB::table('members'), ['conversation_id' => $conversation_id, 'user_id' => $target_id, 'role' => $role, 'joined_at' => $now]);
        if ($ok === false) {
            return self::database_error();
        }
        SN_DB::add_notification($target_id, 'conversation_invite', 'Added to a Network conversation', '', 'conversation', $conversation_id);
        SN_DB::audit('member_added', 'conversation', $conversation_id, 'success', ['target_id' => $target_id, 'role' => $role]);
        return rest_ensure_response(['added' => true, 'role' => $role]);
    }

    public static function transfer_conversation_owner(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $actor_id = get_current_user_id();
        $target_id = absint($request->get_param('user_id'));
        if ($target_id <= 0 || $target_id === $actor_id || !get_user_by('id', $target_id)) {
            return new WP_Error('invalid_owner', 'Select an active adult conversation member.', ['status' => 400]);
        }
        if (SN_Policy::is_suspended($target_id) || SN_Policy::is_minor($target_id)) {
            return new WP_Error('owner_ineligible', 'The selected member is not eligible to own this conversation.', ['status' => 403]);
        }

        $conversations = SN_DB::table('conversations');
        $members = SN_DB::table('members');
        $wpdb->query('START TRANSACTION');
        $conversation = $wpdb->get_row($wpdb->prepare("SELECT * FROM $conversations WHERE id=%d FOR UPDATE", $conversation_id));
        if (!$conversation || (string) $conversation->type === 'direct' || (string) $conversation->status !== 'active') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid_conversation', 'Ownership cannot be transferred for this conversation.', ['status' => 400]);
        }
        $locked_members = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id,role,left_at FROM $members WHERE conversation_id=%d AND user_id IN (%d,%d) FOR UPDATE",
            $conversation_id,
            $actor_id,
            $target_id
        ));
        $roles = [];
        foreach ($locked_members as $member) {
            if ($member->left_at === null) {
                $roles[(int) $member->user_id] = (string) $member->role;
            }
        }
        if (($roles[$actor_id] ?? '') !== 'owner') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('forbidden', 'Only the current conversation owner may transfer ownership.', ['status' => 403]);
        }
        if (!isset($roles[$target_id])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('member_required', 'The new owner must be an active conversation member.', ['status' => 409]);
        }

        $now = current_time('mysql', true);
        $old_owner_updated = $wpdb->update($members, ['role' => 'moderator'], ['conversation_id' => $conversation_id, 'user_id' => $actor_id, 'left_at' => null], ['%s'], ['%d', '%d', '%s']);
        $new_owner_updated = $wpdb->update($members, ['role' => 'owner'], ['conversation_id' => $conversation_id, 'user_id' => $target_id, 'left_at' => null], ['%s'], ['%d', '%d', '%s']);
        $conversation_updated = $wpdb->update($conversations, ['owner_id' => $target_id, 'updated_at' => $now], ['id' => $conversation_id, 'owner_id' => $actor_id], ['%d', '%s'], ['%d', '%d']);
        if ($old_owner_updated === false || $new_owner_updated === false || $conversation_updated !== 1) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }
        $wpdb->query('COMMIT');

        SN_DB::add_notification($target_id, 'conversation_owner', 'Conversation ownership transferred to you', '', 'conversation', $conversation_id);
        SN_DB::audit('conversation_owner_transferred', 'conversation', $conversation_id, 'success', ['from' => $actor_id, 'to' => $target_id]);
        $fresh = self::conversation_row($conversation_id);
        return rest_ensure_response(['transferred' => true, 'conversation' => self::format_conversation($fresh, $actor_id, true)]);
    }

    public static function remove_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $actor_id = get_current_user_id();
        $target_id = absint($request->get_param('user_id')) ?: $actor_id;
        $conversation = self::conversation_row($conversation_id);
        if (!$conversation || (string) $conversation->type === 'direct') {
            return new WP_Error('invalid_conversation', 'Members cannot be removed from this conversation.', ['status' => 400]);
        }
        $actor_role = SN_DB::member_role($conversation_id, $actor_id);
        $target_role = SN_DB::member_role($conversation_id, $target_id);
        $self_leave = $actor_id === $target_id;
        if (!$target_role) {
            return self::not_found();
        }
        if (!$self_leave && !in_array($actor_role, ['owner', 'moderator'], true)) {
            return new WP_Error('forbidden', 'You cannot remove this member.', ['status' => 403]);
        }
        if ($target_role === 'owner') {
            return new WP_Error('owner_removal_forbidden', 'Transfer ownership before the owner leaves or is removed.', ['status' => 409]);
        }
        if (!$self_leave && $target_role === 'moderator' && $actor_role !== 'owner') {
            return new WP_Error('moderator_removal_forbidden', 'Only the conversation owner may remove a moderator.', ['status' => 403]);
        }
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->update(SN_DB::table('members'), ['left_at' => $now, 'is_muted' => 0, 'is_archived' => 0], ['conversation_id' => $conversation_id, 'user_id' => $target_id]) === false) {
                throw new RuntimeException('member_leave_failed');
            }
            $active_call_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM " . SN_DB::table('calls') . " WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE",
                $conversation_id
            )));
            foreach ($active_call_ids as $call_id) {
                if ($wpdb->update(SN_DB::table('call_members'), ['status' => 'left', 'left_at' => $now], ['call_id' => $call_id, 'user_id' => $target_id]) === false) {
                    throw new RuntimeException('call_membership_revoke_failed');
                }
                $signal_delete = $wpdb->query($wpdb->prepare(
                    'DELETE FROM ' . SN_DB::table('signals') . ' WHERE call_id=%d AND (from_user_id=%d OR to_user_id=%d)',
                    $call_id,
                    $target_id,
                    $target_id
                ));
                if ($signal_delete === false) {
                    throw new RuntimeException('call_signal_revoke_failed');
                }
                $remaining = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . SN_DB::table('call_members') . " WHERE call_id=%d AND status IN ('invited','joined')",
                    $call_id
                ));
                if ($remaining < 2) {
                    if ($wpdb->update(SN_DB::table('calls'), ['status' => 'ended', 'active_key' => null, 'ended_at' => $now], ['id' => $call_id]) === false) {
                        throw new RuntimeException('call_end_after_member_removal_failed');
                    }
                    if ($wpdb->query($wpdb->prepare(
                        "UPDATE " . SN_DB::table('call_members') . " SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id=%d AND status IN ('invited','joined')",
                        $now,
                        $call_id
                    )) === false || $wpdb->delete(SN_DB::table('signals'), ['call_id' => $call_id], ['%d']) === false) {
                        throw new RuntimeException('call_cleanup_after_member_removal_failed');
                    }
                }
            }
            $wpdb->delete(SN_DB::table('typing'), ['conversation_id' => $conversation_id, 'user_id' => $target_id], ['%d', '%d']);
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }
        SN_DB::audit('member_removed', 'conversation', $conversation_id, 'success', ['target_id' => $target_id, 'calls_revoked' => count($active_call_ids)]);
        return rest_ensure_response(['removed' => true]);
    }

    public static function update_conversation_preferences(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        $data = [];
        if ($request->has_param('muted')) {
            $data['is_muted'] = rest_sanitize_boolean($request->get_param('muted')) ? 1 : 0;
        }
        if ($request->has_param('archived')) {
            $data['is_archived'] = rest_sanitize_boolean($request->get_param('archived')) ? 1 : 0;
        }
        if (!$data) {
            return new WP_Error('invalid_preferences', 'Select a conversation preference to update.', ['status' => 400]);
        }
        if ($wpdb->update(SN_DB::table('members'), $data, [
            'conversation_id' => $conversation_id,
            'user_id' => $user_id,
            'left_at' => null,
        ]) === false) {
            return self::database_error();
        }
        SN_DB::audit('conversation_preferences_updated', 'conversation', $conversation_id, 'success', [
            'muted' => $data['is_muted'] ?? null,
            'archived' => $data['is_archived'] ?? null,
        ]);
        return rest_ensure_response(['preferences' => SN_DB::member_preferences($conversation_id, $user_id)]);
    }

    public static function set_typing(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        $conversation = self::conversation_row($conversation_id);
        if (!$conversation || !SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        $post_policy = SN_Policy::can_post_to_conversation($conversation, $user_id);
        if (is_wp_error($post_policy)) {
            return $post_policy;
        }
        $contact = self::conversation_contact_check($conversation, $conversation_id, $user_id, 'message');
        if (is_wp_error($contact)) {
            return $contact;
        }
        if (!SN_Policy::consume_rate_limit('typing', $user_id . ':' . $conversation_id, 180, MINUTE_IN_SECONDS)) {
            return self::rate_limited();
        }
        $typing = rest_sanitize_boolean($request->get_param('typing'));
        if (!$typing) {
            $wpdb->delete(SN_DB::table('typing'), ['conversation_id' => $conversation_id, 'user_id' => $user_id], ['%d', '%d']);
            return rest_ensure_response(['typing' => false]);
        }
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + 8);
        $ok = $wpdb->replace(SN_DB::table('typing'), [
            'conversation_id' => $conversation_id,
            'user_id' => $user_id,
            'expires_at' => $expires,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s']);
        return $ok === false ? self::database_error() : rest_ensure_response(['typing' => true, 'expires_at' => $expires]);
    }

    public static function get_typing(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id) || !self::conversation_row($conversation_id)) {
            return self::not_found();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT t.user_id,t.expires_at FROM ' . SN_DB::table('typing') . ' t INNER JOIN ' . SN_DB::table('members') . ' m ON m.conversation_id=t.conversation_id AND m.user_id=t.user_id AND m.left_at IS NULL WHERE t.conversation_id=%d AND t.user_id<>%d AND t.expires_at>%s ORDER BY t.updated_at DESC LIMIT 20',
            $conversation_id,
            $user_id,
            current_time('mysql', true)
        ));
        $users = [];
        foreach ($rows as $row) {
            if (SN_DB::is_blocked($user_id, (int) $row->user_id)) {
                continue;
            }
            $projection = SN_Auth::public_user((int) $row->user_id);
            if ($projection) {
                $users[] = $projection;
            }
        }
        return rest_ensure_response(['typing' => $users]);
    }

    public static function get_messages(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id) || !self::conversation_row($conversation_id)) {
            return self::not_found();
        }
        $after = absint($request->get_param('after'));
        $before = absint($request->get_param('before'));
        $limit = min(100, max(1, absint($request->get_param('limit')) ?: 50));
        $table = SN_DB::table('messages');
        if ($after) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE conversation_id=%d AND id>%d ORDER BY id ASC LIMIT %d", $conversation_id, $after, $limit));
        } elseif ($before) {
            $rows = array_reverse($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE conversation_id=%d AND id<%d ORDER BY id DESC LIMIT %d", $conversation_id, $before, $limit)));
        } else {
            $rows = array_reverse($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE conversation_id=%d ORDER BY id DESC LIMIT %d", $conversation_id, $limit)));
        }
        return rest_ensure_response(['messages' => array_map(fn($row) => self::format_message($row, $user_id), $rows)]);
    }

    public static function send_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        $conversation = self::conversation_row($conversation_id);
        if (!$conversation || !SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        $post_policy = SN_Policy::can_post_to_conversation($conversation, $user_id);
        if (is_wp_error($post_policy)) {
            return $post_policy;
        }
        $contact = self::conversation_contact_check($conversation, $conversation_id, $user_id, 'message');
        if (is_wp_error($contact)) {
            return $contact;
        }
        if (!SN_Policy::consume_rate_limit('message_send', (string) $user_id, 120, MINUTE_IN_SECONDS)) {
            return self::rate_limited();
        }
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if (mb_strlen($body) > self::MAX_MESSAGE_CHARS) {
            return new WP_Error('message_too_long', 'The message is longer than the permitted limit.', ['status' => 413]);
        }
        $message_type = sanitize_key((string) $request->get_param('message_type')) ?: 'text';
        if (!in_array($message_type, ['text', 'image', 'video', 'audio', 'document'], true)) {
            $message_type = 'text';
        }
        $reply_to = absint($request->get_param('reply_to'));
        if ($reply_to && !self::message_in_conversation($reply_to, $conversation_id)) {
            return new WP_Error('invalid_reply', 'The replied-to message is unavailable.', ['status' => 400]);
        }
        $client_id = strtolower(trim((string) $request->get_param('client_id')));
        if ($client_id === '') {
            $client_id = wp_generate_uuid4();
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client_id)) {
            return new WP_Error('invalid_client_id', 'A valid message idempotency key is required.', ['status' => 400]);
        }
        $idempotency_key = hash('sha256', $user_id . ':' . $conversation_id . ':' . $client_id);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idempotency_key));
        if ($existing) {
            return rest_ensure_response(['message' => self::format_message($existing, $user_id), 'duplicate' => true]);
        }
        $attachment = null;
        $files = $request->get_file_params();
        if (!empty($files['attachment']) && is_array($files['attachment'])) {
            $attachment = SN_Private_Files::create_from_upload($files['attachment'], $user_id);
            if (is_wp_error($attachment)) {
                return $attachment;
            }
            $message_type = (string) $attachment['type'];
        }
        if ($body === '' && !$attachment) {
            return new WP_Error('empty_message', 'Write a message or attach a file.', ['status' => 400]);
        }
        if (!$attachment) {
            $message_type = 'text';
        }
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(SN_DB::table('messages'), [
            'conversation_id' => $conversation_id,
            'sender_id' => $user_id,
            'message_type' => $message_type,
            'body' => $body,
            'attachment_id' => $attachment ? (int) $attachment['id'] : 0,
            'attachment_source' => $attachment ? 'private' : 'none',
            'reply_to' => $reply_to,
            'idempotency_key' => $idempotency_key,
            'metadata' => '{}',
            'created_at' => $now,
        ]);
        if ($inserted === false) {
            if ($attachment) {
                SN_Private_Files::delete((int) $attachment['id'], $user_id);
            }
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE idempotency_key=%s', $idempotency_key));
            return $existing ? rest_ensure_response(['message' => self::format_message($existing, $user_id), 'duplicate' => true]) : self::database_error();
        }
        $message_id = (int) $wpdb->insert_id;
        $wpdb->update(SN_DB::table('conversations'), ['last_message_id' => $message_id, 'updated_at' => $now], ['id' => $conversation_id]);
        foreach (self::conversation_member_ids($conversation_id) as $member_id) {
            if ($member_id !== $user_id) {
                SN_DB::add_notification($member_id, 'message_received', 'New Network message', '', 'conversation', $conversation_id);
            }
        }
        SN_DB::audit('message_sent', 'message', $message_id, 'success', ['conversation_id' => $conversation_id, 'type' => $message_type]);
        return rest_ensure_response(['message' => self::format_message(self::message_row($message_id), $user_id)]);
    }

    public static function mark_read(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $conversation_id = absint($request['id']);
        $user_id = get_current_user_id();
        if (!SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        $message_id = absint($request->get_param('message_id'));
        if ($message_id && !self::message_in_conversation($message_id, $conversation_id)) {
            return new WP_Error('invalid_message', 'The message is not in this conversation.', ['status' => 400]);
        }
        if (!$message_id) {
            $message_id = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));
        }
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('members') . ' SET last_read_message_id=GREATEST(last_read_message_id,%d) WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL',
            $message_id,
            $conversation_id,
            $user_id
        ));
        return rest_ensure_response(['read_through' => $message_id]);
    }

    public static function edit_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $message = self::message_row($id);
        $user_id = get_current_user_id();
        if (!$message || !SN_DB::is_member((int) $message->conversation_id, $user_id)) {
            return self::not_found();
        }
        if (!SN_Policy::can_edit_message($message, $user_id)) {
            return new WP_Error('edit_forbidden', 'This message can no longer be edited.', ['status' => 403]);
        }
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if ($body === '' || mb_strlen($body) > self::MAX_MESSAGE_CHARS) {
            return new WP_Error('invalid_message', 'Enter a valid message within the permitted length.', ['status' => 400]);
        }
        if ($wpdb->update(SN_DB::table('messages'), ['body' => $body, 'edited_at' => current_time('mysql', true)], ['id' => $id]) === false) {
            return self::database_error();
        }
        SN_DB::audit('message_edited', 'message', $id, 'success', ['conversation_id' => (int) $message->conversation_id]);
        return rest_ensure_response(['message' => self::format_message(self::message_row($id), $user_id)]);
    }

    public static function delete_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $message = self::message_row($id);
        $user_id = get_current_user_id();
        if (!$message || !SN_DB::is_member((int) $message->conversation_id, $user_id)) {
            return self::not_found();
        }
        if (!SN_Policy::can_delete_message($message, $user_id)) {
            return new WP_Error('delete_forbidden', 'This message can no longer be deleted.', ['status' => 403]);
        }
        $private_attachment_id = (string) $message->attachment_source === 'private' ? (int) $message->attachment_id : 0;
        if ($wpdb->update(SN_DB::table('messages'), ['body' => '', 'attachment_id' => 0, 'attachment_source' => 'erased', 'deleted_at' => current_time('mysql', true)], ['id' => $id]) === false) {
            return self::database_error();
        }
        $wpdb->delete(SN_DB::table('reactions'), ['message_id' => $id], ['%d']);
        if ($private_attachment_id) {
            SN_Private_Files::delete($private_attachment_id, $user_id);
        }
        SN_DB::audit('message_deleted', 'message', $id, 'success', ['conversation_id' => (int) $message->conversation_id]);
        return rest_ensure_response(['deleted' => true]);
    }

    public static function react_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $message_id = absint($request['id']);
        $message = self::message_row($message_id);
        $user_id = get_current_user_id();
        if (!$message || !SN_DB::is_member((int) $message->conversation_id, $user_id)) {
            return self::not_found();
        }
        $reaction = SN_Policy::sanitize_reaction((string) $request->get_param('reaction'));
        if ($message->deleted_at && $reaction !== '') {
            return new WP_Error('message_deleted', 'Deleted messages cannot receive new reactions.', ['status' => 409]);
        }
        $changed = $reaction === ''
            ? $wpdb->delete(SN_DB::table('reactions'), ['message_id' => $message_id, 'user_id' => $user_id], ['%d', '%d'])
            : $wpdb->replace(SN_DB::table('reactions'), ['message_id' => $message_id, 'user_id' => $user_id, 'reaction' => $reaction, 'created_at' => current_time('mysql', true)]);
        if ($changed === false) {
            return self::database_error();
        }
        return rest_ensure_response(['reactions' => self::message_reactions($message_id)]);
    }

    public static function get_updates(): WP_REST_Response {
        global $wpdb;
        $viewer_id = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SN_DB::table('updates') . ' WHERE expires_at>%s ORDER BY created_at DESC LIMIT 200', current_time('mysql', true)));
        $updates = [];
        foreach ($rows as $row) {
            if (self::can_view_update($row, $viewer_id)) {
                $updates[] = self::format_update($row, $viewer_id);
            }
        }
        return rest_ensure_response(['updates' => $updates]);
    }

    public static function create_update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('update_create', (string) $user_id, 20, DAY_IN_SECONDS)) {
            return self::rate_limited();
        }
        $body = trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('body'))));
        if (mb_strlen($body) > self::MAX_UPDATE_CHARS) {
            return new WP_Error('update_too_long', 'The update is longer than the permitted limit.', ['status' => 413]);
        }
        $privacy = sanitize_key((string) $request->get_param('privacy')) ?: 'contacts';
        if (!in_array($privacy, ['contacts', 'private', 'public'], true)) {
            $privacy = 'contacts';
        }
        if ($privacy === 'public' && !SN_Policy::can_publish_public_update($user_id)) {
            return new WP_Error('public_update_forbidden', 'You cannot publish a public update.', ['status' => 403]);
        }
        $attachment = null;
        $files = $request->get_file_params();
        if (!empty($files['attachment']) && is_array($files['attachment'])) {
            $attachment = SN_Private_Files::create_from_upload($files['attachment'], $user_id);
            if (is_wp_error($attachment)) {
                return $attachment;
            }
        }
        if ($body === '' && !$attachment) {
            return new WP_Error('empty_update', 'Write an update or attach media.', ['status' => 400]);
        }
        $hours = min(168, max(1, absint($request->get_param('expires_in_hours')) ?: 24));
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(SN_DB::table('updates'), [
            'user_id' => $user_id,
            'body' => $body,
            'media_id' => $attachment ? (int) $attachment['id'] : 0,
            'media_source' => $attachment ? 'private' : 'none',
            'media_type' => $attachment ? (string) $attachment['type'] : 'text',
            'privacy' => $privacy,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS),
            'created_at' => $now,
        ]);
        if ($ok === false) {
            if ($attachment) {
                SN_Private_Files::delete((int) $attachment['id'], $user_id);
            }
            return self::database_error();
        }
        $id = (int) $wpdb->insert_id;
        SN_DB::audit('update_created', 'update', $id, 'success', ['privacy' => $privacy]);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('updates') . ' WHERE id=%d', $id));
        return rest_ensure_response(['update' => self::format_update($row, $user_id)]);
    }

    public static function view_update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $viewer_id = get_current_user_id();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('updates') . ' WHERE id=%d AND expires_at>%s', $id, current_time('mysql', true)));
        if (!$row || !self::can_view_update($row, $viewer_id)) {
            return self::not_found();
        }
        if ((int) $row->user_id !== $viewer_id) {
            $wpdb->replace(SN_DB::table('update_views'), ['update_id' => $id, 'viewer_id' => $viewer_id, 'viewed_at' => current_time('mysql', true)]);
        }
        return rest_ensure_response(['viewed' => true]);
    }

    public static function get_notifications(): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SN_DB::table('notifications') . ' WHERE user_id=%d ORDER BY id DESC LIMIT 100', $user_id));
        return rest_ensure_response(['notifications' => array_map(static fn($row) => [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'title' => (string) $row->title,
            'body' => (string) $row->body,
            'entity_type' => (string) $row->entity_type,
            'entity_id' => (int) $row->entity_id,
            'is_read' => (bool) $row->is_read,
            'created_at' => (string) $row->created_at,
        ], $rows)]);
    }

    public static function read_notifications(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('ids'))))), 0, 100);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('notifications') . " SET is_read=1 WHERE user_id=%d AND id IN ($placeholders)", $user_id, ...$ids));
        } else {
            $wpdb->update(SN_DB::table('notifications'), ['is_read' => 1], ['user_id' => $user_id], ['%d'], ['%d']);
        }
        return rest_ensure_response(['read' => true]);
    }

    public static function heartbeat_presence(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('presence', (string) $user_id, 180, HOUR_IN_SECONDS)) {
            return self::rate_limited();
        }
        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, ['online', 'away', 'offline'], true)) {
            $status = 'online';
        }
        $now = current_time('mysql', true);
        $expires = $status === 'offline' ? $now : gmdate('Y-m-d H:i:s', time() + 90);
        $ok = $wpdb->replace(SN_DB::table('presence'), [
            'user_id' => $user_id,
            'status' => $status,
            'last_seen_at' => $now,
            'expires_at' => $expires,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s']);
        return $ok === false ? self::database_error() : rest_ensure_response([
            'presence' => ['user_id' => $user_id, 'status' => $status, 'last_seen_at' => $now, 'expires_at' => $expires],
        ]);
    }

    public static function get_presence(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $viewer_id = get_current_user_id();
        $raw = $request->get_param('user_ids');
        if (is_string($raw)) {
            $raw = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $raw)))), 0, 100);
        if (!$ids) {
            return rest_ensure_response(['presence' => []]);
        }
        $allowed = array_values(array_filter($ids, static fn($id) => SN_Policy::can_view_presence($viewer_id, (int) $id)));
        if (!$allowed) {
            return rest_ensure_response(['presence' => []]);
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id,status,last_seen_at,expires_at FROM ' . SN_DB::table('presence') . " WHERE user_id IN ($placeholders)",
            ...$allowed
        ));
        $now = time();
        $presence = [];
        foreach ($rows as $row) {
            $expires = strtotime((string) $row->expires_at . ' UTC');
            $status = $expires > $now ? (string) $row->status : 'offline';
            $presence[] = [
                'user_id' => (int) $row->user_id,
                'status' => in_array($status, ['online', 'away'], true) ? $status : 'offline',
                'last_seen_at' => (string) $row->last_seen_at,
            ];
        }
        return rest_ensure_response(['presence' => $presence]);
    }

    public static function get_calls(): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT c.*,cm.status member_status FROM ' . SN_DB::table('calls') . ' c INNER JOIN ' . SN_DB::table('call_members') . ' cm ON cm.call_id=c.id AND cm.user_id=%d INNER JOIN ' . SN_DB::table('members') . ' m ON m.conversation_id=c.conversation_id AND m.user_id=%d AND m.left_at IS NULL ORDER BY c.id DESC LIMIT 100',
            $user_id,
            $user_id
        ));
        return rest_ensure_response(['calls' => array_map(fn($row) => self::format_call($row), $rows)]);
    }

    public static function create_call(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $conversation_id = absint($request->get_param('conversation_id'));
        $conversation = self::conversation_row($conversation_id);
        if (!$conversation || !SN_DB::is_member($conversation_id, $user_id)) {
            return self::not_found();
        }
        if ((string) $conversation->type === 'channel') {
            return new WP_Error('channel_calls_unavailable', 'Calls are not available in broadcast channels.', ['status' => 403]);
        }
        $members = self::conversation_member_ids($conversation_id);
        if (count($members) < 2) {
            return new WP_Error('call_members_unavailable', 'At least two active members are required for a call.', ['status' => 409]);
        }
        $contact = self::conversation_contact_check($conversation, $conversation_id, $user_id, 'call');
        if (is_wp_error($contact)) {
            return $contact;
        }
        if (!SN_Policy::consume_rate_limit('call_create', (string) $user_id, 20, HOUR_IN_SECONDS)) {
            return self::rate_limited();
        }
        $type = sanitize_key((string) $request->get_param('type'));
        if (!in_array($type, ['audio', 'video'], true)) {
            return new WP_Error('invalid_call_type', 'Choose an audio or video call.', ['status' => 400]);
        }
        if (count($members) > 2) {
            if (!SN_Policy::can_use_group_calls($user_id, $conversation_id)) {
                return new WP_Error('group_call_forbidden', 'Group calling is not available for this account or conversation.', ['status' => 403]);
            }
            $handled = apply_filters('sn_network_group_call_create_result', null, $request, $conversation, $members, $user_id);
            if ($handled instanceof WP_REST_Response || is_wp_error($handled)) {
                return $handled;
            }
            return new WP_Error('group_call_unavailable', 'Group calling requires an approved SFU adapter that owns the group-call session.', ['status' => 503]);
        }
        $active = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*,cm.status member_status FROM " . SN_DB::table('calls') . " c INNER JOIN " . SN_DB::table('call_members') . " cm ON cm.call_id=c.id AND cm.user_id=%d WHERE c.conversation_id=%d AND c.status IN ('ringing','active') ORDER BY c.id DESC LIMIT 1",
            $user_id,
            $conversation_id
        ));
        if ($active) {
            return new WP_Error('call_already_active', 'An active or ringing call already exists in this conversation.', ['status' => 409, 'call' => self::format_call($active)]);
        }

        $now = current_time('mysql', true);
        $call_id = 0;
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->insert(SN_DB::table('calls'), [
                'conversation_id' => $conversation_id,
                'initiator_id' => $user_id,
                'call_type' => $type,
                'status' => 'ringing',
                'room_key' => wp_generate_password(48, false, false),
                'active_key' => hash('sha256', 'conversation:' . $conversation_id),
                'metadata' => '{}',
                'created_at' => $now,
            ]) === false) {
                throw new RuntimeException('call_insert_failed');
            }
            $call_id = (int) $wpdb->insert_id;
            foreach ($members as $member_id) {
                if ($wpdb->insert(SN_DB::table('call_members'), [
                    'call_id' => $call_id,
                    'user_id' => $member_id,
                    'status' => $member_id === $user_id ? 'joined' : 'invited',
                    'joined_at' => $member_id === $user_id ? $now : null,
                ]) === false) {
                    throw new RuntimeException('call_member_insert_failed');
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($call_id > 0) {
                $wpdb->delete(SN_DB::table('call_members'), ['call_id' => $call_id], ['%d']);
                $wpdb->delete(SN_DB::table('signals'), ['call_id' => $call_id], ['%d']);
                $wpdb->delete(SN_DB::table('calls'), ['id' => $call_id], ['%d']);
            }
            $active = $wpdb->get_row($wpdb->prepare(
                "SELECT c.*,cm.status member_status FROM " . SN_DB::table('calls') . " c INNER JOIN " . SN_DB::table('call_members') . " cm ON cm.call_id=c.id AND cm.user_id=%d WHERE c.conversation_id=%d AND c.status IN ('ringing','active') ORDER BY c.id DESC LIMIT 1",
                $user_id,
                $conversation_id
            ));
            if ($active) {
                return new WP_Error('call_already_active', 'An active or ringing call already exists in this conversation.', ['status' => 409, 'call' => self::format_call($active)]);
            }
            return self::database_error();
        }
        foreach ($members as $member_id) {
            if ($member_id !== $user_id) {
                SN_DB::add_notification($member_id, 'incoming_call', 'Incoming Network call', '', 'call', $call_id);
            }
        }
        SN_DB::audit('call_created', 'call', $call_id, 'success', ['conversation_id' => $conversation_id, 'type' => $type]);
        $row = $wpdb->get_row($wpdb->prepare('SELECT c.*,cm.status member_status FROM ' . SN_DB::table('calls') . ' c INNER JOIN ' . SN_DB::table('call_members') . ' cm ON cm.call_id=c.id AND cm.user_id=%d WHERE c.id=%d', $user_id, $call_id));
        return rest_ensure_response(['call' => self::format_call($row), 'ice_servers' => SN_Auth::ice_servers($user_id, $conversation_id)]);
    }

    public static function update_call_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $call_id = absint($request['id']);
        $user_id = get_current_user_id();
        $call = self::call_row($call_id);
        $member = self::call_member_row($call_id, $user_id);
        if (!$call || !$member || !SN_DB::is_member((int) $call->conversation_id, $user_id)) {
            return self::not_found();
        }
        if (!in_array((string) $call->status, ['ringing', 'active'], true)) {
            return new WP_Error('call_ended', 'This call has already ended.', ['status' => 409]);
        }
        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, ['joined', 'declined', 'left', 'missed'], true)) {
            return new WP_Error('invalid_call_status', 'The call status is invalid.', ['status' => 400]);
        }
        $current = (string) $member->status;
        $allowed = ['invited' => ['joined', 'declined', 'missed', 'left'], 'joined' => ['joined', 'left']];
        if (!isset($allowed[$current]) || !in_array($status, $allowed[$current], true)) {
            return new WP_Error('invalid_call_transition', 'This call status transition is not allowed.', ['status' => 409]);
        }
        if ($current === $status) {
            $response = ['status' => $status, 'call_status' => (string) $call->status, 'unchanged' => true];
            if ($status === 'joined' && (string) $call->status !== 'ended') {
                $response['ice_servers'] = SN_Auth::ice_servers($user_id, (int) $call->conversation_id);
            }
            return rest_ensure_response($response);
        }

        $now = current_time('mysql', true);
        $data = ['status' => $status];
        if ($status === 'joined') {
            $data['joined_at'] = $now;
            $data['left_at'] = null;
        } else {
            $data['left_at'] = $now;
        }
        $call_status = (string) $call->status;
        $conversation_type = (string) $wpdb->get_var($wpdb->prepare('SELECT type FROM ' . SN_DB::table('conversations') . ' WHERE id=%d', (int) $call->conversation_id));
        $end = $conversation_type === 'direct' && in_array($status, ['declined', 'missed', 'left'], true);
        if ((int) $call->initiator_id === $user_id && $status === 'left') {
            $end = true;
        }

        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->update(SN_DB::table('call_members'), $data, ['call_id' => $call_id, 'user_id' => $user_id]) === false) {
                throw new RuntimeException('call_member_status_failed');
            }
            if ($status === 'joined' && $call_status === 'ringing') {
                if ($wpdb->update(SN_DB::table('calls'), ['status' => 'active', 'started_at' => $now], ['id' => $call_id, 'status' => 'ringing']) === false) {
                    throw new RuntimeException('call_start_failed');
                }
                $call_status = 'active';
            }
            $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . SN_DB::table('call_members') . " WHERE call_id=%d AND status IN ('invited','joined')", $call_id));
            if ($remaining === 0) {
                $end = true;
            }
            if ($end) {
                if ($wpdb->update(SN_DB::table('calls'), ['status' => 'ended', 'active_key' => null, 'ended_at' => $now], ['id' => $call_id]) === false) {
                    throw new RuntimeException('call_end_failed');
                }
                $member_update = $wpdb->query($wpdb->prepare("UPDATE " . SN_DB::table('call_members') . " SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id=%d AND status IN ('invited','joined')", $now, $call_id));
                if ($member_update === false || $wpdb->delete(SN_DB::table('signals'), ['call_id' => $call_id], ['%d']) === false) {
                    throw new RuntimeException('call_cleanup_failed');
                }
                $call_status = 'ended';
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }

        SN_DB::audit('call_member_status', 'call', $call_id, 'success', ['from' => $current, 'status' => $status, 'call_status' => $call_status]);
        $response = ['status' => $status, 'call_status' => $call_status];
        if ($status === 'joined' && $call_status !== 'ended') {
            $response['ice_servers'] = SN_Auth::ice_servers($user_id, (int) $call->conversation_id);
        }
        return rest_ensure_response($response);
    }

    public static function send_signal(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $call_id = absint($request['id']);
        $from_id = get_current_user_id();
        $to_id = absint($request->get_param('to_user_id'));
        $call = self::call_row($call_id);
        $from = self::call_member_row($call_id, $from_id);
        $to = self::call_member_row($call_id, $to_id);
        if (!$call || !$from || !$to || $from_id === $to_id
            || !SN_DB::is_member((int) $call->conversation_id, $from_id)
            || !SN_DB::is_member((int) $call->conversation_id, $to_id)) {
            return self::not_found();
        }
        if (!in_array((string) $call->status, ['ringing', 'active'], true) || (string) $from->status !== 'joined' || !in_array((string) $to->status, ['invited', 'joined'], true)) {
            return new WP_Error('call_signal_forbidden', 'Signaling is unavailable for this call state.', ['status' => 409]);
        }
        if (!SN_Policy::consume_rate_limit('webrtc_signal', $from_id . ':' . $call_id, 600, MINUTE_IN_SECONDS)) {
            return self::rate_limited();
        }
        $type = sanitize_key((string) $request->get_param('type'));
        if (!in_array($type, ['offer', 'answer', 'candidate', 'renegotiate', 'bye'], true)) {
            return new WP_Error('invalid_signal', 'The WebRTC signal type is invalid.', ['status' => 400]);
        }
        $payload = self::sanitize_signal_payload($type, $request->get_param('payload'));
        if (is_wp_error($payload)) {
            return $payload;
        }
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_SIGNAL_BYTES) {
            return new WP_Error('signal_too_large', 'The WebRTC signal payload is invalid or too large.', ['status' => 413]);
        }
        $ok = $wpdb->insert(SN_DB::table('signals'), [
            'call_id' => $call_id,
            'from_user_id' => $from_id,
            'to_user_id' => $to_id,
            'signal_type' => $type,
            'payload' => $json,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? self::database_error() : rest_ensure_response(['sent' => true, 'id' => (int) $wpdb->insert_id]);
    }

    public static function get_signals(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $call_id = absint($request['id']);
        $user_id = get_current_user_id();
        $call = self::call_row($call_id);
        $member = self::call_member_row($call_id, $user_id);
        if (!$call || !$member || !SN_DB::is_member((int) $call->conversation_id, $user_id)) {
            return self::not_found();
        }
        if (!in_array((string) $call->status, ['ringing', 'active'], true) || !in_array((string) $member->status, ['invited', 'joined'], true)) {
            return new WP_Error('call_signal_unavailable', 'Signaling is unavailable for this call state.', ['status' => 409]);
        }
        $after = absint($request->get_param('after'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SN_DB::table('signals') . ' WHERE call_id=%d AND to_user_id=%d AND consumed_at IS NULL AND id>%d ORDER BY id ASC LIMIT 100',
            $call_id,
            $user_id,
            $after
        ));
        return rest_ensure_response(['signals' => array_map(static fn($row) => [
            'id' => (int) $row->id,
            'from_user_id' => (int) $row->from_user_id,
            'type' => (string) $row->signal_type,
            'payload' => json_decode((string) $row->payload, true),
        ], $rows)]);
    }

    public static function ack_signals(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $call_id = absint($request['id']);
        $user_id = get_current_user_id();
        $call = self::call_row($call_id);
        if (!$call || !self::call_member_row($call_id, $user_id) || !SN_DB::is_member((int) $call->conversation_id, $user_id)) {
            return self::not_found();
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('ids'))))), 0, 100);
        if (!$ids) {
            return rest_ensure_response(['acknowledged' => 0]);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('signals') . " SET consumed_at=%s WHERE call_id=%d AND to_user_id=%d AND consumed_at IS NULL AND id IN ($placeholders)",
            current_time('mysql', true),
            $call_id,
            $user_id,
            ...$ids
        ));
        return rest_ensure_response(['acknowledged' => max(0, (int) $result)]);
    }

    public static function block_user(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $user_id = get_current_user_id();
        $target_id = absint($request->get_param('user_id'));
        if (!$target_id || $target_id === $user_id || !get_user_by('id', $target_id)) {
            return new WP_Error('invalid_user', 'Select a valid user.', ['status' => 400]);
        }
        $raw = $request->get_param('blocked');
        $blocked = $raw === null ? true : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            if ($blocked) {
                if ($wpdb->replace(SN_DB::table('blocks'), ['user_id' => $user_id, 'blocked_user_id' => $target_id, 'created_at' => $now]) === false) {
                    throw new RuntimeException('block_write_failed');
                }
                $contact = SN_DB::contact_record($user_id, $target_id);
                if ($contact && $wpdb->update(SN_DB::table('contacts'), ['status' => 'blocked', 'updated_at' => $now], ['id' => (int) $contact->id]) === false) {
                    throw new RuntimeException('contact_block_failed');
                }
                $conversation = self::direct_conversation_row($user_id, $target_id);
                if ($conversation) {
                    $call_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM " . SN_DB::table('calls') . " WHERE conversation_id=%d AND status IN ('ringing','active')",
                        (int) $conversation->id
                    )));
                    if ($call_ids) {
                        $placeholders = implode(',', array_fill(0, count($call_ids), '%d'));
                        $ended = $wpdb->query($wpdb->prepare(
                            'UPDATE ' . SN_DB::table('calls') . " SET status='ended',active_key=NULL,ended_at=%s WHERE id IN ($placeholders)",
                            $now,
                            ...$call_ids
                        ));
                        $members = $wpdb->query($wpdb->prepare(
                            'UPDATE ' . SN_DB::table('call_members') . " SET status=CASE WHEN status='invited' THEN 'missed' ELSE 'left' END,left_at=%s WHERE call_id IN ($placeholders) AND status IN ('invited','joined')",
                            $now,
                            ...$call_ids
                        ));
                        $signals = $wpdb->query($wpdb->prepare('DELETE FROM ' . SN_DB::table('signals') . " WHERE call_id IN ($placeholders)", ...$call_ids));
                        if ($ended === false || $members === false || $signals === false) {
                            throw new RuntimeException('active_call_block_cleanup_failed');
                        }
                    }
                }
            } else {
                if ($wpdb->delete(SN_DB::table('blocks'), ['user_id' => $user_id, 'blocked_user_id' => $target_id], ['%d', '%d']) === false) {
                    throw new RuntimeException('unblock_write_failed');
                }
                $contact = SN_DB::contact_record($user_id, $target_id);
                if ($contact && (string) $contact->status === 'blocked' && $wpdb->update(SN_DB::table('contacts'), ['status' => 'declined', 'updated_at' => $now], ['id' => (int) $contact->id]) === false) {
                    throw new RuntimeException('contact_unblock_failed');
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }
        SN_DB::audit($blocked ? 'user_blocked' : 'user_unblocked', 'user', $target_id);
        return rest_ensure_response(['blocked' => $blocked]);
    }

    public static function report(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $reporter_id = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('report', (string) $reporter_id, 20, DAY_IN_SECONDS)) {
            return self::rate_limited();
        }
        $reported_user_id = absint($request->get_param('reported_user_id'));
        $conversation_id = absint($request->get_param('conversation_id'));
        $message_id = absint($request->get_param('message_id'));
        if ($conversation_id && !SN_DB::is_member($conversation_id, $reporter_id)) {
            return self::not_found();
        }
        if ($message_id) {
            $message = self::message_row($message_id);
            if (!$message || !SN_DB::is_member((int) $message->conversation_id, $reporter_id) || ($conversation_id && (int) $message->conversation_id !== $conversation_id)) {
                return self::not_found();
            }
            $conversation_id = (int) $message->conversation_id;
            if ($reported_user_id && $reported_user_id !== (int) $message->sender_id) {
                return new WP_Error('invalid_reported_user', 'The reported user does not match the reported message.', ['status' => 400]);
            }
            $reported_user_id = (int) $message->sender_id;
        }
        if ($conversation_id && $reported_user_id && !SN_DB::is_member($conversation_id, $reported_user_id)) {
            return new WP_Error('invalid_reported_user', 'The reported user is not a member of this conversation.', ['status' => 400]);
        }
        if ($reported_user_id && ($reported_user_id === $reporter_id || !get_user_by('id', $reported_user_id))) {
            return new WP_Error('invalid_reported_user', 'Select a valid reported user.', ['status' => 400]);
        }
        if (!$reported_user_id && !$conversation_id) {
            return new WP_Error('invalid_report_target', 'Select a valid report target.', ['status' => 400]);
        }
        $category = sanitize_key((string) $request->get_param('category'));
        $allowed = ['spam', 'fraud', 'harassment', 'threat', 'hate', 'impersonation', 'fake_doctor', 'medical_misinformation', 'sexual_content', 'child_safety', 'illegal_products', 'malware', 'stolen_account', 'privacy'];
        if (!in_array($category, $allowed, true)) {
            return new WP_Error('invalid_report_category', 'Choose a valid report category.', ['status' => 400]);
        }
        $details = mb_substr(sanitize_textarea_field((string) $request->get_param('details')), 0, 4000);
        $evidence = self::sanitize_report_evidence($request->get_param('evidence'));
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(SN_DB::table('reports'), [
            'reporter_id' => $reporter_id,
            'reported_user_id' => $reported_user_id,
            'conversation_id' => $conversation_id,
            'message_id' => $message_id,
            'category' => $category,
            'details' => $details,
            'evidence' => wp_json_encode($evidence),
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok === false) {
            return self::database_error();
        }
        $id = (int) $wpdb->insert_id;
        SN_DB::audit('report_created', 'report', $id, 'success', ['category' => $category, 'conversation_id' => $conversation_id, 'message_id' => $message_id]);
        return rest_ensure_response(['reported' => true, 'id' => $id]);
    }

    private static function required_tables(): array {
        return ['conversations', 'members', 'messages', 'reactions', 'contacts', 'updates', 'update_views', 'calls', 'call_members', 'signals', 'presence', 'typing', 'notifications', 'blocks', 'reports', 'attachments', 'rate_limits', 'audit_log'];
    }

    private static function identity_authority_ready(): bool {
        return SN_Policy::identity_authority_available();
    }

    private static function client_capabilities(int $user_id): array {
        return [
            'create_group' => SN_Policy::can_create_conversation($user_id, 'group'),
            'create_community' => SN_Policy::can_create_conversation($user_id, 'community'),
            'create_channel' => SN_Policy::can_create_conversation($user_id, 'channel'),
            'publish_public_update' => SN_Policy::can_publish_public_update($user_id),
            'group_calls' => has_filter('sn_network_group_call_create_result') && (bool) apply_filters('sn_network_group_call_ui_available', false, $user_id),
        ];
    }

    private static function search_directory(string $search, int $viewer_id, array $exclude_ids): array {
        $exclude_ids[] = $viewer_id;
        $exclude_ids = array_values(array_unique(array_map('absint', $exclude_ids)));
        $ids = get_users([
            'number' => 20,
            'exclude' => $exclude_ids,
            'search' => '*' . $search . '*',
            'search_columns' => ['display_name'],
            'fields' => 'ID',
            'count_total' => false,
        ]);
        if ((bool) apply_filters('sn_network_allow_phone_directory_lookup', false, $viewer_id, $search)) {
            $phone = SN_Auth::normalize_phone($search);
            if (!is_wp_error($phone)) {
                $user = SN_Auth::user_by_phone($phone);
                if ($user && !in_array((int) $user->ID, $exclude_ids, true)) {
                    array_unshift($ids, (int) $user->ID);
                }
            }
        }
        $output = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 20) as $id) {
            if (SN_Policy::is_minor($id) && !(bool) apply_filters('sn_network_minor_discoverable', false, $id, $viewer_id)) {
                continue;
            }
            $user = SN_Auth::public_user($id);
            if ($user) {
                $output[] = $user;
            }
        }
        return $output;
    }

    private static function format_conversation(object $row, int $viewer_id, bool $include_members = false): array {
        $member_ids = self::conversation_member_ids((int) $row->id);
        $members = array_values(array_filter(array_map(fn($id) => SN_Auth::public_user($id), $member_ids)));
        $title = (string) $row->title;
        $avatar = '';
        if ((string) $row->type === 'direct') {
            foreach ($members as $member) {
                if ((int) $member['id'] !== $viewer_id) {
                    $title = (string) $member['name'];
                    $avatar = (string) $member['avatar'];
                    break;
                }
            }
        }
        if (!$avatar) {
            $avatar = SN_URL . 'assets/network-default-avatar.svg';
        }
        $item = [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'title' => $title ?: ucfirst((string) $row->type),
            'description' => (string) ($row->description ?? ''),
            'avatar' => $avatar,
            'privacy' => (string) ($row->privacy ?? 'private'),
            'owner_id' => (int) $row->owner_id,
            'status' => (string) ($row->status ?? 'active'),
            'last_message' => isset($row->last_message_at) && $row->last_message_at ? [
                'body' => mb_substr((string) $row->last_body, 0, 160),
                'type' => (string) $row->last_type,
                'sender_id' => (int) $row->last_sender_id,
                'created_at' => (string) $row->last_message_at,
            ] : null,
            'unread_count' => (int) ($row->unread_count ?? 0),
            'muted' => (bool) ($row->is_muted ?? SN_DB::member_preferences((int) $row->id, $viewer_id)['muted']),
            'archived' => (bool) ($row->is_archived ?? SN_DB::member_preferences((int) $row->id, $viewer_id)['archived']),
            'can_post' => !is_wp_error(SN_Policy::can_post_to_conversation($row, $viewer_id)),
            'updated_at' => (string) $row->updated_at,
        ];
        if ($include_members) {
            $item['member_ids'] = $member_ids;
            $item['members'] = array_values(array_map(static function (array $member) use ($row): array {
                $member['conversation_role'] = SN_DB::member_role((int) $row->id, (int) $member['id']);
                return $member;
            }, $members));
            $item['viewer_role'] = SN_DB::member_role((int) $row->id, $viewer_id);
        }
        return $item;
    }

    private static function format_message(object $row, int $viewer_id): array {
        $attachment = null;
        if (!$row->deleted_at && (int) $row->attachment_id) {
            $attachment = (string) $row->attachment_source === 'private'
                ? SN_Private_Files::formatted((int) $row->attachment_id, $viewer_id)
                : ['id' => (int) $row->attachment_id, 'unavailable' => true, 'title' => 'Legacy attachment requires controlled migration'];
        }
        $sender = (int) $row->sender_id ? SN_Auth::public_user((int) $row->sender_id) : [];
        if (!$sender) {
            $sender = ['id' => 0, 'name' => 'Unavailable account', 'avatar' => SN_URL . 'assets/network-default-avatar.svg'];
        }
        return [
            'id' => (int) $row->id,
            'conversation_id' => (int) $row->conversation_id,
            'sender' => $sender,
            'message_type' => (string) $row->message_type,
            'body' => $row->deleted_at ? '' : (string) $row->body,
            'attachment' => $attachment,
            'reply_to' => (int) $row->reply_to,
            'reactions' => self::message_reactions((int) $row->id),
            'edited' => (bool) $row->edited_at,
            'deleted' => (bool) $row->deleted_at,
            'created_at' => (string) $row->created_at,
        ];
    }

    private static function format_update(object $row, int $viewer_id): array {
        $media = null;
        if ((int) $row->media_id) {
            $media = (string) $row->media_source === 'private'
                ? SN_Private_Files::formatted((int) $row->media_id, $viewer_id)
                : ['id' => (int) $row->media_id, 'unavailable' => true, 'title' => 'Legacy media requires controlled migration'];
        }
        return [
            'id' => (int) $row->id,
            'user' => SN_Auth::public_user((int) $row->user_id),
            'body' => (string) $row->body,
            'media' => $media,
            'media_type' => (string) $row->media_type,
            'privacy' => (string) $row->privacy,
            'created_at' => (string) $row->created_at,
            'expires_at' => (string) $row->expires_at,
        ];
    }

    private static function format_call(object $row): array {
        return [
            'id' => (int) $row->id,
            'conversation_id' => (int) $row->conversation_id,
            'initiator_id' => (int) $row->initiator_id,
            'type' => (string) $row->call_type,
            'status' => (string) $row->status,
            'member_status' => (string) ($row->member_status ?? ''),
            'members' => array_values(array_filter(array_map(fn($id) => SN_Auth::public_user($id), self::call_member_ids((int) $row->id)))),
            'created_at' => (string) $row->created_at,
            'started_at' => (string) $row->started_at,
            'ended_at' => (string) $row->ended_at,
        ];
    }

    private static function message_reactions(int $message_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total FROM ' . SN_DB::table('reactions') . ' WHERE message_id=%d GROUP BY reaction', $message_id));
        return array_map(static fn($row) => ['reaction' => (string) $row->reaction, 'count' => (int) $row->total], $rows);
    }

    private static function can_view_update(object $row, int $viewer_id): bool {
        if ((int) $row->user_id === $viewer_id) {
            return true;
        }
        if ((string) $row->privacy === 'public') {
            return !SN_Policy::is_minor((int) $row->user_id);
        }
        return (string) $row->privacy === 'contacts' && SN_DB::are_contacts($viewer_id, (int) $row->user_id) && !SN_DB::is_blocked($viewer_id, (int) $row->user_id);
    }

    private static function conversation_contact_check(object $conversation, int $conversation_id, int $actor_id, string $context): true|WP_Error {
        if ((string) $conversation->type !== 'direct') {
            foreach (self::conversation_member_ids($conversation_id) as $target_id) {
                if ($target_id !== $actor_id && SN_DB::is_blocked($actor_id, $target_id)) {
                    return new WP_Error('blocked', 'A conversation member is unavailable.', ['status' => 403]);
                }
            }
            return true;
        }
        $others = array_values(array_diff(self::conversation_member_ids($conversation_id), [$actor_id]));
        if (count($others) !== 1) {
            return new WP_Error('invalid_direct_conversation', 'The direct conversation membership is invalid.', ['status' => 409]);
        }
        return SN_Policy::can_contact($actor_id, $others[0], $context);
    }

    private static function direct_conversation_row(int $a, int $b): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('conversations') . ' WHERE type=%s AND direct_key=%s LIMIT 1', 'direct', SN_DB::direct_key($a, $b)));
        return is_object($row) ? $row : null;
    }

    private static function restore_direct_conversation(object $conversation, int $a, int $b): true|WP_Error {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->update(SN_DB::table('conversations'), ['status' => 'active', 'updated_at' => $now], ['id' => (int) $conversation->id]) === false) {
                throw new RuntimeException('conversation_restore_failed');
            }
            foreach ([$a, $b] as $member_id) {
                $member = self::member_row((int) $conversation->id, $member_id);
                $role = (int) $conversation->owner_id === $member_id ? 'owner' : 'member';
                $ok = $member
                    ? $wpdb->update(SN_DB::table('members'), ['role' => $role, 'left_at' => null, 'joined_at' => $now], ['id' => (int) $member->id])
                    : $wpdb->insert(SN_DB::table('members'), ['conversation_id' => (int) $conversation->id, 'user_id' => $member_id, 'role' => $role, 'joined_at' => $now]);
                if ($ok === false) {
                    throw new RuntimeException('member_restore_failed');
                }
            }
            $wpdb->query('COMMIT');
            $conversation->status = 'active';
            $conversation->updated_at = $now;
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }
    }

    private static function conversation_row(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('conversations') . ' WHERE id=%d AND status=%s', $id, 'active'));
        return is_object($row) ? $row : null;
    }

    private static function member_row(int $conversation_id, int $user_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id=%d LIMIT 1', $conversation_id, $user_id));
        return is_object($row) ? $row : null;
    }

    private static function message_row(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        return is_object($row) ? $row : null;
    }

    private static function message_in_conversation(int $message_id, int $conversation_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id=%d AND conversation_id=%d', $message_id, $conversation_id));
    }

    private static function conversation_member_ids(int $conversation_id): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL ORDER BY id ASC', $conversation_id)));
    }

    private static function call_member_ids(int $call_id): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . SN_DB::table('call_members') . ' WHERE call_id=%d ORDER BY id ASC', $call_id)));
    }

    private static function call_row(int $call_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('calls') . ' WHERE id=%d', $call_id));
        return is_object($row) ? $row : null;
    }

    private static function call_member_row(int $call_id, int $user_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('call_members') . ' WHERE call_id=%d AND user_id=%d', $call_id, $user_id));
        return is_object($row) ? $row : null;
    }


    private static function sanitize_signal_payload(string $type, $payload): array|WP_Error {
        if (!is_array($payload)) {
            return new WP_Error('invalid_signal_payload', 'The WebRTC signal payload must be a JSON object.', ['status' => 400]);
        }
        if (in_array($type, ['offer', 'answer'], true)) {
            $sdp_type = sanitize_key((string) ($payload['type'] ?? ''));
            $sdp = (string) ($payload['sdp'] ?? '');
            if ($sdp_type !== $type || $sdp === '' || strlen($sdp) > 49152 || !str_starts_with($sdp, 'v=0')) {
                return new WP_Error('invalid_signal_payload', 'The session description is invalid.', ['status' => 400]);
            }
            return ['type' => $sdp_type, 'sdp' => $sdp];
        }
        if ($type === 'candidate') {
            $candidate = (string) ($payload['candidate'] ?? '');
            if ($candidate === '' || strlen($candidate) > 8192) {
                return new WP_Error('invalid_signal_payload', 'The ICE candidate is invalid.', ['status' => 400]);
            }
            return [
                'candidate' => $candidate,
                'sdpMid' => mb_substr(sanitize_text_field((string) ($payload['sdpMid'] ?? '')), 0, 64),
                'sdpMLineIndex' => isset($payload['sdpMLineIndex']) ? max(0, min(65535, (int) $payload['sdpMLineIndex'])) : null,
                'usernameFragment' => mb_substr(sanitize_text_field((string) ($payload['usernameFragment'] ?? '')), 0, 256),
            ];
        }
        if ($type === 'renegotiate') {
            return [];
        }
        return [];
    }

    private static function sanitize_report_evidence($evidence): array {
        if (!is_array($evidence)) {
            return [];
        }
        $clean = [];
        foreach (array_slice($evidence, 0, 20, true) as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key !== '' && is_scalar($value)) {
                $clean[$key] = mb_substr(sanitize_text_field((string) $value), 0, 500);
            }
        }
        return $clean;
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested Network item is unavailable.', ['status' => 404]);
    }

    private static function rate_limited(): WP_Error {
        return new WP_Error('rate_limited', 'Too many requests. Please wait and try again.', ['status' => 429]);
    }

    private static function database_error(): WP_Error {
        return new WP_Error('database_error', 'The Network request could not be completed.', ['status' => 500]);
    }
}
