from pathlib import Path

# Repair the R3 regression literal so PHP does not interpolate $wpdb.
p = Path('sabri-network/tests/fifth-fresh-closure-contracts.php')
s = p.read_text(encoding='utf-8')
old = 'str_contains($privacyRuntime,"$wpdb->last_error!==\'\'")'
new = 'str_contains($privacyRuntime,"\\$wpdb->last_error!==\'\'")'
if new not in s:
    if s.count(old) != 1:
        raise SystemExit('R3 closure regression literal target mismatch')
    s = s.replace(old, new, 1)
p.write_text(s, encoding='utf-8')

# Preserve the older Future retained-truth contract while accepting the safer checked-read implementation.
p = Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s = p.read_text(encoding='utf-8')
old = "$check(str_contains($privacyFinal,'$remaining_versions = (int) $wpdb->get_var')&&str_contains($privacyFinal,'$retained_any = $retained > 0 || $remaining_versions > 0;')&&str_contains($privacyFinal,\"'items_retained'=>\\$retained_any\"),'Fresh R7: final Future erasure receipt must derive retained truth from committed remaining message-version rows.');"
new = "$check(str_contains($privacyFinal,'$remaining_versions_raw = $wpdb->get_var')&&str_contains($privacyFinal,\"if (\\$wpdb->last_error !== '') return self::retry('Message-version retained-data truth could not be verified safely.');\")&&str_contains($privacyFinal,'$remaining_versions = (int) $remaining_versions_raw;')&&str_contains($privacyFinal,'$retained_any = $retained > 0 || $remaining_versions > 0;')&&str_contains($privacyFinal,\"'items_retained'=>\\$retained_any\"),'Fresh R7: final Future erasure receipt must derive retained truth from committed remaining message-version rows.');"
if new not in s:
    if s.count(old) != 1:
        raise SystemExit('R3 seventh regression target mismatch')
    s = s.replace(old, new, 1)
p.write_text(s, encoding='utf-8')
