from pathlib import Path
root=Path('sabri-network'); p=root/'includes/class-sn-privacy-runtime-hardening.php'; t=p.read_text(encoding='utf-8')

# R35 Defect Ledger frozen after full privacy review:
# D1 privacy GET_LOCK DB uncertainty was cast to ordinary contention; release failure silent.
# D2 export enrichment DB failure silently produced an apparently complete export.
# D3 message/update erasure batch enumeration DB failures collapsed to “no work”.
# D4 locked message reread DB failure could silently skip a record and commit partial truth.
# D5 relational erasure preflight/lock/member/call enumerations collapsed DB failures to empty sets,
#    allowing destructive work from an incomplete snapshot.

old="$row=$wpdb->get_row($wpdb->prepare('SELECT id,user_id,body FROM '.SN_DB::table('updates').' WHERE id=%d',(int)$m[1]));if(!$row||(int)$row->user_id!==$uid)continue;"
new="$row=$wpdb->get_row($wpdb->prepare('SELECT id,user_id,body FROM '.SN_DB::table('updates').' WHERE id=%d',(int)$m[1]));if(($wpdb->last_error ?? '')!==''){unset($item);$result['done']=false;return$result;}if(!$row||(int)$row->user_id!==$uid)continue;"
assert old in t; t=t.replace(old,new,1)

old="$lock='sn:f17:privacy:'.$uid;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Privacy erasure is already running. Retry this page.','sabri-network')],'done'=>false];"
new="$lock='sn:f17:privacy:'.$uid;$raw_lock=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if(($wpdb->last_error ?? '')!==''||$raw_lock===null)return self::retry('Privacy erasure concurrency control could not be verified and must be retried.');$got=(int)$raw_lock;if($got!==1)return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Privacy erasure is already running. Retry this page.','sabri-network')],'done'=>false];"
assert old in t; t=t.replace(old,new,1)
old="} finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}"
new="} finally {$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(($wpdb->last_error ?? '')!==''||$released===null)do_action('sn_network_privacy_lock_release_failed',$uid,$lock,(string)($wpdb->last_error ?? ''));}"
assert old in t; t=t.replace(old,new,1)

old="global $wpdb;$table=SN_DB::table('messages');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,attachment_id,attachment_source FROM $table WHERE sender_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(!$rows)return null;$now=current_time('mysql',true);$attachments=[];"
new="global $wpdb;$table=SN_DB::table('messages');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,attachment_id,attachment_source FROM $table WHERE sender_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(($wpdb->last_error ?? '')!=='')return self::retry('Message-erasure enumeration could not be verified and must be retried.');if(!$rows)return null;$now=current_time('mysql',true);$attachments=[];"
assert old in t; t=t.replace(old,new,1)
old="foreach($rows as $row){$id=(int)$row->id;$locked=$wpdb->get_row($wpdb->prepare(\"SELECT id,sender_id,attachment_id,attachment_source FROM $table WHERE id=%d FOR UPDATE\",$id));if(!$locked||(int)$locked->sender_id!==$uid)continue;"
new="foreach($rows as $row){$id=(int)$row->id;$locked=$wpdb->get_row($wpdb->prepare(\"SELECT id,sender_id,attachment_id,attachment_source FROM $table WHERE id=%d FOR UPDATE\",$id));if(($wpdb->last_error ?? '')!=='')throw new RuntimeException('privacy_message_lock_read_failed');if(!$locked||(int)$locked->sender_id!==$uid)continue;"
assert old in t; t=t.replace(old,new,1)

old="global $wpdb;$updates=SN_DB::table('updates');$views=SN_DB::table('update_views');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,media_id,media_source FROM $updates WHERE user_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(!$rows)return null;$ids=[];$media=[];"
new="global $wpdb;$updates=SN_DB::table('updates');$views=SN_DB::table('update_views');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,media_id,media_source FROM $updates WHERE user_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(($wpdb->last_error ?? '')!=='')return self::retry('Temporary-update erasure enumeration could not be verified and must be retried.');if(!$rows)return null;$ids=[];$media=[];"
assert old in t; t=t.replace(old,new,1)

old="$owned_non_direct=array_values(array_filter(array_map('absint',$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $conversations WHERE owner_id=%d AND type<>'direct' AND status='active' ORDER BY id ASC\",$uid))?:[])));\n        $attachments=array_values(array_filter(array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.SN_DB::table('attachments').' WHERE owner_id=%d AND deleted_at IS NULL',$uid))?:[])));"
new="$owned_raw=$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $conversations WHERE owner_id=%d AND type<>'direct' AND status='active' ORDER BY id ASC\",$uid));if(($wpdb->last_error ?? '')!=='')return self::retry('Owned-conversation privacy snapshot could not be verified and must be retried.');$owned_non_direct=array_values(array_filter(array_map('absint',$owned_raw?:[])));\n        $attachments_raw=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.SN_DB::table('attachments').' WHERE owner_id=%d AND deleted_at IS NULL',$uid));if(($wpdb->last_error ?? '')!=='')return self::retry('Attachment privacy snapshot could not be verified and must be retried.');$attachments=array_values(array_filter(array_map('absint',$attachments_raw?:[])));"
assert old in t; t=t.replace(old,new,1)

old="$wpdb->get_results($wpdb->prepare(\"SELECT id,conversation_id,role FROM $members WHERE user_id=%d FOR UPDATE\",$uid));\n            $conversation_ids=array_map('intval',$wpdb->get_col($wpdb->prepare(\"SELECT conversation_id FROM $members WHERE user_id=%d\",$uid))?:[]);"
new="$locked_members=$wpdb->get_results($wpdb->prepare(\"SELECT id,conversation_id,role FROM $members WHERE user_id=%d FOR UPDATE\",$uid));if(($wpdb->last_error ?? '')!=='')throw new RuntimeException('privacy_membership_lock_read_failed');\n            $conversation_ids_raw=$wpdb->get_col($wpdb->prepare(\"SELECT conversation_id FROM $members WHERE user_id=%d\",$uid));if(($wpdb->last_error ?? '')!=='')throw new RuntimeException('privacy_membership_enumeration_failed');$conversation_ids=array_map('intval',$conversation_ids_raw?:[]);"
assert old in t; t=t.replace(old,new,1)

old="$call_ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT call_id FROM '.SN_DB::table('call_members').' WHERE user_id=%d',$uid))?:[]);\n            if($call_ids){$ph=implode(',',array_fill(0,count($call_ids),'%d'));$direct=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT c.id FROM '.SN_DB::table('calls').\" c INNER JOIN $conversations cv ON cv.id=c.conversation_id AND cv.type='direct' WHERE c.id IN ($ph)\",...$call_ids))?:[]);"
new="$call_ids_raw=$wpdb->get_col($wpdb->prepare('SELECT call_id FROM '.SN_DB::table('call_members').' WHERE user_id=%d',$uid));if(($wpdb->last_error ?? '')!=='')throw new RuntimeException('privacy_call_membership_enumeration_failed');$call_ids=array_map('intval',$call_ids_raw?:[]);\n            if($call_ids){$ph=implode(',',array_fill(0,count($call_ids),'%d'));$direct_raw=$wpdb->get_col($wpdb->prepare('SELECT c.id FROM '.SN_DB::table('calls').\" c INNER JOIN $conversations cv ON cv.id=c.conversation_id AND cv.type='direct' WHERE c.id IN ($ph)\",...$call_ids));if(($wpdb->last_error ?? '')!=='')throw new RuntimeException('privacy_direct_call_enumeration_failed');$direct=array_map('intval',$direct_raw?:[]);"
assert old in t; t=t.replace(old,new,1)

p.write_text(t,encoding='utf-8')

q=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; s=q.read_text(encoding='utf-8'); marker='\nif ($fail) {\n'; assert marker in s and '// Round 35 —' not in s
block=r'''

// Round 35 — privacy export/erasure preserves lock and destructive-snapshot database truth.
$privacy=$read('includes/class-sn-privacy-runtime-hardening.php');
$check(str_contains($privacy,'Privacy erasure concurrency control could not be verified') && str_contains($privacy,'$raw_lock===null') && str_contains($privacy,'sn_network_privacy_lock_release_failed'), 'Round 35: privacy lock acquisition/release DB uncertainty must be distinguished from ordinary contention.');
$check(str_contains($privacy,'Message-erasure enumeration could not be verified') && str_contains($privacy,'privacy_message_lock_read_failed') && str_contains($privacy,'Temporary-update erasure enumeration could not be verified'), 'Round 35: message/update erasure must not interpret failed enumeration or locked rereads as no work.');
$check(str_contains($privacy,'Owned-conversation privacy snapshot could not be verified') && str_contains($privacy,'Attachment privacy snapshot could not be verified') && str_contains($privacy,'privacy_membership_enumeration_failed') && str_contains($privacy,'privacy_call_membership_enumeration_failed') && str_contains($privacy,'privacy_direct_call_enumeration_failed'), 'Round 35: relational privacy deletion must not proceed from incomplete conversation/member/call snapshots.');
$check(str_contains($privacy,"$result['done']=false;return$result") || str_contains($privacy,"$result['done']=false;return$result;"), 'Round 35: privacy export enrichment DB failure must remain retryable instead of returning an apparently complete export.');
'''
q.write_text(s.replace(marker,block+marker,1),encoding='utf-8')
