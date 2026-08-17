<?php
defined('ABSPATH') || exit;

/** Versioned authenticated cryptography for File-17 private communication data. */
final class SN_Communication_Crypto {
    private const V1_SODIUM = "SNC1";
    private const V1_OPENSSL = "SNC2";
    private const V2_SODIUM = "SNC3";
    private const V2_OPENSSL = "SNC4";
    private const KEY_ID_BYTES = 16;
    private const MAX_PREVIOUS_KEYS = 4;
    private const SECRET_FILE = 'communication-master.key';
    private const MIN_SECRET_BYTES = 32;
    private static ?string $durable_secret_cache = null;

    /**
     * A durable File-17 key must be independent from WordPress authentication salts.
     * Operators may inject it from a real secret manager through the constant/filter.
     * Otherwise a random key is created atomically in File-17's private storage, which
     * is outside the public web root. Existing wp_salt encrypted records remain
     * decryptable as a legacy migration key, but new durable writes never use it.
     */
    private static function durable_secret(): string|WP_Error {
        if (self::$durable_secret_cache !== null) return self::$durable_secret_cache;

        $constant = defined('SN_COMMUNICATION_MASTER_SECRET') ? (string) constant('SN_COMMUNICATION_MASTER_SECRET') : '';
        $configured = (string) apply_filters('sn_network_communication_secret', $constant);
        if ($configured !== '') {
            if (!self::valid_dedicated_secret($configured)) {
                return new WP_Error('communication_key_invalid', 'The configured private communication key is too weak or is coupled to a WordPress authentication salt.', ['status' => 503]);
            }
            self::$durable_secret_cache = $configured;
            return $configured;
        }

        if (!class_exists('SN_Private_Files') || !SN_Private_Files::ensure_storage()) {
            return new WP_Error('communication_key_store_unavailable', 'The private communication key store is unavailable.', ['status' => 503]);
        }
        $path = SN_Private_Files::storage_dir() . DIRECTORY_SEPARATOR . self::SECRET_FILE;
        $existing = self::read_secret_file($path);
        if (!is_wp_error($existing)) {
            self::$durable_secret_cache = $existing;
            return $existing;
        }
        if ($existing->get_error_code() !== 'communication_key_missing') return $existing;

        try {
            $generated = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        } catch (Throwable $e) {
            return new WP_Error('communication_key_random_failed', 'Secure random generation for private communication is unavailable.', ['status' => 503]);
        }
        if (!self::valid_dedicated_secret($generated)) {
            return new WP_Error('communication_key_generation_failed', 'A valid private communication key could not be generated.', ['status' => 503]);
        }

        $handle = @fopen($path, 'x+b');
        if (is_resource($handle)) {
            $written = @fwrite($handle, $generated . "\n");
            @fflush($handle);
            @fclose($handle);
            @chmod($path, 0600);
            if ($written === false || $written < strlen($generated)) {
                @unlink($path);
                return new WP_Error('communication_key_write_failed', 'The private communication key could not be stored safely.', ['status' => 503]);
            }
            self::$durable_secret_cache = $generated;
            do_action('sn_network_communication_key_created', hash('sha256', $path));
            return $generated;
        }

        // Another worker may have won the atomic create race. Re-read only; never
        // overwrite an existing key because doing so would orphan prior ciphertext.
        $race = self::read_secret_file($path);
        if (is_wp_error($race)) return $race;
        self::$durable_secret_cache = $race;
        return $race;
    }

    private static function read_secret_file(string $path): string|WP_Error {
        if (!is_file($path)) return new WP_Error('communication_key_missing', 'The private communication key does not exist yet.', ['status' => 503]);
        if (!is_readable($path)) return new WP_Error('communication_key_unreadable', 'The private communication key store is unreadable.', ['status' => 503]);
        $secret = trim((string) @file_get_contents($path));
        if (!self::valid_dedicated_secret($secret)) {
            return new WP_Error('communication_key_invalid', 'The stored private communication key is invalid.', ['status' => 503]);
        }
        return $secret;
    }

    private static function valid_dedicated_secret(string $secret): bool {
        if (strlen($secret) < self::MIN_SECRET_BYTES) return false;
        foreach (['auth', 'secure_auth', 'logged_in', 'nonce'] as $scheme) {
            if (hash_equals((string) wp_salt($scheme), $secret)) return false;
        }
        return true;
    }

    /** Legacy salt is decrypt/short-lived-token compatibility only, never durable encryption authority. */
    private static function legacy_secret(): string { return (string) wp_salt('secure_auth'); }

    private static function signing_secret(): string {
        $durable = self::durable_secret();
        if (!is_wp_error($durable)) return $durable;
        // Signed grants are deliberately short-lived. If the durable store is down,
        // retain legacy token compatibility rather than fabricating a persistent key.
        do_action('sn_network_communication_signing_degraded', $durable->get_error_code());
        return self::legacy_secret();
    }

    private static function keyring(): array {
        $ring = [];
        $current = self::durable_secret();
        if (!is_wp_error($current)) $ring[] = $current;
        $previous = apply_filters('sn_network_communication_previous_secrets', []);
        foreach (array_slice(is_array($previous) ? $previous : [], 0, self::MAX_PREVIOUS_KEYS) as $secret) {
            $secret = (string) $secret;
            if ($secret !== '' && !in_array($secret, $ring, true)) $ring[] = $secret;
        }
        $legacy = self::legacy_secret();
        if ($legacy !== '' && !in_array($legacy, $ring, true)) $ring[] = $legacy;
        return $ring;
    }

    private static function key_id(string $secret): string { return substr(hash('sha256', 'file17-key-id|' . $secret), 0, self::KEY_ID_BYTES); }
    public static function current_key_id(): string {
        $secret = self::durable_secret();
        return is_wp_error($secret) ? '' : self::key_id($secret);
    }
    private static function key_for_secret(string $secret, string $context): string { return hash_hmac('sha256', 'file17|' . $context, $secret, true); }

    private static function secret_for_id(string $key_id): ?string {
        foreach (self::keyring() as $secret) if (hash_equals(self::key_id($secret), $key_id)) return $secret;
        return null;
    }

    public static function encrypt(string $plaintext, string $context): string|WP_Error {
        $secret = self::durable_secret();
        if (is_wp_error($secret)) return $secret;
        $key = self::key_for_secret($secret, $context); $key_id = self::key_id($secret);
        try {
            if (function_exists('sodium_crypto_secretbox')) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                return self::V2_SODIUM . $key_id . $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
            }
            if (function_exists('openssl_encrypt')) {
                $nonce = random_bytes(12); $tag = '';
                $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
                if (is_string($cipher) && strlen($tag) === 16) return self::V2_OPENSSL . $key_id . $nonce . $tag . $cipher;
            }
        } catch (Throwable $e) {
            return new WP_Error('communication_crypto_random_failed', 'Private communication encryption is temporarily unavailable.', ['status' => 503]);
        }
        return new WP_Error('communication_crypto_unavailable', 'Private communication encryption is unavailable.', ['status' => 503]);
    }

    public static function decrypt(string $ciphertext, string $context): string|WP_Error {
        $version = substr($ciphertext, 0, 4);
        if (in_array($version, [self::V2_SODIUM, self::V2_OPENSSL], true)) {
            $key_id = substr($ciphertext, 4, self::KEY_ID_BYTES);
            if (strlen($key_id) !== self::KEY_ID_BYTES) return new WP_Error('communication_cipher_invalid', 'Private communication data has an invalid key identifier.', ['status' => 500]);
            $secret = self::secret_for_id($key_id);
            if ($secret === null) return new WP_Error('communication_key_unavailable', 'The required private communication key is unavailable.', ['status' => 503]);
            return self::decrypt_with_secret($ciphertext, $context, $version, $secret, 4 + self::KEY_ID_BYTES);
        }
        if (in_array($version, [self::V1_SODIUM, self::V1_OPENSSL], true)) {
            foreach (self::keyring() as $secret) {
                $plain = self::decrypt_with_secret($ciphertext, $context, $version, $secret, 4);
                if (!is_wp_error($plain)) return $plain;
            }
            return new WP_Error('communication_decrypt_failed', 'Private communication data could not be opened with the available keyring.', ['status' => 500]);
        }
        return new WP_Error('communication_cipher_invalid', 'Private communication data has an unsupported format.', ['status' => 500]);
    }

    private static function decrypt_with_secret(string $ciphertext, string $context, string $version, string $secret, int $offset): string|WP_Error {
        $key = self::key_for_secret($secret, $context);
        if (in_array($version, [self::V1_SODIUM, self::V2_SODIUM], true) && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($ciphertext, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $body = substr($ciphertext, $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $body === '') return new WP_Error('communication_cipher_invalid', 'Private communication data has an invalid encrypted payload.', ['status' => 500]);
            $plain = sodium_crypto_secretbox_open($body, $nonce, $key);
            return is_string($plain) ? $plain : new WP_Error('communication_decrypt_failed', 'Private communication data could not be opened.', ['status' => 500]);
        }
        if (in_array($version, [self::V1_OPENSSL, self::V2_OPENSSL], true) && function_exists('openssl_decrypt')) {
            $nonce = substr($ciphertext, $offset, 12); $tag = substr($ciphertext, $offset + 12, 16); $body = substr($ciphertext, $offset + 28);
            if (strlen($nonce) !== 12 || strlen($tag) !== 16 || $body === '') return new WP_Error('communication_cipher_invalid', 'Private communication data has an invalid encrypted payload.', ['status' => 500]);
            $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
            return is_string($plain) ? $plain : new WP_Error('communication_decrypt_failed', 'Private communication data could not be opened.', ['status' => 500]);
        }
        return new WP_Error('communication_crypto_unavailable', 'The required private communication cipher is unavailable.', ['status' => 503]);
    }

    public static function needs_rotation(string $ciphertext): bool {
        $version = substr($ciphertext, 0, 4);
        if (in_array($version, [self::V1_SODIUM, self::V1_OPENSSL], true)) return true;
        if (!in_array($version, [self::V2_SODIUM, self::V2_OPENSSL], true)) return false;
        $current = self::current_key_id();
        return $current === '' || !hash_equals($current, substr($ciphertext, 4, self::KEY_ID_BYTES));
    }

    public static function rotate(string $ciphertext, string $context): string|WP_Error {
        if (!self::needs_rotation($ciphertext)) return $ciphertext;
        $plain = self::decrypt($ciphertext, $context);
        return is_wp_error($plain) ? $plain : self::encrypt($plain, $context);
    }

    public static function write_encrypted_file(string $path, string $plaintext, string $context): bool|WP_Error {
        $cipher = self::encrypt($plaintext, $context); if (is_wp_error($cipher)) return $cipher;
        $dir = dirname($path);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) return new WP_Error('private_storage_unavailable', 'Private transfer storage is unavailable.', ['status' => 503]);
        try { $tmp = $path . '.tmp-' . bin2hex(random_bytes(6)); }
        catch (Throwable $e) { return new WP_Error('secure_random_unavailable', 'Secure private storage naming is unavailable.', ['status' => 503]); }
        $written = file_put_contents($tmp, $cipher, LOCK_EX);
        if ($written === false || !@rename($tmp, $path)) { @unlink($tmp); return new WP_Error('private_storage_write_failed', 'The encrypted transfer chunk could not be stored.', ['status' => 500]); }
        @chmod($path, 0600); return true;
    }

    public static function read_encrypted_file(string $path, string $context): string|WP_Error {
        if (!is_file($path) || !is_readable($path)) return new WP_Error('private_chunk_unavailable', 'The private encrypted object is unavailable.', ['status' => 404]);
        $cipher = file_get_contents($path);
        if (!is_string($cipher)) return new WP_Error('private_chunk_read_failed', 'The private encrypted object could not be read.', ['status' => 500]);
        $plain = self::decrypt($cipher, $context);
        if (!is_wp_error($plain) && self::needs_rotation($cipher)) {
            $rotated = self::write_encrypted_file($path, $plain, $context);
            if (is_wp_error($rotated)) do_action('sn_network_crypto_rotation_deferred', hash('sha256', $path), $rotated->get_error_code());
        }
        return $plain;
    }

    public static function sign(array $claims, string $context): string {
        $json = wp_json_encode($claims, JSON_UNESCAPED_SLASHES); $payload = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
        $secret = self::signing_secret(); $key_id = self::key_id($secret);
        $mac = hash_hmac('sha256', $payload . '.' . $key_id, self::key_for_secret($secret, 'sign|' . $context));
        return $payload . '.' . $key_id . '.' . $mac;
    }

    public static function verify(string $token, string $context): array|WP_Error {
        $parts = explode('.', $token); $payload = '';
        if (count($parts) === 3) {
            [$payload, $key_id, $mac] = $parts; $secret = self::secret_for_id($key_id);
            if ($secret === null || !hash_equals(hash_hmac('sha256', $payload . '.' . $key_id, self::key_for_secret($secret, 'sign|' . $context)), $mac)) return new WP_Error('invalid_access_grant', 'The private access grant is invalid.', ['status' => 403]);
        } elseif (count($parts) === 2) {
            [$payload, $mac] = $parts; $valid = false;
            foreach (self::keyring() as $secret) {
                $legacy_key = hash_hmac('sha256', 'file17|sign|' . $context, $secret, true);
                if (hash_equals(hash_hmac('sha256', $payload, $legacy_key), $mac)) { $valid = true; break; }
            }
            if (!$valid) return new WP_Error('invalid_access_grant', 'The private access grant is invalid.', ['status' => 403]);
        } else return new WP_Error('invalid_access_grant', 'The private access grant is invalid.', ['status' => 403]);
        $encoded = strtr($payload, '-_', '+/'); $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $raw = base64_decode($encoded, true); $claims = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($claims) || (int) ($claims['exp'] ?? 0) <= time()) return new WP_Error('expired_access_grant', 'The private access grant has expired.', ['status' => 403]);
        return $claims;
    }
}
