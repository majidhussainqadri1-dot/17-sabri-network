<?php
/** Frozen Round-20 corrective closure: lifecycle, interoperability, privacy and release-runtime invariants. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Round20_Correction {
    private const LOCK_TIMEOUT = 5;
    private const EXPIRY_CURSOR_OPTION = 'sn_message_expiry_scan_after';
    private const EXPIRY_SCAN_BATCH = 250;

    public static function register(): void {
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], -29995, 3);
        add_filter('rest_post_dispatch', [self::class, 'post_dispatch'], PHP_INT_MAX, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 2600);
        add_action('sn_cleanup_hourly', [self::class, 'expire_stale_high_risk'], 1);
        remove_action('sn_cleanup_hourly', [SN_Two_Plan_Runtime_Hardening::class, 'expire_messages_cursor'], 12);
        add_action('sn_cleanup_hourly', [self::class, 'expire_messages_cursor'], 12);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_future_eraser'], 100);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 100);
        add_action('wp_footer', [self::class, 'enqueue_footer_asset'], 1);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)', [
            'methods'=>'DELETE','callback'=>[self::class,'delete_message'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/future/records/(?P<id>\d+)', [
            'methods'=>'DELETE','callback'=>[self::class,'delete_future_record'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/structured', [
            'methods'=>'GET','callback'=>[self::class,'structured_message'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $method = strtoupper($request->get_method());
        $route = $request->get_route();
        $locks = [];

        if ($method === 'POST' && $route === '/sabri-network/v2/future/interop') {
            $conversation = absint($request->get_param('conversation_id'));
            if ($conversation > 0) $locks[] = self::conversation_lock($conversation);
        } elseif (preg_match('#^/sabri-network/v2/future/interop/(\d+)(?:/(outbound|inbound))?$#', $route, $m)) {
            $bridge = self::typed_bridge((int)$m[1]);
            if (is_wp_error($bridge)) return $bridge;
            $locks[] = 'sn:f17:interop-bridge:' . (int)$bridge->id;
            $locks[] = self::conversation_lock((int)$bridge->scope_id);
        }

        if (($method === 'DELETE' && preg_match('#^/sabri-network/v2/messages/(\d+)$#', $route, $m))
            || ($method === 'POST' && preg_match('#^/sabri-network/v2/messages/(\d+)/poll-vote$#', $route, $m))) {
            $message_id = (int)$m[1];
            global $wpdb;
            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $message_id));
            if ($conversation > 0) {
                // Acquire the canonical conversation/message locks together with retention in one sorted set.
                // This keeps delete/poll-vote lock order compatible with expiry and legal-hold mutations.
                $locks[] = self::conversation_lock($conversation);
                $locks[] = 'sn:f17:msg-edit:' . $message_id;
            }
            $locks[] = self::retention_lock($message_id);
        }

        if ($method === 'POST' && $request->has_param('legal_hold') && preg_match('#^/sabri-network/v2/admin/reports/(\d+)$#', $route, $m)) {
            global $wpdb;
            $message_id = (int)$wpdb->get_var($wpdb->prepare('SELECT message_id FROM ' . SN_DB::table('reports') . ' WHERE id=%d', (int)$m[1]));
            if ($message_id > 0) $locks[] = self::retention_lock($message_id);
        }

        if (!$locks) return $result;
        $held = self::acquire($locks);
        if (is_wp_error($held)) return $held;
        $request->set_param('_sn_round20_locks', $held);

        if (preg_match('#^/sabri-network/v2/future/interop/(\d+)(?:/(?:outbound|inbound))?$#', $route, $m)) {
            $fresh = self::typed_bridge((int)$m[1]);
            if (is_wp_error($fresh)) { self::release($held); $request->set_param('_sn_round20_locks', []); return $fresh; }
        }
        return $result;
    }

    public static function post_dispatch($response, WP_REST_Server $server, WP_REST_Request $request) {
        $held = $request->get_param('_sn_round20_locks');
        if (is_array($held) && $held) self::release($held);
        $request->set_param('_sn_round20_locks', []);
        return is_wp_error($response) ? rest_convert_error_to_response($response) : $response;
    }

    public static function structured_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $response = SN_Two_Plan_Presentation::structured_message($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        $row = $wpdb->get_row($wpdb->prepare('SELECT metadata FROM ' . SN_DB::table('messages') . ' WHERE id=%d', absint($request['id'])));
        if (!$row) return self::not_found();
        $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : [];
        $data['version'] = max(1, absint($meta['_mutation_version'] ?? 1));
        $response->set_data($data);
        return $response;
    }

    public static function delete_future_record(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']); $user = get_current_user_id();
        $row = $wpdb->get_row($wpdb->prepare("SELECT feature_id FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND owner_id=%d", $id, $user));
        if (!$row) return self::not_found();
        if ((string)$row->feature_id === 'F17-FUT-24') {
            return new WP_Error('sn_interop_governed_delete_required', 'Interoperability bridges and replay receipts must use their governed revoke/reconciliation lifecycle.', ['status'=>409]);
        }
        return SN_Future_Superset::delete_owned_record($request);
    }

    public static function delete_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']); $actor = get_current_user_id(); $expected = absint($request->get_param('expected_version'));
        if ($expected <= 0) return new WP_Error('message_version_required', 'An exact message version is required.', ['status'=>400]);
        $messages = SN_DB::table('messages'); $members = SN_DB::table('members');
        $probe = $wpdb->get_row($wpdb->prepare("SELECT conversation_id FROM $messages WHERE id=%d", $id));
        if (!$probe) return self::not_found();
        $conversation = (int)$probe->conversation_id;
        if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
        $attachment = 0;
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE", $id));
            $member = $wpdb->get_row($wpdb->prepare("SELECT id FROM $members WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL FOR UPDATE", $conversation, $actor));
            if (!$row || !$member || !empty($row->deleted_at)) throw new DomainException('not_found');
            $meta = json_decode((string)$row->metadata, true); $meta = is_array($meta) ? $meta : [];
            $version = max(1, absint($meta['_mutation_version'] ?? 1));
            if ($version !== $expected) throw new UnexpectedValueException('version_conflict');
            if (!SN_Policy::can_delete_message($row, $actor)) throw new UnexpectedValueException('delete_forbidden');
            $attachment = (string)$row->attachment_source === 'private' ? (int)$row->attachment_id : 0;
            $meta['_mutation_version'] = $expected + 1;
            $deleted_at = current_time('mysql', true);
            $changed = $wpdb->query($wpdb->prepare("UPDATE $messages SET body='',attachment_id=0,attachment_source='erased',metadata=%s,deleted_at=%s WHERE id=%d AND deleted_at IS NULL", (string)wp_json_encode($meta), $deleted_at, $id));
            if ($changed !== 1) throw new RuntimeException('message_delete_failed');
            if ($wpdb->delete(SN_DB::table('reactions'), ['message_id'=>$id], ['%d']) === false) throw new RuntimeException('message_reaction_delete_failed');
            if ($wpdb->delete($wpdb->prefix . 'sn_poll_votes', ['message_id'=>$id], ['%d']) === false) throw new RuntimeException('message_poll_vote_delete_failed');
            $removed = SN_Message_Search::remove_message($id); if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
            $event = SN_Outbox::enqueue('message.deleted','message',$id,['message_id'=>$id,'conversation_id'=>$conversation,'sender_id'=>(int)$row->sender_id,'deleted_by'=>$actor,'deleted_at'=>$deleted_at,'version'=>$expected+1], 'message.deleted:' . $id . ':v' . ($expected+1));
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_delete_commit_failed');
            if ($attachment > 0) SN_Private_Files::delete($attachment, $actor);
            SN_DB::audit('message_deleted','message',$id,'success',['conversation_id'=>$conversation,'version'=>$expected+1],$actor);
            do_action('sn_network_event_queued',$event,'message.deleted');
            return rest_ensure_response(['deleted'=>true,'version'=>$expected+1]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof DomainException) return self::not_found();
            if ($e instanceof UnexpectedValueException && $e->getMessage()==='version_conflict') return new WP_Error('message_version_conflict','The message changed. Reload the authoritative version and retry.',['status'=>409]);
            if ($e instanceof UnexpectedValueException && $e->getMessage()==='delete_forbidden') return new WP_Error('delete_forbidden','This message can no longer be deleted.',['status'=>403]);
            SN_DB::audit('message_atomic_delete_failed','message',$id,'failure',['reason'=>$e->getMessage()],$actor);
            return new WP_Error('message_atomic_delete_failed','The message deletion could not be committed.',['status'=>500]);
        }
    }

    public static function expire_messages_cursor(): void {
        global $wpdb;
        $messages = SN_DB::table('messages'); $cursor = max(0,(int)get_option(self::EXPIRY_CURSOR_OPTION,0)); $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM $messages WHERE id>%d AND deleted_at IS NULL AND metadata IS NOT NULL AND metadata<>'' ORDER BY id ASC LIMIT %d", $cursor, self::EXPIRY_SCAN_BATCH));
        if (!is_array($rows) || !$rows) { update_option(self::EXPIRY_CURSOR_OPTION,0,false); return; }
        foreach ($rows as $probe) {
            $id=(int)$probe->id; update_option(self::EXPIRY_CURSOR_OPTION,$id,false);
            $held=self::acquire([self::retention_lock($id)]); if (is_wp_error($held)) continue;
            try {
                if ($wpdb->query('START TRANSACTION') === false) continue;
                try {
                    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $messages WHERE id=%d FOR UPDATE",$id));
                    if (!$row || $row->deleted_at!==null) { $wpdb->query('COMMIT'); continue; }
                    $meta=json_decode((string)$row->metadata,true); $meta=is_array($meta)?$meta:[]; $expires=(string)($meta['expires_at']??'');
                    if ($expires==='' || strtotime($expires.' UTC')>time() || self::message_has_legal_hold($id)) { $wpdb->query('COMMIT'); continue; }
                    $version=max(1,absint($meta['_mutation_version']??1))+1; $meta=['expired'=>true,'expired_at'=>$now,'_mutation_version'=>$version];
                    $attachment=(string)$row->attachment_source==='private'?(int)$row->attachment_id:0;
                    $updated=$wpdb->query($wpdb->prepare("UPDATE $messages SET body='',attachment_id=0,attachment_source='expired',metadata=%s,deleted_at=%s WHERE id=%d AND deleted_at IS NULL",(string)wp_json_encode($meta),$now,$id));
                    if ($updated!==1) throw new RuntimeException('expire_update_failed');
                    if ($wpdb->delete(SN_DB::table('reactions'),['message_id'=>$id],['%d'])===false) throw new RuntimeException('expire_reactions_failed');
                    if ($wpdb->delete($wpdb->prefix.'sn_poll_votes',['message_id'=>$id],['%d'])===false) throw new RuntimeException('expire_poll_votes_failed');
                    $removed=SN_Message_Search::remove_message($id); if (is_wp_error($removed)) throw new RuntimeException($removed->get_error_code());
                    $event=SN_Outbox::enqueue('message.expired','message',$id,['message_id'=>$id,'conversation_id'=>(int)$row->conversation_id,'expired_at'=>$now,'version'=>$version],'message.expired:'.$id.':v'.$version); if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
                    if ($wpdb->query('COMMIT')===false) throw new RuntimeException('expire_commit_failed');
                    if ($attachment>0) SN_Private_Files::delete($attachment,(int)$row->sender_id);
                    SN_DB::audit('message_expired','message',$id,'success',['version'=>$version],0); do_action('sn_network_event_queued',$event,'message.expired');
                } catch (Throwable $e) { $wpdb->query('ROLLBACK'); SN_DB::audit('message_expiry_failed','message',$id,'failure',['reason'=>$e->getMessage()],0); }
            } finally { self::release($held); }
        }
        if (count($rows)<self::EXPIRY_SCAN_BATCH) update_option(self::EXPIRY_CURSOR_OPTION,0,false);
    }

    public static function expire_stale_high_risk(): void {
        global $wpdb; $now=current_time('mysql',true); $stale=gmdate('Y-m-d H:i:s',time()-10*MINUTE_IN_SECONDS); $table=SN_DB::table('high_risk_actions');
        $wpdb->query($wpdb->prepare("UPDATE $table SET status='expired',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND expires_at<=%s LIMIT 100",$now,$stale,$now));
    }

    public static function override_future_eraser(array $erasers): array {
        if (isset($erasers['sabri-network-future'])) $erasers['sabri-network-future']['callback']=[self::class,'future_erase'];
        return $erasers;
    }

    public static function future_erase(string $email, int $page=1): array {
        global $wpdb; $user=get_user_by('email',$email); if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
        $uid=(int)$user->ID; $table=$wpdb->prefix.'sn_future_records'; $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE owner_id=%d AND state NOT IN ('deleted','erased') ORDER BY id ASC LIMIT 100",$uid));
        $removed=0;$retained=0;
        foreach(is_array($rows)?$rows:[] as $row){
            if(in_array((string)$row->feature_id,['F17-FUT-03','F17-FUT-24'],true)){ $retained++; continue; }
            if($wpdb->update($table,['payload_cipher'=>null,'state'=>'erased','updated_at'=>current_time('mysql',true)],['id'=>(int)$row->id])!==false)$removed++;
        }
        $versions=$wpdb->get_results($wpdb->prepare('SELECT v.id,v.message_id FROM '.$wpdb->prefix.'sn_future_message_versions v INNER JOIN '.SN_DB::table('messages').' m ON m.id=v.message_id WHERE m.sender_id=%d LIMIT 200',$uid));
        foreach(is_array($versions)?$versions:[] as $version){ if((bool)apply_filters('sn_network_message_version_hold',false,(int)$version->message_id,$uid)){ $retained++; continue; } if($wpdb->delete($wpdb->prefix.'sn_future_message_versions',['id'=>(int)$version->id],['%d'])===1)$removed++; }
        return ['items_removed'=>$removed>0,'items_retained'=>$retained>0,'messages'=>$retained?['Governed key-transparency/interoperability or held integrity evidence was retained.']:[],'done'=>count(is_array($rows)?$rows:[])<100&&count(is_array($versions)?$versions:[])<200];
    }

    public static function register_assets(): void { wp_register_script('sn-round20-correction',SN_URL.'assets/js/round20-correction.js',[],SN_VERSION,true); }
    public static function enqueue_footer_asset(): void { if(wp_script_is('sn-two-plan-ui','enqueued')) wp_enqueue_script('sn-round20-correction'); }

    private static function typed_bridge(int $id): stdClass|WP_Error {
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_future_records WHERE id=%d AND feature_id='F17-FUT-24' AND scope_type='conversation' LIMIT 1",$id));
        if(!$row)return self::not_found();
        $plain=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|F17-FUT-24|'.(int)$row->owner_id.'|conversation|'.(int)$row->scope_id);
        if(is_wp_error($plain))return self::not_found(); $data=json_decode($plain,true);
        return is_array($data)&&($data['subtype']??'')==='bridge'?$row:self::not_found();
    }
    private static function message_has_legal_hold(int $id): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('reports').' WHERE message_id=%d AND legal_hold=1 LIMIT 1',$id)); }
    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:'.substr(hash('sha256',(string)$id),0,32); }
    private static function retention_lock(int $id): string { return 'sn:f17:message-retention:'.$id; }
    private static function acquire(array $locks): array|WP_Error { global $wpdb; $locks=array_values(array_unique(array_filter($locks))); sort($locks,SORT_STRING); $held=[]; foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT)); if($ok!==1){self::release($held); return new WP_Error('sn_round20_mutation_busy','The communication state is changing. Retry the request.',['status'=>409]);}$held[]=$lock;} return $held; }
    private static function release(array $locks): void { global $wpdb; foreach(array_reverse($locks) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',(string)$lock)); }
    private static function not_found(): WP_Error { return new WP_Error('not_found','Requested communication object is unavailable.',['status'=>404]); }
    private static function database_error(): WP_Error { return new WP_Error('database_error','The communication change could not be committed safely.',['status'=>500]); }
}
