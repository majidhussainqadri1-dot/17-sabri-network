from pathlib import Path
p=Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
old='$check(str_contains($call,"append_direct_pair_lock(array &$locks, int $conversation, int $actor): bool|WP_Error"),\'R1 pair-lock derivation must propagate DB uncertainty.\');'
new='$check(str_contains($call,\'append_direct_pair_lock(array &$locks, int $conversation, int $actor): bool|WP_Error\'),\'R1 pair-lock derivation must propagate DB uncertainty.\');'
if old not in s: raise SystemExit('missing R1 test compatibility anchor')
p.write_text(s.replace(old,new,1),encoding='utf-8')
