from pathlib import Path

# Correct static regression needles created by the R05 patch helper; production edits remain unchanged.
p=Path('sabri-network/tests/transfer-adversarial-contracts.php')
s=p.read_text(encoding='utf-8')
s=s.replace('$chunkInsert=strpos($src,"$wpdb->insert(self::chunks_table(),[\'transfer_id\'");', '$chunkInsert=strpos($src,"\\$wpdb->insert(self::chunks_table(),[\'transfer_id\'");')
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
s=s.replace('str_contains($migration,"if (!$force && (string)get_option(\'sn_plugin_version\',\'\') === SN_VERSION && self::verify_schema() && self::verify_version_truth()) {")', 'str_contains($migration,"if (!\\$force && (string)get_option(\'sn_plugin_version\',\'\') === SN_VERSION && self::verify_schema() && self::verify_version_truth()) {")')
p.write_text(s,encoding='utf-8')
