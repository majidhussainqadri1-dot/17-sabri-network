<?php
defined('ABSPATH') || exit;

/** Small, secret-free-at-rest cryptographic helper for File-17 private drafts and transfer chunks. */
final class SN_Communication_Crypto {
    private const V1_SODIUM = "SNC1";
    private const V1_OPENSSL = "SNC2";

    private static function key(string $context): string {
        return hash_hmac('sha256', 'file17|' . $context, wp_salt('secure_auth'), true);
    }

    public static function encrypt(string $plaintext, string $context): string|WP_Error {
        $key = self::key($context);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return self::V1_SODIUM . $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
        }
        if (function_exists('openssl_encrypt')) {
            $nonce = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
            if (is_string($cipher) && strlen($tag) === 16) {
                return self::V1_OPENSSL . $nonce . $tag . $cipher;
            }
        }
        return new WP_Error('communication_crypto_unavailable', 'Private communication encryption is unavailable.', ['status' => 503]);
    }

    public static function decrypt(string $ciphertext, string $context): string|WP_Error {
        $key = self::key($context);
        $version = substr($ciphertext, 0, 4);
        if ($version === self::V1_SODIUM && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($ciphertext, 4, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $body = substr($ciphertext, 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($body, $nonce, $key);
            return is_string($plain) ? $plain : new WP_Error('communication_decrypt_failed', 'Private communication data could not be opened.', ['status' => 500]);
        }
        if ($version === self::V1_OPENSSL && function_exists('openssl_decrypt')) {
            $nonce = substr($ciphertext, 4, 12);
            $tag = substr($ciphertext, 16, 16);
            $body = substr($ciphertext, 32);
            $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
            return is_string($plain) ? $plain : new WP_Error('communication_decrypt_failed', 'Private communication data could not be opened.', ['status' => 500]);
        }
        return new WP_Error('communication_cipher_invalid', 'Private communication data has an unsupported format.', ['status' => 500]);
    }

    public static function write_encrypted_file(string $path, string $plaintext, string $context): bool|WP_Error {
        $cipher = self::encrypt($plaintext, $context);
        if (is_wp_error($cipher)) {
            return $cipher;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('private_storage_unavailable', 'Private transfer storage is unavailable.', ['status' => 503]);
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $written = file_put_contents($tmp, $cipher, LOCK_EX);
        if ($written === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('private_storage_write_failed', 'The encrypted transfer chunk could not be stored.', ['status' => 500]);
        }
        @chmod($path, 0600);
        return true;
    }

    public static function read_encrypted_file(string $path, string $context): string|WP_Error {
        if (!is_file($path) || !is_readable($path)) {
            return new WP_Error('private_chunk_unavailable', 'The private transfer chunk is unavailable.', ['status' => 404]);
        }
        $cipher = file_get_contents($path);
        return is_string($cipher) ? self::decrypt($cipher, $context) : new WP_Error('private_chunk_read_failed', 'The private transfer chunk could not be read.', ['status' => 500]);
    }

    public static function sign(array $claims, string $context): string {
        $json = wp_json_encode($claims, JSON_UNESCAPED_SLASHES);
        $payload = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
        $mac = hash_hmac('sha256', $payload, self::key('sign|' . $context));
        return $payload . '.' . $mac;
    }

    public static function verify(string $token, string $context): array|WP_Error {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], self::key('sign|' . $context)), $parts[1])) {
            return new WP_Error('invalid_access_grant', 'The private access grant is invalid.', ['status' => 403]);
        }
        $encoded = strtr($parts[0], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $raw = base64_decode($encoded, true);
        $claims = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($claims) || (int) ($claims['exp'] ?? 0) < time()) {
            return new WP_Error('expired_access_grant', 'The private access grant has expired.', ['status' => 403]);
        }
        return $claims;
    }
}
