from pathlib import Path
p=Path('sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php')
t=p.read_text(encoding='utf-8')
t=t.replace('str_contains($rt,"($wpdb->last_error ?? \'\')!==\'\'")', "str_contains($rt,'sn_realtime_lock_unavailable') && str_contains($rt,'$raw===null')")
p.write_text(t,encoding='utf-8')
