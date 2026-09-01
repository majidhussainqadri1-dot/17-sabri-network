from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
p = ROOT / 'sabri-network/tests/search-outbox-review-2-adversarial-contracts.php'
text = p.read_text()
old_uuid = "$check(str_contains($outbox,'wp_is_uuid($event_uuid,4)'),'Incoming events must use canonical UUIDv4 identities.');"
new_uuid = "$check(str_contains($outbox,'wp_is_uuid($event_uuid, 4)')||str_contains($outbox,'wp_is_uuid($event_uuid,4)'),'Incoming events must use canonical UUIDv4 identities.');"
old_processed = "$check(str_contains($outbox,\"if(\\$existing&&(string)\\$existing->status==='processed')return true\"),'Processed incoming events must be idempotent.');"
new_processed = "$check(str_contains($outbox,\"status === 'processed'\")&&str_contains($outbox,\"$wpdb->query('ROLLBACK')\")&&str_contains($outbox,'return true;'),'Processed incoming events must be idempotent and close the claim transaction before replay success.');"
if old_uuid not in text or old_processed not in text:
    raise SystemExit('R38 legacy regression targets missing')
text = text.replace(old_uuid, new_uuid, 1).replace(old_processed, new_processed, 1)
p.write_text(text)
