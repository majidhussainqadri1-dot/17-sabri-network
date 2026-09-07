from pathlib import Path

# R05-D01: central migration success also proves every installer-local version option.
p=Path('sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php')
s=p.read_text(encoding='utf-8')
s=s.replace("if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) {\n            $state", "if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema() && self::verify_version_truth()) {\n            $state", 1)
s=s.replace("if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) {\n                // Another request", "if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema() && self::verify_version_truth()) {\n                // Another request", 1)
s=s.replace("            if (!self::verify_schema()) throw new RuntimeException('schema_verification_failed');\n            update_option('sn_plugin_version', SN_VERSION, false);", "            if (!self::verify_schema()) throw new RuntimeException('schema_verification_failed');\n            if (!self::verify_version_truth()) throw new RuntimeException('schema_version_truth_failed');\n            update_option('sn_plugin_version', SN_VERSION, false);", 1)
needle="""    private static function installers(): array {
"""
method="""    /** Installer-local versions are part of migration truth, not independent upgrade authority. */
    private static function verify_version_truth(): bool {
        $required = [
            'sn_db_version'=>'2.0.4',
            'sn_high_risk_schema_version'=>'1.0.0',
            'sn_spaces_schema_version'=>'2.0.0',
            'sn_presence_devices_schema_version'=>'1.0.0',
            'sn_message_operations_schema_version'=>'1.0.0',
            'sn_context_adapters_schema_version'=>'1.0.0',
            'sn_cf01_context_schema_version'=>'1.0.0',
            'sn_conference_provider_schema_version'=>'1.0.0',
            'sn_message_receipts_schema_version'=>'1.0.0',
            'sn_file_transfer_schema_version'=>'1.0.0',
            'sn_smail_schema_version'=>'1.0.0',
            'sn_message_search_schema_version'=>'1.0.0',
            'sn_event_delivery_schema_version'=>'1.0.0',
            'sn_meet_db_version'=>'1.0.0',
            'sn_two_plan_schema_version'=>'2.1.0',
            'sn_two_plan_firewall_schema_version'=>'1.0.0',
            'sn_future_superset_schema_version'=>'1.0.0',
        ];
        foreach ($required as $key=>$version) {
            if ((string)get_option($key,'') !== $version) return false;
        }
        return true;
    }

"""
if needle not in s: raise SystemExit('migration installers anchor missing')
s=s.replace(needle,method+needle,1)
p.write_text(s,encoding='utf-8')

# R05-D02a: initiation duplicate and transaction mutation point revalidate current relationship truth.
p=Path('sabri-network/includes/class-sn-file-transfer-part-2.php')
s=p.read_text(encoding='utf-8')
old="""        if ($existing) {
            if (!self::same_initiation($existing, $recipients, $name, $declared_mime, $total, $chunk_bytes, $conversation_id, $expected)) {
                return new WP_Error('transfer_idempotency_conflict', 'This transfer idempotency key was already used for different transfer parameters.', ['status' => 409]);
            }
            return rest_ensure_response(['transfer' => self::format($existing, $sender_id), 'duplicate' => true]);
        }
"""
new="""        if ($existing) {
            if (!self::same_initiation($existing, $recipients, $name, $declared_mime, $total, $chunk_bytes, $conversation_id, $expected)) {
                return new WP_Error('transfer_idempotency_conflict', 'This transfer idempotency key was already used for different transfer parameters.', ['status' => 409]);
            }
            $existing_policy = self::revalidate($existing, $sender_id, true);
            if (is_wp_error($existing_policy)) return $existing_policy;
            return rest_ensure_response(['transfer' => self::format($existing, $sender_id), 'duplicate' => true]);
        }
"""
if old not in s: raise SystemExit('transfer duplicate anchor missing')
s=s.replace(old,new,1)
old="""        try {
            if ($wpdb->insert(self::sessions_table(), [
"""
new="""        try {
            $current_access = self::verified_access();
            if (is_wp_error($current_access)) { $wpdb->query('ROLLBACK'); return $current_access; }
            $fresh_recipients = self::resolve_recipients($request, $sender_id);
            if (is_wp_error($fresh_recipients)) { $wpdb->query('ROLLBACK'); return $fresh_recipients; }
            $approved_recipients = array_map('intval', $recipients);
            $current_recipients = array_map('intval', $fresh_recipients);
            sort($approved_recipients, SORT_NUMERIC); sort($current_recipients, SORT_NUMERIC);
            if ($approved_recipients !== $current_recipients) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('transfer_relationship_changed', 'Transfer membership, relationship or consent changed before the transfer could be created.', ['status'=>409]);
            }
            if ($wpdb->insert(self::sessions_table(), [
"""
if old not in s: raise SystemExit('transfer initiation transaction anchor missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# R05-D02b: chunk ledger mutation revalidates under the locked session immediately before insert.
p=Path('sabri-network/includes/class-sn-file-transfer-part-3.php')
s=p.read_text(encoding='utf-8')
old="""            $current=$wpdb->get_row($wpdb->prepare('SELECT id,status,expires_at FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));
            if(!$current||(string)$current->status!=='uploading'||strtotime((string)$current->expires_at)<time())throw new RuntimeException('chunk_session_changed');
            if($wpdb->insert(self::chunks_table(),['transfer_id'=>(int)$row->id,'chunk_index'=>$index,'byte_count'=>$bytes,'sha256'=>$sha,'storage_key'=>$storage_key,'created_at'=>$now])===false)throw new RuntimeException('chunk_row_failed');
"""
new="""            $current=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE',(int)$row->id));
            if(!$current||(string)$current->status!=='uploading'||strtotime((string)$current->expires_at)<time())throw new RuntimeException('chunk_session_changed');
            $locked_policy=self::revalidate($current,$user_id,true);
            if(is_wp_error($locked_policy)){$wpdb->query('ROLLBACK');@unlink($path);return $locked_policy;}
            if($wpdb->insert(self::chunks_table(),['transfer_id'=>(int)$row->id,'chunk_index'=>$index,'byte_count'=>$bytes,'sha256'=>$sha,'storage_key'=>$storage_key,'created_at'=>$now])===false)throw new RuntimeException('chunk_row_failed');
"""
if old not in s: raise SystemExit('transfer chunk transaction anchor missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Permanent regression additions.
p=Path('sabri-network/tests/transfer-adversarial-contracts.php')
s=p.read_text(encoding='utf-8')
insert="""fta(str_contains($src,'$existing_policy = self::revalidate($existing, $sender_id, true)'),'Fresh20 R05: transfer initiation replay must not bypass current relationship and consent truth.');
fta(str_contains($src,'$fresh_recipients = self::resolve_recipients($request, $sender_id)')&&str_contains($src,"transfer_relationship_changed', 'Transfer membership, relationship or consent changed before the transfer could be created."),'Fresh20 R05: initiation must re-resolve recipients after its transaction starts and fail if the approved set changed.');
$chunkLock=strpos($src,"SELECT * FROM '.self::sessions_table().' WHERE id=%d FOR UPDATE");$chunkRevalidate=strpos($src,'$locked_policy=self::revalidate($current,$user_id,true)');$chunkInsert=strpos($src,"$wpdb->insert(self::chunks_table(),['transfer_id'");
fta($chunkLock!==false&&$chunkRevalidate!==false&&$chunkInsert!==false&&$chunkLock<$chunkRevalidate&&$chunkRevalidate<$chunkInsert,'Fresh20 R05: chunk acceptance must revalidate current policy under the locked session before the chunk ledger mutation.');
"""
tail=s.rfind('if($fails){')
if tail<0: raise SystemExit('transfer regression tail missing')
s=s[:tail]+insert+s[tail:]
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/fifth-fresh-migration-contracts.php')
s=p.read_text(encoding='utf-8')
insert="""$check(str_contains($m,'private static function verify_version_truth()')&&str_contains($m,"'sn_file_transfer_schema_version'=>'1.0.0'")&&str_contains($m,"'sn_two_plan_schema_version'=>'2.1.0'"),'Fresh20 R05: centralized migration success must include installer-local version truth.');
$check(substr_count($m,'self::verify_version_truth()')>=3,'Fresh20 R05: both verified fast paths and post-install verification must enforce centralized version truth.');
"""
tail=s.rfind('if($fail){')
if tail<0: raise SystemExit('migration regression tail missing')
s=s[:tail]+insert+s[tail:]
p.write_text(s,encoding='utf-8')

# Update prior static needle to allow the now-stronger fast-path predicate.
p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
old="""str_contains($migration,'if (!$force && (string)get_option(\\'sn_plugin_version\\',\\'\\') === SN_VERSION && self::verify_schema()) {')"""
new="""str_contains($migration,"if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema() && self::verify_version_truth()) {")"""
if old in s: s=s.replace(old,new)
p.write_text(s,encoding='utf-8')
