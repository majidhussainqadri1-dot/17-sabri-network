from pathlib import Path
p=Path('sabri-network/tests/fifth-fresh-closure-contracts.php')
s=p.read_text(encoding='utf-8')
marker='if($fail){fwrite(STDERR,"Fifth fresh closure failures'
if 'Another fresh Round 5 — transfer privacy/integrity' not in s:
    pos=s.find(marker)
    if pos<0: raise SystemExit('R5 regression insertion marker missing')
    block=r'''
// Another fresh Round 5 — transfer privacy/integrity must fail closed.
$transfer2=(string)file_get_contents($root.'/includes/class-sn-file-transfer-part-2.php');
$transfer5=(string)file_get_contents($root.'/includes/class-sn-file-transfer-part-5.php');
$transfer6=(string)file_get_contents($root.'/includes/class-sn-file-transfer-part-6.php');
$transfer7=(string)file_get_contents($root.'/includes/class-sn-file-transfer-part-7.php');
$transfer8=(string)file_get_contents($root.'/includes/class-sn-file-transfer-part-8.php');
$check(str_contains($transfer2,'transfer_volume_read_failed')&&str_contains($transfer2,"$wpdb->last_error !== '' || $used_raw === null"),'Another R5: daily transfer-volume truth must fail closed on database read failure.');
$check(str_contains($transfer6,'recipient_ids_authoritative')&&str_contains($transfer6,'transfer_recipient_ledger_unavailable')&&str_contains($transfer6,'if(is_wp_error($recipients))return $recipients;'),'Another R5: sender authorization must fail closed when the authoritative recipient ledger cannot be read.');
$check(str_contains($transfer7,'file_transfer_chunk_ledger_read_failed')&&str_contains($transfer7,"$wpdb->last_error!==''||!is_array($rows)"),'Another R5: encrypted chunk cleanup must not treat a failed ledger read as successful deletion.');
$check(str_contains($transfer8,'private static function privacy_read_retry')&&substr_count($transfer8,"$wpdb->last_error!==''")>=5&&str_contains($transfer8,"'done'=>false"),'Another R5: transfer privacy batch/completion reads must remain retryable on database failure.');
$preflight=strpos($transfer5,"file_transfer_download_preflight_failed");
$successHeader=strpos($transfer5,'status_header($status)');
$firstEcho=strpos($transfer5,'echo $slice');
$check($preflight!==false&&$successHeader!==false&&$firstEcho!==false&&$preflight<$successHeader&&$successHeader<$firstEcho,'Another R5: encrypted download integrity preflight must complete before a success envelope or plaintext body is emitted.');
$check(str_contains($transfer5,"count($chunks)!==(int)$row->total_chunks")&&str_contains($transfer5,"if($offset!==$total)")&&substr_count($transfer5,'hash_equals((string)$chunk->sha256')>=2,'Another R5: download preflight must prove complete sequence, byte coverage and chunk hashes before streaming.');

'''
    s=s[:pos]+block+s[pos:]
p.write_text(s,encoding='utf-8')
