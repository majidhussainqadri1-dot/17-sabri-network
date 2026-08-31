<?php
/** Governed mentions, forwarding, pins, stars, folders and private hides. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Message_Operations {
    private const SCHEMA_VERSION = '1.0.0';
    private const MAX_MENTIONS = 20;
    private const MAX_FOLDERS = 50;
    private const MAX_FORWARD_BODY = 10000;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE ".self::mentions_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            mentioned_user_id BIGINT UNSIGNED NOT NULL,
            mentioned_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_user (message_id,mentioned_user_id),
            KEY user_conversation (mentioned_user_id,conversation_id,message_id)
        ) $charset;");
        dbDelta("CREATE TABLE ".self::pins_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            pinned_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY conversation_message (conversation_id,message_id),
            KEY conversation_created (conversation_id,created_at)
        ) $charset;");
        dbDelta("CREATE TABLE ".self::stars_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_message (user_id,message_id),
            KEY user_created (user_id,created_at)
        ) $charset;");
        dbDelta("CREATE TABLE ".self::folders_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            slug VARCHAR(80) NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_slug (user_id,slug),
            KEY user_updated (user_id,updated_at)
        ) $charset;");
        dbDelta("CREATE TABLE ".self::folder_items_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            folder_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY folder_conversation (folder_id,conversation_id),
            KEY user_conversation (user_id,conversation_id)
        ) $charset;");
        dbDelta("CREATE TABLE ".self::hides_table()." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            hidden_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_message (user_id,message_id),
            KEY hidden_at (hidden_at)
        ) $charset;");
        update_option('sn_message_operations_schema_version',self::SCHEMA_VERSION,false);
    }

    public static function maybe_upgrade(): void {if((string)get_option('sn_message_operations_schema_version','')!==self::SCHEMA_VERSION)self::install();}

    public static function register_routes(): void {
        $access=[SN_REST::class,'access'];
        register_rest_route('sabri-network/v2','/messages/(?P<id>\d+)/mentions',['methods'=>'POST','callback'=>[self::class,'set_mentions'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2','/messages/(?P<id>\d+)/forward',['methods'=>'POST','callback'=>[self::class,'forward_message'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2','/messages/(?P<id>\d+)/pin',['methods'=>'POST','callback'=>[self::class,'change_pin'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2','/messages/(?P<id>\d+)/star',['methods'=>'POST','callback'=>[self::class,'change_star'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2','/messages/(?P<id>\d+)/hide',['methods'=>'POST','callback'=>[self::class,'hide_message'],'permission_callback'=>$access]);
        register_rest_route('sabri-network/v2','/message-folders',[
            ['methods'=>'GET','callback'=>[self::class,'list_folders'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'create_folder'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2','/message-folders/(?P<id>\d+)',[
            ['methods'=>'PATCH','callback'=>[self::class,'update_folder'],'permission_callback'=>$access],
            ['methods'=>'DELETE','callback'=>[self::class,'delete_folder'],'permission_callback'=>$access],
        ]);
        register_rest_route('sabri-network/v2','/message-folders/(?P<id>\d+)/conversations',['methods'=>'POST','callback'=>[self::class,'change_folder_item'],'permission_callback'=>$access]);
    }

    public static function set_mentions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();$message=self::message($id);
        if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor))return self::not_found();
        if((int)$message->sender_id!==$actor)return self::error('sn_mentions_author_required','Only the message author may set mentions.',403);
        if(!SN_Policy::can_edit_message($message,$actor))return self::error('sn_mentions_edit_window_closed','Mentions can be changed only during the message edit window.',409);
        if($message->deleted_at)return self::error('sn_message_deleted','Deleted messages cannot mention members.',409);
        $ids=array_values(array_unique(array_filter(array_map('absint',is_array($request->get_param('user_ids'))?$request->get_param('user_ids'):[]))));
        if(count($ids)>self::MAX_MENTIONS)return self::error('sn_mentions_too_many','Too many mentions were requested.',413);
        foreach($ids as $user){if($user===$actor||!SN_DB::is_member((int)$message->conversation_id,$user))return self::error('sn_mention_member_invalid','Every mentioned account must be an active conversation member.',400);if(SN_DB::is_blocked($actor,$user))return self::error('sn_mention_blocked','A mentioned account is unavailable.',403);}
        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            if($wpdb->delete(self::mentions_table(),['message_id'=>$id])===false)throw new RuntimeException('mention_reset_failed');
            $now=self::now();foreach($ids as $user){if($wpdb->insert(self::mentions_table(),['message_id'=>$id,'conversation_id'=>(int)$message->conversation_id,'mentioned_user_id'=>$user,'mentioned_by'=>$actor,'created_at'=>$now])===false)throw new RuntimeException('mention_insert_failed');}
            $event=SN_Outbox::enqueue('message.mentions_updated','message',$id,['message_id'=>$id,'conversation_id'=>(int)$message->conversation_id,'mention_count'=>count($ids)],'message.mentions_updated:'.$id.':'.hash('sha256',implode(',',$ids)));
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_mentions_updated','message',$id,'success',['mention_count'=>count($ids)],$actor);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('mention_commit_failed');
            return rest_ensure_response(['message_id'=>$id,'mentioned_user_ids'=>$ids]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_mentions_failed','The mentions could not be committed.',500);}
    }

    public static function forward_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$source_id=absint($request['id']);$actor=get_current_user_id();$target_conversation=absint($request->get_param('conversation_id'));
        $source=self::message($source_id);if(!$source||!SN_DB::is_member((int)$source->conversation_id,$actor)||!SN_DB::is_member($target_conversation,$actor))return self::not_found();
        if($source->deleted_at)return self::error('sn_forward_source_unavailable','The source message is unavailable.',409);
        $target=self::conversation($target_conversation);if(!$target)return self::not_found();
        $policy=SN_Policy::can_post_to_conversation($target,$actor);if(is_wp_error($policy))return $policy;
        if(!SN_Policy::consume_rate_limit('message_forward',(string)$actor,60,MINUTE_IN_SECONDS))return self::error('sn_forward_rate_limited','Too many forwards were requested.',429);
        $body=mb_substr((string)$source->body,0,self::MAX_FORWARD_BODY);
        $attachment_id=0;$attachment_source='none';$message_type='text';
        if((int)$source->attachment_id>0&&(string)$source->attachment_source==='private'&&$body==='')return self::error('sn_forward_private_attachment_requires_resend','Private attachments must be re-uploaded rather than reusing their identifier or bytes.',409);
        if($body===''&&$attachment_id===0)return self::error('sn_forward_empty','There is no permitted content to forward.',409);
        $shared_audience=self::all_target_members_share_source((int)$source->conversation_id,$target_conversation);
        $metadata=['forwarded'=>true,'source_hash'=>hash('sha256',$source_id.'|'.(int)$source->conversation_id.'|'.(string)$source->created_at)];
        if($shared_audience)$metadata['source_message_id']=$source_id;
        $client=strtolower(trim((string)$request->get_param('client_id')))?:wp_generate_uuid4();if(!preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/',$client))return self::error('sn_forward_client_id_invalid','A valid idempotency key is required.',400);
        $idem=hash('sha256',$actor.':'.$target_conversation.':forward:'.$source_id.':'.$client);$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($existing)return rest_ensure_response(['message_id'=>(int)$existing->id,'duplicate'=>true]);
        $now=self::now();try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $space_policy=SN_Spaces::assert_post_allowed_in_transaction($target_conversation,$actor);if(is_wp_error($space_policy)){$wpdb->query('ROLLBACK');return $space_policy;}
            $ok=$wpdb->insert(SN_DB::table('messages'),['conversation_id'=>$target_conversation,'sender_id'=>$actor,'message_type'=>$message_type,'body'=>$body,'attachment_id'=>$attachment_id,'attachment_source'=>$attachment_source,'reply_to'=>0,'idempotency_key'=>$idem,'metadata'=>(string)wp_json_encode($metadata),'created_at'=>$now]);if($ok===false)throw new RuntimeException('forward_insert_failed');
            $new_id=(int)$wpdb->insert_id;
            if($wpdb->query($wpdb->prepare('UPDATE '.SN_DB::table('conversations').' SET last_message_id=GREATEST(last_message_id,%d),updated_at=GREATEST(updated_at,%s) WHERE id=%d',$new_id,$now,$target_conversation))===false)throw new RuntimeException('forward_pointer_failed');
            SN_Spaces::mark_posted_for_conversation($target_conversation,$actor,$now);
            $indexed=SN_Message_Search::index_message($new_id);if(is_wp_error($indexed))throw new RuntimeException($indexed->get_error_code());
            $event=SN_Outbox::enqueue('message.forwarded','message',$new_id,['message_id'=>$new_id,'conversation_id'=>$target_conversation,'sender_id'=>$actor,'source_scope_hash'=>(string)$metadata['source_hash'],'source_visible'=>$shared_audience],'message.forwarded:'.$new_id);
            if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
            SN_DB::audit('message_forwarded','message',$new_id,'success',['target_conversation_id'=>$target_conversation,'source_scope_hash'=>(string)$metadata['source_hash'],'attachment_reused'=>$attachment_id>0],$actor);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('forward_commit_failed');
            return new WP_REST_Response(['message_id'=>$new_id,'source_visible'=>$shared_audience,'private_attachment_forwarded'=>$attachment_id>0],201);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');$race=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.SN_DB::table('messages').' WHERE idempotency_key=%s',$idem));if($race)return rest_ensure_response(['message_id'=>(int)$race->id,'duplicate'=>true]);return self::error('sn_forward_failed','The forward could not be committed.',500);}
    }

    public static function change_pin(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$actor=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$actor))return self::not_found();
        $role=SN_DB::member_role((int)$message->conversation_id,$actor);if(!in_array($role,['owner','moderator'],true)&&!current_user_can('manage_options'))return self::error('sn_pin_role_required','A conversation management role is required.',403);
        $action=sanitize_key((string)$request->get_param('action'));if($action==='')$action='pin';if(!in_array($action,['pin','unpin'],true))return self::error('sn_pin_action_invalid','Select pin or unpin.',400);$now=self::now();
        if($action==='unpin'){$deleted=$wpdb->delete(self::pins_table(),['conversation_id'=>(int)$message->conversation_id,'message_id'=>$id]);if($deleted===false)return self::error('sn_unpin_failed','The message could not be unpinned.',500);SN_DB::audit('message_unpinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>false]);}
        if($message->deleted_at)return self::error('sn_pin_message_deleted','Deleted messages cannot be pinned.',409);
        $sql=$wpdb->prepare('INSERT INTO '.self::pins_table().' (conversation_id,message_id,pinned_by,created_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE pinned_by=VALUES(pinned_by)',(int)$message->conversation_id,$id,$actor,$now);
        if($wpdb->query($sql)===false)return self::error('sn_pin_failed','The message could not be pinned.',500);SN_DB::audit('message_pinned','message',$id,'success',[],$actor);return rest_ensure_response(['pinned'=>true]);
    }

    public static function change_star(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$user))return self::not_found();$action=sanitize_key((string)$request->get_param('action'));if($action==='')$action='star';if(!in_array($action,['star','unstar'],true))return self::error('sn_star_action_invalid','Select star or unstar.',400);
        if($action==='unstar'){$deleted=$wpdb->delete(self::stars_table(),['user_id'=>$user,'message_id'=>$id]);if($deleted===false)return self::error('sn_unstar_failed','The message could not be unstarred.',500);return rest_ensure_response(['starred'=>false]);}
        if($message->deleted_at)return self::error('sn_star_message_deleted','Deleted messages cannot be starred.',409);
        $sql=$wpdb->prepare('INSERT IGNORE INTO '.self::stars_table().' (user_id,message_id,created_at) VALUES (%d,%d,%s)',$user,$id,self::now());if($wpdb->query($sql)===false)return self::error('sn_star_failed','The message could not be starred.',500);return rest_ensure_response(['starred'=>true]);
    }

    public static function hide_message(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$message=self::message($id);if(!$message||!SN_DB::is_member((int)$message->conversation_id,$user))return self::not_found();
        $sql=$wpdb->prepare('INSERT IGNORE INTO '.self::hides_table().' (user_id,message_id,hidden_at) VALUES (%d,%d,%s)',$user,$id,self::now());if($wpdb->query($sql)===false)return self::error('sn_hide_failed','The message could not be hidden.',500);return rest_ensure_response(['hidden_for_self'=>true]);
    }

    public static function list_folders(): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$rows=$wpdb->get_results($wpdb->prepare('SELECT f.id,f.name,f.slug,f.version,f.created_at,f.updated_at,COUNT(i.id) item_count FROM '.self::folders_table().' f LEFT JOIN '.self::folder_items_table().' i ON i.folder_id=f.id WHERE f.user_id=%d GROUP BY f.id ORDER BY f.name ASC LIMIT %d',$user,self::MAX_FOLDERS));if(!is_array($rows)||$wpdb->last_error!=='')return self::error('sn_folder_list_failed','Folders could not be read safely.',500);return rest_ensure_response(['items'=>$rows]);}

    public static function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::folders_table().' WHERE user_id=%d',$user));if($wpdb->last_error!=='')return self::error('sn_folder_count_failed','Folder capacity could not be verified safely.',500);if($count>=self::MAX_FOLDERS)return self::error('sn_folder_limit','The folder limit has been reached.',409);$slug=sanitize_title($name);$now=self::now();$ok=$wpdb->insert(self::folders_table(),['user_id'=>$user,'name'=>$name,'slug'=>$slug,'created_at'=>$now,'updated_at'=>$now]);if($ok===false)return self::error('sn_folder_conflict','A folder with this name already exists.',409);return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'name'=>$name,'slug'=>$slug,'version'=>1],201);}

    public static function update_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$row=self::folder($id,$user);if(!$row)return self::error('sn_folder_missing','The folder is unavailable.',404);$expected=absint($request->get_param('version'));if($expected!==(int)$row->version)return self::error('sn_folder_version_conflict','The folder changed. Reload and retry.',409);$name=self::text((string)$request->get_param('name'),80);if($name==='')return self::error('sn_folder_name_required','Enter a folder name.',400);$changed=$wpdb->update(self::folders_table(),['name'=>$name,'slug'=>sanitize_title($name),'updated_at'=>self::now(),'version'=>$expected+1],['id'=>$id,'user_id'=>$user,'version'=>$expected]);return$changed===1?rest_ensure_response(['id'=>$id,'name'=>$name,'version'=>$expected+1]):self::error('sn_folder_update_conflict','The folder could not be updated.',409);}

    public static function delete_folder(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$id=absint($request['id']);$user=get_current_user_id();$row=self::folder($id,$user);if(!$row)return self::error('sn_folder_missing','The folder is unavailable.',404);$expected=absint($request->get_param('version'));if($expected<=0)return self::error('sn_folder_version_required','An exact folder version is required.',400);if($expected!==(int)$row->version)return self::error('sn_folder_version_conflict','The folder changed. Reload and retry.',409);try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');$locked=$wpdb->get_row($wpdb->prepare('SELECT id,version FROM '.self::folders_table().' WHERE id=%d AND user_id=%d FOR UPDATE',$id,$user));if(!$locked||(int)$locked->version!==$expected)throw new UnexpectedValueException('folder_version_conflict');if($wpdb->delete(self::folder_items_table(),['folder_id'=>$id,'user_id'=>$user])===false)throw new RuntimeException('folder_items_delete_failed');if($wpdb->delete(self::folders_table(),['id'=>$id,'user_id'=>$user])!==1)throw new RuntimeException('folder_delete_failed');if($wpdb->query('COMMIT')===false)throw new RuntimeException('folder_delete_commit_failed');return rest_ensure_response(['deleted'=>true]);}catch(Throwable $e){$wpdb->query('ROLLBACK');if($e instanceof UnexpectedValueException&&$e->getMessage()==='folder_version_conflict')return self::error('sn_folder_version_conflict','The folder changed. Reload and retry.',409);return self::error('sn_folder_delete_failed','The folder could not be deleted.',500);}}

    public static function change_folder_item(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$folder_id=absint($request['id']);$user=get_current_user_id();$folder=self::folder($folder_id,$user);if(!$folder)return self::error('sn_folder_missing','The folder is unavailable.',404);$conversation=absint($request->get_param('conversation_id'));if(!SN_DB::is_member($conversation,$user))return self::error('sn_folder_conversation_missing','The conversation is unavailable.',404);$action=sanitize_key((string)$request->get_param('action'));if($action==='')$action='add';if(!in_array($action,['add','remove'],true))return self::error('sn_folder_item_action_invalid','Select add or remove.',400);if($action==='remove'){$deleted=$wpdb->delete(self::folder_items_table(),['folder_id'=>$folder_id,'user_id'=>$user,'conversation_id'=>$conversation]);if($deleted===false)return self::error('sn_folder_item_remove_failed','The conversation could not be removed from the folder.',500);return rest_ensure_response(['included'=>false]);}$sql=$wpdb->prepare('INSERT IGNORE INTO '.self::folder_items_table().' (folder_id,user_id,conversation_id,created_at) VALUES (%d,%d,%d,%s)',$folder_id,$user,$conversation,self::now());if($wpdb->query($sql)===false)return self::error('sn_folder_item_failed','The conversation could not be added to the folder.',500);return rest_ensure_response(['included'=>true]);}

    public static function cleanup(): void {global $wpdb;$messages=SN_DB::table('messages');foreach([self::mentions_table(),self::pins_table(),self::stars_table(),self::hides_table()] as $table){$wpdb->query("DELETE x FROM $table x LEFT JOIN $messages m ON m.id=x.message_id WHERE m.id IS NULL LIMIT 500");}$folders=self::folders_table();$wpdb->query('DELETE i FROM '.self::folder_items_table()." i LEFT JOIN $folders f ON f.id=i.folder_id WHERE f.id IS NULL LIMIT 500");}

    public static function register_exporter(array $exporters): array {$exporters['sabri-network-message-organization']=['exporter_friendly_name'=>__('Network message organization','sabri-network'),'callback'=>[self::class,'export_data']];return$exporters;}
    public static function register_eraser(array $erasers): array {$erasers['sabri-network-message-organization']=['eraser_friendly_name'=>__('Network message organization','sabri-network'),'callback'=>[self::class,'erase_data']];return$erasers;}
    public static function export_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['data'=>[],'done'=>true];$uid=(int)$user->ID;$data=[];$folders=$wpdb->get_results($wpdb->prepare('SELECT id,name,created_at,updated_at FROM '.self::folders_table().' WHERE user_id=%d ORDER BY id ASC LIMIT 100',$uid));foreach(is_array($folders)?$folders:[] as $row)$data[]=['group_id'=>'sabri-network-message-folders','group_label'=>__('Network message folders','sabri-network'),'item_id'=>'folder-'.(int)$row->id,'data'=>[['name'=>__('Name','sabri-network'),'value'=>(string)$row->name],['name'=>__('Created','sabri-network'),'value'=>(string)$row->created_at],['name'=>__('Updated','sabri-network'),'value'=>(string)$row->updated_at]]];$star_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::stars_table().' WHERE user_id=%d',$uid));$hide_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::hides_table().' WHERE user_id=%d',$uid));$data[]=['group_id'=>'sabri-network-message-preferences','group_label'=>__('Network message preferences','sabri-network'),'item_id'=>'message-preference-counts','data'=>[['name'=>__('Starred messages','sabri-network'),'value'=>$star_count],['name'=>__('Messages hidden for self','sabri-network'),'value'=>$hide_count]]];return['data'=>$data,'done'=>true];}
    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;$ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::folders_table().' WHERE user_id=%d',$uid));$removed=false;foreach(array_map('absint',is_array($ids)?$ids:[]) as $id){$wpdb->delete(self::folder_items_table(),['folder_id'=>$id,'user_id'=>$uid]);}$removed=$wpdb->delete(self::folders_table(),['user_id'=>$uid])>0||$wpdb->delete(self::stars_table(),['user_id'=>$uid])>0||$wpdb->delete(self::hides_table(),['user_id'=>$uid])>0;return['items_removed'=>$removed,'items_retained'=>false,'messages'=>[],'done'=>true];}

    public static function is_hidden(int $user_id,int $message_id): bool {global $wpdb;return(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::hides_table().' WHERE user_id=%d AND message_id=%d LIMIT 1',$user_id,$message_id));}

    private static function all_target_members_share_source(int $source,int $target): bool {global $wpdb;$target_ids=array_map('absint',$wpdb->get_col($wpdb->prepare("SELECT user_id FROM ".SN_DB::table('members')." WHERE conversation_id=%d AND left_at IS NULL",$target))?:[]);if(!$target_ids)return false;foreach($target_ids as $uid)if(!SN_DB::is_member($source,$uid))return false;return true;}
    private static function folder(int $id,int $user): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::folders_table().' WHERE id=%d AND user_id=%d',$id,$user))?:null;}
    private static function message(int $id): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d',$id))?:null;}
    private static function conversation(int $id): ?object {global $wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM ".SN_DB::table('conversations')." WHERE id=%d AND status='active'",$id))?:null;}
    private static function text(string $value,int $max): string {return mb_substr(sanitize_textarea_field(wp_unslash($value)),0,$max);}
    private static function now(): string {return current_time('mysql',true);}
    private static function mentions_table(): string {return SN_DB::table('message_mentions');}
    private static function pins_table(): string {return SN_DB::table('message_pins');}
    private static function stars_table(): string {return SN_DB::table('message_stars');}
    private static function folders_table(): string {return SN_DB::table('message_folders');}
    private static function folder_items_table(): string {return SN_DB::table('message_folder_items');}
    private static function hides_table(): string {return SN_DB::table('message_hides');}
    private static function not_found(): WP_Error {return self::error('sn_message_operation_not_found','The requested message or conversation is unavailable.',404);}
    private static function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,['status'=>$status]);}
}
