<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_7 {
    private static function format(object $row,int $viewer): array {
        return [
            'id'=>(string)$row->public_id,
            'sender_id'=>(int)$row->sender_id,
            'conversation_id'=>(int)$row->conversation_id,
            'name'=>(string)$row->safe_name,
            'mime'=>(string)($row->detected_mime?:$row->declared_mime),
            'size'=>(int)$row->total_bytes,
            'chunk_size'=>(int)$row->chunk_bytes,
            'total_chunks'=>(int)$row->total_chunks,
            'received_chunks'=>(int)$row->received_chunks,
            'received_indices'=>self::received_indices((int)$row->id),
            'received_bytes'=>(int)$row->received_bytes,
            'sha256'=>(int)$row->sender_id===$viewer?(string)($row->actual_sha256?:$row->expected_sha256):(string)$row->actual_sha256,
            'status'=>(string)$row->status,
            'scan_status'=>(string)$row->scan_status,
            'failure_code'=>(string)$row->failure_code,
            'expires_at'=>(string)$row->expires_at,
            'created_at'=>(string)$row->created_at,
            'recipients'=>(int)$row->sender_id===$viewer?(($recipient_snapshot=self::recipient_ids((int)$row->id)) instanceof WP_Error?[]:$recipient_snapshot):[],
        ];
    }

    private static function received_indices(int $transfer_id): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT chunk_index FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',
            $transfer_id
        )) ?: []);
    }

    private static function not_found(): WP_Error{return new WP_Error('transfer_not_found','The private transfer is unavailable.',['status'=>404]);}

    public static function ensure_storage(): bool {$root=self::storage_root();if(!self::is_safe_storage_root($root)||(!is_dir($root)&&!wp_mkdir_p($root))||!self::is_safe_storage_root($root))return false;@chmod($root,0700);@file_put_contents($root.'/.htaccess',"Deny from all\nRequire all denied\n",LOCK_EX);@file_put_contents($root.'/index.php',"<?php http_response_code(404); exit;\n",LOCK_EX);return is_dir($root)&&is_writable($root);}
    private static function storage_root(): string{$default=trailingslashit(dirname(ABSPATH)).'sabri-private/file17-transfers';return untrailingslashit((string)apply_filters('sn_network_transfer_storage_root',$default));}
    private static function is_safe_storage_root(string $root): bool{$normalized=trailingslashit(wp_normalize_path($root));$web=trailingslashit(wp_normalize_path(ABSPATH));if($normalized==='/'||str_starts_with($normalized,$web))return false;$resolved=realpath($root);$resolved_web=realpath(ABSPATH);if($resolved!==false&&$resolved_web!==false&&str_starts_with(trailingslashit(wp_normalize_path($resolved)),trailingslashit(wp_normalize_path($resolved_web))))return false;return true;}
    private static function existing_storage_path(string $storage_key): string|WP_Error{$storage_key=str_replace('\\','/',trim($storage_key));if($storage_key===''||str_contains($storage_key,"\0")||str_starts_with($storage_key,'/')||preg_match('~(^|/)\.\.(/|$)~',$storage_key))return new WP_Error('transfer_storage_key_invalid','The private transfer storage reference is invalid.',['status'=>500]);$root=realpath(self::storage_root());$candidate=realpath(self::storage_root().'/'.$storage_key);if($root===false||$candidate===false)return new WP_Error('private_chunk_unavailable','The private encrypted object is unavailable.',['status'=>404]);$root=trailingslashit(wp_normalize_path($root));$candidate_normalized=wp_normalize_path($candidate);if(!str_starts_with($candidate_normalized,$root))return new WP_Error('transfer_storage_path_escape','The private transfer storage reference failed containment validation.',['status'=>500]);return $candidate;}

    /** Keep ledger rows until their encrypted bytes are actually gone, so cleanup is retryable. */
    private static function delete_chunks(int $transfer_id): bool {
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT id,storage_key FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY id ASC',$transfer_id));$all=true;
        if($wpdb->last_error!==''){SN_DB::audit('file_transfer_chunk_ledger_read_failed','file_transfer',$transfer_id,'failure',['reason'=>(string)$wpdb->last_error]);return false;}
        foreach(is_array($rows)?$rows:[] as $row){
            $path=self::existing_storage_path((string)$row->storage_key);
            if(is_wp_error($path)){
                // A missing file is already deleted; containment failures are not silently discarded.
                if($path->get_error_code()==='private_chunk_unavailable'){$deleted=$wpdb->delete(self::chunks_table(),['id'=>(int)$row->id],['%d']);if($deleted===false){$all=false;SN_DB::audit('file_transfer_chunk_row_delete_failed','file_transfer',$transfer_id,'failure',['chunk_id'=>(int)$row->id]);}continue;}
                $all=false;SN_DB::audit('file_transfer_chunk_delete_path_rejected','file_transfer',$transfer_id,'failure',['storage_key_hash'=>hash('sha256',(string)$row->storage_key)]);continue;
            }
            if(is_file($path)&&!@unlink($path)){$all=false;SN_DB::audit('file_transfer_chunk_delete_failed','file_transfer',$transfer_id,'failure',['chunk_id'=>(int)$row->id]);continue;}
            $deleted=$wpdb->delete(self::chunks_table(),['id'=>(int)$row->id],['%d']);if($deleted===false){$all=false;SN_DB::audit('file_transfer_chunk_row_delete_failed','file_transfer',$transfer_id,'failure',['chunk_id'=>(int)$row->id]);}
        }
        return $all;
    }

    public static function cleanup(): void {
        global $wpdb;$now=current_time('mysql',true);$sessions=self::sessions_table();$chunks=self::chunks_table();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.id,s.status FROM $sessions s WHERE (s.expires_at<%s AND s.status NOT IN ('expired','revoked','rejected')) OR (s.status IN ('expired','revoked','rejected') AND EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) ORDER BY s.id ASC LIMIT 100",$now));
        foreach(is_array($rows)?$rows:[] as $row){
            if(!in_array((string)$row->status,['expired','revoked','rejected'],true)){
                $changed=$wpdb->query($wpdb->prepare("UPDATE $sessions SET status='expired',version=version+1,updated_at=%s WHERE id=%d AND status NOT IN ('expired','revoked','rejected')",$now,(int)$row->id));
                if($changed!==1)continue;
            }
            self::delete_chunks((int)$row->id);
        }
    }

    public static function health(): WP_REST_Response {global $wpdb;$missing=[];foreach([self::sessions_table(),self::chunks_table(),self::recipients_table()] as $table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)$missing[]=$table;$storage=self::ensure_storage();$scanner_ready=apply_filters('sn_network_transfer_scanner_ready',false)===true;return rest_ensure_response(['ok'=>!$missing&&$storage&&$scanner_ready,'schema_version'=>self::SCHEMA_VERSION,'missing_tables'=>$missing,'max_file_bytes'=>self::MAX_FILE_BYTES,'storage_ready'=>$storage,'scanner_connected'=>$scanner_ready,'scanner_readiness_contract'=>'sn_network_transfer_scanner_ready']);}
    public static function register_assets(): void{wp_register_style('sabri-file-transfer',SN_URL.'assets/css/file-transfer.css',[],SN_VERSION);wp_register_script('sabri-file-transfer',SN_URL.'assets/js/file-transfer.js',[],SN_VERSION,true);}
    public static function render(): string{self::register_assets();wp_enqueue_style('sabri-file-transfer');wp_enqueue_script('sabri-file-transfer');$destination=self::url();wp_localize_script('sabri-file-transfer','SN_TRANSFER_CONFIG',['restUrl'=>esc_url_raw(rest_url('sabri-network/v2/transfers')),'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'','ready'=>self::verified_access()===true,'maxBytes'=>self::MAX_FILE_BYTES,'defaultChunkBytes'=>self::DEFAULT_CHUNK_BYTES,'loginUrl'=>esc_url_raw((string)apply_filters('sn_network_login_url',wp_login_url($destination),$destination))]);ob_start();include SN_DIR.'templates/file-transfer-app.php';return(string)ob_get_clean();}
    public static function force_content(string $content): string{return in_the_loop()&&is_main_query()&&get_queried_object_id()===(int)get_option('sn_file_transfer_page_id')?do_shortcode('[sabri_file_transfer]'):$content;}
    public static function disable_cache(): void{if(get_queried_object_id()!==(int)get_option('sn_file_transfer_page_id'))return;if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();header('X-Robots-Tag: noindex, noarchive',true);header('X-Content-Type-Options: nosniff',true);header('Referrer-Policy: no-referrer',true);}
    public static function ensure_page(bool $repair): int{$id=(int)get_option('sn_file_transfer_page_id');$page=$id?get_post($id):null;if($page instanceof WP_Post&&(string)get_post_meta($id,self::PAGE_OWNER_META,true)==='file-transfer'){if($repair||!has_shortcode((string)$page->post_content,'sabri_file_transfer')||$page->post_status!=='publish')wp_update_post(['ID'=>$id,'post_title'=>'File Transfer','post_content'=>'[sabri_file_transfer]','post_status'=>'publish','comment_status'=>'closed']);return$id;}$candidate=get_page_by_path('file-transfer',OBJECT,'page');if($candidate instanceof WP_Post&&(string)get_post_meta((int)$candidate->ID,self::PAGE_OWNER_META,true)!=='file-transfer')return 0;$created=$candidate instanceof WP_Post?(int)$candidate->ID:wp_insert_post(['post_title'=>'File Transfer','post_name'=>'file-transfer','post_content'=>'[sabri_file_transfer]','post_status'=>'publish','post_type'=>'page','comment_status'=>'closed'],true);if(is_wp_error($created))return 0;$id=(int)$created;if($id>0){update_post_meta($id,self::PAGE_OWNER_META,'file-transfer');update_option('sn_file_transfer_page_id',$id,false);}return$id;}
    public static function url(): string{$id=(int)get_option('sn_file_transfer_page_id');$url=$id?get_permalink($id):false;return$url?(string)$url:home_url('/file-transfer/');}
    public static function register_exporter(array $exporters): array{$exporters['sabri-network-transfers']=['exporter_friendly_name'=>'Sabri private file transfers','callback'=>[self::class,'export_personal_data']];return$exporters;}
    public static function export_personal_data(string $email,int $page=1): array{global $wpdb;$user=get_user_by('email',$email);if(!$user)return['data'=>[],'done'=>true];$rows=$wpdb->get_results($wpdb->prepare('SELECT DISTINCT s.* FROM '.self::sessions_table().' s LEFT JOIN '.self::recipients_table().' r ON r.transfer_id=s.id WHERE s.sender_id=%d OR r.user_id=%d ORDER BY s.id DESC LIMIT 100 OFFSET %d',$user->ID,$user->ID,max(0,($page-1)*100)));$data=array_map(static fn($r):array=>['group_id'=>'sabri-file-transfers','group_label'=>'Private file transfers','item_id'=>'transfer-'.$r->public_id,'data'=>[['name'=>'Identifier','value'=>$r->public_id],['name'=>'File name','value'=>$r->safe_name],['name'=>'Size','value'=>$r->total_bytes],['name'=>'Status','value'=>$r->status],['name'=>'SHA-256','value'=>$r->actual_sha256],['name'=>'Created','value'=>$r->created_at],['name'=>'Expires','value'=>$r->expires_at]]],$rows?:[]);return['data'=>$data,'done'=>count($rows?:[])<100];}
    public static function register_eraser(array $erasers): array{$erasers['sabri-network-transfers']=['eraser_friendly_name'=>'Sabri private file transfers','callback'=>[self::class,'erase_personal_data']];return$erasers;}
}
