<?php
defined('ABSPATH') || exit;

/**
 * Authenticated at-rest envelope for File-17 canonical message bodies.
 * This is server-side storage encryption, not E2EE.
 */
final class SN_Message_Body {
    public const PREFIX = 'SNE1:';

    private static function context(int $conversation_id, int $sender_id): string {
        return 'message-body|' . max(0, $conversation_id) . '|' . max(0, $sender_id);
    }

    public static function is_encrypted(string $stored): bool {
        return str_starts_with($stored, self::PREFIX);
    }

    private static function raw_cipher(string $stored): string|WP_Error {
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        return is_string($raw) && $raw !== ''
            ? $raw
            : new WP_Error('message_cipher_invalid', 'The private message body has an invalid encrypted envelope.', ['status' => 500]);
    }

    public static function encrypt(string $plaintext, int $conversation_id, int $sender_id): string|WP_Error {
        if ($plaintext === '') {
            return '';
        }
        $cipher = SN_Communication_Crypto::encrypt($plaintext, self::context($conversation_id, $sender_id));
        if (is_wp_error($cipher)) {
            return $cipher;
        }
        return self::PREFIX . base64_encode($cipher);
    }

    public static function decrypt_value(string $stored, int $conversation_id, int $sender_id): string|WP_Error {
        if ($stored === '' || !self::is_encrypted($stored)) {
            return $stored;
        }
        $raw = self::raw_cipher($stored);
        if (is_wp_error($raw)) {
            return $raw;
        }
        return SN_Communication_Crypto::decrypt($raw, self::context($conversation_id, $sender_id));
    }

    public static function decrypt_row(object $row): string|WP_Error {
        if (!empty($row->deleted_at)) {
            return '';
        }
        $stored = (string) ($row->body ?? '');
        $plain = self::decrypt_value($stored, (int) ($row->conversation_id ?? 0), (int) ($row->sender_id ?? 0));
        if (!is_wp_error($plain) && self::is_encrypted($stored)) {
            $raw = self::raw_cipher($stored);
            if (!is_wp_error($raw) && SN_Communication_Crypto::needs_rotation($raw)) {
                self::rotate_row($row, $plain);
            }
        }
        return $plain;
    }

    /** Encrypt plaintext or re-encrypt a legacy-key envelope with optimistic CAS. */
    public static function ensure_encrypted_row(object $row): mixed {
        $stored = (string) ($row->body ?? '');
        if ($stored === '' || !empty($row->deleted_at)) {
            return $row;
        }
        if (self::is_encrypted($stored)) {
            $raw = self::raw_cipher($stored);
            if (is_wp_error($raw)) {
                return $raw;
            }
            if (!SN_Communication_Crypto::needs_rotation($raw)) {
                return $row;
            }
            $plain = SN_Communication_Crypto::decrypt($raw, self::context((int) $row->conversation_id, (int) $row->sender_id));
            return is_wp_error($plain) ? $plain : self::rotate_row($row, $plain);
        }
        return self::write_cas($row, $stored, $stored);
    }

    private static function rotate_row(object $row, string $plain): mixed {
        $stored = (string) ($row->body ?? '');
        return self::write_cas($row, $stored, $plain);
    }

    private static function write_cas(object $row, string $expected_stored, string $plain): mixed {
        global $wpdb;
        $encrypted = self::encrypt($plain, (int) $row->conversation_id, (int) $row->sender_id);
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('messages') . ' SET body=%s WHERE id=%d AND body=%s AND deleted_at IS NULL',
            $encrypted,
            (int) $row->id,
            $expected_stored
        ));
        if ($updated === false) {
            return new WP_Error('message_encryption_write_failed', 'The private message could not be encrypted at rest.', ['status' => 500]);
        }
        if ($updated === 0) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', (int) $row->id));
            if (!$fresh || ((string) $fresh->body !== '' && !self::is_encrypted((string) $fresh->body))) {
                return new WP_Error('message_encryption_conflict', 'The private message changed during encryption migration.', ['status' => 409]);
            }
            return $fresh;
        }
        $row->body = $encrypted;
        return $row;
    }
}