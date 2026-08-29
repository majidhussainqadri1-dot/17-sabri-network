<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
require_once SN_DIR . 'includes/class-sn-smail-runtime-hardening.php';
require_once SN_DIR . 'includes/class-sn-privacy-runtime-hardening.php';

/** Fail-closed private attachment and voice-note corrections. */
final class SN_Attachment_Runtime_Hardening {
    private const PRIVATE_QUERY_VAR = 'sn_network_file';

    public static function register(): void {
        add_filter('sn_network_attachment_scan_result', [self::class, 'require_scanner_for_opaque_media'], PHP_INT_MAX, 3);
        add_action('template_redirect', [self::class, 'verify_private_download_integrity'], -101);
        add_action('rest_api_init', [self::class, 'override_routes'], 1950);
        SN_Smail_Runtime_Hardening::register();
        SN_Privacy_Runtime_Hardening::register();
    }

    public static function require_scanner_for_opaque_media($result, string $path, array $meta) {
        if ($result !== null) return $result;
        $mime = strtolower((string) ($meta['mime'] ?? ''));
        if (str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || str_starts_with($mime, 'application/')) {
            return new WP_Error('scanner_required', 'This private media type requires an approved malware scanner.', ['status' => 503]);
        }
        return $result;
    }

    public static function verify_private_download_integrity(): void {
        $attachment_id = absint(get_query_var(self::PRIVATE_QUERY_VAR));
        if (!$attachment_id && isset($_GET[self::PRIVATE_QUERY_VAR])) $attachment_id = absint(wp_unslash($_GET[self::PRIVATE_QUERY_VAR]));
        if ($attachment_id <= 0) return;

        // The actual delivery layer performs this authorization again. Repeating it
        // here is intentional: integrity hashing can read a large private object and
        // must never become an unauthenticated/unauthorized disk-I/O oracle.
        if (!is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $nonce = sanitize_text_field(wp_unslash((string) ($_GET['sn_file_nonce'] ?? '')));
        if (!wp_verify_nonce($nonce, 'sn_private_file_' . $attachment_id . '_' . $user_id) || !SN_DB::user_can_access_attachment($attachment_id, $user_id)) return;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,storage_key,sha256,deleted_at FROM ' . SN_DB::table('attachments') . ' WHERE id=%d', $attachment_id));
        if (!$row || $row->deleted_at !== null) return;
        $base = realpath(SN_Private_Files::storage_dir());
        $key = ltrim(str_replace(['\\', "\0"], ['/', ''], (string) $row->storage_key), '/');
        $candidate = $base !== false ? realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key)) : false;
        if ($base === false || $candidate === false || !str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) || !is_file($candidate)) return;
        $actual = hash_file('sha256', $candidate);
        if (!is_string($actual) || strlen($actual) !== 64 || !hash_equals((string) $row->sha256, $actual)) {
            SN_DB::audit('attachment_integrity_mismatch', 'attachment', $attachment_id, 'failure', ['storage_key_hash' => hash('sha256', (string) $row->storage_key)], $user_id);
            status_header(404);
            exit;
        }
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/voice-notes', [
            'methods' => 'POST', 'callback' => [self::class, 'send_voice_note'], 'permission_callback' => [SN_REST::class, 'access'],
        ], true);
    }

    public static function send_voice_note(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files = $request->get_file_params();
        if (empty($files['attachment']) || !is_array($files['attachment'])) return new WP_Error('sn_voice_note_file_required', 'An audio recording or audio file is required.', ['status' => 400]);
        $forward = new WP_REST_Request('POST', '/sabri-network/v2/conversations/' . absint($request['id']) . '/messages');
        $forward->set_param('id', absint($request['id']));
        $forward->set_param('body', '');
        $forward->set_param('message_type', 'audio');
        $forward->set_param('client_id', (string) $request->get_param('client_id'));
        $forward->set_file_params(['attachment' => $files['attachment']]);
        $result = SN_Message_Runtime_Hardening::send_message($forward);
        if (is_wp_error($result)) return $result;
        $data = $result->get_data();
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $id = absint($message['id'] ?? 0);
        if ($id <= 0 || (string) ($message['message_type'] ?? '') !== 'audio') return new WP_Error('sn_voice_note_send_failed', 'The voice note could not be finalized.', ['status' => 500]);
        $transcript = mb_substr(trim(sanitize_textarea_field(wp_unslash((string) $request->get_param('transcript')))), 0, 10000);
        $voice = [
            'playback_speeds' => [0.75, 1, 1.25, 1.5, 2],
            'waveform_adapter' => 'sn_network_voice_waveform',
            'transcript_available' => $transcript !== '',
            'transcript_source' => $transcript !== '' ? 'user_supplied_unverified' : 'none',
        ];
        if ($transcript !== '') $voice['transcript'] = $transcript;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        if (!$row || $row->deleted_at !== null) return new WP_Error('sn_voice_note_state_changed', 'The voice note changed before metadata finalization.', ['status' => 409]);
        $meta = json_decode((string) $row->metadata, true); $meta = is_array($meta) ? $meta : [];
        $meta['voice_note'] = $voice;
        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('messages') . ' SET metadata=%s WHERE id=%d AND metadata=%s AND deleted_at IS NULL', (string) wp_json_encode($meta), $id, (string) $row->metadata));
        if ($changed !== 1) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
            $fresh_meta = $fresh ? json_decode((string) $fresh->metadata, true) : null;
            if (!$fresh || $fresh->deleted_at !== null || !is_array($fresh_meta) || ($fresh_meta['voice_note'] ?? null) !== $voice) {
                SN_DB::audit('voice_note_metadata_finalize_failed', 'message', $id, 'failure', [], get_current_user_id());
                return new WP_Error('sn_voice_note_metadata_finalize_failed', 'The audio message was committed but its voice-note metadata needs a safe retry.', ['status' => 503, 'message_id' => $id]);
            }
        }
        SN_DB::audit('voice_note_finalized', 'message', $id, 'success', ['transcript_source' => $voice['transcript_source']], get_current_user_id());
        return rest_ensure_response(['message_id' => $id, 'message' => $message, 'voice_note' => $voice, 'duplicate' => (bool) ($data['duplicate'] ?? false)]);
    }
}