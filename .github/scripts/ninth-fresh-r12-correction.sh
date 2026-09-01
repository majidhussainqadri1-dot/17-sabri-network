#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

def replace(rel, old, new, count=1):
    p=root/rel; t=p.read_text(encoding='utf-8'); n=t.count(old)
    if n < count: raise SystemExit(f'{rel}: expected {count}, found {n}: {old[:140]!r}')
    p.write_text(t.replace(old,new,count),encoding='utf-8')

replace('includes/class-sn-file-transfer-part-2.php',
"        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_bytes),0) FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND created_at>=%s AND status NOT IN (\\'rejected\\',\\'revoked\\',\\'expired\\')', $sender_id, $today));\n        if ($used + $total > $daily_limit) { return new WP_Error('daily_transfer_volume_exceeded', 'The transparent daily transfer volume limit has been reached.', ['status' => 429]); }\n",
"        $used_raw = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_bytes),0) FROM ' . self::sessions_table() . ' WHERE sender_id=%d AND created_at>=%s AND status NOT IN (\\'rejected\\',\\'revoked\\',\\'expired\\')', $sender_id, $today));\n        if ($wpdb->last_error !== '') { SN_DB::audit('file_transfer_quota_read_failed','file_transfer',0,'failure',['reason'=>(string)$wpdb->last_error],$sender_id); return new WP_Error('transfer_quota_unavailable', 'Transfer quota could not be verified safely. Retry later.', ['status'=>503]); }\n        $used = (int) $used_raw;\n        if ($used + $total > $daily_limit) { return new WP_Error('daily_transfer_volume_exceeded', 'The transparent daily transfer volume limit has been reached.', ['status' => 429]); }\n")

replace('includes/class-sn-file-transfer-part-7.php',
"    public static function health(): WP_REST_Response {global $wpdb;$missing=[];foreach([self::sessions_table(),self::chunks_table(),self::recipients_table()] as $table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)$missing[]=$table;$storage=self::ensure_storage();return rest_ensure_response(['ok'=>!$missing&&$storage,'schema_version'=>self::SCHEMA_VERSION,'missing_tables'=>$missing,'max_file_bytes'=>self::MAX_FILE_BYTES,'storage_ready'=>$storage,'scanner_connected'=>has_filter('sn_network_transfer_scan_result')]);}\n",
"    public static function health(): WP_REST_Response {global $wpdb;$missing=[];foreach([self::sessions_table(),self::chunks_table(),self::recipients_table()] as $table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)$missing[]=$table;$storage=self::ensure_storage();$scanner_ready=apply_filters('sn_network_transfer_scanner_ready',false)===true;return rest_ensure_response(['ok'=>!$missing&&$storage&&$scanner_ready,'schema_version'=>self::SCHEMA_VERSION,'missing_tables'=>$missing,'max_file_bytes'=>self::MAX_FILE_BYTES,'storage_ready'=>$storage,'scanner_connected'=>$scanner_ready,'scanner_readiness_contract'=>'sn_network_transfer_scanner_ready']);}\n")

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor="\nif ($fail) {\n"
if anchor not in t: raise SystemExit('suite anchor missing')
block=r'''
// Round 12 — transfer quota and scanner readiness truth fail closed.
$transfer2 = $read('includes/class-sn-file-transfer-part-2.php');
$transfer7 = $read('includes/class-sn-file-transfer-part-7.php');
$check(str_contains($transfer2, 'transfer_quota_unavailable') && str_contains($transfer2, 'file_transfer_quota_read_failed') && str_contains($transfer2, '$wpdb->last_error'), 'Round 12: daily transfer quota DB failure must not become zero usage.');
$check(str_contains($transfer7, "apply_filters('sn_network_transfer_scanner_ready',false)===true") && !str_contains($transfer7, "scanner_connected'=>has_filter") && str_contains($transfer7, "'ok'=>!\$missing&&!\$storage") === false && str_contains($transfer7, "'ok'=>!\$missing&&\$storage&&\$scanner_ready"), 'Round 12: scanner health requires an explicit readiness declaration, not hook presence.');
'''
p.write_text(t.replace(anchor,"\n"+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-file-transfer-part-2.php
php -l sabri-network/includes/class-sn-file-transfer-part-7.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
