from pathlib import Path
p=Path('sabri-network/tools/quality-check.sh')
s=p.read_text(encoding='utf-8')
old='  ninth-fresh/ninth-fresh-forty-round-contracts.php\n)'
new='  ninth-fresh/ninth-fresh-forty-round-contracts.php\n  eleventh-fresh/eleventh-fresh-ten-round-contracts.php\n)'
if old not in s: raise SystemExit('missing quality inventory anchor')
p.write_text(s.replace(old,new,1),encoding='utf-8')
