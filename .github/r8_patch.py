from pathlib import Path

# -----------------------------------------------------------------------------
# R8-D01 / R8-D02 — canonical message idempotency + authoritative recipients
# -----------------------------------------------------------------------------
p=Path('sabri-network/includes/class-sn-message-runtime-hardening.php')
s=p.read_text(encoding='utf-8')

old="""        $client=strtolower(trim((string)$request->get_param('client_id'));if($client===''||!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$client))return new WP_Error('invalid_client_id','A caller-supplied message idempotency key is required.',['status'=>400]);
        $idem=hash('sha256',$user_id.':'.$conversation_id.':'.$client);$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing)return self::reconcile_existing($existing,$user_id,true);
        $attachment=null;$files=$request->get_file_params();
"""
# Exact source has one extra closing parenthesis after get_param; use literal known source below.
old="""        $client=strtolower(trim((string)$request->get_param('client_id')));if($client===''||!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$client))return new WP_Error('invalid_client_id','A caller-supplied message idempotency key is required.',['status'=>400]);
        $idem=hash('sha256',$user_id.':'.$conversation_id.':'.$client);$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing)return self::reconcile_existing($existing,$user_id,true);
        $attachment=null;$files=$request->get_file_params();
"""
new="""        $client=strtolower(trim((string)$request->get_param('client_id')));if($client===''||!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$client))return new WP_Error('invalid_client_id','A caller-supplied message idempotency key is required.',['status'=>400]);
        $files=$request->get_file_params();
        $descriptor=self::request_descriptor($body,$reply,$files);if(is_wp_error($descriptor))return $descriptor;
        $fingerprint=self::request_fingerprint($descriptor);
        $idem=hash('sha256',$user_id.':'.$conversation_id.':'.$client);$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing)return self::reconcile_existing($existing,$user_id,true,$fingerprint,$descriptor);
        $attachment=null;
"""
if old not in s: raise SystemExit('R8 message pre-existing idempotency target mismatch')
s=s.replace(old,new,1)

old="""            if($wpdb->insert(SN_DB::table('messages'),['conversation_id'=>$conversation_id,'sender_id'=>$user_id,'message_type'=>$type,'body'=>$cipher,'attachment_id'=>$attachment?(int)$attachment['id']:0,'attachment_source'=>$attachment?'private':'none','reply_to'=>$reply,'idempotency_key'=>$idem,'metadata'=>'{}','created_at'=>$now])===false)throw new RuntimeException('message_insert_failed');
"""
new="""            $message_meta=(string)wp_json_encode(['_request_fingerprint'=>$fingerprint]);
            if($wpdb->insert(SN_DB::table('messages'),['conversation_id'=>$conversation_id,'sender_id'=>$user_id,'message_type'=>$type,'body'=>$cipher,'attachment_id'=>$attachment?(int)$attachment['id']:0,'attachment_source'=>$attachment?'private':'none','reply_to'=>$reply,'idempotency_key'=>$idem,'metadata'=>$message_meta,'created_at'=>$now])===false)throw new RuntimeException('message_insert_failed');
"""
if old not in s: raise SystemExit('R8 message metadata target mismatch')
s=s.replace(old,new,1)

old="""                return self::reconcile_existing($race,$user_id,true);
"""
new="""                return self::reconcile_existing($race,$user_id,true,$fingerprint,$descriptor);
"""
if old not in s: raise SystemExit('R8 message race reconcile target mismatch')
s=s.replace(old,new,1)

old="""    private static function reconcile_existing(object $message,int $user,bool $duplicate):WP_REST_Response|WP_Error{
        global $wpdb;
        $conversation_id=(int)$message->conversation_id;
        $conversation=self::conversation($conversation_id);
        if((int)$message->sender_id!==$user||!$conversation||!SN_DB::is_member($conversation_id,$user))return self::not_found();
"""
new="""    private static function reconcile_existing(object $message,int $user,bool $duplicate,string $fingerprint,array $descriptor):WP_REST_Response|WP_Error{
        global $wpdb;
        $conversation_id=(int)$message->conversation_id;
        $same=self::same_message_request($message,$fingerprint,$descriptor);if(is_wp_error($same))return $same;if(!$same)return self::idempotency_conflict();
        $conversation=self::conversation($conversation_id);
        if((int)$message->sender_id!==$user||!$conversation||!SN_DB::is_member($conversation_id,$user))return self::not_found();
"""
if old not in s: raise SystemExit('R8 message reconcile signature target mismatch')
s=s.replace(old,new,1)

old="""    private static function contact_check(object $conversation,int $conversation_id,int $actor):bool|WP_Error{$others=self::recipients($conversation_id,$actor);if((string)$conversation->type!=='direct'){foreach($others as $target)if(SN_DB::is_blocked($actor,$target))return new WP_Error('blocked','A conversation member is unavailable.',['status'=>403]);return true;}if(count($others)!==1)return new WP_Error('invalid_direct_conversation','The direct conversation membership is invalid.',['status'=>409]);return SN_Policy::can_contact($actor,$others[0],'message');}
    private static function conversation(int $id):?object{global $wpdb;$row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM \".SN_DB::table('conversations').\" WHERE id=%d AND status='active'\",$id));return $row?:null;}
    private static function recipients(int $conversation,int $sender):array{global $wpdb;return array_values(array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1000',$conversation,$sender))?:[]));}
"""
new="""    private static function contact_check(object $conversation,int $conversation_id,int $actor):bool|WP_Error{$others=self::recipients_authoritative($conversation_id,$actor);if(is_wp_error($others))return $others;if((string)$conversation->type!=='direct'){foreach($others as $target)if(SN_DB::is_blocked($actor,$target))return new WP_Error('blocked','A conversation member is unavailable.',['status'=>403]);return true;}if(count($others)!==1)return new WP_Error('invalid_direct_conversation','The direct conversation membership is invalid.',['status'=>409]);return SN_Policy::can_contact($actor,$others[0],'message');}
    private static function conversation(int $id):?object{global $wpdb;$row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM \".SN_DB::table('conversations').\" WHERE id=%d AND status='active'\",$id));return $row?:null;}
    private static function recipients_authoritative(int $conversation,int $sender):array|WP_Error{global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1000',$conversation,$sender));if($wpdb->last_error!==''||!is_array($raw))return new WP_Error('message_recipient_ledger_unavailable','The conversation recipient state could not be verified.',['status'=>503]);return array_values(array_map('absint',$raw));}
    private static function recipients(int $conversation,int $sender):array{global $wpdb;return array_values(array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY user_id ASC LIMIT 1000',$conversation,$sender))?:[]));}
"""
if old not in s: raise SystemExit('R8 message recipient target mismatch')
s=s.replace(old,new,1)

insert_before="""    private static function reactions(int $message):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT reaction,COUNT(*) total FROM '.SN_DB::table('reactions').' WHERE message_id=%d GROUP BY reaction ORDER BY reaction ASC',$message));return array_map(static fn($row)=>['reaction'=>(string)$row->reaction,'count'=>(int)$row->total],is_array($rows)?$rows:[]);}
"""
helpers="""    private static function request_descriptor(string $body,int $reply,array $files):array|WP_Error{$file=!empty($files['attachment'])&&is_array($files['attachment'])?$files['attachment']:null;$descriptor=['body'=>$body,'reply_to'=>$reply,'attachment'=>false,'attachment_sha256'=>'','attachment_name'=>''];if(!$file)return $descriptor;$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_file($tmp))return new WP_Error('invalid_upload','The uploaded attachment is unavailable.',['status'=>400]);$hash=hash_file('sha256',$tmp);if(!is_string($hash)||strlen($hash)!==64)return new WP_Error('attachment_hash_failed','The attachment request fingerprint could not be created.',['status'=>500]);$descriptor['attachment']=true;$descriptor['attachment_sha256']=$hash;$descriptor['attachment_name']=sanitize_file_name((string)($file['name']??'attachment'));return $descriptor;}
    private static function request_fingerprint(array $descriptor):string{$json=wp_json_encode($descriptor);return hash('sha256',is_string($json)?$json:'');}
    private static function same_message_request(object $message,string $fingerprint,array $descriptor):bool|WP_Error{global $wpdb;$meta=json_decode((string)($message->metadata??''),true);if(is_array($meta)&&isset($meta['_request_fingerprint'])&&is_string($meta['_request_fingerprint']))return hash_equals($meta['_request_fingerprint'],$fingerprint);if((int)($message->reply_to??0)!==(int)$descriptor['reply_to'])return false;$plain=SN_Message_Body::decrypt_row($message);if(is_wp_error($plain))return $plain;if((string)$plain!==(string)$descriptor['body'])return false;$has=(int)($message->attachment_id??0)>0&&(string)($message->attachment_source??'')==='private';if($has!==(bool)$descriptor['attachment'])return false;if(!$has)return true;$row=$wpdb->get_row($wpdb->prepare('SELECT original_name,sha256 FROM '.SN_DB::table('attachments').' WHERE id=%d AND deleted_at IS NULL',(int)$message->attachment_id));if(!$row)return false;return hash_equals((string)$row->sha256,(string)$descriptor['attachment_sha256'])&&(string)$row->original_name===(string)$descriptor['attachment_name'];}
    private static function idempotency_conflict():WP_Error{return new WP_Error('message_idempotency_conflict','This message idempotency key is already bound to a different request.',['status'=>409]);}
"""
if insert_before not in s: raise SystemExit('R8 message helper insertion target mismatch')
s=s.replace(insert_before,helpers+insert_before,1)
p.write_text(s,encoding='utf-8')

# -----------------------------------------------------------------------------
# R8-D03 — final Smail privacy owner must distinguish DB failure from empty state
# -----------------------------------------------------------------------------
p=Path('sabri-network/includes/class-sn-fifth-fresh-privacy-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        $state_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            \"SELECT id FROM $states WHERE user_id=%d ORDER BY id ASC LIMIT %d\",
            $uid,
            self::BATCH
        )) ?: []);
        $draft_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            \"SELECT id FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d\",
            $uid,
            self::BATCH
        )) ?: []);
"""
new="""        $wpdb->last_error = '';
        $state_raw = $wpdb->get_col($wpdb->prepare(
            \"SELECT id FROM $states WHERE user_id=%d ORDER BY id ASC LIMIT %d\",
            $uid,
            self::BATCH
        ));
        if ($wpdb->last_error !== '' || !is_array($state_raw)) return self::retry('Smail state privacy truth could not be read safely.');
        $state_ids = array_map('intval', $state_raw);
        $wpdb->last_error = '';
        $draft_raw = $wpdb->get_col($wpdb->prepare(
            \"SELECT id FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d\",
            $uid,
            self::BATCH
        ));
        if ($wpdb->last_error !== '' || !is_array($draft_raw)) return self::retry('Smail draft privacy truth could not be read safely.');
        $draft_ids = array_map('intval', $draft_raw);
"""
if old not in s: raise SystemExit('R8 Smail initial reads target mismatch')
s=s.replace(old,new,1)
old="""        $more_states = (bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $states WHERE user_id=%d LIMIT 1\", $uid));
        $more_drafts = (bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1\", $uid));
"""
new="""        $wpdb->last_error = '';
        $more_states_raw = $wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $states WHERE user_id=%d LIMIT 1\", $uid));
        if ($wpdb->last_error !== '') return self::retry('Smail state privacy completion could not be verified safely.');
        $wpdb->last_error = '';
        $more_drafts_raw = $wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1\", $uid));
        if ($wpdb->last_error !== '') return self::retry('Smail draft privacy completion could not be verified safely.');
        $more_states = $more_states_raw !== null;
        $more_drafts = $more_drafts_raw !== null;
"""
if old not in s: raise SystemExit('R8 Smail completion reads target mismatch')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# -----------------------------------------------------------------------------
# R8-D04 — duplicate voice note must not mutate transcript metadata
# -----------------------------------------------------------------------------
p=Path('sabri-network/includes/class-sn-fifth-fresh-feature-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        $data = $result->get_data();
        $message_id = absint($data['message']['id'] ?? 0);
        if ($message_id <= 0) return new WP_Error('sn_voice_note_send_failed', 'The voice note could not be finalized.', ['status'=>500]);

        global $wpdb;
"""
new="""        $data = $result->get_data();
        $message_id = absint($data['message']['id'] ?? 0);
        if ($message_id <= 0) return new WP_Error('sn_voice_note_send_failed', 'The voice note could not be finalized.', ['status'=>500]);
        $was_duplicate = !empty($data['duplicate']);
        $transcript = mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('transcript')))), 0, 10000);

        global $wpdb;
"""
if old not in s: raise SystemExit('R8 voice duplicate flag target mismatch')
s=s.replace(old,new,1)
old="""            $meta = json_decode((string)$row->metadata, true);
            $meta = is_array($meta) ? $meta : [];
            $meta['voice_note'] = [
"""
new="""            $meta = json_decode((string)$row->metadata, true);
            $meta = is_array($meta) ? $meta : [];
            if ($was_duplicate && isset($meta['voice_note']) && is_array($meta['voice_note'])) {
                $existing_voice = $meta['voice_note'];
                $existing_transcript = '';
                if (!empty($existing_voice['transcript_cipher'])) {
                    $decoded = SN_Communication_Crypto::decrypt((string)$existing_voice['transcript_cipher'], self::transcript_context($row));
                    if (is_wp_error($decoded)) throw new RuntimeException($decoded->get_error_code());
                    $existing_transcript = (string)$decoded;
                } elseif (isset($existing_voice['transcript'])) {
                    $existing_transcript = mb_substr(trim((string)$existing_voice['transcript']), 0, 10000);
                }
                if ($existing_transcript !== $transcript) throw new UnexpectedValueException('voice_note_idempotency_conflict');
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('voice_note_duplicate_commit_failed');
                return rest_ensure_response(['message_id'=>$message_id,'message'=>$data['message']??null,'voice_note'=>['playback_speeds'=>[0.75,1,1.25,1.5,2],'transcript_available'=>$transcript!=='','transcript'=>$transcript],'duplicate'=>true]);
            }
            $meta['voice_note'] = [
"""
if old not in s: raise SystemExit('R8 voice metadata target mismatch')
s=s.replace(old,new,1)
old="""            $transcript = mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('transcript')))), 0, 10000);
            if ($transcript !== '') {
"""
new="""            if ($transcript !== '') {
"""
if old not in s: raise SystemExit('R8 voice duplicate transcript declaration target mismatch')
s=s.replace(old,new,1)
old="""        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('voice_note_metadata_failed', 'message', $message_id, 'failure', ['reason'=>$e->getMessage()], $actor);
            return new WP_Error('sn_voice_note_metadata_failed', 'The voice note was created but its protected metadata could not be finalized. Retry with the same idempotency key.', ['status'=>500]);
        }
"""
new="""        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof UnexpectedValueException && $e->getMessage() === 'voice_note_idempotency_conflict') {
                return new WP_Error('voice_note_idempotency_conflict', 'This voice-note idempotency key is already bound to a different transcript request.', ['status'=>409]);
            }
            SN_DB::audit('voice_note_metadata_failed', 'message', $message_id, 'failure', ['reason'=>$e->getMessage()], $actor);
            return new WP_Error('sn_voice_note_metadata_failed', 'The voice note was created but its protected metadata could not be finalized. Retry with the same idempotency key.', ['status'=>500]);
        }
"""
if old not in s: raise SystemExit('R8 voice catch target mismatch')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# -----------------------------------------------------------------------------
# R8-D05 — search rebuild completion probe must fail closed
# -----------------------------------------------------------------------------
p=Path('sabri-network/includes/class-sn-fourth-fresh-search-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        $next = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id>%d ORDER BY id ASC LIMIT 1',
            $after
        ));
        if ($next <= 0 && (string) get_option(self::ERROR_OPTION, '') === '') {
"""
new="""        $wpdb->last_error = '';
        $next_raw = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id>%d ORDER BY id ASC LIMIT 1',
            $after
        ));
        if ($wpdb->last_error !== '' || ($next_raw !== null && !is_numeric($next_raw))) {
            self::record_error('finish_rebuild_read_failed', $after, 0);
            return;
        }
        $next = $next_raw === null ? 0 : (int)$next_raw;
        if ($next <= 0 && (string) get_option(self::ERROR_OPTION, '') === '') {
"""
if old not in s: raise SystemExit('R8 search finish target mismatch')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')
