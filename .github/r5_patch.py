from pathlib import Path
import re

# R5-D01/D02: transfer volume and authorization-ledger reads fail closed.
p=Path('sabri-network/includes/class-sn-file-transfer-part-2.php')
s=p.read_text(encoding='utf-8')
old="$used = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_bytes),0) FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND created_at>=%s AND status NOT IN (\\'rejected\\',\\'revoked\\',\\'expired\\')', $sender_id, $today));"
new="$wpdb->last_error = '';\n        $used_raw = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_bytes),0) FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND created_at>=%s AND status NOT IN (\\'rejected\\',\\'revoked\\',\\'expired\\')', $sender_id, $today));\n        if ($wpdb->last_error !== '' || $used_raw === null) return new WP_Error('transfer_volume_read_failed', 'The current transfer-volume total could not be verified safely.', ['status'=>503]);\n        $used = (int) $used_raw;"
if 'transfer_volume_read_failed' not in s:
    if s.count(old)!=1: raise SystemExit('R5 daily-volume target mismatch')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/includes/class-sn-file-transfer-part-6.php')
s=p.read_text(encoding='utf-8')
old="$recipients=self::recipient_ids((int)$row->id);"
new="$recipients=self::recipient_ids_authoritative((int)$row->id);if(is_wp_error($recipients))return $recipients;"
if 'recipient_ids_authoritative((int)$row->id)' not in s:
    if s.count(old)!=1: raise SystemExit('R5 revalidate recipient target mismatch')
    s=s.replace(old,new,1)
old="    private static function recipient_ids(int $transfer): array{global $wpdb;return array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL',$transfer)));}\n"
new="""    private static function recipient_ids(int $transfer): array{global $wpdb;return array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL',$transfer))?:[]);}
    private static function recipient_ids_authoritative(int $transfer): array|WP_Error {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL ORDER BY user_id ASC',$transfer));if($wpdb->last_error!==''||!is_array($raw))return new WP_Error('transfer_recipient_ledger_unavailable','The transfer recipient ledger could not be verified safely.',['status'=>503]);return array_map('intval',$raw);}
"""
if 'private static function recipient_ids_authoritative' not in s:
    if s.count(old)!=1: raise SystemExit('R5 recipient helper target mismatch')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# R5-D03: privacy batch/completion/chunk-ledger truth is error-aware.
p=Path('sabri-network/includes/class-sn-file-transfer-part-7.php')
s=p.read_text(encoding='utf-8')
old="global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT id,storage_key FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY id ASC',$transfer_id));$all=true;"
new="global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT id,storage_key FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY id ASC',$transfer_id));if($wpdb->last_error!==''||!is_array($rows)){SN_DB::audit('file_transfer_chunk_ledger_read_failed','file_transfer',$transfer_id,'failure',[]);return false;}$all=true;"
if 'file_transfer_chunk_ledger_read_failed' not in s:
    if s.count(old)!=1: raise SystemExit('R5 delete_chunks read target mismatch')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/includes/class-sn-file-transfer-part-8.php')
s=p.read_text(encoding='utf-8')
old="""        $sent=array_map('intval',$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $sessions WHERE sender_id=%d AND status NOT IN ('revoked','expired','rejected') ORDER BY id ASC LIMIT %d\",$uid,$page_size))?:[]);
        $received=array_map('intval',$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $recipients WHERE user_id=%d AND state<>'erased' ORDER BY id ASC LIMIT %d\",$uid,$page_size))?:[]);
"""
new="""        $wpdb->last_error='';$sent_raw=$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $sessions WHERE sender_id=%d AND status NOT IN ('revoked','expired','rejected') ORDER BY id ASC LIMIT %d\",$uid,$page_size));
        if($wpdb->last_error!==''||!is_array($sent_raw))return self::privacy_read_retry('Private transfer sender records could not be read safely.');$sent=array_map('intval',$sent_raw);
        $wpdb->last_error='';$received_raw=$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $recipients WHERE user_id=%d AND state<>'erased' ORDER BY id ASC LIMIT %d\",$uid,$page_size));
        if($wpdb->last_error!==''||!is_array($received_raw))return self::privacy_read_retry('Private transfer recipient records could not be read safely.');$received=array_map('intval',$received_raw);
"""
if 'Private transfer sender records could not be read safely.' not in s:
    if s.count(old)!=1: raise SystemExit('R5 eraser batch target mismatch')
    s=s.replace(old,new,1)
old="""        // Bytes are destroyed only after canonical sender access has been revoked.
        foreach($sent as $id)self::delete_chunks($id);
        $more_sent=(bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $sessions WHERE sender_id=%d AND status NOT IN ('revoked','expired','rejected') LIMIT 1\",$uid));
        $more_received=(bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $recipients WHERE user_id=%d AND state<>'erased' LIMIT 1\",$uid));
        $leftover_chunks=false;foreach($sent as $id){if((bool)$wpdb->get_var($wpdb->prepare('SELECT 1 FROM '.self::chunks_table().' WHERE transfer_id=%d LIMIT 1',$id))){$leftover_chunks=true;break;}}
        return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Minimum integrity, legal-hold and abuse evidence may be retained under the approved retention policy.'],'done'=>!$more_sent&&!$more_received&&!$leftover_chunks];
    }

    private static function privacy_erase_page_size(): int{return 100;}
"""
new="""        // Bytes are destroyed only after canonical sender access has been revoked.
        $leftover_chunks=false;foreach($sent as $id){if(!self::delete_chunks($id))$leftover_chunks=true;}
        $wpdb->last_error='';$more_sent_raw=$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $sessions WHERE sender_id=%d AND status NOT IN ('revoked','expired','rejected') LIMIT 1\",$uid));if($wpdb->last_error!=='')return self::privacy_read_retry('Private transfer sender completion could not be verified safely.',$removed);$more_sent=$more_sent_raw!==null;
        $wpdb->last_error='';$more_received_raw=$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $recipients WHERE user_id=%d AND state<>'erased' LIMIT 1\",$uid));if($wpdb->last_error!=='')return self::privacy_read_retry('Private transfer recipient completion could not be verified safely.',$removed);$more_received=$more_received_raw!==null;
        foreach($sent as $id){$wpdb->last_error='';$chunk_raw=$wpdb->get_var($wpdb->prepare('SELECT 1 FROM '.self::chunks_table().' WHERE transfer_id=%d LIMIT 1',$id));if($wpdb->last_error!=='')return self::privacy_read_retry('Private transfer byte cleanup could not be verified safely.',$removed);if($chunk_raw!==null){$leftover_chunks=true;break;}}
        return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Minimum integrity, legal-hold and abuse evidence may be retained under the approved retention policy.'],'done'=>!$more_sent&&!$more_received&&!$leftover_chunks];
    }

    private static function privacy_read_retry(string $message,bool $removed=false): array{return['items_removed'=>$removed,'items_retained'=>true,'messages'=>[$message],'done'=>false];}
    private static function privacy_erase_page_size(): int{return 100;}
"""
if 'private static function privacy_read_retry' not in s:
    if s.count(old)!=1: raise SystemExit('R5 eraser completion target mismatch')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# R5-D04: preflight every requested encrypted chunk before successful headers/body.
p=Path('sabri-network/includes/class-sn-file-transfer-part-5.php')
s=p.read_text(encoding='utf-8')
pattern=r"    private static function stream_download\(object \$row,int \$user\): void \{.*?\n    \}\n\n    public static function materialize_for_scan"
new_method='''    private static function stream_download(object $row,int $user): void {
        global $wpdb;$total=(int)$row->total_bytes;$start=0;$end=$total-1;$status=200;$range=isset($_SERVER['HTTP_RANGE'])?trim((string)$_SERVER['HTTP_RANGE']):'';
        if($range!==''&&preg_match('/^bytes=(\\d*)-(\\d*)$/',$range,$m)){if($m[1]===''&&$m[2]!==''){$length=min($total,(int)$m[2]);$start=$total-$length;}else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}if($start<0||$start>$end||$start>=$total){header('Content-Range: bytes */'.$total);status_header(416);return;}$status=206;}
        $wpdb->last_error='';$chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',(int)$row->id));
        if($wpdb->last_error!==''||!is_array($chunks)||count($chunks)!==(int)$row->total_chunks){SN_DB::audit('file_transfer_download_preflight_failed','file_transfer',(int)$row->id,'failure',['reason'=>'chunk_ledger_unavailable'],$user);status_header(404);return;}
        // First pass authenticates the complete canonical chunk sequence before a 200/206 envelope or plaintext is emitted.
        $offset=0;$expected_index=0;
        foreach($chunks as $chunk){if((int)$chunk->chunk_index!==$expected_index++){SN_DB::audit('file_transfer_download_preflight_failed','file_transfer',(int)$row->id,'failure',['reason'=>'chunk_sequence'],$user);status_header(404);return;}$chunk_start=$offset;$chunk_end=$offset+(int)$chunk->byte_count-1;$offset=$chunk_end+1;$path=self::existing_storage_path((string)$chunk->storage_key);if(is_wp_error($path)){SN_DB::audit('file_transfer_download_preflight_failed','file_transfer',(int)$row->id,'failure',['reason'=>$path->get_error_code()],$user);status_header(404);return;}$plain=SN_Communication_Crypto::read_encrypted_file($path,self::chunk_context($row,(int)$chunk->chunk_index));if(is_wp_error($plain)||strlen($plain)!==(int)$chunk->byte_count||!hash_equals((string)$chunk->sha256,hash('sha256',is_wp_error($plain)?'':$plain))){SN_DB::audit('file_transfer_download_preflight_failed','file_transfer',(int)$row->id,'failure',['reason'=>'chunk_integrity'],$user);status_header(404);return;}}
        if($offset!==$total){SN_DB::audit('file_transfer_download_preflight_failed','file_transfer',(int)$row->id,'failure',['reason'=>'total_bytes_mismatch'],$user);status_header(404);return;}
        status_header($status);nocache_headers();header('Content-Type: '.($row->detected_mime?:'application/octet-stream'));header('Content-Disposition: attachment; filename="'.str_replace(['"',"\\r","\\n"],'',(string)$row->safe_name).'"');header('Accept-Ranges: bytes');header('Content-Length: '.(($end-$start)+1));header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: private, no-store, max-age=0');if($status===206)header("Content-Range: bytes $start-$end/$total");
        $offset=0;$sent=0;$failed=false;
        foreach($chunks as $chunk){$chunk_start=$offset;$chunk_end=$offset+(int)$chunk->byte_count-1;$offset=$chunk_end+1;if($chunk_end<$start||$chunk_start>$end)continue;$path=self::existing_storage_path((string)$chunk->storage_key);if(is_wp_error($path)){$failed=true;break;}$plain=SN_Communication_Crypto::read_encrypted_file($path,self::chunk_context($row,(int)$chunk->chunk_index));if(is_wp_error($plain)||strlen($plain)!==(int)$chunk->byte_count||!hash_equals((string)$chunk->sha256,hash('sha256',is_wp_error($plain)?'':$plain))){$failed=true;break;}$slice_start=max(0,$start-$chunk_start);$slice_end=min((int)$chunk->byte_count-1,$end-$chunk_start);$slice=substr($plain,$slice_start,($slice_end-$slice_start)+1);$sent+=strlen($slice);echo $slice;if(ob_get_level()>0)@ob_flush();flush();}
        $expected=($end-$start)+1;if($failed||$sent!==$expected){SN_DB::audit('file_transfer_download_failed','file_transfer',(int)$row->id,'failure',['range'=>$status===206,'expected_bytes'=>$expected,'sent_bytes'=>$sent],$user);return;}
        $now=current_time('mysql',true);$changed=$wpdb->query($wpdb->prepare("UPDATE ".self::recipients_table()." SET state='downloaded',first_accessed_at=COALESCE(first_accessed_at,%s),downloaded_at=%s,updated_at=%s WHERE transfer_id=%d AND user_id=%d AND revoked_at IS NULL",$now,$now,$now,(int)$row->id,$user));if($changed===false){SN_DB::audit('file_transfer_download_receipt_failed','file_transfer',(int)$row->id,'failure',['bytes'=>$sent],$user);return;}SN_DB::audit('file_transfer_downloaded','file_transfer',(int)$row->id,'success',['range'=>$status===206,'bytes'=>$sent],$user);
    }

    public static function materialize_for_scan'''
if 'file_transfer_download_preflight_failed' not in s:
    s,n=re.subn(pattern,new_method,s,count=1,flags=re.S)
    if n!=1: raise SystemExit('R5 stream download target mismatch')
p.write_text(s,encoding='utf-8')
