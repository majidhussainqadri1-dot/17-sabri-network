<?php
/** Fifth fresh review: Future/Top-20 feature completion without private-content leakage. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fifth_Fresh_Feature_Hardening {
    private const MIGRATION_BATCH = 100;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 3000);
        add_action('sn_cleanup_hourly', [self::class, 'migrate_legacy_voice_transcripts'], 13);
    }

    public static function override_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/voice-notes', [
            'methods'=>'POST','callback'=>[self::class,'send_voice_note'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/structured', [
            'methods'=>'GET','callback'=>[self::class,'structured_message'],'permission_callback'=>$access,
        ], true);
    }

    public static function send_voice_note(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request['id']);
        $actor = get_current_user_id();
        $files = $request->get_file_params();
        if (empty($files['attachment']) || !is_array($files['attachment'])) {
            return new WP_Error('sn_voice_note_file_required', 'An audio recording or audio file is required.', ['status'=>400]);
        }
        $forward = new WP_REST_Request('POST', '/sabri-network/v2/conversations/' . $conversation . '/messages');
        $forward->set_url_params(['id'=>$conversation]);
        $forward->set_param('id', $conversation);
        $forward->set_param('body', '');
        $forward->set_param('message_type', 'audio');
        $forward->set_param('client_id', (string)$request->get_param('client_id'));
        $forward->set_file_params(['attachment'=>$files['attachment']]);
        $result = SN_Message_Runtime_Hardening::send_message($forward);
        if (is_wp_error($result)) return $result;
        $data = $result->get_data();
        $message_id = absint($data['message']['id'] ?? 0);
        if ($message_id <= 0) return new WP_Error('sn_voice_note_send_failed', 'The voice note could not be finalized.', ['status'=>500]);

        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('sn_voice_note_metadata_failed', 'The voice note was created but its protected metadata could not be finalized. Retry with the same idempotency key.', ['status'=>500]);
        }
        try {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d FOR UPDATE', $message_id));
            if (!$row || (int)$row->conversation_id !== $conversation || (int)$row->sender_id !== $actor || $row->deleted_at) {
                throw new RuntimeException('voice_note_message_scope_changed');
            }
            $meta = json_decode((string)$row->metadata, true);
            $meta = is_array($meta) ? $meta : [];
            $meta['voice_note'] = [
                'playback_speeds'=>[0.75,1,1.25,1.5,2],
                'waveform_adapter'=>'sn_network_voice_waveform',
                'transcript_available'=>false,
            ];
            $transcript = mb_substr(trim(sanitize_textarea_field(wp_unslash((string)$request->get_param('transcript')))), 0, 10000);
            if ($transcript !== '') {
                $cipher = SN_Communication_Crypto::encrypt($transcript, self::transcript_context($row));
                if (is_wp_error($cipher)) throw new RuntimeException($cipher->get_error_code());
                $meta['voice_note']['transcript_cipher'] = $cipher;
                $meta['voice_note']['transcript_available'] = true;
                $meta['voice_note']['transcript_encrypted'] = true;
            }
            unset($meta['voice_note']['transcript']);
            $version = max(1, absint($meta['_mutation_version'] ?? 1));
            $meta['_mutation_version'] = $version + 1;
            $updated = $wpdb->update(SN_DB::table('messages'), [
                'metadata'=>(string)wp_json_encode($meta),
                'edited_at'=>$row->edited_at,
            ], ['id'=>$message_id]);
            if ($updated === false) throw new RuntimeException('voice_note_metadata_update_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('voice_note_metadata_commit_failed');
            $data['message']['version'] = $version + 1;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('voice_note_metadata_failed', 'message', $message_id, 'failure', ['reason'=>$e->getMessage()], $actor);
            return new WP_Error('sn_voice_note_metadata_failed', 'The voice note was created but its protected metadata could not be finalized. Retry with the same idempotency key.', ['status'=>500]);
        }
        SN_DB::audit('voice_note_metadata_secured', 'message', $message_id, 'success', ['transcript_encrypted'=>$transcript!=='' ], $actor);
        return rest_ensure_response([
            'message_id'=>$message_id,
            'message'=>$data['message'] ?? null,
            'voice_note'=>[
                'playback_speeds'=>[0.75,1,1.25,1.5,2],
                'transcript_available'=>$transcript!=='',
                'transcript'=>$transcript,
            ],
        ]);
    }

    public static function structured_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $response = SN_Round20_Correction::structured_message($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        if (!is_array($data) || empty($data['voice_note']) || !is_array($data['voice_note'])) return $response;
        global $wpdb;
        $id = absint($request['id']);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        if (!$row || !SN_DB::is_member((int)$row->conversation_id, get_current_user_id()) || $row->deleted_at) return new WP_Error('not_found','The requested communication object is unavailable.',['status'=>404]);
        $meta = json_decode((string)$row->metadata, true);
        $voice = is_array($meta['voice_note'] ?? null) ? $meta['voice_note'] : [];
        $plain = '';
        if (!empty($voice['transcript_available']) && !empty($voice['transcript_cipher'])) {
            $decoded = SN_Communication_Crypto::decrypt((string)$voice['transcript_cipher'], self::transcript_context($row));
            if (!is_wp_error($decoded)) $plain = (string)$decoded;
        } elseif (!empty($voice['transcript_available']) && isset($voice['transcript'])) {
            $plain = mb_substr((string)$voice['transcript'], 0, 10000);
        }
        $data['voice_note']['transcript'] = $plain;
        $data['voice_note']['transcript_available'] = $plain !== '';
        $response->set_data($data);
        return $response;
    }

    public static function migrate_legacy_voice_transcripts(): void {
        global $wpdb;
        $table = SN_DB::table('messages');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE deleted_at IS NULL AND message_type='audio' AND metadata LIKE %s ORDER BY id ASC LIMIT %d",
            '%"transcript"%', self::MIGRATION_BATCH
        ));
        foreach (is_array($rows) ? $rows : [] as $probe) {
            if ($wpdb->query('START TRANSACTION') === false) break;
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", (int)$probe->id));
                if (!$row || $row->deleted_at) { $wpdb->query('COMMIT'); continue; }
                $meta = json_decode((string)$row->metadata, true);
                $meta = is_array($meta) ? $meta : [];
                $voice = is_array($meta['voice_note'] ?? null) ? $meta['voice_note'] : [];
                $plain = trim((string)($voice['transcript'] ?? ''));
                if ($plain === '' || !empty($voice['transcript_cipher'])) { $wpdb->query('COMMIT'); continue; }
                $cipher = SN_Communication_Crypto::encrypt(mb_substr($plain,0,10000), self::transcript_context($row));
                if (is_wp_error($cipher)) throw new RuntimeException($cipher->get_error_code());
                $meta['voice_note']['transcript_cipher'] = $cipher;
                $meta['voice_note']['transcript_encrypted'] = true;
                $meta['voice_note']['transcript_available'] = true;
                unset($meta['voice_note']['transcript']);
                $updated = $wpdb->update($table, ['metadata'=>(string)wp_json_encode($meta)], ['id'=>(int)$row->id]);
                if ($updated === false) throw new RuntimeException('voice_transcript_migration_update_failed');
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('voice_transcript_migration_commit_failed');
                SN_DB::audit('voice_note_transcript_encrypted', 'message', (int)$row->id, 'success', [], 0);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                SN_DB::audit('voice_note_transcript_migration_failed', 'message', (int)$probe->id, 'failure', ['reason'=>$e->getMessage()], 0);
                break;
            }
        }
    }

    private static function transcript_context(object $row): string {
        return 'voice-note-transcript|' . (int)$row->id . '|' . (int)$row->conversation_id . '|' . (int)$row->sender_id;
    }
}
