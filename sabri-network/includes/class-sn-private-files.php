<?php
defined('ABSPATH') || exit;

/** Private, authorization-gated attachment storage outside the public web root. */
final class SN_Private_Files {
    private const QUERY_VAR = 'sn_network_file';

    public static function register(): void {
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', [self::class, 'maybe_deliver'], -100);
    }

    public static function storage_dir(): string {
        $default = dirname(untrailingslashit(ABSPATH)) . DIRECTORY_SEPARATOR . 'sabri-network-private';
        return untrailingslashit((string) apply_filters('sn_network_private_storage_dir', $default));
    }

    public static function ensure_storage(): bool {
        $dir = self::storage_dir();
        if ($dir === '' || self::is_inside_web_root($dir)) {
            return false;
        }
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.php', "<?php\nhttp_response_code(404);\nexit;\n");
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\n");
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'web.config', "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n");
        return is_dir($dir) && is_writable($dir);
    }

    public static function create_from_upload(array $file, int $owner_id): array|WP_Error {
        if ($owner_id <= 0 || !get_user_by('id', $owner_id)) {
            return new WP_Error('invalid_owner', 'The attachment owner is unavailable.', ['status' => 400]);
        }
        if (!self::ensure_storage()) {
            return new WP_Error('private_storage_unavailable', 'Private attachment storage is unavailable or unsafe.', ['status' => 503]);
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_failed', 'The attachment upload failed.', ['status' => 400, 'upload_error' => $error]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return new WP_Error('invalid_upload', 'The uploaded attachment is unavailable.', ['status' => 400]);
        }
        $is_uploaded = is_uploaded_file($tmp) || (bool) apply_filters('sn_network_allow_non_http_upload_for_tests', false, $tmp);
        if (!$is_uploaded) {
            return new WP_Error('invalid_upload_source', 'The attachment did not originate from a valid upload.', ['status' => 400]);
        }
        $max = min(100, max(1, (int) get_option('sn_max_upload_mb', 25))) * MB_IN_BYTES;
        $size = (int) ($file['size'] ?? filesize($tmp));
        if ($size <= 0 || $size > $max) {
            return new WP_Error('upload_size_invalid', 'The attachment exceeds the permitted size.', ['status' => 413]);
        }

        $original = sanitize_file_name((string) ($file['name'] ?? 'attachment'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($tmp));
        $allowed = self::allowed_types();
        if (!isset($allowed[$mime]) || !in_array($ext, $allowed[$mime]['extensions'], true)) {
            return new WP_Error('attachment_type_rejected', 'This attachment type is not permitted.', ['status' => 415]);
        }
        $type = $allowed[$mime]['type'];
        if ($type === 'image' && @getimagesize($tmp) === false) {
            return new WP_Error('invalid_image', 'The uploaded image is invalid.', ['status' => 415]);
        }
        if ($mime === 'application/pdf') {
            $handle = fopen($tmp, 'rb');
            $signature = $handle ? fread($handle, 5) : '';
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($signature !== '%PDF-') {
                return new WP_Error('invalid_pdf', 'The uploaded PDF is invalid.', ['status' => 415]);
            }
        }

        $scan_status = self::scan_status($tmp, $mime, $original, $owner_id);
        if (is_wp_error($scan_status)) {
            return $scan_status;
        }
        $requires_scanner = in_array($type, ['document'], true);
        if ($requires_scanner && !in_array($scan_status, ['clean', 'validated'], true)) {
            return new WP_Error('scanner_required', 'Document uploads require an approved malware scanner.', ['status' => 503]);
        }
        if (in_array($scan_status, ['infected', 'rejected', 'suspicious'], true)) {
            return new WP_Error('attachment_rejected', 'The attachment failed the security scan.', ['status' => 415]);
        }

        $storage_key = gmdate('Y/m') . '/' . wp_generate_uuid4() . '.' . $allowed[$mime]['storage_ext'];
        $path = self::path_for_key($storage_key);
        if (!wp_mkdir_p(dirname($path))) {
            return new WP_Error('storage_directory_failed', 'The private attachment directory could not be created.', ['status' => 500]);
        }
        if (!@move_uploaded_file($tmp, $path) && !@rename($tmp, $path)) {
            if (!@copy($tmp, $path)) {
                return new WP_Error('storage_write_failed', 'The attachment could not be stored privately.', ['status' => 500]);
            }
        }
        @chmod($path, 0640);

        if ($type === 'image') {
            $normalized = self::normalize_image($path, $mime);
            if (is_wp_error($normalized)) {
                @unlink($path);
                return $normalized;
            }
            clearstatcache(true, $path);
            $size = (int) filesize($path);
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || strlen($hash) !== 64) {
            @unlink($path);
            return new WP_Error('attachment_hash_failed', 'The attachment integrity hash could not be created.', ['status' => 500]);
        }

        global $wpdb;
        $ok = $wpdb->insert(SN_DB::table('attachments'), [
            'owner_id' => $owner_id,
            'storage_key' => $storage_key,
            'original_name' => mb_substr($original ?: 'attachment.' . $ext, 0, 255),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => $hash,
            'scan_status' => $scan_status,
            'created_at' => current_time('mysql', true),
        ]);
        if ($ok === false) {
            @unlink($path);
            return new WP_Error('attachment_record_failed', 'The private attachment record could not be created.', ['status' => 500]);
        }
        $id = (int) $wpdb->insert_id;
        SN_DB::audit('attachment_stored', 'attachment', $id, 'success', ['type' => $type, 'size' => $size, 'scan' => $scan_status], $owner_id);
        return ['id' => $id, 'type' => $type, 'mime' => $mime, 'size' => $size, 'name' => $original, 'scan_status' => $scan_status];
    }

    public static function formatted(int $attachment_id, int $viewer_id): ?array {
        global $wpdb;
        if (!SN_DB::user_can_access_attachment($attachment_id, $viewer_id)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->original_name,
            'mime' => (string) $row->mime_type,
            'size' => (int) $row->size_bytes,
            'type' => self::type_for_mime((string) $row->mime_type),
            'url' => self::download_url((int) $row->id, $viewer_id),
            'scan_status' => (string) $row->scan_status,
        ];
    }

    public static function download_url(int $attachment_id, int $user_id): string {
        return add_query_arg([
            self::QUERY_VAR => $attachment_id,
            'sn_file_nonce' => wp_create_nonce('sn_private_file_' . $attachment_id . '_' . $user_id),
        ], home_url('/'));
    }

    public static function maybe_deliver(): void {
        $attachment_id = absint(get_query_var(self::QUERY_VAR));
        if (!$attachment_id && isset($_GET[self::QUERY_VAR])) {
            $attachment_id = absint(wp_unslash($_GET[self::QUERY_VAR]));
        }
        if (!$attachment_id) {
            return;
        }
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }
        $user_id = get_current_user_id();
        $nonce = sanitize_text_field(wp_unslash((string) ($_GET['sn_file_nonce'] ?? '')));
        if (!wp_verify_nonce($nonce, 'sn_private_file_' . $attachment_id . '_' . $user_id) || !SN_DB::user_can_access_attachment($attachment_id, $user_id)) {
            status_header(404);
            exit;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (!$row) {
            status_header(404);
            exit;
        }
        $path = self::path_for_key((string) $row->storage_key);
        if (!self::is_safe_path($path) || !is_file($path)) {
            status_header(404);
            exit;
        }
        self::stream($path, (string) $row->mime_type, (string) $row->original_name, self::type_for_mime((string) $row->mime_type) === 'document');
    }

    public static function delete(int $attachment_id, int $actor_id = 0, bool $force = false): bool {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (!$row || (!$force && (int) $row->owner_id !== $actor_id)) {
            return false;
        }

        // Revoke authorization before touching bytes. A database failure must not leave
        // an apparently active attachment whose file has already disappeared.
        $updated = $wpdb->update(
            SN_DB::table('attachments'),
            ['deleted_at' => current_time('mysql', true)],
            ['id' => $attachment_id, 'deleted_at' => null],
            ['%s'],
            ['%d', '%s']
        );
        if ($updated === false) {
            SN_DB::audit('attachment_delete_failed', 'attachment', $attachment_id, 'failure', ['stage' => 'revoke'], $actor_id);
            return false;
        }

        $path = self::path_for_key((string) $row->storage_key);
        $bytes_deleted = true;
        if (is_file($path)) {
            $bytes_deleted = self::is_safe_path($path) && @unlink($path);
        }
        SN_DB::audit(
            $bytes_deleted ? 'attachment_deleted' : 'attachment_bytes_delete_failed',
            'attachment',
            $attachment_id,
            $bytes_deleted ? 'success' : 'failure',
            $bytes_deleted ? [] : ['storage_key_hash' => hash('sha256', (string) $row->storage_key)],
            $actor_id
        );
        if (!$bytes_deleted) {
            do_action('sn_network_private_bytes_delete_failed', $attachment_id, (string) $row->storage_key);
            if (!wp_next_scheduled('sn_network_retry_private_delete', [$attachment_id])) {
                wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'sn_network_retry_private_delete', [$attachment_id]);
            }
        }
        return $bytes_deleted;
    }

    public static function retry_delete_bytes(int $attachment_id): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,storage_key FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NOT NULL',
            $attachment_id
        ));
        if (!$row) {
            return;
        }
        $path = self::path_for_key((string) $row->storage_key);
        if (!is_file($path)) {
            delete_transient('sn_private_delete_retry_' . $attachment_id);
            return;
        }
        $deleted = self::is_safe_path($path) && @unlink($path);
        $attempts = (int) get_transient('sn_private_delete_retry_' . $attachment_id) + 1;
        SN_DB::audit(
            $deleted ? 'attachment_delete_retry_succeeded' : 'attachment_delete_retry_failed',
            'attachment',
            $attachment_id,
            $deleted ? 'success' : 'failure',
            ['attempt' => $attempts, 'storage_key_hash' => hash('sha256', (string) $row->storage_key)],
            0
        );
        if ($deleted) {
            delete_transient('sn_private_delete_retry_' . $attachment_id);
            return;
        }
        set_transient('sn_private_delete_retry_' . $attachment_id, $attempts, DAY_IN_SECONDS);
        if ($attempts < 5 && !wp_next_scheduled('sn_network_retry_private_delete', [$attachment_id])) {
            wp_schedule_single_event(time() + min(HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** $attempts)), 'sn_network_retry_private_delete', [$attachment_id]);
        }
    }

    private static function scan_status(string $path, string $mime, string $name, int $owner_id): string|WP_Error {
        $result = apply_filters('sn_network_attachment_scan_result', null, $path, [
            'mime' => $mime,
            'name' => $name,
            'owner_id' => $owner_id,
            'sha256' => hash_file('sha256', $path),
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === null) {
            return self::type_for_mime($mime) === 'document' ? 'scanner_unavailable' : 'validated';
        }
        $status = sanitize_key(is_array($result) ? (string) ($result['status'] ?? '') : (string) $result);
        return $status ?: 'scanner_unavailable';
    }

    private static function normalize_image(string $path, string $mime): true|WP_Error {
        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return new WP_Error('image_processing_failed', 'The image could not be safely normalized.', ['status' => 415]);
        }
        $size = $editor->get_size();
        $max_dimension = max(512, (int) apply_filters('sn_network_image_max_dimension', 4096));
        if (!empty($size['width']) && !empty($size['height']) && ($size['width'] > $max_dimension || $size['height'] > $max_dimension)) {
            $editor->resize($max_dimension, $max_dimension, false);
        }
        $saved = $editor->save($path, $mime);
        return is_wp_error($saved) ? new WP_Error('image_processing_failed', 'The image could not be safely normalized.', ['status' => 415]) : true;
    }

    private static function stream(string $path, string $mime, string $name, bool $download): never {
        while (ob_get_level()) {
            ob_end_clean();
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            status_header(404);
            exit;
        }
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
            $requested_start = $match[1] === '' ? null : (int) $match[1];
            $requested_end = $match[2] === '' ? null : (int) $match[2];
            if ($requested_start === null && $requested_end !== null) {
                $start = max(0, $size - $requested_end);
            } else {
                $start = $requested_start ?? 0;
                $end = $requested_end ?? $end;
            }
            if ($start > $end || $end >= $size) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $status = 206;
        }
        status_header($status);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (($end - $start) + 1));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        $safe_name = sanitize_file_name($name) ?: 'attachment';
        $ascii_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $safe_name) ?: 'attachment';
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . rawurlencode($safe_name));
        if ($status === 206) {
            header("Content-Range: bytes $start-$end/$size");
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            status_header(500);
            exit;
        }
        fseek($handle, $start);
        $remaining = ($end - $start) + 1;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
        exit;
    }

    private static function allowed_types(): array {
        return (array) apply_filters('sn_network_allowed_private_attachment_types', [
            'image/jpeg' => ['extensions' => ['jpg', 'jpeg'], 'storage_ext' => 'jpg', 'type' => 'image'],
            'image/png' => ['extensions' => ['png'], 'storage_ext' => 'png', 'type' => 'image'],
            'image/webp' => ['extensions' => ['webp'], 'storage_ext' => 'webp', 'type' => 'image'],
            'video/mp4' => ['extensions' => ['mp4'], 'storage_ext' => 'mp4', 'type' => 'video'],
            'video/webm' => ['extensions' => ['webm'], 'storage_ext' => 'webm', 'type' => 'video'],
            'audio/mpeg' => ['extensions' => ['mp3'], 'storage_ext' => 'mp3', 'type' => 'audio'],
            'audio/ogg' => ['extensions' => ['ogg', 'oga'], 'storage_ext' => 'ogg', 'type' => 'audio'],
            'audio/wav' => ['extensions' => ['wav'], 'storage_ext' => 'wav', 'type' => 'audio'],
            'audio/mp4' => ['extensions' => ['m4a'], 'storage_ext' => 'm4a', 'type' => 'audio'],
            'application/pdf' => ['extensions' => ['pdf'], 'storage_ext' => 'pdf', 'type' => 'document'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['extensions' => ['docx'], 'storage_ext' => 'docx', 'type' => 'document'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['extensions' => ['xlsx'], 'storage_ext' => 'xlsx', 'type' => 'document'],
        ]);
    }

    private static function type_for_mime(string $mime): string {
        $allowed = self::allowed_types();
        return isset($allowed[$mime]) ? (string) $allowed[$mime]['type'] : 'document';
    }

    private static function path_for_key(string $key): string {
        $key = ltrim(str_replace(['\\', "\0"], ['/', ''], $key), '/');
        return self::storage_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    private static function is_safe_path(string $path): bool {
        $base = realpath(self::storage_dir());
        $candidate = realpath($path);
        return $base !== false && $candidate !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR);
    }

    private static function is_inside_web_root(string $dir): bool {
        $web = realpath(untrailingslashit(ABSPATH));
        if (!$web) {
            return true;
        }

        // Resolve an existing directory itself first so a symlink cannot disguise a
        // target inside the public web root. For a not-yet-created directory, resolve
        // its parent and append only the final basename.
        $resolved = realpath($dir);
        if ($resolved === false) {
            $parent = realpath(dirname($dir));
            $resolved = $parent ? $parent . DIRECTORY_SEPARATOR . basename($dir) : $dir;
        }
        $candidate = trailingslashit(wp_normalize_path($resolved));
        $web = trailingslashit(wp_normalize_path($web));
        return str_starts_with($candidate, $web);
    }
}
