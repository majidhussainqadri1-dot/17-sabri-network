<?php
/** Fourth fresh cycle: lossless, monotonic private-message search reconstruction. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Search_Hardening {
    private const EPOCH_OPTION = 'sn_message_search_key_epoch';
    private const REBUILD_OPTION = 'sn_message_search_epoch_rebuilding';
    private const ERROR_OPTION = 'sn_message_search_epoch_error';
    private const CONTINUE_HOOK = 'sn_message_search_epoch_continue';
    private const BATCH = 100;

    public static function register(): void {
        // Replace implementations that advanced past failed rows and reset the
        // high-water mark to zero after completion, which could both lose search
        // coverage and trigger perpetual full-corpus reindexing.
        remove_action('sn_cleanup_hourly', [SN_Message_Search::class, 'backfill']);
        remove_action('init', [SN_Runtime_Boundary_Policy::class, 'reconcile_search_epoch'], 11);
        remove_action(self::CONTINUE_HOOK, [SN_Runtime_Boundary_Policy::class, 'continue_search_rebuild']);
        remove_action('sn_cleanup_hourly', [SN_Runtime_Boundary_Policy::class, 'finish_search_rebuild'], 9999);

        add_action('init', [self::class, 'reconcile_epoch'], 11);
        add_action(self::CONTINUE_HOOK, [self::class, 'continue_rebuild']);
        add_action('sn_cleanup_hourly', [self::class, 'backfill'], 20);
        add_action('sn_cleanup_hourly', [self::class, 'finish_rebuild'], 9999);
        // The legacy administrator rebuild callback still called the lossy base
        // backfill directly. Own that route last so manual reconstruction follows
        // exactly the same fail-closed state machine as epoch/hourly rebuilding.
        add_action('rest_api_init', [self::class, 'override_routes'], 2300);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/admin/message-search/rebuild', [
            'methods'=>'POST',
            'callback'=>[self::class, 'rebuild'],
            'permission_callback'=>[SN_REST::class, 'admin_access'],
        ], true);
    }

    public static function rebuild(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        if ($request->get_param('confirm') !== true) {
            return new WP_Error('confirmation_required', 'Exact boolean confirmation is required.', ['status'=>400]);
        }
        $actor = get_current_user_id();
        if (!SN_Policy::consume_rate_limit('message_search_rebuild', (string)$actor, 3, DAY_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many rebuild requests.', ['status'=>429]);
        }
        $tokens = SN_DB::table('message_search_tokens');
        if ($wpdb->query('TRUNCATE TABLE ' . $tokens) === false) {
            update_option(self::ERROR_OPTION, 'truncate_failed', false);
            update_option(self::REBUILD_OPTION, true, false);
            return new WP_Error('search_rebuild_failed', 'The search index could not be reset.', ['status'=>500]);
        }
        update_option('sn_message_search_backfill_after', 0, false);
        update_option(self::EPOCH_OPTION, self::epoch(), false);
        update_option(self::REBUILD_OPTION, true, false);
        delete_option(self::ERROR_OPTION);
        SN_DB::audit('message_search_rebuild_started', 'message_search', 0, 'success', ['mode'=>'manual-lossless'], $actor);

        self::backfill();
        self::finish_rebuild();
        $error = (string)get_option(self::ERROR_OPTION, '');
        if ($error !== '') {
            return new WP_Error('search_rebuild_deferred', 'The private search rebuild encountered a row that could not be indexed and will retry without skipping it.', [
                'status'=>503,
                'reason'=>$error,
                'backfill_after'=>(int)get_option('sn_message_search_backfill_after', 0),
            ]);
        }
        return rest_ensure_response([
            'rebuild_started'=>(bool)get_option(self::REBUILD_OPTION, false),
            'rebuild_complete'=>!(bool)get_option(self::REBUILD_OPTION, false),
            'backfill_after'=>(int)get_option('sn_message_search_backfill_after', 0),
        ]);
    }

    public static function reconcile_epoch(): void {
        if (!class_exists('SN_DB') || !class_exists('SN_Message_Search')) return;
        global $wpdb;
        $tokens = SN_DB::table('message_search_tokens');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($tokens))) !== $tokens) return;
        $current = self::epoch();
        $stored = (string) get_option(self::EPOCH_OPTION, '');
        if ($stored !== $current) {
            if ($wpdb->query('TRUNCATE TABLE ' . $tokens) === false) {
                update_option(self::ERROR_OPTION, 'truncate_failed', false);
                update_option(self::REBUILD_OPTION, true, false);
                return;
            }
            update_option('sn_message_search_backfill_after', 0, false);
            update_option(self::EPOCH_OPTION, $current, false);
            update_option(self::REBUILD_OPTION, true, false);
            delete_option(self::ERROR_OPTION);
            SN_DB::audit('message_search_key_epoch_rebuild_started', 'message_search', 0, 'success', ['epoch'=>substr($current,0,12)], 0);
            self::backfill();
        }
        self::finish_rebuild();
    }

    public static function continue_rebuild(): void {
        if (!(bool) get_option(self::REBUILD_OPTION, false)) return;
        self::backfill();
        self::finish_rebuild();
    }

    public static function backfill(): void {
        global $wpdb;
        $after = max(0, (int) get_option('sn_message_search_backfill_after', 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id>%d ORDER BY id ASC LIMIT %d',
            $after,
            self::BATCH
        ));
        if (!is_array($rows)) {
            self::record_error('backfill_read_failed', $after, 0);
            return;
        }
        if (!$rows) return; // Preserve the high-water mark; never restart at zero implicitly.

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $indexed = SN_Message_Search::index_message($id);
            if (is_wp_error($indexed)) {
                // Do not advance past the failed message: otherwise a key-epoch rebuild
                // can finish while this message remains permanently unsearchable.
                self::record_error($indexed->get_error_code(), $after, $id);
                return;
            }
            $after = $id;
            update_option('sn_message_search_backfill_after', $after, false);
        }
        delete_option(self::ERROR_OPTION);
    }

    public static function finish_rebuild(): void {
        if (!(bool) get_option(self::REBUILD_OPTION, false) || !class_exists('SN_DB')) return;
        global $wpdb;
        $after = max(0, (int) get_option('sn_message_search_backfill_after', 0));
        $next = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SN_DB::table('messages') . ' WHERE id>%d ORDER BY id ASC LIMIT 1',
            $after
        ));
        if ($next <= 0 && (string) get_option(self::ERROR_OPTION, '') === '') {
            // Keep the high-water mark so ordinary hourly maintenance only considers
            // genuinely newer message ids rather than reindexing the entire corpus.
            update_option(self::REBUILD_OPTION, false, false);
            SN_DB::audit('message_search_key_epoch_rebuild_completed', 'message_search', 0, 'success', ['epoch'=>substr(self::epoch(),0,12),'after'=>$after], 0);
            return;
        }
        if (!wp_next_scheduled(self::CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::CONTINUE_HOOK);
        }
    }

    private static function record_error(string $code, int $after, int $message_id): void {
        update_option(self::ERROR_OPTION, sanitize_key($code) ?: 'backfill_failed', false);
        SN_DB::audit('message_search_backfill_failed', 'message_search', $message_id, 'failure', ['after'=>$after,'reason'=>sanitize_key($code)], 0);
        if ((bool) get_option(self::REBUILD_OPTION, false) && !wp_next_scheduled(self::CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::CONTINUE_HOOK);
        }
    }

    private static function epoch(): string {
        return hash('sha256', 'sn-message-search-v1|' . wp_salt('auth'));
    }
}
