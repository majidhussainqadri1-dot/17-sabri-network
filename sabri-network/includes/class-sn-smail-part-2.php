<?php
defined('ABSPATH') || exit;

trait SN_Smail_Part_2 {

    public static function send(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $sender_id = get_current_user_id();
        $draft_recipients = array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('recipient_ids')))));
        $recipients = array_values(array_diff($draft_recipients, [$sender_id]));
        sort($recipients, SORT_NUMERIC);
        if (!$recipients || count($recipients) > self::MAX_RECIPIENTS) return new WP_Error('invalid_recipients', 'Select between one and fifty permitted recipients.', ['status' => 400]);
        $subject = mb_substr(sanitize_text_field((string) $request->get_param('subject')), 0, self::MAX_SUBJECT);
        $draft_body = sanitize_textarea_field(wp_unslash((string) $request->get_param('body')));
        if (mb_strlen($draft_body) > 10000) return new WP_Error('smail_content_too_long', 'The Smail message is longer than the permitted limit.', ['status' => 413]);
        $body = trim($draft_body);
        if ($subject === '' || $body === '') return new WP_Error('smail_content_required', 'A subject and message are required.', ['status' => 400]);
        foreach ($recipients as $recipient_id) {$allowed = SN_Policy::can_contact($sender_id, $recipient_id, count($recipients) === 1 ? 'message' : 'group');if (is_wp_error($allowed)) return $allowed;}
        if (!SN_Policy::consume_rate_limit('smail_send', (string) $sender_id, 60, HOUR_IN_SECONDS)) return new WP_Error('smail_rate_limited', 'Too many Smail messages were sent. Try again later.', ['status' => 429]);
        $client_id = strtolower(trim((string) $request->get_param('client_id')));
        if ($client_id === '' || !preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client_id)) return new WP_Error('invalid_client_id', 'A caller-supplied Smail idempotency key is required.', ['status' => 400]);
        $draft_id = sanitize_text_field((string) $request->get_param('draft_id'));
        $draft_version = absint($request->get_param('draft_version'));
        $draft_payload_hash = self::draft_payload_hash($draft_recipients, $subject, $draft_body);
        $scope_hash = self::smail_scope_hash($recipients, $subject);
        $client_key = hash('sha256', $sender_id . '|' . $client_id);

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE client_key=%s', $client_key));
        if ($existing) {
            $projection = self::assert_same_projection($existing, $sender_id, $recipients, $subject, 0);if (is_wp_error($projection)) return $projection;
            $message_response = self::reconcile_canonical_message($existing, $body, $client_key, $scope_hash);if (is_wp_error($message_response)) return $message_response;
            $message_data = $message_response->get_data();$message_id = (int) ($message_data['message']['id'] ?? 0);
            if ($message_id !== (int) $existing->message_id) return new WP_Error('smail_projection_conflict', 'The Smail projection does not match its canonical message.', ['status' => 409]);
            $cleanup = self::cleanup_matching_draft($draft_id, $sender_id, $draft_version, $draft_payload_hash);
            return rest_ensure_response(['smail'=>self::format_smail($existing),'message'=>$message_data['message']??null,'duplicate'=>true,'draft_cleanup_pending'=>!$cleanup]);
        }

        if ($draft_id !== '') {$draft = self::draft_row($draft_id, $sender_id);if (!$draft) return new WP_Error('draft_not_found', 'The Smail draft is unavailable.', ['status'=>404]);if ($draft_version <= 0 || $draft_version !== (int)$draft->version) return new WP_Error('draft_conflict', 'The Smail draft changed on another device. Reload and retry.', ['status'=>409]);}

        $conversation_id = SN_Central_Plan_Hardening::resolve_smail_conversation($sender_id, $recipients, $subject, $client_key);if (is_wp_error($conversation_id)) return $conversation_id;$conversation_id = (int)$conversation_id;if (!$conversation_id) return new WP_Error('smail_conversation_failed','The Smail conversation could not be resolved.',['status'=>500]);
        $message_request = new WP_REST_Request('POST','/sabri-network/v2/conversations/'.$conversation_id.'/messages');$message_request->set_url_params(['id'=>$conversation_id]);$message_request->set_param('id',$conversation_id);$message_request->set_param('body',$body);$message_request->set_param('message_type','text');$message_request->set_param('client_id','smail:'.substr($client_key,0,40));$message_request->set_param('request_scope_hash',$scope_hash);
        $message_response = SN_Message_Runtime_Hardening::send_message($message_request);if (is_wp_error($message_response)) return $message_response;$message_data=$message_response->get_data();$message_id=(int)($message_data['message']['id']??0);if(!$message_id)return new WP_Error('smail_message_failed','The canonical message could not be created.',['status'=>500]);

        $now=current_time('mysql',true);$smail_id=0;$event=null;
        if($wpdb->query('START TRANSACTION')===false)return new WP_Error('smail_projection_failed','The canonical message was created but the mailbox transaction could not start.',['status'=>500]);
        try{
            $race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::messages_table().' WHERE client_key=%s FOR UPDATE',$client_key));
            if($race){$same=self::assert_same_projection($race,$sender_id,$recipients,$subject,$message_id);if(is_wp_error($same)){$wpdb->query('ROLLBACK');return $same;}$wpdb->query('ROLLBACK');$cleanup=self::cleanup_matching_draft($draft_id,$sender_id,$draft_version,$draft_payload_hash);return rest_ensure_response(['smail'=>self::format_smail($race),'message'=>$message_data['message']??null,'duplicate'=>true,'commit_reconciled'=>true,'draft_cleanup_pending'=>!$cleanup]);}
            if($wpdb->insert(self::messages_table(),['message_id'=>$message_id,'conversation_id'=>$conversation_id,'sender_id'=>$sender_id,'subject'=>$subject,'client_key'=>$client_key,'created_at'=>$now])===false)throw new RuntimeException('smail_projection_failed');
            $smail_id=(int)$wpdb->insert_id;
            foreach(array_values(array_unique(array_merge([$sender_id],$recipients))) as $user_id){if($wpdb->insert(self::states_table(),['smail_message_id'=>$smail_id,'user_id'=>$user_id,'updated_at'=>$now,'read_at'=>$user_id===$sender_id?$now:null])===false)throw new RuntimeException('smail_state_failed');}
            $event=SN_Outbox::enqueue('smail.sent','smail',$smail_id,['smail_id'=>$smail_id,'conversation_id'=>$conversation_id,'message_id'=>$message_id,'sender_id'=>$sender_id,'recipient_count'=>count($recipients)],'smail-sent-'.$smail_id);if(is_wp_error($event))throw new RuntimeException('smail_event_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('smail_projection_commit_failed');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');SN_DB::audit('smail_projection_failed','message',$message_id,'failure',['conversation_id'=>$conversation_id,'reason'=>$e->getMessage()]);
            $race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::messages_table().' WHERE client_key=%s',$client_key));
            if($race){$same=self::assert_same_projection($race,$sender_id,$recipients,$subject,$message_id);if(is_wp_error($same))return $same;$cleanup=self::cleanup_matching_draft($draft_id,$sender_id,$draft_version,$draft_payload_hash);return rest_ensure_response(['smail'=>self::format_smail($race),'message'=>$message_data['message']??null,'duplicate'=>true,'commit_reconciled'=>true,'draft_cleanup_pending'=>!$cleanup]);}
            return new WP_Error('smail_projection_failed','The canonical message was created but its Smail mailbox projection could not be completed.',['status'=>500]);
        }
        foreach($recipients as $recipient_id)SN_DB::add_notification($recipient_id,'smail_received','New Smail message','','smail',$smail_id);if($event!==null)do_action('sn_network_event_queued',$event,'smail.sent');SN_DB::audit('smail_sent','smail',$smail_id,'success',['conversation_id'=>$conversation_id,'recipients'=>count($recipients)]);
        $cleanup=self::cleanup_matching_draft($draft_id,$sender_id,$draft_version,$draft_payload_hash);$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::messages_table().' WHERE id=%d',$smail_id));return rest_ensure_response(['smail'=>self::format_smail($row),'message'=>$message_data['message']??null,'draft_cleanup_pending'=>!$cleanup]);
    }

    private static function reconcile_canonical_message(object $smail,string $body,string $client_key,string $scope_hash):WP_REST_Response|WP_Error{$message_request=new WP_REST_Request('POST','/sabri-network/v2/conversations/'.(int)$smail->conversation_id.'/messages');$message_request->set_url_params(['id'=>(int)$smail->conversation_id]);$message_request->set_param('id',(int)$smail->conversation_id);$message_request->set_param('body',$body);$message_request->set_param('message_type','text');$message_request->set_param('client_id','smail:'.substr($client_key,0,40));$message_request->set_param('request_scope_hash',$scope_hash);return SN_Message_Runtime_Hardening::send_message($message_request);}

    private static function assert_same_projection(object $row,int $sender_id,array $recipients,string $subject,int $message_id):bool|WP_Error{
        global $wpdb;
        if((int)$row->sender_id!==$sender_id||!hash_equals((string)$row->subject,$subject))return new WP_Error('smail_idempotency_conflict','The Smail idempotency key was already used for different content or recipients.',['status'=>409]);
        if($message_id>0&&(int)$row->message_id!==$message_id)return new WP_Error('smail_projection_conflict','The Smail projection points to a different canonical message.',['status'=>409]);
        $state_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::states_table().' WHERE smail_message_id=%d',(int)$row->id));
        $actual=array_values(array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.self::states_table().' WHERE smail_message_id=%d AND user_id<>%d ORDER BY user_id ASC',(int)$row->id,$sender_id))?:[]));$expected=array_values(array_unique(array_map('absint',$recipients)));sort($actual,SORT_NUMERIC);sort($expected,SORT_NUMERIC);
        if($state_count!==count($expected)+1||$actual!==$expected)return new WP_Error('smail_idempotency_conflict','The Smail idempotency key was already used for different content, recipients, or an incomplete mailbox projection.',['status'=>409]);
        return true;
    }

    private static function smail_scope_hash(array $recipients,string $subject):string{$recipients=array_values(array_unique(array_map('absint',$recipients)));sort($recipients,SORT_NUMERIC);return hash('sha256',(string)wp_json_encode(['v'=>1,'subject'=>$subject,'recipient_ids'=>$recipients],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private static function draft_payload_hash(array $recipients,string $subject,string $body):string{$payload=['recipient_ids'=>array_values(array_unique(array_filter(array_map('absint',$recipients)))),'subject'=>mb_substr($subject,0,self::MAX_SUBJECT),'body'=>mb_substr($body,0,10000)];$json=(string)wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return hash_hmac('sha256',$json,wp_salt('auth').'|sn-sm-draft-blind-v1');}
    private static function cleanup_matching_draft(string $public_id,int $owner_id,int $expected_version,string $payload_hash):bool{if($public_id==='')return true;$row=self::draft_row($public_id,$owner_id);if(!$row)return true;if($expected_version<=0||(int)$row->version!==$expected_version||!hash_equals((string)$row->payload_hash,$payload_hash))return false;return self::trash_draft_exact($public_id,$owner_id,$expected_version,$payload_hash);}
    private static function trash_draft_exact(string $public_id,int $owner_id,int $expected_version,string $expected_payload_hash):bool{global $wpdb;if($public_id===''||$expected_version<=0||!preg_match('/^[a-f0-9]{64}$/',$expected_payload_hash))return false;$now=current_time('mysql',true);return $wpdb->query($wpdb->prepare('UPDATE '.self::drafts_table().' SET deleted_at=%s,encrypted_payload=%s,payload_hash=%s,version=version+1,updated_at=%s WHERE public_id=%s AND owner_id=%d AND version=%d AND payload_hash=%s AND deleted_at IS NULL',$now,'',hash_hmac('sha256','',wp_salt('auth').'|sn-sm-draft-blind-v1'),$now,sanitize_text_field($public_id),$owner_id,$expected_version,$expected_payload_hash))===1;}

    public static function update_state(WP_REST_Request $request):WP_REST_Response|WP_Error{global $wpdb;$id=absint($request['id']);$user_id=get_current_user_id();$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::states_table().' WHERE smail_message_id=%d AND user_id=%d',$id,$user_id));if(!$row)return new WP_Error('smail_not_found','The Smail item is unavailable.',['status'=>404]);$allowed=['starred'=>'is_starred','archived'=>'is_archived','spam'=>'is_spam','trashed'=>'trashed_at','read'=>'read_at'];$field=sanitize_key((string)$request->get_param('field'));if(!isset($allowed[$field]))return new WP_Error('invalid_smail_state','Select a valid Smail state.',['status'=>400]);$value=rest_sanitize_boolean($request->get_param('value'));$column=$allowed[$field];$now=current_time('mysql',true);$data=['updated_at'=>$now,$column=>in_array($column,['trashed_at','read_at'],true)?($value?$now:null):($value?1:0)];if($wpdb->update(self::states_table(),$data,['id'=>(int)$row->id])===false)return new WP_Error('smail_state_failed','The Smail state could not be updated.',['status'=>500]);SN_DB::audit('smail_state_updated','smail',$id,'success',['field'=>$field,'value'=>$value]);return rest_ensure_response(['updated'=>true,'field'=>$field,'value'=>$value]);}
    public static function list_drafts(WP_REST_Request $request):WP_REST_Response{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT public_id,version,created_at,updated_at FROM '.self::drafts_table().' WHERE owner_id=%d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT %d',get_current_user_id(),self::MAX_DRAFTS));return rest_ensure_response(['drafts'=>array_map(static fn($r):array=>['id'=>(string)$r->public_id,'version'=>(int)$r->version,'created_at'=>(string)$r->created_at,'updated_at'=>(string)$r->updated_at],$rows?:[])]);}
}
