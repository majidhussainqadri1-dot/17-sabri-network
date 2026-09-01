#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

def rep(path, old, new, label):
    p=root/path; t=p.read_text(encoding='utf-8')
    if old not in t: raise SystemExit(label+' anchor missing')
    p.write_text(t.replace(old,new,1),encoding='utf-8')

# Strict recipient snapshot for protected sender actions.
p=root/'includes/class-sn-file-transfer-part-6.php'; t=p.read_text(encoding='utf-8')
old="""        $recipients=self::recipient_ids((int)$row->id);
"""
new="""        $recipients=self::recipient_ids((int)$row->id,true);
        if(is_wp_error($recipients))return $recipients;
"""
if old not in t: raise SystemExit('R24 revalidate recipients anchor missing')
t=t.replace(old,new,1)
old="""    private static function recipient_ids(int $transfer): array{global $wpdb;return array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL',$transfer)));}
"""
new="""    private static function recipient_ids(int $transfer,bool $strict=false): array|WP_Error{global $wpdb;$rows=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL',$transfer));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_recipient_snapshot_failed','file_transfer',$transfer,'failure',['reason'=>(string)$wpdb->last_error]);if($strict)return new WP_Error('transfer_recipient_state_unavailable','Transfer recipient state could not be verified safely.',['status'=>503]);return[];}return array_map('intval',is_array($rows)?$rows:[]);}
"""
if old not in t: raise SystemExit('R24 recipient helper anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Format remains non-strict and never leaks DB errors.
p=root/'includes/class-sn-file-transfer-part-7.php'; t=p.read_text(encoding='utf-8')
# delete_chunks read uncertainty must be retryable failure.
old="""        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT id,storage_key FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY id ASC',$transfer_id));$all=true;
        foreach(is_array($rows)?$rows:[] as $row){
"""
new="""        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT id,storage_key FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY id ASC',$transfer_id));$all=true;
        if($wpdb->last_error!==''){SN_DB::audit('file_transfer_chunk_ledger_read_failed','file_transfer',$transfer_id,'failure',['reason'=>(string)$wpdb->last_error]);return false;}
        foreach(is_array($rows)?$rows:[] as $row){
"""
if old not in t: raise SystemExit('R24 delete chunks anchor missing')
t=t.replace(old,new,1)
# format sender recipients: guard union return.
old="""            'recipients'=>(int)$row->sender_id===$viewer?self::recipient_ids((int)$row->id):[],
"""
new="""            'recipients'=>(int)$row->sender_id===$viewer?(($recipient_snapshot=self::recipient_ids((int)$row->id)) instanceof WP_Error?[]:$recipient_snapshot):[],
"""
if old not in t: raise SystemExit('R24 format recipients anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Scanner materialization must use a complete, verified DB snapshot.
p=root/'includes/class-sn-file-transfer-part-5.php'; t=p.read_text(encoding='utf-8')
old="""        $tmp=self::storage_root().'/'.$row->public_id.'/scan-'.$suffix.'.tmp';$handle=@fopen($tmp,'xb');if(!$handle)return new WP_Error('scan_materialization_failed','A private scan file could not be created.',['status'=>500]);@chmod($tmp,0600);$chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',$transfer_id));$expected=0;
        foreach($chunks as $chunk){if((int)$chunk->chunk_index!==$expected++){fclose($handle);@unlink($tmp);return new WP_Error('transfer_chunk_gap','The transfer chunk sequence is incomplete.',['status'=>409]);}$path=self::existing_storage_path((string)$chunk->storage_key);if(is_wp_error($path)){fclose($handle);@unlink($tmp);return $path;}$plain=SN_Communication_Crypto::read_encrypted_file($path,self::chunk_context($row,(int)$chunk->chunk_index));if(is_wp_error($plain)||strlen($plain)!==(int)$chunk->byte_count||!hash_equals((string)$chunk->sha256,hash('sha256',is_wp_error($plain)?'':$plain))||fwrite($handle,is_wp_error($plain)?'':$plain)===false){fclose($handle);@unlink($tmp);return is_wp_error($plain)?$plain:new WP_Error('scan_materialization_failed','The private scan file could not be completed.',['status'=>500]);}}
        fclose($handle);return $tmp;
"""
new="""        $tmp=self::storage_root().'/'.$row->public_id.'/scan-'.$suffix.'.tmp';$handle=@fopen($tmp,'xb');if(!$handle)return new WP_Error('scan_materialization_failed','A private scan file could not be created.',['status'=>500]);@chmod($tmp,0600);$chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',$transfer_id));
        if($wpdb->last_error!==''||!is_array($chunks)||count($chunks)!==(int)$row->total_chunks){fclose($handle);@unlink($tmp);SN_DB::audit('file_transfer_scan_snapshot_failed','file_transfer',$transfer_id,'failure',['reason'=>(string)$wpdb->last_error]);return new WP_Error('scan_materialization_failed','The complete transfer snapshot could not be verified for scanning.',['status'=>503]);}
        $expected=0;$materialized_bytes=0;
        foreach($chunks as $chunk){if((int)$chunk->chunk_index!==$expected++){fclose($handle);@unlink($tmp);return new WP_Error('transfer_chunk_gap','The transfer chunk sequence is incomplete.',['status'=>409]);}$path=self::existing_storage_path((string)$chunk->storage_key);if(is_wp_error($path)){fclose($handle);@unlink($tmp);return $path;}$plain=SN_Communication_Crypto::read_encrypted_file($path,self::chunk_context($row,(int)$chunk->chunk_index));if(is_wp_error($plain)||strlen($plain)!==(int)$chunk->byte_count||!hash_equals((string)$chunk->sha256,hash('sha256',is_wp_error($plain)?'':$plain))||fwrite($handle,is_wp_error($plain)?'':$plain)===false){fclose($handle);@unlink($tmp);return is_wp_error($plain)?$plain:new WP_Error('scan_materialization_failed','The private scan file could not be completed.',['status'=>500]);}$materialized_bytes+=strlen($plain);}
        if($materialized_bytes!==(int)$row->total_bytes){fclose($handle);@unlink($tmp);return new WP_Error('scan_materialization_failed','The scanner materialization byte count is incomplete.',['status'=>409]);}
        fclose($handle);return $tmp;
"""
if old not in t: raise SystemExit('R24 materialize anchor missing')
t=t.replace(old,new,1)
# revoke reconciliation DB uncertainty cannot be success.
old="""        }catch(Throwable $e){$wpdb->query('ROLLBACK');$fresh=self::session((string)$row->public_id);if($fresh&&$fresh->revoked_at){$remaining=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL',(int)$row->id));if($remaining===0)return rest_ensure_response(['revoked'=>true,'duplicate'=>true,'commit_reconciled'=>true]);}SN_DB::audit('file_transfer_revoke_failed','file_transfer',(int)$row->id,'failure',['reason'=>$e->getMessage()],$user);return new WP_Error('transfer_revoke_failed','The transfer could not be revoked atomically.',['status'=>500]);}
"""
new="""        }catch(Throwable $e){$wpdb->query('ROLLBACK');$fresh=self::session((string)$row->public_id);if($fresh&&$fresh->revoked_at){$remaining_raw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::recipients_table().' WHERE transfer_id=%d AND revoked_at IS NULL',(int)$row->id));if($wpdb->last_error===''){$remaining=(int)$remaining_raw;if($remaining===0)return rest_ensure_response(['revoked'=>true,'duplicate'=>true,'commit_reconciled'=>true]);}else{SN_DB::audit('file_transfer_revoke_reconciliation_read_failed','file_transfer',(int)$row->id,'failure',['reason'=>(string)$wpdb->last_error],$user);}}SN_DB::audit('file_transfer_revoke_failed','file_transfer',(int)$row->id,'failure',['reason'=>$e->getMessage()],$user);return new WP_Error('transfer_revoke_failed','The transfer could not be revoked atomically.',['status'=>500]);}
"""
if old not in t: raise SystemExit('R24 revoke reconcile anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Finalize reconciliation DB uncertainty cannot be success.
p=root/'includes/class-sn-file-transfer-part-4.php'; t=p.read_text(encoding='utf-8')
old="""                $recipient_ok=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::recipients_table()." WHERE transfer_id=%d AND revoked_at IS NULL AND state<>'ready'",(int)$row->id))===0;
                if($recipient_ok)return rest_ensure_response(['transfer'=>self::format($fresh,$sender),'duplicate'=>true,'commit_reconciled'=>true]);
"""
new="""                $recipient_remaining=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::recipients_table()." WHERE transfer_id=%d AND revoked_at IS NULL AND state<>'ready'",(int)$row->id));
                $recipient_ok=$wpdb->last_error===''&&(int)$recipient_remaining===0;
                if($wpdb->last_error!=='')SN_DB::audit('file_transfer_finalize_reconciliation_read_failed','file_transfer',(int)$row->id,'failure',['reason'=>(string)$wpdb->last_error],$sender);
                if($recipient_ok)return rest_ensure_response(['transfer'=>self::format($fresh,$sender),'duplicate'=>true,'commit_reconciled'=>true]);
"""
if old not in t: raise SystemExit('R24 finalize reconcile anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Permanent R24 contracts.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('R24 suite anchor missing')
block=r'''
// Round 24 — File Transfer snapshots, scanning, reconciliation and cleanup fail closed.
$ft5=$read('includes/class-sn-file-transfer-part-5.php');$ft6=$read('includes/class-sn-file-transfer-part-6.php');$ft7=$read('includes/class-sn-file-transfer-part-7.php');$ft4=$read('includes/class-sn-file-transfer-part-4.php');
$check(str_contains($ft6,'recipient_ids((int)$row->id,true)') && str_contains($ft6,'transfer_recipient_state_unavailable') && str_contains($ft6,'file_transfer_recipient_snapshot_failed'), 'Round 24: protected transfer revalidation must fail closed on recipient snapshot DB uncertainty.');
$check(str_contains($ft5,'file_transfer_scan_snapshot_failed') && str_contains($ft5,"count($chunks)!==(int)$row->total_chunks") && str_contains($ft5,'$materialized_bytes!==(int)$row->total_bytes'), 'Round 24: scanner materialization must prove a complete chunk and byte snapshot.');
$check(str_contains($ft4,'file_transfer_finalize_reconciliation_read_failed') && str_contains($ft4,"$wpdb->last_error===''"), 'Round 24: finalize commit reconciliation must not convert DB uncertainty into success.');
$check(str_contains($ft5,'file_transfer_revoke_reconciliation_read_failed') && str_contains($ft5,"$wpdb->last_error===''") , 'Round 24: revoke commit reconciliation must not convert DB uncertainty into success.');
$check(str_contains($ft7,'file_transfer_chunk_ledger_read_failed') && str_contains($ft7,'return false;'), 'Round 24: chunk-ledger DB uncertainty must keep cleanup retryable.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
for f in sabri-network/includes/class-sn-file-transfer-part-{4,5,6,7}.php sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php; do php -l "$f"; done
