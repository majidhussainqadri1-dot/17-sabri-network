#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

def replace(rel, old, new, count=1):
    p=root/rel; t=p.read_text(encoding='utf-8'); n=t.count(old)
    if n < count: raise SystemExit(f'{rel}: expected >= {count}, found {n}: {old[:120]!r}')
    p.write_text(t.replace(old,new,count),encoding='utf-8')

# 1) Central shared lock boundary for message metadata and private folder mutations.
p='includes/class-sn-runtime-boundary-policy.php'
replace(p,
"        add_filter('rest_pre_dispatch', [self::class, 'final_identity_gate'], PHP_INT_MAX, 3);\n",
"        add_filter('rest_pre_dispatch', [self::class, 'lock_message_metadata_mutation'], 2200, 3);\n        add_filter('rest_post_dispatch', [self::class, 'release_message_metadata_mutation'], 2200, 3);\n        add_filter('rest_pre_dispatch', [self::class, 'final_identity_gate'], PHP_INT_MAX, 3);\n")
replace(p,
"    /** Final action-time identity check after all File-17 pre-dispatch locks and caches. */\n",
"""    /** Serialize message metadata with conversation membership and user-folder state. */
    public static function lock_message_metadata_mutation($result, WP_REST_Server $server, WP_REST_Request $request) {
        if ($result !== null) return $result;
        $method = strtoupper($request->get_method());
        if (in_array($method, ['GET','HEAD','OPTIONS'], true)) return $result;
        $route = $request->get_route();
        if (!str_starts_with($route, '/sabri-network/v2/')) return $result;
        global $wpdb;
        $locks = []; $conversation = 0; $user = get_current_user_id();
        if (preg_match('#^/sabri-network/v2/conversations/(\\d+)/read$#', $route, $m)) {
            $conversation = (int)$m[1];
        } elseif (preg_match('#^/sabri-network/v2/messages/(\\d+)/(?:reaction|mentions|pin|star|hide)$#', $route, $m)) {
            $conversation = (int)$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM '.SN_DB::table('messages').' WHERE id=%d', (int)$m[1]));
            if ($wpdb->last_error !== '') return new WP_Error('sn_message_metadata_lookup_failed','The message scope could not be verified safely.',['status'=>503]);
        } elseif (str_starts_with($route, '/sabri-network/v2/message-folders')) {
            if ($user > 0) $locks[] = 'sn:f17:message-folders:' . substr(hash('sha256', (string)$user), 0, 32);
            if (str_ends_with($route, '/conversations')) $conversation = absint($request->get_param('conversation_id'));
        } else {
            return $result;
        }
        if ($conversation > 0) $locks[] = 'sn:f17:conversation:' . substr(hash('sha256', (string)$conversation), 0, 32);
        $locks = array_values(array_unique(array_filter($locks))); sort($locks, SORT_STRING); $held=[];
        foreach ($locks as $lock) {
            $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, 5));
            if ($ok!==1) { foreach (array_reverse($held) as $h) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $h)); return new WP_Error('sn_message_metadata_busy','Message organization is changing. Retry the request.',['status'=>409]); }
            $held[]=$lock;
        }
        $request->set_param('_sn_message_metadata_locks',$held);
        return $result;
    }

    public static function release_message_metadata_mutation($response, WP_REST_Server $server, WP_REST_Request $request) {
        $held=$request->get_param('_sn_message_metadata_locks');
        if (is_array($held) && $held) { global $wpdb; foreach (array_reverse($held) as $lock) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string)$lock)); $request->set_param('_sn_message_metadata_locks',[]); }
        return $response;
    }

    /** Final action-time identity check after all File-17 pre-dispatch locks and caches. */
""")

# 2) Message-organization mutation truth.
p='includes/class-sn-message-operations.php'
replace(p,
"        $action=sanitize_key((string)$request->get_param('action'))?:'pin';$now=self::now();\n        if($action==='unpin'){$wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id]);SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>false]);}\n",
"        $action=sanitize_key((string)$request->get_param('action'));if($action==='')$action='pin';if(!in_array($action,['pin','unpin'],true))return self::error('sn_pin_action_invalid','Select pin or unpin.',400);$now=self::now();\n        if($action==='unpin'){$deleted=$wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id]);if($deleted===false)return self::error('sn_unpin_failed','The message could not be unpinned.',500);SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>false]);}\n")
replace(p,
"        global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$user))return self::not_found();$action=sanitize_key((string)$request->get_param('action'))?:'star';\n        if($action==='unstar'){$wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id]);return rest_ensure_response(['starred'=>false]);}\n",
"        global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$user))return self::not_found();$action=sanitize_key((string)$request->get_param('action'));if($action==='')$action='star';if(!in_array($action,['star','unstar'],true))return self::error('sn_star_action_invalid','Select star or unstar.',400);\n        if($action==='unstar'){$deleted=$wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id]);if($deleted===false)return self::error('sn_unstar_failed','The message could not be unstarred.',500);return rest_ensure_response(['starred'=>false]);}\n")
replace(p,
"    public static function list_folders(): WP_REST_Response {global $wpdb;$user=get_current_user_id();$rows=$wpdb->get_results($wpdb->prepare('SELECT f.id,f.name,f.slug,f.version,f.created_at,f.updated_at,COUNT(i.id) item_count FROM '.self::folders_table().' f LEFT JOIN '.self::folder_items_table().' i ON i.folder_id=f.id WHERE f.user_id=%d GROUP BY f.id ORDER BY f.name ASC LIMIT %d',$user,self::MAX_FOLDERS));return rest_ensure_response(['items'=>is_array($rows)?$rows:[]]);}\n",
"    public static function list_folders(): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$rows=$wpdb->get_results($wpdb->prepare('SELECT f.id,f.name,f.slug,f.version,f.created_at,f.updated_at,COUNT(i.id) item_count FROM '.self::folders_table().' f LEFT JOIN '.self::folder_items_table().' i ON i.folder_id=f.id WHERE f.user_id=%d GROUP BY f.id ORDER BY f.name ASC LIMIT %d',$user,self::MAX_FOLDERS));if(!is_array($rows)||$wpdb->last_error!=='')return self::error('sn_folder_list_failed','Folders could not be read safely.',500);return rest_ensure_response(['items'=>$rows]);}\n")
replace(p,
"public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($count>=self::MAX_FOLDERS)",
"public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($wpdb->last_error!=='')return self::error('sn_folder_count_failed','Folder capacity could not be verified safely.',500);if($count>=self::MAX_FOLDERS)")
replace(p,
"    public static function delete_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$row=self::folder($id,$user);if(!$row)return self::error('sn_folder_missing','The folder is unavailable.',404);try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');",
"    public static function delete_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$row=self::folder($id,$user);if(!$row)return self::error('sn_folder_missing','The folder is unavailable.',404);$expected=absint($request->get_param('version'));if($expected<=0)return self::error('sn_folder_version_required','An exact folder version is required.',400);if($expected!==(int)$row->version)return self::error('sn_folder_version_conflict','The folder changed. Reload and retry.',409);try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');$locked=$wpdb->get_row($wpdb->prepare('SELECT id,version FROM '.self::folders_table().' WHERE id=%d AND user_id=%d FOR UPDATE',$id,$user));if(!$locked||(int)$locked->version!==$expected)throw new UnexpectedValueException('folder_version_conflict');")
replace(p,
"}catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_folder_delete_failed','The folder could not be deleted.',500);}}\n",
"}catch(Throwable $e){$wpdb->query('ROLLBACK');if($e instanceof UnexpectedValueException&&$e->getMessage()==='folder_version_conflict')return self::error('sn_folder_version_conflict','The folder changed. Reload and retry.',409);return self::error('sn_folder_delete_failed','The folder could not be deleted.',500);}}\n",1)
replace(p,
"$action=sanitize_key((string)$request->get_param('action'))?:'add';if($action==='remove'){$wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation]);return rest_ensure_response(['included'=>false]);}",
"$action=sanitize_key((string)$request->get_param('action'));if($action==='')$action='add';if(!in_array($action,['add','remove'],true))return self::error('sn_folder_item_action_invalid','Select add or remove.',400);if($action==='remove'){$deleted=$wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation]);if($deleted===false)return self::error('sn_folder_item_remove_failed','The conversation could not be removed from the folder.',500);return rest_ensure_response(['included'=>false]);}")

# 3) Core read/reaction input and DB truth; shared pre-dispatch lock protects membership.
p='includes/class-sn-rest.php'
replace(p,
"            $message_id = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));\n",
"            $message_id = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));\n            if ($wpdb->last_error !== '') return new WP_Error('message_read_lookup_failed', 'The latest message could not be verified safely.', ['status' => 500]);\n")
replace(p,
"        $reaction = SN_Policy::sanitize_reaction((string) $request->get_param('reaction'));\n",
"        $raw_reaction = trim((string) $request->get_param('reaction'));\n        $reaction = SN_Policy::sanitize_reaction($raw_reaction);\n        if ($raw_reaction !== '' && $reaction === '') return new WP_Error('invalid_reaction', 'Select a supported reaction or send an empty value to remove it.', ['status' => 400]);\n")

# Permanent R9 contracts.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor="\nif ($fail) {\n"
if anchor not in t: raise SystemExit('ninth suite anchor missing')
block=r'''
// Round 9 — message organization/read/reaction mutations are serialized and fail closed.
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$ops = $read('includes/class-sn-message-operations.php');
$rest = $read('includes/class-sn-rest.php');
$check(str_contains($boundary, "lock_message_metadata_mutation'], 2200") && str_contains($boundary, "release_message_metadata_mutation'], 2200") && str_contains($boundary, "sn:f17:conversation:"), 'Round 9: message metadata mutations must hold the same conversation lock used by membership changes.');
foreach (['reaction|mentions|pin|star|hide','message-folders','/read$'] as $needle) $check(str_contains($boundary,$needle), 'Round 9: central metadata lock routing lost coverage for '.$needle.'.');
$check(str_contains($ops,"sn_pin_action_invalid") && str_contains($ops,"sn_unpin_failed") && str_contains($ops,"sn_star_action_invalid") && str_contains($ops,"sn_unstar_failed"), 'Round 9: pin/star actions must reject unknown verbs and surface delete failures.');
$check(str_contains($ops,"sn_folder_item_action_invalid") && str_contains($ops,"sn_folder_item_remove_failed"), 'Round 9: folder-item actions must reject unknown verbs and surface removal failures.');
$check(str_contains($ops,"sn_folder_count_failed") && str_contains($ops,"sn_folder_version_required") && str_contains($ops,"FOR UPDATE") && str_contains($ops,"folder_version_conflict"), 'Round 9: folder capacity and deletion must use fail-closed count truth plus exact-version CAS.');
$check(str_contains($rest,"$raw_reaction = trim") && str_contains($rest,"invalid_reaction") && str_contains($rest,"message_read_lookup_failed"), 'Round 9: invalid reactions must not become removals and latest-read lookup failures must not become zero pointers.');
'''
p.write_text(t.replace(anchor,"\n"+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-runtime-boundary-policy.php
php -l sabri-network/includes/class-sn-message-operations.php
php -l sabri-network/includes/class-sn-rest.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
