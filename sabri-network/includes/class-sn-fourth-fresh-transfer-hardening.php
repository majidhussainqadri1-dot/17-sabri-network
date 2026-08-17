<?php
/** Fourth fresh cycle: verified-transfer storage containment and initiation serialization. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Transfer_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_filter('sn_network_transfer_storage_root', [self::class, 'safe_storage_root'], PHP_INT_MAX, 1);
        add_action('rest_api_init', [self::class, 'override_routes'], 2260);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/transfers', [
            ['methods'=>'GET','callback'=>[SN_File_Transfer::class,'list_transfers'],'permission_callback'=>[SN_File_Transfer::class,'verified_access']],
            ['methods'=>'POST','callback'=>[self::class,'initiate'],'permission_callback'=>[SN_File_Transfer::class,'verified_access']],
        ], true);
    }

    public static function initiate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $client = strtolower(trim((string) $request->get_param('client_id')));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) {
            return new WP_Error('invalid_client_id', 'A caller-supplied transfer idempotency key is required.', ['status'=>400]);
        }
        if (!SN_File_Transfer::ensure_storage()) {
            return new WP_Error('transfer_storage_unavailable', 'Private transfer storage is unavailable or not safely outside every public document root.', ['status'=>503]);
        }
        global $wpdb;
        $sender = get_current_user_id();
        $lock = 'sn:f17:transfer-init:' . substr(hash('sha256', (string) $sender), 0, 32);
        $held = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT));
        if ($held !== 1) return new WP_Error('transfer_initiation_busy', 'Another transfer is being initiated. Retry with the same idempotency key.', ['status'=>409]);
        try {
            // The original owner now evaluates its daily-volume total and inserts the
            // session while all initiations by this sender are serialized.
            return SN_File_Transfer::initiate($request);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Reject roots that are in, equal to, symlink into, or encompass a known public root. */
    public static function safe_storage_root(string $root): string {
        $resolved = self::resolved_path($root, true);
        if ($resolved === '') return '';
        foreach (self::public_roots() as $public) {
            if (self::same_or_within($resolved, $public) || self::same_or_within($public, $resolved)) return '';
        }
        return $root;
    }

    private static function public_roots(): array {
        $roots = [ABSPATH];
        if (function_exists('get_home_path')) $roots[] = get_home_path();
        elseif (defined('WP_ADMIN_DIR')) $roots[] = dirname((string) ABSPATH . WP_ADMIN_DIR);
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $roots[] = (string) $_SERVER['DOCUMENT_ROOT'];
        $extra = apply_filters('sn_network_public_document_roots', []);
        if (is_array($extra)) $roots = array_merge($roots, $extra);
        $out = [];
        foreach ($roots as $root) {
            $value = self::resolved_path((string) $root, false);
            if ($value !== '') $out[$value] = $value;
        }
        return array_values($out);
    }

    private static function resolved_path(string $path, bool $allow_missing): string {
        $path = trim($path);
        if ($path === '') return '';
        $real = realpath($path);
        if ($real !== false) return untrailingslashit(wp_normalize_path($real));
        if (!$allow_missing) return '';
        $parent = realpath(dirname($path));
        if ($parent === false) return '';
        return untrailingslashit(wp_normalize_path($parent . DIRECTORY_SEPARATOR . basename($path)));
    }

    private static function same_or_within(string $candidate, string $root): bool {
        $candidate = untrailingslashit(wp_normalize_path($candidate));
        $root = untrailingslashit(wp_normalize_path($root));
        return $candidate === $root || str_starts_with($candidate . '/', $root . '/');
    }
}
