from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if text.count(old) != 1:
        raise SystemExit(f'{label}: expected one target, found {text.count(old)}')
    return text.replace(old, new, 1)

# Canonical privacy eraser: distinguish DB read failure from a genuinely empty set.
p = Path('sabri-network/includes/class-sn-privacy-runtime-hardening.php')
s = p.read_text(encoding='utf-8')
s = replace_once(s,
"global $wpdb;$table=SN_DB::table('messages');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,attachment_id,attachment_source FROM $table WHERE sender_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(!$rows)return null;$now=current_time('mysql',true);$attachments=[];",
"global $wpdb;$table=SN_DB::table('messages');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,attachment_id,attachment_source FROM $table WHERE sender_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(!is_array($rows))return self::retry('Message privacy erasure could not read its next batch safely.');if(!$rows)return null;$now=current_time('mysql',true);$attachments=[];",
'privacy messages read')
s = replace_once(s,
"global $wpdb;$updates=SN_DB::table('updates');$views=SN_DB::table('update_views');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,media_id,media_source FROM $updates WHERE user_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(!$rows)return null;$ids=[];$media=[];",
"global $wpdb;$updates=SN_DB::table('updates');$views=SN_DB::table('update_views');$rows=$wpdb->get_results($wpdb->prepare(\"SELECT id,media_id,media_source FROM $updates WHERE user_id=%d ORDER BY id ASC LIMIT %d\",$uid,self::BATCH));if(!is_array($rows))return self::retry('Temporary-update privacy erasure could not read its next batch safely.');if(!$rows)return null;$ids=[];$media=[];",
'privacy updates read')
s = replace_once(s,
"$owned_non_direct=array_values(array_filter(array_map('absint',$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $conversations WHERE owner_id=%d AND type<>'direct' AND status='active' ORDER BY id ASC\",$uid))?:[])));\n        $attachments=array_values(array_filter(array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.SN_DB::table('attachments').' WHERE owner_id=%d AND deleted_at IS NULL',$uid))?:[])));",
"$owned_raw=$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $conversations WHERE owner_id=%d AND type<>'direct' AND status='active' ORDER BY id ASC\",$uid));if(!is_array($owned_raw))return self::retry('Relational privacy erasure could not read owned conversations safely.');$owned_non_direct=array_values(array_filter(array_map('absint',$owned_raw)));\n        $attachment_raw=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.SN_DB::table('attachments').' WHERE owner_id=%d AND deleted_at IS NULL',$uid));if(!is_array($attachment_raw))return self::retry('Relational privacy erasure could not read private attachments safely.');$attachments=array_values(array_filter(array_map('absint',$attachment_raw)));",
'privacy relational preflight')
s = replace_once(s,
"$wpdb->get_results($wpdb->prepare(\"SELECT id,conversation_id,role FROM $members WHERE user_id=%d FOR UPDATE\",$uid));\n            $conversation_ids=array_map('intval',$wpdb->get_col($wpdb->prepare(\"SELECT conversation_id FROM $members WHERE user_id=%d\",$uid))?:[]);",
"$locked_members=$wpdb->get_results($wpdb->prepare(\"SELECT id,conversation_id,role FROM $members WHERE user_id=%d FOR UPDATE\",$uid));if(!is_array($locked_members))throw new RuntimeException('privacy_membership_lock_read_failed');\n            $conversation_raw=$wpdb->get_col($wpdb->prepare(\"SELECT conversation_id FROM $members WHERE user_id=%d\",$uid));if(!is_array($conversation_raw))throw new RuntimeException('privacy_membership_read_failed');$conversation_ids=array_map('intval',$conversation_raw);",
'privacy membership lock')
s = replace_once(s,
"$call_ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT call_id FROM '.SN_DB::table('call_members').' WHERE user_id=%d',$uid))?:[]);\n            if($call_ids){$ph=implode(',',array_fill(0,count($call_ids),'%d'));$direct=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT c.id FROM '.SN_DB::table('calls').\" c INNER JOIN $conversations cv ON cv.id=c.conversation_id AND cv.type='direct' WHERE c.id IN ($ph)\",...$call_ids))?:[]);",
"$call_raw=$wpdb->get_col($wpdb->prepare('SELECT call_id FROM '.SN_DB::table('call_members').' WHERE user_id=%d',$uid));if(!is_array($call_raw))throw new RuntimeException('privacy_call_membership_read_failed');$call_ids=array_map('intval',$call_raw);\n            if($call_ids){$ph=implode(',',array_fill(0,count($call_ids),'%d'));$direct_raw=$wpdb->get_col($wpdb->prepare('SELECT c.id FROM '.SN_DB::table('calls').\" c INNER JOIN $conversations cv ON cv.id=c.conversation_id AND cv.type='direct' WHERE c.id IN ($ph)\",...$call_ids));if(!is_array($direct_raw))throw new RuntimeException('privacy_direct_call_read_failed');$direct=array_map('intval',$direct_raw);",
'privacy calls read')

wrapper_old = "                return $result;\n            };"
wrapper_new = """                if ($user && !empty($result['done'])) {
                    $verified = self::verify_erasure_completion($eraser_key, (int)$user->ID);
                    if (is_wp_error($verified)) {
                        return ['items_removed'=>(bool)($result['items_removed'] ?? false),'items_retained'=>true,'messages'=>array_values(array_unique(array_merge((array)($result['messages'] ?? []),[__('Privacy completion could not be verified and must be retried.','sabri-network')]))),'done'=>false];
                    }
                    if ($verified !== true) {
                        $result['items_retained'] = true;
                        $result['done'] = false;
                        $result['messages'] = array_values(array_unique(array_merge((array)($result['messages'] ?? []),[__('Additional File 17 personal data remains and will be erased in a later batch.','sabri-network')])));
                    }
                }
                return $result;
            };"""
if 'verify_erasure_completion($eraser_key' not in s:
    s = replace_once(s, wrapper_old, wrapper_new, 'privacy final wrapper')

marker = "    private static function must_delete(string $table,array $where,array $where_format): void {global $wpdb;if($wpdb->delete($table,$where,$where_format)===false)throw new RuntimeException('privacy_delete_failed');}"
helper = """    private static function verify_erasure_completion(string $eraser_key,int $uid): bool|WP_Error {
        global $wpdb;
        $q=null;
        switch($eraser_key){
            case 'sabri-network-contexts': $q=$wpdb->prepare('SELECT 1 FROM '.SN_DB::table('conversation_contexts').' WHERE attached_by=%d LIMIT 1',$uid);break;
            case 'sabri-network-cf01-references': $q=$wpdb->prepare(\"SELECT 1 FROM \".SN_DB::table('cf01_context_refs').\" WHERE issued_by=%d AND status='active' LIMIT 1\",$uid);break;
            case 'sabri-network-smail': $q=$wpdb->prepare(\"SELECT 1 FROM \".SN_DB::table('smail_states').\" WHERE user_id=%d LIMIT 1 UNION ALL SELECT 1 FROM \".SN_DB::table('smail_drafts').\" WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1\",$uid,$uid);break;
            case 'sabri-network-presence-devices': $q=$wpdb->prepare('SELECT 1 FROM '.SN_DB::table('presence_devices').' WHERE user_id=%d LIMIT 1',$uid);break;
            case 'sabri-network-transfers': $q=$wpdb->prepare(\"SELECT 1 FROM \".SN_DB::table('transfer_sessions').\" WHERE sender_id=%d AND status IN ('revoked','expired','rejected') LIMIT 1 UNION ALL SELECT 1 FROM \".SN_DB::table('transfer_recipients').\" WHERE user_id=%d LIMIT 1\",$uid,$uid);break;
            case 'sabri-network-message-receipts': $q=$wpdb->prepare('SELECT 1 FROM '.SN_DB::table('message_receipts').' WHERE user_id=%d LIMIT 1',$uid);break;
            case 'sabri-network-message-organization': $q=$wpdb->prepare('SELECT 1 FROM '.SN_DB::table('message_folder_items').' WHERE user_id=%d LIMIT 1 UNION ALL SELECT 1 FROM '.SN_DB::table('message_folders').' WHERE user_id=%d LIMIT 1 UNION ALL SELECT 1 FROM '.SN_DB::table('message_stars').' WHERE user_id=%d LIMIT 1 UNION ALL SELECT 1 FROM '.SN_DB::table('message_hides').' WHERE user_id=%d LIMIT 1',$uid,$uid,$uid,$uid);break;
            case 'sabri-network-two-plan': $q=$wpdb->prepare(\"SELECT 1 FROM \".SN_DB::table('scheduled_messages').\" WHERE sender_id=%d AND status IN ('pending','cancelled','failed') LIMIT 1 UNION ALL SELECT 1 FROM \".SN_DB::table('message_requests').\" WHERE requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>'' LIMIT 1 UNION ALL SELECT 1 FROM \".SN_DB::table('poll_votes').\" pv WHERE pv.user_id=%d AND NOT EXISTS (SELECT 1 FROM \".SN_DB::table('reports').\" r WHERE r.message_id=pv.message_id AND r.legal_hold=1) LIMIT 1\",$uid,$uid,$uid);break;
            case 'sabri-network-future': $q=$wpdb->prepare(\"SELECT 1 FROM {$wpdb->prefix}sn_future_records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') LIMIT 1 UNION ALL SELECT 1 FROM {$wpdb->prefix}sn_future_device_keys WHERE user_id=%d LIMIT 1\",$uid,$uid);break;
            default:return true;
        }
        $wpdb->last_error='';
        $pending=$wpdb->get_var($q);
        if($wpdb->last_error!=='')return new WP_Error('sn_privacy_completion_read_failed','Privacy completion could not be verified safely.',['status'=>503]);
        return $pending===null;
    }

"""
if 'private static function verify_erasure_completion' not in s:
    s = replace_once(s, marker, helper + marker, 'privacy completion helper')
p.write_text(s, encoding='utf-8')

# Future message-version eraser: invalid reads must not look like empty batches.
p = Path('sabri-network/includes/class-sn-sixth-fresh-privacy-hardening.php')
s = p.read_text(encoding='utf-8')
s = replace_once(s,
"""        $retained = (int) $wpdb->get_var($wpdb->prepare(
            \"SELECT COUNT(*) FROM $records WHERE owner_id=%d AND feature_id IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')\",
            $uid
        ));""",
"""        $wpdb->last_error = '';
        $retained_raw = $wpdb->get_var($wpdb->prepare(
            \"SELECT COUNT(*) FROM $records WHERE owner_id=%d AND feature_id IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased')\",
            $uid
        ));
        if ($wpdb->last_error !== '') return self::retry('Future retained-data privacy truth could not be read safely.');
        $retained = (int) $retained_raw;""", 'future retained read')
rows_block = """        $rows = $wpdb->get_results($wpdb->prepare(
            \"SELECT id FROM $records WHERE owner_id=%d AND feature_id NOT IN ('F17-FUT-03','F17-FUT-24') AND state NOT IN ('deleted','erased') ORDER BY id ASC LIMIT %d\",
            $uid,
            self::BATCH
        ));"""
if "Future-capability erasure could not read its next record batch safely." not in s:
    s = replace_once(s, rows_block, rows_block + "\n        if (!is_array($rows)) return self::retry('Future-capability erasure could not read its next record batch safely.');", 'future record batch')
scan_block = """        $scan = $wpdb->get_results($wpdb->prepare(
            \"SELECT v.id,v.message_id FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d AND v.id>%d ORDER BY v.id ASC LIMIT %d\",
            $uid,
            $cursor,
            self::VERSION_SCAN
        ));"""
if "Message-version privacy erasure could not read its scan safely." not in s:
    s = replace_once(s, scan_block, scan_block + "\n        if (!is_array($scan)) return self::retry('Message-version privacy erasure could not read its scan safely.');", 'future version scan')
s = replace_once(s,
"""        $remaining_versions = (int) $wpdb->get_var($wpdb->prepare(
            \"SELECT COUNT(*) FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d\",
            $uid
        ));""",
"""        $wpdb->last_error = '';
        $remaining_versions_raw = $wpdb->get_var($wpdb->prepare(
            \"SELECT COUNT(*) FROM $versions v INNER JOIN $messages m ON m.id=v.message_id WHERE m.sender_id=%d\",
            $uid
        ));
        if ($wpdb->last_error !== '') return self::retry('Message-version retained-data truth could not be verified safely.');
        $remaining_versions = (int) $remaining_versions_raw;""", 'future version retained truth')
p.write_text(s, encoding='utf-8')

# Final device-key eraser: both pending-key and retained transparency reads are checked.
p = Path('sabri-network/includes/class-sn-r9-runtime-hardening.php')
s = p.read_text(encoding='utf-8')
s = replace_once(s,
"""        $more_keys = (bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $table WHERE user_id=%d LIMIT 1\", $uid));
        $key_log_count = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sn_future_key_log WHERE user_id=%d',
            $uid
        ));""",
"""        $wpdb->last_error = '';
        $more_keys_raw = $wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $table WHERE user_id=%d LIMIT 1\", $uid));
        if ($wpdb->last_error !== '') return self::retry('Device-key privacy completion could not be verified safely.');
        $more_keys = (bool)$more_keys_raw;
        $wpdb->last_error = '';
        $key_log_raw = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sn_future_key_log WHERE user_id=%d',
            $uid
        ));
        if ($wpdb->last_error !== '') return self::retry('Key-transparency retained-data truth could not be verified safely.');
        $key_log_count = (int)$key_log_raw;""", 'future device-key completion')
p.write_text(s, encoding='utf-8')
