<?php
/** Final route overlay for hidden-message privacy and space posting reservations. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Message_Visibility {
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
        return self::filter(SN_REST::get_messages($request), 'messages');
    }

    public static function search(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::filter(SN_Message_Search::search($request), 'results');
    }

    public static function context(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::filter(SN_Message_Search::context($request), 'messages');
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
}
