<?php
/**
 * Round-6 final transaction fail-closed guard for active Sabri Meet and
 * conference-provider governance mutations.
 *
 * This layer intentionally does not create a parallel data owner. It rebinds
 * only the final REST callbacks and delegates to the canonical owners while a
 * request-scoped wpdb proxy converts an otherwise ignored direct-query failure
 * into an exception. The canonical owner then rolls back its transaction; a
 * failure before its try/catch is converted here to a stable 503 response.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_R6_Transaction_Hardening {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 3200);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/meetings', [
            ['methods'=>'GET','callback'=>[SN_Meet::class,'list_meetings'],'permission_callback'=>[SN_Meet::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'create_meeting'],'permission_callback'=>[SN_Meet::class,'access']],
        ], true);

        foreach ([
            'invite'    => 'invite',
            'join'      => 'join',
            'heartbeat' => 'heartbeat',
            'leave'     => 'leave',
            'moderate'  => 'moderate',
        ] as $suffix => $method) {
            register_rest_route('sabri-network/v2', '/meetings/(?P<meeting>[A-Za-z0-9_-]{22,64})/' . $suffix, [
                'methods'=>'POST',
                'callback'=>static fn(WP_REST_Request $request) => self::guard(static fn() => SN_Meet::{$method}($request), 'meet_' . $method),
                'permission_callback'=>[SN_Meet::class,'access'],
            ], true);
        }

        register_rest_route('sabri-network/v2', '/admin/conference-providers', [
            ['methods'=>'GET','callback'=>[SN_Conference_Provider::class,'list_providers'],'permission_callback'=>[SN_REST::class,'admin_access']],
            ['methods'=>'POST','callback'=>[self::class,'configure_provider'],'permission_callback'=>[SN_REST::class,'admin_access']],
        ], true);
    }

    public static function create_meeting(WP_REST_Request $request): WP_REST_Response|WP_Error {
        // Preserve the later exact-request idempotency owner before the canonical
        // SN_Meet create handler runs under the query-failure guard.
        return self::guard(static fn() => SN_Call_Runtime_Hardening::create_meeting($request), 'meet_create');
    }

    public static function configure_provider(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::guard(static fn() => SN_Conference_Provider::configure_provider($request), 'provider_configuration');
    }

    private static function guard(callable $callback, string $operation): WP_REST_Response|WP_Error {
        global $wpdb;
        $original = $wpdb;
        $wpdb = new SN_R6_WPDB_Guard($original);
        try {
            $result = $callback();
            return $result instanceof WP_REST_Response || is_wp_error($result)
                ? $result
                : rest_ensure_response($result);
        } catch (Throwable $e) {
            // START TRANSACTION sits before several canonical try/catch blocks.
            // Any direct DB failure escaping those owners therefore fails closed
            // here rather than allowing an autocommit mutation to continue.
            if ($operation === 'provider_configuration') {
                SN_DB::audit('conference_provider_transaction_failed','conference_provider',0,'failure',['reason'=>$e->getMessage()],get_current_user_id());
                return new WP_Error('sn_provider_transaction_failed','The provider configuration transaction could not start or complete safely.',['status'=>503]);
            }
            SN_DB::audit('meet_transaction_failed','meeting',0,'failure',['operation'=>$operation,'reason'=>$e->getMessage()],get_current_user_id());
            return new WP_Error('sn_meet_transaction_failed','The meeting transaction could not start or complete safely. Retry the request.',['status'=>503]);
        } finally {
            $wpdb = $original;
        }
    }
}

/**
 * Request-scoped transparent proxy for the canonical WordPress database object.
 * Only direct query() failures are promoted to exceptions. ROLLBACK is allowed
 * to report its own failure without masking the original mutation failure.
 */
final class SN_R6_WPDB_Guard {
    private object $inner;

    public function __construct(object $inner) {
        $this->inner = $inner;
    }

    public function query($query) {
        $result = $this->inner->query($query);
        $sql = ltrim((string)$query);
        if ($result === false && !preg_match('/^ROLLBACK(?:\s|$)/i', $sql)) {
            throw new RuntimeException('sn_r6_direct_query_failed:' . substr(hash('sha256', $sql), 0, 16));
        }
        return $result;
    }

    public function __call(string $name, array $arguments) {
        return $this->inner->{$name}(...$arguments);
    }

    public function &__get(string $name) {
        $value =& $this->inner->{$name};
        return $value;
    }

    public function __set(string $name, mixed $value): void {
        $this->inner->{$name} = $value;
    }

    public function __isset(string $name): bool {
        return isset($this->inner->{$name});
    }
}
