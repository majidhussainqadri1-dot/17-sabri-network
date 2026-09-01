from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def replace(path: str, old: str, new: str) -> None:
    p = ROOT / path
    text = p.read_text()
    if old not in text:
        raise SystemExit(f"missing replacement target: {path}: {old[:120]!r}")
    p.write_text(text.replace(old, new, 1))

# Part 2 — initiation/idempotency database truth.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-2.php',
    "$existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND idempotency_key=%s', $sender_id, $idempotency));\n        if ($existing) {\n            if (!self::same_initiation($existing, $recipients, $name, $declared_mime, $total, $chunk_bytes, $conversation_id, $expected)) {",
    "$existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND idempotency_key=%s', $sender_id, $idempotency));\n        if ($wpdb->last_error !== '') { SN_DB::audit('file_transfer_idempotency_lookup_failed','file_transfer',0,'failure',['reason'=>(string)$wpdb->last_error],$sender_id); return new WP_Error('transfer_state_unavailable', 'Transfer idempotency state could not be verified safely.', ['status'=>503]); }\n        if ($existing) {\n            $same = self::same_initiation($existing, $recipients, $name, $declared_mime, $total, $chunk_bytes, $conversation_id, $expected);\n            if (is_wp_error($same)) { return $same; }\n            if (!$same) {"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-2.php',
    "            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND idempotency_key=%s', $sender_id, $idempotency));\n            if ($race && self::same_initiation($race, $recipients, $name, $declared_mime, $total, $chunk_bytes, $conversation_id, $expected)) {\n                return rest_ensure_response(['transfer'=>self::format($race,$sender_id),'duplicate'=>true,'commit_reconciled'=>true]);\n            }\n            if ($race) {",
    "            $race = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND idempotency_key=%s', $sender_id, $idempotency));\n            if ($wpdb->last_error !== '') { SN_DB::audit('file_transfer_reconciliation_read_failed','file_transfer',$transfer_id,'failure',['reason'=>(string)$wpdb->last_error],$sender_id); return new WP_Error('transfer_state_unavailable', 'Transfer commit state could not be reconciled safely.', ['status'=>503]); }\n            if ($race) {\n                $same = self::same_initiation($race, $recipients, $name, $declared_mime, $total, $chunk_bytes, $conversation_id, $expected);\n                if (is_wp_error($same)) { return $same; }\n                if ($same) { return rest_ensure_response(['transfer'=>self::format($race,$sender_id),'duplicate'=>true,'commit_reconciled'=>true]); }\n            }\n            if ($race) {"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-2.php',
    "        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE id=%d', $transfer_id));\n        return rest_ensure_response(['transfer' => self::format($row, $sender_id)]);",
    "        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessions_table() . ' WHERE id=%d', $transfer_id));\n        if ($wpdb->last_error !== '' || !$row) { SN_DB::audit('file_transfer_post_commit_read_failed','file_transfer',$transfer_id,'failure',['reason'=>(string)$wpdb->last_error],$sender_id); return new WP_Error('transfer_state_unavailable', 'The committed transfer state could not be re-read safely.', ['status'=>503]); }\n        return rest_ensure_response(['transfer' => self::format($row, $sender_id)]);"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-2.php',
    "    private static function same_initiation(object $row, array $recipients, string $name, string $declared_mime, int $total, int $chunk_bytes, int $conversation_id, string $expected): bool {\n        global $wpdb;\n        $stored = array_values(array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . self::recipients_table() . ' WHERE transfer_id=%d ORDER BY user_id ASC', (int) $row->id)) ?: []));",
    "    private static function same_initiation(object $row, array $recipients, string $name, string $declared_mime, int $total, int $chunk_bytes, int $conversation_id, string $expected): bool|WP_Error {\n        global $wpdb;\n        $stored_raw = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . self::recipients_table() . ' WHERE transfer_id=%d ORDER BY user_id ASC', (int) $row->id));\n        if ($wpdb->last_error !== '') { SN_DB::audit('file_transfer_idempotency_recipient_read_failed','file_transfer',(int)$row->id,'failure',['reason'=>(string)$wpdb->last_error]); return new WP_Error('transfer_state_unavailable', 'Transfer recipient state could not be verified safely.', ['status'=>503]); }\n        $stored = array_values(array_map('intval', is_array($stored_raw) ? $stored_raw : []));"
)

# Part 3 — chunk lookup/lock/reconciliation truth.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-3.php',
    "$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d AND chunk_index=%d',(int)$row->id,$index));\n        if($existing)return",
    "$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d AND chunk_index=%d',(int)$row->id,$index));\n        if($wpdb->last_error!==''){SN_DB::audit('file_transfer_chunk_lookup_failed','file_transfer',(int)$row->id,'failure',['index'=>$index,'reason'=>(string)$wpdb->last_error],$user_id);return new WP_Error('transfer_state_unavailable','Transfer chunk state could not be verified safely.',['status'=>503]);}\n        if($existing)return"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-3.php',
    "$current=$wpdb->get_row($wpdb->prepare('SELECT id,status,expires_at FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));\n            if(!$current||(string)$current->status!=='uploading'",
    "$current=$wpdb->get_row($wpdb->prepare('SELECT id,status,expires_at FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));\n            if($wpdb->last_error!=='')throw new RuntimeException('chunk_session_read_failed');\n            if(!$current||(string)$current->status!=='uploading'"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-3.php',
    "$wpdb->query('ROLLBACK');@unlink($path);$race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d AND chunk_index=%d',(int)$row->id,$index));\n            if($race&&hash_equals",
    "$wpdb->query('ROLLBACK');@unlink($path);$race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d AND chunk_index=%d',(int)$row->id,$index));\n            if($wpdb->last_error!==''){SN_DB::audit('file_transfer_chunk_reconciliation_read_failed','file_transfer',(int)$row->id,'failure',['index'=>$index,'reason'=>(string)$wpdb->last_error],$user_id);return new WP_Error('transfer_state_unavailable','Transfer chunk commit state could not be reconciled safely.',['status'=>503]);}\n            if($race&&hash_equals"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-3.php',
    "return new WP_Error($e->getMessage()==='chunk_session_changed'?'transfer_not_uploadable':'chunk_store_failed','The encrypted transfer chunk could not be committed.',['status'=>$e->getMessage()==='chunk_session_changed'?409:500]);",
    "return new WP_Error($e->getMessage()==='chunk_session_changed'?'transfer_not_uploadable':($e->getMessage()==='chunk_session_read_failed'?'transfer_state_unavailable':'chunk_store_failed'),'The encrypted transfer chunk could not be committed.',['status'=>$e->getMessage()==='chunk_session_changed'?409:($e->getMessage()==='chunk_session_read_failed'?503:500)]);"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-3.php',
    "$fresh=self::session((string)$row->public_id);return rest_ensure_response(['accepted'=>true,'index'=>$index,'sha256'=>$sha,'received_bytes'=>$fresh?(int)$fresh->received_bytes:(int)$row->received_bytes+$bytes]);",
    "$fresh=self::session((string)$row->public_id);if($wpdb->last_error!==''||!$fresh){SN_DB::audit('file_transfer_chunk_post_commit_read_failed','file_transfer',(int)$row->id,'failure',['index'=>$index,'reason'=>(string)$wpdb->last_error],$user_id);return new WP_Error('transfer_state_unavailable','The committed chunk state could not be re-read safely.',['status'=>503]);}return rest_ensure_response(['accepted'=>true,'index'=>$index,'sha256'=>$sha,'received_bytes'=>(int)$fresh->received_bytes]);"
)

# Part 4 — finalize/list/status/grant database truth.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-4.php',
    "$row=self::session((string)$request['public_id']);$sender=get_current_user_id();\n        if(!$row||(int)$row->sender_id!==$sender)return self::not_found();",
    "$row=self::session((string)$request['public_id']);$sender=get_current_user_id();\n        if($wpdb->last_error!=='')return new WP_Error('transfer_state_unavailable','Transfer state could not be verified safely.',['status'=>503]);\n        if(!$row||(int)$row->sender_id!==$sender)return self::not_found();"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-4.php',
    "$chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',(int)$row->id));\n        if(count($chunks?:[])!==(int)$row->total_chunks)",
    "$chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',(int)$row->id));\n        if($wpdb->last_error!==''||!is_array($chunks)){SN_DB::audit('file_transfer_finalize_chunk_read_failed','file_transfer',(int)$row->id,'failure',['reason'=>(string)$wpdb->last_error],$sender);return new WP_Error('transfer_state_unavailable','Transfer chunk state could not be verified safely.',['status'=>503]);}\n        if(count($chunks)!==(int)$row->total_chunks)"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-4.php',
    "$locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));\n            if(!$locked)throw new RuntimeException('transfer_missing');",
    "$locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));\n            if($wpdb->last_error!=='')throw new RuntimeException('transfer_finalize_read_failed');\n            if(!$locked)throw new RuntimeException('transfer_missing');"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-4.php',
    "$wpdb->query('ROLLBACK');$fresh=self::session((string)$row->public_id);\n            if($fresh&&(string)$fresh->status==='ready'",
    "$wpdb->query('ROLLBACK');$fresh=self::session((string)$row->public_id);\n            if($wpdb->last_error!==''){SN_DB::audit('file_transfer_finalize_reconciliation_session_read_failed','file_transfer',(int)$row->id,'failure',['reason'=>(string)$wpdb->last_error],$sender);return new WP_Error('transfer_state_unavailable','Transfer finalization state could not be reconciled safely.',['status'=>503]);}\n            if($fresh&&(string)$fresh->status==='ready'"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-4.php',
    "    public static function list_transfers(WP_REST_Request $request): WP_REST_Response {global $wpdb;$user=get_current_user_id();$box=sanitize_key((string)$request->get_param('box'))==='sent'?'sent':'inbox';if($box==='sent')$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE sender_id=%d ORDER BY id DESC LIMIT 100',$user));else$rows=$wpdb->get_results($wpdb->prepare('SELECT s.* FROM '.self::sessions_table().' s INNER JOIN '.self::recipients_table().' r ON r.transfer_id=s.id AND r.user_id=%d AND r.revoked_at IS NULL ORDER BY s.id DESC LIMIT 100',$user));return rest_ensure_response(['box'=>$box,'transfers'=>array_map(fn($r):array=>self::format($r,$user),$rows?:[])]);}",
    "    public static function list_transfers(WP_REST_Request $request): WP_REST_Response|WP_Error {global $wpdb;$user=get_current_user_id();$box=sanitize_key((string)$request->get_param('box'))==='sent'?'sent':'inbox';if($box==='sent')$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE sender_id=%d ORDER BY id DESC LIMIT 100',$user));else$rows=$wpdb->get_results($wpdb->prepare('SELECT s.* FROM '.self::sessions_table().' s INNER JOIN '.self::recipients_table().' r ON r.transfer_id=s.id AND r.user_id=%d AND r.revoked_at IS NULL ORDER BY s.id DESC LIMIT 100',$user));if($wpdb->last_error!==''||!is_array($rows)){SN_DB::audit('file_transfer_list_read_failed','user',$user,'failure',['reason'=>(string)$wpdb->last_error],$user);return new WP_Error('transfer_state_unavailable','Transfer list state could not be verified safely.',['status'=>503]);}return rest_ensure_response(['box'=>$box,'transfers'=>array_map(fn($r):array=>self::format($r,$user),$rows)]);}" 
)

# Part 5 — revoke/download/materialization reads.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-5.php',
    "global $wpdb;$row=self::session((string)$request['public_id']);$user=get_current_user_id();if(!$row||(int)$row->sender_id!==$user)return self::not_found();",
    "global $wpdb;$row=self::session((string)$request['public_id']);$user=get_current_user_id();if($wpdb->last_error!=='')return new WP_Error('transfer_state_unavailable','Transfer revocation state could not be verified safely.',['status'=>503]);if(!$row||(int)$row->sender_id!==$user)return self::not_found();"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-5.php',
    "$locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));if(!$locked)throw new RuntimeException('transfer_missing');",
    "$locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));if($wpdb->last_error!=='')throw new RuntimeException('transfer_revoke_read_failed');if(!$locked)throw new RuntimeException('transfer_missing');"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-5.php',
    "        status_header($status);nocache_headers();header('Content-Type: '.($row->detected_mime?:'application/octet-stream'));",
    "        $chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',(int)$row->id));if($wpdb->last_error!==''||!is_array($chunks)||count($chunks)!==(int)$row->total_chunks){SN_DB::audit('file_transfer_download_snapshot_failed','file_transfer',(int)$row->id,'failure',['reason'=>(string)$wpdb->last_error],$user);status_header(503);nocache_headers();return;}\n        status_header($status);nocache_headers();header('Content-Type: '.($row->detected_mime?:'application/octet-stream'));"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-5.php',
    "$chunks=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::chunks_table().' WHERE transfer_id=%d ORDER BY chunk_index ASC',(int)$row->id));$offset=0;$sent=0;$failed=false;$expected_index=0;",
    "$offset=0;$sent=0;$failed=false;$expected_index=0;"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-5.php',
    "global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$transfer_id));if(!$row||",
    "global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$transfer_id));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_scan_session_read_failed','file_transfer',$transfer_id,'failure',['reason'=>(string)$wpdb->last_error]);return new WP_Error('transfer_state_unavailable','Transfer scan state could not be verified safely.',['status'=>503]);}if(!$row||"
)

# Part 6 — conversation recipient enumeration fails closed.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-6.php',
    "if($conversation){if(!SN_DB::is_member($conversation,$sender))return new WP_Error('conversation_membership_required','An active conversation membership is required.',['status'=>403]);$ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND left_at IS NULL AND user_id<>%d',$conversation,$sender)));}",
    "if($conversation){if(!SN_DB::is_member($conversation,$sender))return new WP_Error('conversation_membership_required','An active conversation membership is required.',['status'=>403]);$raw_ids=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND left_at IS NULL AND user_id<>%d',$conversation,$sender));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_recipient_enumeration_failed','conversation',$conversation,'failure',['reason'=>(string)$wpdb->last_error],$sender);return new WP_Error('transfer_recipient_state_unavailable','Conversation recipient state could not be verified safely.',['status'=>503]);}$ids=array_map('intval',is_array($raw_ids)?$raw_ids:[]);}" 
)

# Part 7 — cleanup/export read failures remain observable/retryable.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-7.php',
    "$rows=$wpdb->get_results($wpdb->prepare(\"SELECT s.id,s.status FROM $sessions s WHERE (s.expires_at<%s AND s.status NOT IN ('expired','revoked','rejected')) OR (s.status IN ('expired','revoked','rejected') AND EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) ORDER BY s.id ASC LIMIT 100\",$now));\n        foreach(is_array($rows)?$rows:[] as $row){",
    "$rows=$wpdb->get_results($wpdb->prepare(\"SELECT s.id,s.status FROM $sessions s WHERE (s.expires_at<%s AND s.status NOT IN ('expired','revoked','rejected')) OR (s.status IN ('expired','revoked','rejected') AND EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) ORDER BY s.id ASC LIMIT 100\",$now));\n        if($wpdb->last_error!==''||!is_array($rows)){SN_DB::audit('file_transfer_cleanup_enumeration_failed','file_transfer',0,'failure',['reason'=>(string)$wpdb->last_error]);return;}\n        foreach($rows as $row){"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-7.php',
    "$rows=$wpdb->get_results($wpdb->prepare('SELECT DISTINCT s.* FROM '.self::sessions_table().' s LEFT JOIN '.self::recipients_table().' r ON r.transfer_id=s.id WHERE s.sender_id=%d OR r.user_id=%d ORDER BY s.id DESC LIMIT 100 OFFSET %d',$user->ID,$user->ID,max(0,($page-1)*100)));$data=array_map",
    "$rows=$wpdb->get_results($wpdb->prepare('SELECT DISTINCT s.* FROM '.self::sessions_table().' s LEFT JOIN '.self::recipients_table().' r ON r.transfer_id=s.id WHERE s.sender_id=%d OR r.user_id=%d ORDER BY s.id DESC LIMIT 100 OFFSET %d',$user->ID,$user->ID,max(0,($page-1)*100)));if($wpdb->last_error!==''||!is_array($rows)){SN_DB::audit('file_transfer_privacy_export_read_failed','user',(int)$user->ID,'failure',['reason'=>(string)$wpdb->last_error],(int)$user->ID);return['data'=>[],'done'=>false];}$data=array_map"
)

# Part 8 — privacy erasure must not claim completion from failed reads.
replace(
    'sabri-network/includes/class-sn-file-transfer-part-8.php',
    "$sent_rows=is_array($sent_rows)?$sent_rows:[];\n        $sent=array_map",
    "if($wpdb->last_error!==''||!is_array($sent_rows)){SN_DB::audit('file_transfer_privacy_sent_enumeration_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer erasure enumeration could not be verified and must be retried.'],'done'=>false];}\n        $sent=array_map"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-8.php',
    "$received=array_map('intval',$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $recipients WHERE user_id=%d AND state<>'erased' ORDER BY id ASC LIMIT %d\",$uid,$page_size))?:[]);",
    "$received_raw=$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $recipients WHERE user_id=%d AND state<>'erased' ORDER BY id ASC LIMIT %d\",$uid,$page_size));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_received_enumeration_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer recipient erasure enumeration could not be verified and must be retried.'],'done'=>false];}$received=array_map('intval',is_array($received_raw)?$received_raw:[]);"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-8.php',
    "$had_chunks=(bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $chunks WHERE transfer_id=%d LIMIT 1\",$id));\n            if($had_chunks&&self::delete_chunks($id))$removed=true;",
    "$had_chunks_raw=$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $chunks WHERE transfer_id=%d LIMIT 1\",$id));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_chunk_probe_failed','file_transfer',$id,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Private transfer byte-erasure state could not be verified and must be retried.'],'done'=>false];}$had_chunks=(bool)$had_chunks_raw;\n            if($had_chunks&&self::delete_chunks($id))$removed=true;"
)
replace(
    'sabri-network/includes/class-sn-file-transfer-part-8.php',
    "$more_sent=(bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $sessions s WHERE s.sender_id=%d AND (s.status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) LIMIT 1\",$uid));\n        $more_received=(bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $recipients WHERE user_id=%d AND state<>'erased' LIMIT 1\",$uid));",
    "$more_sent_raw=$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $sessions s WHERE s.sender_id=%d AND (s.status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) LIMIT 1\",$uid));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_completion_sent_read_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Private transfer erasure completion could not be verified and must be retried.'],'done'=>false];}$more_sent=(bool)$more_sent_raw;\n        $more_received_raw=$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $recipients WHERE user_id=%d AND state<>'erased' LIMIT 1\",$uid));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_completion_received_read_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Private transfer recipient erasure completion could not be verified and must be retried.'],'done'=>false];}$more_received=(bool)$more_received_raw;"
)

# Permanent Round 36 contracts.
test = ROOT / 'sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
text = test.read_text()
marker = "\nif ($fail) {"
if marker not in text:
    raise SystemExit('test insertion marker missing')
block = r'''

// Round 36 — private 1 GiB transfer workflow preserves database truth across initiation, chunks, finalize, download, cleanup and privacy erasure.
$t2=$read('includes/class-sn-file-transfer-part-2.php');$t3=$read('includes/class-sn-file-transfer-part-3.php');$t4=$read('includes/class-sn-file-transfer-part-4.php');$t5=$read('includes/class-sn-file-transfer-part-5.php');$t6=$read('includes/class-sn-file-transfer-part-6.php');$t7=$read('includes/class-sn-file-transfer-part-7.php');$t8=$read('includes/class-sn-file-transfer-part-8.php');
$check(str_contains($t2,'file_transfer_idempotency_lookup_failed') && str_contains($t2,'file_transfer_idempotency_recipient_read_failed') && str_contains($t2,'file_transfer_post_commit_read_failed'), 'Round 36: transfer initiation/idempotency DB uncertainty must fail closed.');
$check(str_contains($t3,'file_transfer_chunk_lookup_failed') && str_contains($t3,'chunk_session_read_failed') && str_contains($t3,'file_transfer_chunk_reconciliation_read_failed') && str_contains($t3,'file_transfer_chunk_post_commit_read_failed'), 'Round 36: chunk lookup/lock/reconciliation/post-commit reads must preserve DB truth.');
$check(str_contains($t4,'file_transfer_finalize_chunk_read_failed') && str_contains($t4,'transfer_finalize_read_failed') && str_contains($t4,'file_transfer_finalize_reconciliation_session_read_failed') && str_contains($t4,'file_transfer_list_read_failed'), 'Round 36: finalization and transfer listing must not convert failed reads into ordinary state.');
$check(str_contains($t5,'file_transfer_download_snapshot_failed') && str_contains($t5,'file_transfer_scan_session_read_failed') && strpos($t5,'file_transfer_download_snapshot_failed') < strpos($t5,"header('Content-Type:"), 'Round 36: download/scan must verify canonical DB snapshot before emitting private bytes.');
$check(str_contains($t6,'file_transfer_recipient_enumeration_failed') && str_contains($t7,'file_transfer_cleanup_enumeration_failed') && str_contains($t7,'file_transfer_privacy_export_read_failed'), 'Round 36: recipient enumeration, cleanup and privacy export DB failures must be observable and retryable.');
$check(str_contains($t8,'file_transfer_privacy_sent_enumeration_failed') && str_contains($t8,'file_transfer_privacy_received_enumeration_failed') && str_contains($t8,'file_transfer_privacy_chunk_probe_failed') && str_contains($t8,'file_transfer_privacy_completion_received_read_failed'), 'Round 36: transfer privacy erasure must never claim completion from failed enumeration/probe/completion reads.');
'''
if 'Round 36 — private 1 GiB transfer workflow' not in text:
    test.write_text(text.replace(marker, block + marker, 1))
