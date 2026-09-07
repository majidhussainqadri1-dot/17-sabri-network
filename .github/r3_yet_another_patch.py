from pathlib import Path

p=Path('sabri-network/includes/class-sn-message-operations.php')
s=p.read_text(encoding='utf-8')
old="""    public static function change_pin(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor))return self::not_found();
        $role=SN_DB::member_role((int)$message->conversation_id,$actor);if(!in_array($role,['owner','moderator'],true)&&!current_user_can('manage_options'))return self::error('sn_pin_role_required','A conversation management role is required.',403);
        $action=sanitize_key((string)$request->get_param('action'))?:'pin';$now=self::now();
        if($action==='unpin'){$wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id]);SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>false]);}
        if($message->deleted_at)return self::error('sn_pin_message_deleted','Deleted messages cannot be pinned.',409);
        $sql=$wpdb->prepare('INSERT INTO '.self::pins_table().' (conversation_id,message_id,pinned_by,created_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE pinned_by=VALUES(pinned_by)',(int)$message->conversation_id,$id,$actor,$now);
        if($wpdb->query($sql)===false)return self::error('sn_pin_failed','The message could not be pinned.',500);SN_DB::audit('message_pinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>true]);
    }

    public static function change_star(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$user))return self::not_found();$action=sanitize_key((string)$request->get_param('action'))?:'star';
        if($action==='unstar'){$wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id]);return rest_ensure_response(['starred'=>false]);}
        if($message->deleted_at)return self::error('sn_star_message_deleted','Deleted messages cannot be starred.',409);
        $sql=$wpdb->prepare('INSERT IGNORE INTO '.self::stars_table().' (user_id,message_id,created_at) VALUES (%d,%d,%s)',$user,$id,self::now());if($wpdb->query($sql)===false)return self::error('sn_star_failed','The message could not be starred.',500);return rest_ensure_response(['starred'=>true]);
    }
"""
new="""    public static function change_pin(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id=absint($request['id']);$actor=get_current_user_id();$action=sanitize_key((string)$request->get_param('action'))?:'pin';
        if($wpdb->query('START TRANSACTION')===false)return self::error('sn_pin_transaction_failed','The pin change could not start safely.',500);
        try{
            $message=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d FOR UPDATE',$id));
            if(!$message)throw new DomainException('not_found');
            $member=$wpdb->get_row($wpdb->prepare('SELECT id,role FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',(int)$message->conversation_id,$actor));
            if(!$member)throw new DomainException('not_found');
            if(!in_array((string)$member->role,['owner','moderator'],true)&&!user_can($actor,'manage_options'))throw new UnexpectedValueException('role');
            if($action==='unpin'){
                $deleted=$wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id]);
                if($deleted===false)throw new RuntimeException('pin_delete_failed');
                SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('pin_commit_failed');
                return rest_ensure_response(['pinned'=>false]);
            }
            if($message->deleted_at){$wpdb->query('ROLLBACK');return self::error('sn_pin_message_deleted','Deleted messages cannot be pinned.',409);}
            $sql=$wpdb->prepare('INSERT INTO '.self::pins_table().' (conversation_id,message_id,pinned_by,created_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE pinned_by=VALUES(pinned_by)',(int)$message->conversation_id,$id,$actor,self::now());
            if($wpdb->query($sql)===false)throw new RuntimeException('pin_write_failed');
            SN_DB::audit('message_pinned','message',$id,'success',[],$actor);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('pin_commit_failed');
            return rest_ensure_response(['pinned'=>true]);
        }catch(DomainException $e){$wpdb->query('ROLLBACK');return self::not_found();}
        catch(UnexpectedValueException $e){$wpdb->query('ROLLBACK');return self::error('sn_pin_role_required','A conversation management role is required.',403);}
        catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_pin_failed','The pin change could not be committed safely.',500);}
    }

    public static function change_star(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$user))return self::not_found();$action=sanitize_key((string)$request->get_param('action'))?:'star';
        if($action==='unstar'){if($wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id])===false)return self::error('sn_star_failed','The message star could not be removed safely.',500);return rest_ensure_response(['starred'=>false]);}
        if($message->deleted_at)return self::error('sn_star_message_deleted','Deleted messages cannot be starred.',409);
        $sql=$wpdb->prepare('INSERT IGNORE INTO '.self::stars_table().' (user_id,message_id,created_at) VALUES (%d,%d,%s)',$user,$id,self::now());if($wpdb->query($sql)===false)return self::error('sn_star_failed','The message could not be starred.',500);return rest_ensure_response(['starred'=>true]);
    }
"""
if old not in s: raise SystemExit('R3 pin/star target missing')
s=s.replace(old,new,1)
old="""    public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($count>=self::MAX_FOLDERS)return self::error('sn_folder_limit','The folder limit has been reached.',409);$slug=sanitize_title($name);$now=self::now();$ok=$wpdb->insert(self::folders_table(),['user_id'=>$user,'name'=>$name,'slug'=>$slug,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_folder_conflict','A folder with this name already exists.',409);return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'name'=>$name,'slug'=>$slug,'version'=>1],201);}
"""
new="""    public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$lock='sn:f17:message-folder-create:'.$user;$held=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,5));if($held!==1)return self::error('sn_folder_busy','Another folder change is being committed. Retry the request.',409);try{$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($wpdb->last_error!==''||$raw===null)return self::error('sn_folder_state_unavailable','The folder limit could not be verified safely.',503);$count=(int)$raw;if($count>=self::MAX_FOLDERS)return self::error('sn_folder_limit','The folder limit has been reached.',409);$slug=sanitize_title($name);$now=self::now();$ok=$wpdb->insert(self::folders_table(),['user_id'=>$user,'name'=>$name,'slug'=>$slug,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_folder_conflict','A folder with this name already exists.',409);return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'name'=>$name,'slug'=>$slug,'version'=>1],201);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}
"""
if old not in s: raise SystemExit('R3 folder-create target missing')
s=s.replace(old,new,1)
old="""    public static function change_folder_item(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$folder_id=absint($request['id']);$user=get_current_user_id();$folder=self::folder($folder_id,$user);if(!$folder)return self::error('sn_folder_missing','The folder is unavailable.',404);$conversation=absint($request->get_param('conversation_id'));if(!SN_DB::is_member($conversation,$user))return self::error('sn_folder_conversation_missing','The conversation is unavailable.',404);$action=sanitize_key((string)$request->get_param('action'))?:'add';if($action==='remove'){$wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation]);return rest_ensure_response(['included'=>false]);}$sql=$wpdb->prepare('INSERT IGNORE INTO '.self::folder_items_table().' (folder_id,user_id,conversation_id,created_at) VALUES (%d,%d,%d,%s)',$folder_id,$user,$conversation,self::now());if($wpdb->query($sql)===false)return self::error('sn_folder_item_failed','The conversation could not be added to the folder.',500);return rest_ensure_response(['included'=>true]);}
"""
new="""    public static function change_folder_item(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$folder_id=absint($request['id']);$user=get_current_user_id();$folder=self::folder($folder_id,$user);if(!$folder)return self::error('sn_folder_missing','The folder is unavailable.',404);$conversation=absint($request->get_param('conversation_id'));if(!SN_DB::is_member($conversation,$user))return self::error('sn_folder_conversation_missing','The conversation is unavailable.',404);$action=sanitize_key((string)$request->get_param('action'))?:'add';if($action==='remove'){if($wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation])===false)return self::error('sn_folder_item_failed','The conversation could not be removed from the folder safely.',500);return rest_ensure_response(['included'=>false]);}$sql=$wpdb->prepare('INSERT IGNORE INTO '.self::folder_items_table().' (folder_id,user_id,conversation_id,created_at) VALUES (%d,%d,%d,%s)',$folder_id,$user,$conversation,self::now());if($wpdb->query($sql)===false)return self::error('sn_folder_item_failed','The conversation could not be added to the folder.',500);return rest_ensure_response(['included'=>true]);}
"""
if old not in s: raise SystemExit('R3 folder-item target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Permanent regression in existing inventory-owned suite.
p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
anchor="$highRisk=$read('includes/class-sn-high-risk.php');\n"
if "$messageOps=$read('includes/class-sn-message-operations.php');" not in s:
    if anchor not in s: raise SystemExit('R3 regression variable anchor missing')
    s=s.replace(anchor,anchor+"$messageOps=$read('includes/class-sn-message-operations.php');\n",1)
marker='// Yet-another R3 message-organization regressions.'
if marker not in s:
    checks="""
// Yet-another R3 message-organization regressions.
$check(str_contains($messageOps,"sn_pin_transaction_failed")&&str_contains($messageOps,"SELECT id,role FROM "."'.SN_DB::table('members').'" )===false?false:true,'Yet R3: pin mutation must enter a checked transaction and lock current member authority.');
$check(str_contains($messageOps,"pin_delete_failed")&&str_contains($messageOps,"pin_commit_failed"),'Yet R3: pin removal and commit failures must not be reported as success.');
$check(str_contains($messageOps,"The message star could not be removed safely."),'Yet R3: unstar must preserve database failure truth.');
$check(str_contains($messageOps,"sn:f17:message-folder-create:")&&str_contains($messageOps,"sn_folder_state_unavailable")&&str_contains($messageOps,"SELECT RELEASE_LOCK"),'Yet R3: folder aggregate limit must be serialized and fail closed when count truth is unavailable.');
$check(str_contains($messageOps,"The conversation could not be removed from the folder safely."),'Yet R3: folder-item removal must not return success after failed SQL.');
"""
    # simplify first brittle expression into robust tokens after insertion
    checks=checks.replace("str_contains($messageOps,\"SELECT id,role FROM \".\"'.SN_DB::table('members').'\" )===false?false:true", "str_contains($messageOps,'SELECT id,role FROM ')")
    tail=s.rfind('if($fail){')
    if tail<0: raise SystemExit('R3 regression tail missing')
    s=s[:tail]+checks+s[tail:]
p.write_text(s,encoding='utf-8')
