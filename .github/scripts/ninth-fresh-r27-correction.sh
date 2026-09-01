#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-smail-runtime-hardening.php'; t=p.read_text(encoding='utf-8')
old="""return self::with_locks(['sn:f17:smail-state:'.$user.':'.$id],function()use($request,$id,$user){global $wpdb;$table=SN_DB::table('smail_states');$row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE smail_message_id=%d AND user_id=%d\",$id,$user));if(!$row)return new WP_Error('smail_not_found','The Smail item is unavailable.',['status'=>404]);"""
new="""return self::with_locks(['sn:f17:smail-state:'.$user.':'.$id],function()use($request,$id,$user){global $wpdb;$table=SN_DB::table('smail_states');$row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE smail_message_id=%d AND user_id=%d\",$id,$user));if($wpdb->last_error!=='')return new WP_Error('smail_state_unavailable','The Smail state could not be read safely.',['status'=>503]);if(!$row)return new WP_Error('smail_not_found','The Smail item is unavailable.',['status'=>404]);"""
if old not in t: raise SystemExit('R27 state anchor missing')
t=t.replace(old,new,1)
old="""return self::with_locks([$lock],function()use($request,$owner,$public,$wpdb){if($public!==''){$row=$wpdb->get_row($wpdb->prepare('SELECT version FROM '.SN_DB::table('smail_drafts').' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',$public,$owner));if(!$row)return new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]);"""
new="""return self::with_locks([$lock],function()use($request,$owner,$public,$wpdb){if($public!==''){$row=$wpdb->get_row($wpdb->prepare('SELECT version FROM '.SN_DB::table('smail_drafts').' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',$public,$owner));if($wpdb->last_error!=='')return new WP_Error('draft_state_unavailable','The Smail draft state could not be read safely.',['status'=>503]);if(!$row)return new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]);"""
if old not in t: raise SystemExit('R27 draft update anchor missing')
t=t.replace(old,new,1)
old="""        $owner=get_current_user_id();$public=sanitize_text_field((string)$request['public_id']);return self::with_locks(['sn:f17:smail-draft:'.$owner.':'.$public],static fn()=>self::trash_draft($public,$owner)?rest_ensure_response(['deleted'=>true]):new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]));"""
new="""        $owner=get_current_user_id();$public=sanitize_text_field((string)$request['public_id']);return self::with_locks(['sn:f17:smail-draft:'.$owner.':'.$public],static function()use($public,$owner){global $wpdb;$deleted=self::trash_draft($public,$owner);if($deleted)return rest_ensure_response(['deleted'=>true]);if($wpdb->last_error!=='')return new WP_Error('draft_delete_unavailable','The Smail draft delete state could not be verified safely.',['status'=>503]);return new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]);});"""
if old not in t: raise SystemExit('R27 draft delete anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

p=root/'includes/class-sn-smail-part-2.php'; t=p.read_text(encoding='utf-8')
old="""    public static function list_drafts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT public_id,version,created_at,updated_at FROM ' . self::drafts_table() . ' WHERE owner_id=%d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT %d', get_current_user_id(), self::MAX_DRAFTS));
        return rest_ensure_response(['drafts' => array_map(static fn($r): array => ['id' => (string) $r->public_id, 'version' => (int) $r->version, 'created_at' => (string) $r->created_at, 'updated_at' => (string) $r->updated_at], $rows ?: [])]);
    }"""
new="""    public static function list_drafts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT public_id,version,created_at,updated_at FROM ' . self::drafts_table() . ' WHERE owner_id=%d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT %d', get_current_user_id(), self::MAX_DRAFTS));
        if ($wpdb->last_error !== '') {
            return new WP_Error('smail_drafts_unavailable', 'The Smail draft list could not be read safely.', ['status' => 503]);
        }
        return rest_ensure_response(['drafts' => array_map(static fn($r): array => ['id' => (string) $r->public_id, 'version' => (int) $r->version, 'created_at' => (string) $r->created_at, 'updated_at' => (string) $r->updated_at], $rows ?: [])]);
    }"""
if old not in t: raise SystemExit('R27 draft list anchor missing')
t=t.replace(old,new,1); p.write_text(t,encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t or '// Round 27 —' in t: raise SystemExit('R27 suite anchor problem')
block=r'''

// Round 27 — Smail state/draft reads distinguish database failure from absence.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');$smail2=$read('includes/class-sn-smail-part-2.php');
$check(str_contains($smailRuntime,'smail_state_unavailable') && str_contains($smailRuntime,'draft_state_unavailable') && str_contains($smailRuntime,'draft_delete_unavailable') && substr_count($smailRuntime,'$wpdb->last_error')>=7, 'Round 27: Smail state and draft mutations must not convert DB uncertainty into not-found.');
$check(str_contains($smail2,'WP_REST_Response|WP_Error') && str_contains($smail2,'smail_drafts_unavailable') && str_contains($smail2,'$wpdb->last_error'), 'Round 27: draft-list DB failure must not become a legitimate empty list.');
'''
p.write_text(t.replace(anchor,block+anchor,1),encoding='utf-8')
PY
