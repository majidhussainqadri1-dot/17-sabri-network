<?php
/** Fourth fresh cycle: scheduled/disappearing/translation/reminder lifecycle hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Lifecycle_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        // Run before translation provider authorization and Future-24 pre-dispatch hooks.
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], -29998, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 2180);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/scheduled-messages', [
            ['methods'=>'GET','callback'=>[SN_Two_Plan_Completion::class,'list_scheduled'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'schedule_message'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/expiry', [
            'methods'=>'POST','callback'=>[self::class,'set_message_expiry'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $route = $request->get_route();
        $method = strtoupper($request->get_method());
        if ($method !== 'POST' || !str_starts_with($route, '/sabri-network/v2/')) return $result;
        $actor = get_current_user_id();

        // Translation authorization/provider hooks may themselves incur external work;
        // prove the message object and membership before any later provider hook runs.
        if (preg_match('#^/sabri-network/v2/messages/(\d+)/translate$#', $route, $match)) {
            $row = self::message((int) $match[1]);
            if (!$row || $row->deleted_at !== null || !SN_DB::is_member((int) $row->conversation_id, $actor)) return self::not_found();
        }

        // Expiry is a message mutation and therefore participates in the same CAS
        // version domain as edit/delete/voice metadata rather than overwriting metadata blindly.
        if (preg_match('#^/sabri-network/v2/messages/(\d+)/expiry$#', $route, $match)) {
            $expected = absint($request->get_param('expected_version'));
            if ($expected <= 0) return new WP_Error('message_version_required', 'An exact message version is required.', ['status'=>400]);
            $row = self::message((int) $match[1]);
            if (!$row || $row->deleted_at !== null || !SN_DB::is_member((int) $row->conversation_id, $actor)) return self::not_found();
            if (self::version($row) !== $expected) return self::version_conflict();
        }

        // Reminder message pointers must remain inside the caller-authorized conversation.
        if ($route === '/sabri-network/v2/future/reminders') {
            $conversation = absint($request->get_param('conversation_id'));
            $message_id = absint($request->get_param('message_id'));
            if ($conversation > 0 && !SN_DB::is_member($conversation, $actor)) return self::not_found();
            if ($message_id > 0) {
                $row = self::message($message_id);
                if (!$row || $row->deleted_at !== null || (int) $row->conversation_id !== $conversation || !SN_DB::is_member($conversation, $actor)) return self::not_found();
            }
        }
        return $result;
    }

    public static function schedule_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        if ($conversation <= 0) return self::not_found();
        return self::with_lock(self::conversation_lock($conversation), static function () use ($request, $conversation) {
            $actor = get_current_user_id();
            if (!SN_DB::is_member($conversation, $actor)) return self::not_found();
            // Original owner performs body/time/link/idempotency validation. Executing it
            // under the conversation lock makes its current policy verdict and persisted
            // scheduled state one serialized authorization step.
            return SN_Two_Plan_Completion::schedule_message($request);
        });
    }

    public static function set_message_expiry(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $actor = get_current_user_id();
        $expected = absint($request->get_param('expected_version'));
        $seconds = absint($request->get_param('seconds'));
        if ($expected <= 0) return new WP_Error('message_version_required','An exact message version is required.',['status'=>400]);
        if (!in_array($seconds, [0,3600,86400,604800,2592000], true)) return new WP_Error('sn_expiry_invalid','Expiry must be off, 1 hour, 1 day, 7 days or 30 days.',['status'=>400]);
        $probe = self::message($id);
        if (!$probe) return self::not_found();
        $conversation = (int) $probe->conversation_id;

        return self::with_lock(self::conversation_lock($conversation), function () use ($wpdb,$id,$actor,$expected,$seconds,$conversation) {
            return self::with_lock(self::retention_lock($id), function () use ($wpdb,$id,$actor,$expected,$seconds,$conversation) {
            if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
            try {
                $messages = SN_DB::table('messages');
                $members = SN_DB::table('members');
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
                $member = $wpdb->get_row($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", $conversation, $actor));
                if (!$row || !$member || $row->deleted_at !== null || (int) $row->sender_id !== $actor) throw new DomainException('not_found');
                if (self::version($row) !== $expected) throw new UnexpectedValueException('version_conflict');
                $hold = self::legal_hold($id);
                if (is_wp_error($hold)) throw new RuntimeException('sn_legal_hold_verification_failed');
                if ($hold) throw new UnexpectedValueException('legal_hold');
                $meta = json_decode((string) $row->metadata, true); $meta = is_array($meta) ? $meta : [];
                if ($seconds === 0) unset($meta['expires_at']); else $meta['expires_at'] = gmdate('Y-m-d H:i:s', time() + $seconds);
                $meta['_mutation_version'] = $expected + 1;
                $changed = $wpdb->query($wpdb->prepare("UPDATE $messages SET metadata=%s WHERE id=%d AND sender_id=%d AND deleted_at IS NULL", (string) wp_json_encode($meta), $id, $actor));
                if ($changed !== 1) throw new RuntimeException('expiry_update_failed');
                $event = SN_Outbox::enqueue('message.expiry_changed','message',$id,[
                    'message_id'=>$id,'conversation_id'=>$conversation,'sender_id'=>$actor,
                    'expires_at'=>$meta['expires_at'] ?? null,'version'=>$expected+1,
                ], 'message.expiry_changed:' . $id . ':v' . ($expected+1));
                if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('expiry_commit_failed');
                SN_DB::audit('message_expiry_changed','message',$id,'success',['seconds'=>$seconds,'version'=>$expected+1],$actor);
                do_action('sn_network_event_queued',$event,'message.expiry_changed');
                return rest_ensure_response(['message_id'=>$id,'expires_at'=>$meta['expires_at'] ?? null,'version'=>$expected+1]);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                if ($e instanceof DomainException) return self::not_found();
                if ($e instanceof UnexpectedValueException && $e->getMessage()==='version_conflict') return self::version_conflict();
                if ($e instanceof UnexpectedValueException && $e->getMessage()==='legal_hold') return new WP_Error('sn_expiry_legal_hold','This message is preserved by a safety/legal hold.',['status'=>409]);
                if ($e instanceof RuntimeException && $e->getMessage()==='sn_legal_hold_verification_failed') return new WP_Error('sn_legal_hold_verification_failed','The legal-hold state could not be verified safely. Retry the request.',['status'=>503]);
                SN_DB::audit('message_expiry_failed','message',$id,'failure',['reason'=>$e->getMessage()],$actor);
                return new WP_Error('sn_expiry_failed','The expiry setting could not be committed safely.',['status'=>500]);
            }
            });
        });
    }

    private static function legal_hold(int $id): bool|WP_Error {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('reports') . ' WHERE message_id=%d AND legal_hold=1 LIMIT 1', $id));
        if ($wpdb->last_error !== '') return new WP_Error('sn_legal_hold_verification_failed','The legal-hold state could not be verified safely.',['status'=>503]);
        return (bool)$value;
    }
    private static function message(int $id): ?object { global $wpdb; return $id>0 ? ($wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d',$id)) ?: null) : null; }
    private static function version(object $row): int { $m=json_decode((string)($row->metadata??''),true); return max(1,is_array($m)?absint($m['_mutation_version']??1):1); }
    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:' . substr(hash('sha256',(string)$id),0,32); }
    private static function retention_lock(int $id): string { return 'sn:f17:message-retention:' . $id; }
    private static function with_lock(string $lock, callable $callback) { global $wpdb; $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT)); if($ok!==1)return new WP_Error('sn_conversation_busy','The conversation is changing. Retry the request.',['status'=>409]); try{return $callback();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));} }
    private static function version_conflict(): WP_Error { return new WP_Error('message_version_conflict','The message changed. Reload the authoritative version and retry.',['status'=>409]); }
    private static function database_error(): WP_Error { return new WP_Error('database_error','The communication change could not be committed safely.',['status'=>500]); }
    private static function not_found(): WP_Error { return new WP_Error('not_found','The requested communication object is unavailable.',['status'=>404]); }
}
