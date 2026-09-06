<?php
/**
 * Fresh-20 Round 7: fail-closed database-read truth for final message mutations.
 *
 * The canonical message owners remain the data/mutation authorities. This layer
 * only rebinds their final REST callbacks under a request-scoped wpdb proxy that
 * promotes authoritative SQL read failures to a stable retryable WP_Error instead
 * of allowing them to be mistaken for not-found/idempotency-conflict state.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fresh20_R7_Message_Read_Hardening {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 4200);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/messages', [
            ['methods'=>'GET','callback'=>[SN_Fourth_Fresh_Review_Hardening::class,'get_messages'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'send_message'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)', [
            ['methods'=>'POST','callback'=>[self::class,'edit_message'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'DELETE','callback'=>[self::class,'delete_message'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)/forward', [
            'methods'=>'POST','callback'=>[self::class,'forward_message'],'permission_callback'=>[SN_REST::class,'access'],
        ], true);
        register_rest_route('sabri-network/v2', '/conversations/(?P<id>\d+)/receipts', [
            ['methods'=>'GET','callback'=>[SN_Messages::class,'get_receipts'],'permission_callback'=>[SN_REST::class,'access']],
            ['methods'=>'POST','callback'=>[self::class,'record_receipt'],'permission_callback'=>[SN_REST::class,'access']],
        ], true);
    }

    public static function send_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::guard(static fn() => SN_Fourth_Fresh_Review_Hardening::send_message($request), 'send');
    }
    public static function edit_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::guard(static fn() => SN_Fourth_Fresh_Review_Hardening::edit_message($request), 'edit');
    }
    public static function delete_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::guard(static fn() => SN_Fourth_Fresh_Review_Hardening::delete_message($request), 'delete');
    }
    public static function forward_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::guard(static fn() => SN_Fourth_Fresh_Review_Hardening::forward_message($request), 'forward');
    }
    public static function record_receipt(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return self::guard(static fn() => SN_Fourth_Fresh_Review_Hardening::record_receipt($request), 'receipt');
    }

    private static function guard(callable $callback, string $operation): WP_REST_Response|WP_Error {
        global $wpdb;
        $original = $wpdb;
        $wpdb = new SN_Fresh20_R7_WPDB_Read_Guard($original);
        try {
            $result = $callback();
            return $result instanceof WP_REST_Response || is_wp_error($result) ? $result : rest_ensure_response($result);
        } catch (SN_Fresh20_R7_DB_Read_Exception $e) {
            SN_DB::audit('message_database_read_failed','message',0,'failure',['operation'=>$operation,'read'=>$e->read_method],get_current_user_id());
            return new WP_Error('message_database_read_failed','Canonical message state could not be read safely. Retry the request.',['status'=>503]);
        } finally {
            $wpdb = $original;
        }
    }
}

final class SN_Fresh20_R7_DB_Read_Exception extends RuntimeException {
    public function __construct(public readonly string $read_method) {
        parent::__construct('message_database_read_failed:' . $read_method);
    }
}

final class SN_Fresh20_R7_WPDB_Read_Guard {
    private const READ_METHODS = ['get_row','get_var','get_col','get_results'];
    public function __construct(private object $inner) {}

    public function __call(string $name, array $arguments) {
        $read = in_array($name, self::READ_METHODS, true);
        if ($read) $this->inner->last_error = '';
        $result = $this->inner->{$name}(...$arguments);
        if ($read && (string)$this->inner->last_error !== '') {
            throw new SN_Fresh20_R7_DB_Read_Exception($name);
        }
        return $result;
    }
    public function &__get(string $name) { $value =& $this->inner->{$name}; return $value; }
    public function __set(string $name, mixed $value): void { $this->inner->{$name} = $value; }
    public function __isset(string $name): bool { return isset($this->inner->{$name}); }
}
