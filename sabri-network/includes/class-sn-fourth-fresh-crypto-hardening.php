<?php
/** Fourth fresh cycle: dedicated communication-key strength and filesystem hygiene. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Crypto_Hardening {
    private const INVALID_SENTINEL = '__sn_invalid_communication_master_secret__';

    public static function register(): void {
        // SN_Communication_Crypto casts this filter to string before its own private
        // validator. Returning a short sentinel intentionally makes that validator
        // fail closed with communication_key_invalid instead of silently falling back.
        add_filter('sn_network_communication_secret', [self::class, 'validate_secret_source'], PHP_INT_MAX, 1);
    }

    public static function validate_secret_source(string $configured): string {
        if ($configured !== '') {
            return self::strong_secret($configured) ? $configured : self::INVALID_SENTINEL;
        }
        if (!class_exists('SN_Private_Files')) return $configured;
        $path = SN_Private_Files::storage_dir() . DIRECTORY_SEPARATOR . 'communication-master.key';
        if (!file_exists($path) && !is_link($path)) return $configured; // Allow the canonical atomic creator.
        if (is_link($path) || !is_file($path) || !is_readable($path)) return self::INVALID_SENTINEL;

        // On POSIX hosts the durable master key may not be group/world accessible.
        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = @fileperms($path);
            if ($perms === false) return self::INVALID_SENTINEL;
            if (($perms & 0077) !== 0) {
                @chmod($path, 0600);
                clearstatcache(true, $path);
                $perms = @fileperms($path);
                if ($perms === false || ($perms & 0077) !== 0) return self::INVALID_SENTINEL;
            }
        }
        $secret = trim((string) @file_get_contents($path));
        return self::strong_secret($secret) ? $configured : self::INVALID_SENTINEL;
    }

    private static function strong_secret(string $secret): bool {
        if (strlen($secret) < 32) return false;
        foreach (['auth','secure_auth','logged_in','nonce'] as $scheme) {
            if (hash_equals((string) wp_salt($scheme), $secret)) return false;
        }
        // Raw binary secret material is accepted if it contains non-printable bytes
        // and already provides at least 256 bits of source material.
        if (preg_match('/[^\x20-\x7E]/', $secret) === 1) return strlen($secret) >= 32;

        // Printable operator secrets must be machine-generated hex/base64 material;
        // arbitrary 32-character words/repetitions are not accepted as master keys.
        if (strlen($secret) >= 64 && ctype_xdigit($secret) && strlen($secret) % 2 === 0) {
            $raw = @hex2bin($secret);
            return is_string($raw) && strlen($raw) >= 32;
        }
        $normalized = strtr($secret, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $raw = base64_decode($normalized, true);
        if (!is_string($raw) || strlen($raw) < 32) return false;
        $canonical = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return hash_equals($canonical, rtrim(strtr($secret, '+/', '-_'), '='));
    }
}
