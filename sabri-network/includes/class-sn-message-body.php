<?php
defined('ABSPATH') || exit;

/**
 * Authenticated at-rest envelope for File-17 canonical message bodies.
 *
 * This is server-side storage encryption, not E2EE. Legacy plaintext remains
 * readable only so the bounded migration can encrypt it without data loss.
 */
final class SN_Message_Body {
    public const PREFIX = 'SNE1:';

    private static function context(int $conversation_id, int $sender_id): string {
        return 'message-body|' . max(0, $conversation_id) . '|' . max(0, $sender_id);
    }

    public static function is_encrypted(string $stored): bool {
        return str_starts_with($stored, self::PREFIX);
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
            // Transitional compatibility for pre-2.0.2 rows. New writes never use this path.
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($raw) || $raw === '') {
            return new WP_Error('message_cipher_invalid', 'The private message body has an invalid encrypted envelope.', ['status' => 500]);
        }
        return SN_Communication_Crypto::decrypt($raw, self::context($conversation_id, $sender_id));
    }

    public static function decrypt_row(object $row): string|WP_Error {
        if (!empty($row->deleted_at)) {
            return '';
        }
        return self::decrypt_value((string) ($row->body ?? ''), (int) ($row->conversation_id ?? 0), (int) ($row->sender_id ?? 0));
    }

    /**
     * Encrypt a legacy plaintext row with an optimistic compare-and-swap write.
     * Returns either the refreshed/enriched row or a WP_Error. `mixed` is used
     * deliberately for PHP 8.1 compatibility: `object|WP_Error` is redundant
     * because WP_Error is itself an object and PHP rejects that union type.
     */
    public static function ensure_encrypted_row(object $row): mixed {
        global $wpdb;
        $stored = (string) ($row->body ?? '');
        if ($stored === '' || !empty($row->deleted_at) || self::is_encrypted($stored)) {
            return $row;
        }
        $encrypted = self::encrypt($stored, (int) $row->conversation_id, (int) $row->sender_id);
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('messages') . ' SET body=%s WHERE id=%d AND body=%s AND deleted_at IS NULL',
            $encrypted,
            (int) $row->id,
            $stored
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
