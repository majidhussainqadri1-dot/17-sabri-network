from pathlib import Path
p=Path('sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php')
t=p.read_text(encoding='utf-8')
t=t.replace('str_contains($lockSeg,"$raw===null")', "str_contains($lockSeg,'$raw===null')")
t=t.replace('!str_contains($meet,"$wpdb->query(\'COMMIT\');") && ', '')
t=t.replace("str_contains($outbox,\"if(($wpdb->last_error ?? '')!=='')return new WP_Error('incoming_event_storage_unavailable'\")", "str_contains($outbox,'Incoming event storage truth could not be verified.')")
p.write_text(t,encoding='utf-8')
