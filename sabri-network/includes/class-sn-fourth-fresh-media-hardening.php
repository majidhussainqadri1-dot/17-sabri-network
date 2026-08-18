<?php
/** Fourth fresh cycle: private media validation and encrypted voice-note metadata. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Media_Hardening {
    public static function register(): void {
        add_filter('sn_network_attachment_scan_result', [self::class, 'validate_media_after_scan'], PHP_INT_MAX, 3);
        add_action('rest_api_init', [self::class, 'override_routes'], 2150);
    }

    /**
     * Scanner-clean is necessary but not sufficient for opaque media. Enforce a
     * separate approved container/codec/duration validator and reject image bombs
     * before WordPress image decoding/normalization allocates large pixel buffers.
     */
    public static function validate_media_after_scan($result, string $path, array $meta) {
        if (is_wp_error($result)) return $result;
        $mime = strtolower((string) ($meta['mime'] ?? ''));
        if (str_starts_with($mime, 'image/')) {
            $size = @getimagesize($path);
            if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                return new WP_Error('invalid_image', 'The uploaded image is invalid.', ['status'=>415]);
            }
            $max_dimension = max(512, (int) apply_filters('sn_network_image_max_dimension', 4096));
            $max_pixels = max(1000000, (int) apply_filters('sn_network_image_max_pixels', 25000000));
            $width = (int) $size[0]; $height = (int) $size[1];
            if ($width > $max_dimension * 4 || $height > $max_dimension * 4 || ($width * $height) > $max_pixels) {
                return new WP_Error('image_dimensions_rejected', 'The image dimensions are unsafe for private-media processing.', ['status'=>413]);
            }
            return $result;
        }

        if (!str_starts_with($mime, 'audio/') && !str_starts_with($mime, 'video/')) return $result;
        if (!in_array(sanitize_key(is_array($result) ? (string) ($result['status'] ?? '') : (string) $result), ['clean','validated'], true)) {
            return new WP_Error('media_scan_required', 'Private audio/video must pass the approved malware scanner first.', ['status'=>503]);
        }
        $validation = is_array($result) && isset($result['media_validation']) && is_array($result['media_validation'])
            ? $result['media_validation']
            : apply_filters('sn_network_private_media_validation', null, $path, $meta);
        if (!is_array($validation) || !in_array(sanitize_key((string) ($validation['status'] ?? '')), ['clean','validated'], true)) {
            return new WP_Error('media_validator_required', 'Private audio/video requires approved container, codec and duration validation.', ['status'=>503]);
        }
        $duration = (float) ($validation['duration_seconds'] ?? 0);
        $codec = sanitize_key((string) ($validation['codec'] ?? ''));
        if ($duration <= 0 || $codec === '') return new WP_Error('media_validation_incomplete', 'The approved media validator returned incomplete evidence.', ['status'=>415]);
        $max = str_starts_with($mime, 'audio/')
            ? max(30, (int) apply_filters('sn_network_voice_max_duration_seconds', 900))
            : max(10, (int) apply_filters('sn_network_private_video_max_duration_seconds', 300));
        if ($duration > $max) return new WP_Error('media_duration_rejected', 'This private media exceeds the permitted duration.', ['status'=>413]);
        return $result;
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/voice-notes', [
            'methods'=>'POST', 'callback'=>[self::class,'send_voice_note'],
            'permission_callback'=>[SN_REST::class,'access'],
        ], true);
    }

    public static function send_voice_note(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files = $request->get_file_params();
        if (empty($files['attachment']) || !is_array($files['attachment'])) {
            return new WP_Error('sn_voice_note_file_required', 'An audio recording or audio file is required.', ['status'=>400]);
        }
        $forward = new WP_REST_Request('POST', '/sabri-network/v2/conversations/' . absint($request['id']) . '/messages');
        $forward->set_url_params(['id'=>absint($request['id'])]);
        $forward->set_param('body', '');
        $forward->set_param('message_type', 'audio');
        $forward->set_param('client_id', (string) $request->get_param('client_id'));
        $forward->set_file_params(['attachment'=>$files['attachment']]);
        $result = SN_Fourth_Fresh_Review_Hardening::send_message($forward);
        if (is_wp_error($result)) return $result;
        $data = $result->get_data();
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $id = absint($message['id'] ?? 0);
        if ($id <= 0 || (string) ($message['message_type'] ?? '') !== 'audio') {
            return new WP_Error('sn_voice_note_send_failed', 'The voice note could not be finalized.', ['status'=>500]);
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        if (!$row || $row->deleted_at !== null) return new WP_Error('sn_voice_note_state_changed', 'The voice note changed before metadata finalization.', ['status'=>409]);
        $meta = json_decode((string) $row->metadata, true); $meta = is_array($meta) ? $meta : [];
        $existing = is_array($meta['voice_note'] ?? null) ? $meta['voice_note'] : [];
        $transcript = mb_substr(trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('transcript')))), 0, 10000);

        if ($existing) {
            $plain = '';
            if (!empty($existing['transcript_cipher'])) {
                $dec = SN_Communication_Crypto::decrypt((string) $existing['transcript_cipher'], 'voice-transcript|' . $id);
                if (!is_wp_error($dec)) $plain = (string) $dec;
            }
            return rest_ensure_response(['message_id'=>$id,'message'=>$message,'voice_note'=>self::public_voice($existing,$plain),'duplicate'=>true]);
        }

        $voice = [
            'playback_speeds'=>[0.75,1,1.25,1.5,2],
            'waveform_adapter'=>'sn_network_voice_waveform',
            'transcript_available'=>$transcript !== '',
            'transcript_source'=>$transcript !== '' ? 'user_supplied_unverified' : 'none',
        ];
        if ($transcript !== '') {
            $cipher = SN_Communication_Crypto::encrypt($transcript, 'voice-transcript|' . $id);
            if (is_wp_error($cipher)) return $cipher;
            $voice['transcript_cipher'] = $cipher;
        }
        $meta['voice_note'] = $voice;
        $changed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('messages') . ' SET metadata=%s WHERE id=%d AND metadata=%s AND deleted_at IS NULL',
            (string) wp_json_encode($meta), $id, (string) $row->metadata
        ));
        if ($changed !== 1) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
            $fresh_meta = $fresh ? json_decode((string) $fresh->metadata, true) : null;
            $saved = is_array($fresh_meta) && is_array($fresh_meta['voice_note'] ?? null) ? $fresh_meta['voice_note'] : [];
            if (!$fresh || $fresh->deleted_at !== null || !$saved) {
                SN_DB::audit('voice_note_metadata_finalize_failed','message',$id,'failure',[],get_current_user_id());
                return new WP_Error('sn_voice_note_metadata_finalize_failed','The audio message was committed but its private metadata needs a safe retry.',['status'=>503,'message_id'=>$id]);
            }
            $voice = $saved;
        }
        SN_DB::audit('voice_note_finalized','message',$id,'success',['transcript_source'=>$voice['transcript_source'] ?? 'none','transcript_encrypted'=>!empty($voice['transcript_cipher'])],get_current_user_id());
        return rest_ensure_response(['message_id'=>$id,'message'=>$message,'voice_note'=>self::public_voice($voice,$transcript),'duplicate'=>(bool)($data['duplicate'] ?? false)]);
    }

    private static function public_voice(array $voice, string $plain): array {
        unset($voice['transcript_cipher']);
        if ($plain !== '') $voice['transcript'] = $plain;
        return $voice;
    }
}
