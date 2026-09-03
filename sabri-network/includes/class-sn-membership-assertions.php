<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Fail-closed adapter to File 00 — Sabri Membership Core. */
final class SN_Membership_Assertions {
    private const MIN_CONTRACT_VERSION = '1.1.1';
    private static array $cache = [];

    public static function register(): void {
        add_filter('sn_network_identity_authority_available', [self::class, 'filter_authority_available'], PHP_INT_MIN);
        add_filter('sn_network_user_can_access', [self::class, 'filter_access'], PHP_INT_MIN, 2);
        add_filter('sn_network_user_is_suspended', [self::class, 'filter_suspended'], PHP_INT_MIN, 2);
        add_filter('sn_network_user_age_state', [self::class, 'filter_age_state'], PHP_INT_MIN, 2);
        add_filter('sn_network_guardian_consent_valid', [self::class, 'filter_guardian'], PHP_INT_MIN, 2);
    }

    public static function available(): bool {
        return function_exists('smc_communication_assertions') && function_exists('smc_membership_assertions');
    }

    public static function filter_authority_available($available): bool {
        // This lowest-priority adapter establishes availability from the canonical
        // versioned File-00 contract itself. SN_Policy's legacy class/function
        // heuristic is only a seed and must not veto a valid contract-only File-00
        // implementation. Later filters still retain the ability to fail closed.
        return self::available();
    }

    public static function filter_access($allowed, int $user_id) {
        if ($allowed !== true) {
            return $allowed;
        }
        $assertion = self::communication($user_id);
        if (is_wp_error($assertion)) {
            return $assertion;
        }
        return $assertion['eligible'] === true && $assertion['can_message'] === true
            ? true
            : new WP_Error('network_access_denied', 'The current File 00 communication assertion does not permit Network messaging.', ['status' => 403]);
    }

    public static function filter_suspended($state, int $user_id): bool {
        $assertion = self::communication($user_id);
        return is_wp_error($assertion) ? true : $assertion['suspended'] === true;
    }

    public static function filter_age_state($state, int $user_id): string {
        $assertion = self::communication($user_id);
        return is_wp_error($assertion) ? 'unknown' : (string) $assertion['age_state'];
    }

    public static function filter_guardian($state, int $user_id): bool {
        $assertion = self::communication($user_id);
        return !is_wp_error($assertion) && $assertion['guardian_verified'] === true;
    }

    public static function communication(int $user_id): array|WP_Error {
        if ($user_id <= 0) {
            return self::error('identity_unavailable', 'The platform identity is unavailable.', 401);
        }
        if (array_key_exists($user_id, self::$cache)) {
            return self::$cache[$user_id];
        }
        if (!self::available()) {
            return self::$cache[$user_id] = self::error('identity_authority_unavailable', 'The File 00 communication-assertion authority is unavailable.', 503);
        }
        try {
            $communication = smc_communication_assertions($user_id);
            $membership = smc_membership_assertions($user_id);
        } catch (Throwable $error) {
            return self::$cache[$user_id] = self::error('identity_assertion_failed', 'The File 00 communication assertion could not be verified.', 503);
        }
        if (!is_array($communication) || !is_array($membership)) {
            return self::$cache[$user_id] = self::error('identity_assertion_invalid', 'The File 00 assertion is invalid.', 503);
        }
        $required = ['contract_version', 'user_id', 'status', 'eligible', 'phone_verified', 'can_message', 'can_call', 'suspended'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $communication)) {
                return self::$cache[$user_id] = self::error('identity_assertion_incomplete', 'The File 00 communication assertion is incomplete.', 503);
            }
        }
        $version = trim((string) $communication['contract_version']);
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version) || version_compare($version, self::MIN_CONTRACT_VERSION, '<')) {
            return self::$cache[$user_id] = self::error('identity_contract_unsupported', 'The File 00 communication contract version is unsupported.', 503);
        }
        if ((int) $communication['user_id'] !== $user_id || (isset($membership['user_id']) && (int) $membership['user_id'] !== $user_id)) {
            return self::$cache[$user_id] = self::error('identity_assertion_subject_mismatch', 'The File 00 assertion subject is invalid.', 503);
        }
        foreach (['eligible', 'phone_verified', 'can_message', 'can_call', 'suspended'] as $field) {
            if (!is_bool($communication[$field])) {
                return self::$cache[$user_id] = self::error('identity_assertion_type_invalid', 'The File 00 communication assertion contains an invalid state.', 503);
            }
        }
        $status = sanitize_key((string) $communication['status']);
        if ($status === '') {
            return self::$cache[$user_id] = self::error('identity_assertion_state_invalid', 'The File 00 communication assertion has no valid status.', 503);
        }
        return self::$cache[$user_id] = [
            'contract_version' => $version,
            'user_id' => $user_id,
            'status' => $status,
            'eligible' => $communication['eligible'],
            'phone_verified' => $communication['phone_verified'],
            'can_message' => $communication['can_message'],
            'can_call' => $communication['can_call'],
            'suspended' => $communication['suspended'],
            'age_state' => self::normalize_age_state($communication, $membership),
            'guardian_verified' => self::normalize_guardian_state($communication, $membership),
        ];
    }

    public static function age_state(int $user_id): string {
        $assertion = self::communication($user_id);
        return is_wp_error($assertion) ? 'unknown' : (string) $assertion['age_state'];
    }

    public static function guardian_verified(int $user_id): bool {
        $assertion = self::communication($user_id);
        return !is_wp_error($assertion) && $assertion['guardian_verified'] === true;
    }

    public static function phone_verified(int $user_id): bool {
        $assertion = self::communication($user_id);
        return !is_wp_error($assertion) && $assertion['phone_verified'] === true;
    }

    public static function phone_projection(int $user_id, int $viewer_id, bool $self): string {
        if (!self::phone_verified($user_id)) {
            return '';
        }
        $value = apply_filters('sn_network_file00_phone_projection', null, $user_id, $viewer_id, $self);
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $normalized = SN_Auth::normalize_phone($value);
        return is_wp_error($normalized) ? '' : $normalized;
    }

    public static function resolve_user_by_phone(string $phone): ?WP_User {
        $resolved = apply_filters('sn_network_file00_user_by_phone', null, $phone);
        if ($resolved instanceof WP_User) {
            return $resolved;
        }
        $user_id = is_numeric($resolved) ? absint($resolved) : 0;
        $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
        return $user instanceof WP_User ? $user : null;
    }

    public static function clear_cache(?int $user_id = null): void {
        if ($user_id === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$user_id]);
        }
    }

    private static function normalize_age_state(array $communication, array $membership): string {
        foreach ([$communication, $membership] as $source) {
            foreach (['age_state', 'minor_state'] as $key) {
                if (isset($source[$key]) && is_string($source[$key]) && in_array($source[$key], ['adult', 'minor', 'unknown'], true)) {
                    return $source[$key];
                }
            }
            foreach (['is_minor', 'minor'] as $key) {
                if (array_key_exists($key, $source) && is_bool($source[$key])) {
                    return $source[$key] ? 'minor' : 'adult';
                }
            }
        }
        return 'unknown';
    }

    private static function normalize_guardian_state(array $communication, array $membership): bool {
        foreach ([$communication, $membership] as $source) {
            if (array_key_exists('guardian_verified', $source) && is_bool($source['guardian_verified'])) {
                return $source['guardian_verified'];
            }
        }
        return false;
    }

    private static function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}

SN_Membership_Assertions::register();
