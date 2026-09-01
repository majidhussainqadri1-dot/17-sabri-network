from pathlib import Path
p=Path('sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php')
t=p.read_text(encoding='utf-8')
t=t.replace("str_contains($search,\"!is_array($rows) || ($wpdb->last_error ?? '') !== ''\")", "str_contains($search,'message search backfill could not read its next batch') && substr_count($search,'storage_unavailable()')>=6")
p.write_text(t,encoding='utf-8')
