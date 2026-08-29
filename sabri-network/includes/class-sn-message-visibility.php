<?php
/** Final route overlay for hidden-message privacy and space posting reservations. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Message_Visibility {
    private const MAX_VISIBILITY_SCAN_PAGES = 20;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 1100);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/messages', [
            ['methods'=>'GET','callback'=>[self::class,'get_messages'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'send_message'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/search', [
            'methods'=>'GET','callback'=>[self::class,'search'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/search/context', [
            'methods'=>'GET','callback'=>[self::class,'context'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
    }

    public static function send_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $reservation = SN_Spaces::reserve_post_slot(absint($request['id']), get_current_user_id());
        if (is_wp_error($reservation)) return $reservation;
        $response = SN_Message_Integrity::send_message($request);
        if (is_wp_error($response) || $response->get_status() >= 400) SN_Spaces::release_post_slot($reservation);
        return $response;
    }

    public static function get_messages(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $viewer = get_current_user_id();
        $limit = min(100, max(1, absint($request->get_param('limit')) ?: 50));
        $original_after = absint($request->get_param('after'));
        $original_before = absint($request->get_param('before'));
        $probe = clone $request;
        $visible = [];
        $scan_after = $original_after;
        $scan_before = $original_before;

        for ($page = 0; $page < self::MAX_VISIBILITY_SCAN_PAGES && count($visible) < $limit; $page++) {
            $probe->set_param('limit', min(100, max(1, $limit - count($visible))));
            $probe->set_param('after', $scan_after);
            $probe->set_param('before', $scan_after > 0 ? 0 : $scan_before);
            $response = SN_REST::get_messages($probe);
            if (is_wp_error($response)) return $response;
            $data = $response->get_data();
            $rows = is_array($data) && isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];
            if (!$rows) break;

            $eligible = array_values(array_filter($rows, static function($item) use ($viewer): bool {
                $id = is_array($item) ? absint($item['id'] ?? 0) : (is_object($item) ? absint($item->id ?? 0) : 0);
                return $id === 0 || !SN_Message_Operations::is_hidden($viewer, $id);
            }));

            $first_id = self::message_id(reset($rows));
            $last_id = self::message_id(end($rows));
            if ($scan_after > 0) {
                $visible = array_merge($visible, $eligible);
                if ($last_id <= $scan_after) break;
                $scan_after = $last_id;
            } else {
                $visible = array_merge($eligible, $visible);
                if ($first_id <= 0 || ($scan_before > 0 && $first_id >= $scan_before)) break;
                $scan_before = $first_id;
            }

            if (count($rows) < (int) $probe->get_param('limit')) break;
        }

        if (count($visible) > $limit) {
            $visible = $original_after > 0 ? array_slice($visible, 0, $limit) : array_slice($visible, -$limit);
        }
        return rest_ensure_response(['messages' => array_values($visible)]);
    }

    public static function search(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::filter(SN_Message_Search::search($request), 'results');
    }

    public static function context(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $response = SN_Message_Search::context($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        $target = is_array($data) ? absint($data['target_id'] ?? 0) : 0;
        if ($target > 0 && SN_Message_Operations::is_hidden(get_current_user_id(), $target)) {
            return new WP_Error('not_found', 'The requested conversation or message is unavailable.', ['status' => 404]);
        }
        return self::filter($response, 'messages');
    }

    private static function filter(WP_REST_Response|WP_Error $response, string $key): WP_REST_Response|WP_Error {
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) return $response;
        $viewer = get_current_user_id();
        $data[$key] = array_values(array_filter($data[$key], static function($item) use ($viewer): bool {
            $id = is_array($item) ? absint($item['id'] ?? 0) : (is_object($item) ? absint($item->id ?? 0) : 0);
            return $id === 0 || !SN_Message_Operations::is_hidden($viewer, $id);
        }));
        $response->set_data($data);
        return $response;
    }

    private static function message_id($item): int {
        return is_array($item) ? absint($item['id'] ?? 0) : (is_object($item) ? absint($item->id ?? 0) : 0);
    }
}
