from pathlib import Path

def once(path, old, new, label):
    p=Path(path); s=p.read_text(encoding='utf-8')
    if new in s: return
    if old not in s: raise SystemExit(label+' target mismatch')
    p.write_text(s.replace(old,new,1),encoding='utf-8')

# Search snapshot and context reads.
once('sabri-network/includes/class-sn-message-search.php',
"""        } else {
            $snapshot = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id),0) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));
        }
""",
"""        } else {
            $wpdb->last_error = '';
            $snapshot_raw = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id),0) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));
            if ($wpdb->last_error !== '' || $snapshot_raw === null) return new WP_Error('search_unavailable', 'Message search is temporarily unavailable.', ['status' => 503]);
            $snapshot = (int) $snapshot_raw;
        }
""",'search snapshot')
once('sabri-network/includes/class-sn-message-search.php',
"""        $before = array_reverse($wpdb->get_results($wpdb->prepare("SELECT * FROM $messages WHERE conversation_id=%d AND id<%d AND id<=%d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT)) ?: []);
        $after = $wpdb->get_results($wpdb->prepare("SELECT * FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT)) ?: [];
""",
"""        $wpdb->last_error = '';
        $before_raw = $wpdb->get_results($wpdb->prepare("SELECT * FROM $messages WHERE conversation_id=%d AND id<%d AND id<=%d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT));
        if ($wpdb->last_error !== '' || !is_array($before_raw)) return new WP_Error('search_context_unavailable', 'Message context is temporarily unavailable.', ['status' => 503]);
        $wpdb->last_error = '';
        $after = $wpdb->get_results($wpdb->prepare("SELECT * FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT));
        if ($wpdb->last_error !== '' || !is_array($after)) return new WP_Error('search_context_unavailable', 'Message context is temporarily unavailable.', ['status' => 503]);
        $before = array_reverse($before_raw);
""",'search context reads')

# Organization fail-closed reads/deletes.
once('sabri-network/includes/class-sn-message-operations.php',
"    public static function list_folders(): WP_REST_Response {global $wpdb;$user=get_current_user_id();$rows=$wpdb->get_results($wpdb->prepare('SELECT f.id,f.name,f.slug,f.version,f.created_at,f.updated_at,COUNT(i.id) item_count FROM '.self::folders_table().' f LEFT JOIN '.self::folder_items_table().' i ON i.folder_id=f.id WHERE f.user_id=%d GROUP BY f.id ORDER BY f.name ASC LIMIT %d',$user,self::MAX_FOLDERS));return rest_ensure_response(['items'=>is_array($rows)?$rows:[]]);}\n",
"    public static function list_folders(): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT f.id,f.name,f.slug,f.version,f.created_at,f.updated_at,COUNT(i.id) item_count FROM '.self::folders_table().' f LEFT JOIN '.self::folder_items_table().' i ON i.folder_id=f.id WHERE f.user_id=%d GROUP BY f.id ORDER BY f.name ASC LIMIT %d',$user,self::MAX_FOLDERS));if($wpdb->last_error!==''||!is_array($rows))return self::error('sn_folder_list_unavailable','Message folders are temporarily unavailable.',503);return rest_ensure_response(['items'=>$rows]);}\n",'folder list')
once('sabri-network/includes/class-sn-message-operations.php',
"    public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($count>=self::MAX_FOLDERS)return self::error('sn_folder_limit','The folder limit has been reached.',409);$slug=sanitize_title($name);$now=self::now();$ok=$wpdb->insert(self::folders_table(),['user_id'=>$user,'name'=>$name,'slug'=>$slug,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_folder_conflict','A folder with this name already exists.',409);return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'name'=>$name,'slug'=>$slug,'version'=>1],201);}\n",
"    public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$wpdb->last_error='';$count_raw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($wpdb->last_error!==''||$count_raw===null)return self::error('sn_folder_count_unavailable','The folder limit could not be verified.',503);$count=(int)$count_raw;if($count>=self::MAX_FOLDERS)return self::error('sn_folder_limit','The folder limit has been reached.',409);$slug=sanitize_title($name);$now=self::now();$ok=$wpdb->insert(self::folders_table(),['user_id'=>$user,'name'=>$name,'slug'=>$slug,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_folder_conflict','A folder with this name already exists.',409);return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'name'=>$name,'slug'=>$slug,'version'=>1],201);}\n",'folder count')
once('sabri-network/includes/class-sn-message-operations.php',
"    public static function is_hidden(int $user_id,int $message_id): bool {global $wpdb;return(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::hides_table().' WHERE user_id=%d AND message_id=%d LIMIT 1',$user_id,$message_id));}\n",
"    public static function is_hidden(int $user_id,int $message_id): bool {global $wpdb;$wpdb->last_error='';$hidden=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::hides_table().' WHERE user_id=%d AND message_id=%d LIMIT 1',$user_id,$message_id));if($wpdb->last_error!=='')return true;return(bool)$hidden;}\n",'hidden read')
once('sabri-network/includes/class-sn-message-operations.php',
"        if($action==='unpin'){$wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id]);SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>false]);}\n",
"        if($action==='unpin'){if($wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id])===false)return self::error('sn_unpin_failed','The message could not be unpinned.',500);SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>false]);}\n",'unpin delete')
once('sabri-network/includes/class-sn-message-operations.php',
"        if($action==='unstar'){$wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id]);return rest_ensure_response(['starred'=>false]);}\n",
"        if($action==='unstar'){if($wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id])===false)return self::error('sn_unstar_failed','The message could not be unstarred.',500);return rest_ensure_response(['starred'=>false]);}\n",'unstar delete')
once('sabri-network/includes/class-sn-message-operations.php',
"    public static function change_folder_item(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$folder_id=absint($request['id']);$user=get_current_user_id();$folder=self::folder($folder_id,$user);if(!$folder)return self::error('sn_folder_missing','The folder is unavailable.',404);$conversation=absint($request->get_param('conversation_id'));if(!SN_DB::is_member($conversation,$user))return self::error('sn_folder_conversation_missing','The conversation is unavailable.',404);$action=sanitize_key((string)$request->get_param('action'))?:'add';if($action==='remove'){$wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation]);return rest_ensure_response(['included'=>false]);}$sql=$wpdb->prepare('INSERT IGNORE INTO '.self::folder_items_table().' (folder_id,user_id,conversation_id,created_at) VALUES (%d,%d,%d,%s)',$folder_id,$user,$conversation,self::now());if($wpdb->query($sql)===false)return self::error('sn_folder_item_failed','The conversation could not be added to the folder.',500);return rest_ensure_response(['included'=>true]);}\n",
"    public static function change_folder_item(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$folder_id=absint($request['id']);$user=get_current_user_id();$folder=self::folder($folder_id,$user);if(!$folder)return self::error('sn_folder_missing','The folder is unavailable.',404);$conversation=absint($request->get_param('conversation_id'));if(!SN_DB::is_member($conversation,$user))return self::error('sn_folder_conversation_missing','The conversation is unavailable.',404);$action=sanitize_key((string)$request->get_param('action'))?:'add';if($action==='remove'){if($wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation])===false)return self::error('sn_folder_item_failed','The conversation could not be removed from the folder.',500);return rest_ensure_response(['included'=>false]);}$sql=$wpdb->prepare('INSERT IGNORE INTO '.self::folder_items_table().' (folder_id,user_id,conversation_id,created_at) VALUES (%d,%d,%d,%s)',$folder_id,$user,$conversation,self::now());if($wpdb->query($sql)===false)return self::error('sn_folder_item_failed','The conversation could not be added to the folder.',500);return rest_ensure_response(['included'=>true]);}\n",'folder item remove')

# Extend cumulative regression.
t=Path('sabri-network/tests/next20-contracts.php'); s=t.read_text(encoding='utf-8')
if "$search=$read('includes/class-sn-message-search.php');" not in s:
    s=s.replace("$integrity=$read('includes/class-sn-message-integrity.php');", "$search=$read('includes/class-sn-message-search.php');$ops=$read('includes/class-sn-message-operations.php');$integrity=$read('includes/class-sn-message-integrity.php');",1)
marker='if($fail){fwrite(STDERR,'
checks="""$check(str_contains($ops,'if($wpdb->last_error!==\'\')return true'),'R4 hidden ledger fails closed on database error');
$check(str_contains($search,'$snapshot_raw')&&str_contains($search,"status' => 503"),'R4 search snapshot read is error-aware');
$check(str_contains($search,'$before_raw')&&str_contains($search,'search_context_unavailable'),'R4 context neighbor reads fail closed');
$check(str_contains($ops,'sn_folder_list_unavailable')&&str_contains($ops,'sn_folder_count_unavailable'),'R4 folder list and limit reads fail closed');
$check(str_contains($ops,'sn_unpin_failed')&&str_contains($ops,'sn_unstar_failed')&&str_contains($ops,'The conversation could not be removed from the folder.'),'R4 destructive organization removals check database results');
"""
if 'R4 hidden ledger fails closed' not in s:
    idx=s.find(marker)
    if idx<0: raise SystemExit('R4 test insertion marker missing')
    s=s[:idx]+checks+s[idx:]
t.write_text(s,encoding='utf-8')
