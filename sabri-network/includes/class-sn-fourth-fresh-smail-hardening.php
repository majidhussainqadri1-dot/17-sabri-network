<?php
/** Fourth fresh cycle: Smail retry safety and exact-version draft lifecycle. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Smail_Hardening {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2240);
    }

    public static function override_routes(): void {
        $a = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/smail/send', [
            'methods'=>'POST','callback'=>[self::class,'send'],'permission_callback'=>$a,
        ], true);
        register_rest_route('sabri-network/v2', '/smail/drafts', [
            ['methods'=>'GET','callback'=>[SN_Smail::class,'list_drafts'],'permission_callback'=>$a],
            ['methods'=>'POST','callback'=>[self::class,'save_draft'],'permission_callback'=>$a],
        ], true);
        register_rest_route('sabri-network/v2', '/smail/drafts/(?P<public_id>[a-f0-9-]{36})', [
            ['methods'=>'GET','callback'=>[SN_Smail::class,'get_draft'],'permission_callback'=>$a],
            ['methods'=>'POST','callback'=>[self::class,'save_draft'],'permission_callback'=>$a],
            ['methods'=>'DELETE','callback'=>[self::class,'delete_draft'],'permission_callback'=>$a],
        ], true);
    }

    public static function send(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $client = strtolower(trim((string) $request->get_param('client_id')));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client)) {
            return new WP_Error('invalid_client_id', 'A caller-supplied Smail idempotency key is required.', ['status'=>400]);
        }
        return SN_Smail::send($request);
    }

    public static function save_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $owner = get_current_user_id();
        $public = sanitize_text_field((string) ($request['public_id'] ?: $request->get_param('id')));
        $expected = absint($request->get_param('version'));
        if ($public !== '') {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id,version FROM ' . SN_DB::table('smail_drafts') . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',
                $public,
                $owner
            ));
            if (!$row) return self::not_found();
            if ($expected <= 0) return new WP_Error('draft_version_required', 'Refresh the draft and provide its exact version.', ['status'=>400]);
            if ($expected !== (int) $row->version) return self::conflict();
        } elseif ($expected !== 0) {
            return self::conflict();
        }
        return SN_Smail::save_draft($request);
    }

    public static function delete_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $owner = get_current_user_id();
        $public = sanitize_text_field((string) $request['public_id']);
        $expected = absint($request->get_param('version'));
        if ($expected <= 0) return new WP_Error('draft_version_required', 'Refresh the draft and provide its exact version.', ['status'=>400]);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,version FROM ' . SN_DB::table('smail_drafts') . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',
            $public,
            $owner
        ));
        if (!$row) return self::not_found();
        if ((int) $row->version !== $expected) return self::conflict();
        $now = current_time('mysql', true);
        $changed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SN_DB::table('smail_drafts') . ' SET deleted_at=%s,encrypted_payload=%s,payload_hash=%s,version=version+1,updated_at=%s WHERE id=%d AND version=%d AND deleted_at IS NULL',
            $now,
            '',
            hash_hmac('sha256', '', wp_salt('auth') . '|sn-sm-draft-blind-v1'),
            $now,
            (int) $row->id,
            $expected
        ));
        if ($changed !== 1) return self::conflict();
        SN_DB::audit('smail_draft_deleted', 'smail_draft', (int) $row->id, 'success', ['version'=>$expected+1], $owner);
        return rest_ensure_response(['deleted'=>true,'version'=>$expected+1]);
    }

    private static function conflict(): WP_Error {
        return new WP_Error('draft_conflict', 'The Smail draft changed on another device. Reload and retry.', ['status'=>409]);
    }
    private static function not_found(): WP_Error {
        return new WP_Error('draft_not_found', 'The Smail draft is unavailable.', ['status'=>404]);
    }
}
