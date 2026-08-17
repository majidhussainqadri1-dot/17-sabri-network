<?php
/** Fifth fresh review: keep the strongest AI/semantic governance on the final REST route owner. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fifth_Fresh_Knowledge_Hardening {
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        // Later than Fourth_Fresh_Knowledge (2300) so its direct calls to the older
        // Future_Superset implementation cannot bypass the stronger Future24-G gates.
        add_action('rest_api_init', [self::class, 'override_routes'], 3100);
    }

    public static function override_routes(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/future/ai-assistant', [
            'methods'=>'POST','callback'=>[self::class,'ai_assistant'],'permission_callback'=>$access,
        ], true);
        register_rest_route('sabri-network/v2', '/future/semantic-search', [
            'methods'=>'POST','callback'=>[self::class,'semantic_search'],'permission_callback'=>$access,
        ], true);
    }

    public static function ai_assistant(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request->get_param('conversation_id'));
        $user = get_current_user_id();
        if ($conversation <= 0) return self::not_found();
        return self::with_lock($conversation, static function () use ($request, $conversation, $user) {
            if (!SN_DB::is_member($conversation, $user)) return self::not_found();
            foreach (array_slice(array_values(array_unique(array_filter(array_map('absint', (array)$request->get_param('message_ids'))))), 0, 50) as $id) {
                $message = self::message($id);
                if (!$message || (int)$message->conversation_id !== $conversation || $message->deleted_at !== null || SN_Message_Operations::is_hidden($user, $id)) return self::not_found();
            }
            // This implementation contains the File16 authorization, minor policy,
            // per-request consent, redaction and stale-context checks.
            return SN_Future24_Review_Hardening_G::ai_assistant($request);
        });
    }

    public static function semantic_search(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $conversation = absint($request->get_param('conversation_id'));
        $user = get_current_user_id();
        if ($conversation <= 0) return self::not_found();
        return self::with_lock($conversation, static function () use ($request, $conversation, $user) {
            if (!SN_DB::is_member($conversation, $user)) return self::not_found();
            // Future24-G is the canonical consent owner for private semantic search;
            // it also rechecks current visibility after provider results return.
            return SN_Future24_Review_Hardening_G::semantic_search($request);
        });
    }

    private static function message(int $id): ?object {
        global $wpdb;
        return $id > 0 ? ($wpdb->get_row($wpdb->prepare(
            'SELECT id,conversation_id,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d',
            $id
        )) ?: null) : null;
    }

    private static function with_lock(int $conversation, callable $callback) {
        global $wpdb;
        $lock = 'sn:f17:conversation:' . substr(hash('sha256', (string)$conversation), 0, 32);
        $ok = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT));
        if ($ok !== 1) return new WP_Error('sn_conversation_busy', 'The conversation is changing. Retry the request.', ['status'=>409]);
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function not_found(): WP_Error {
        return new WP_Error('not_found', 'The requested communication object is unavailable.', ['status'=>404]);
    }
}
