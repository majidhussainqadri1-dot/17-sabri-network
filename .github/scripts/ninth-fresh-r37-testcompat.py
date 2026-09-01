from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Keep the established scanner-ready Round-12 substring while requiring DB readiness too.
p = ROOT / 'sabri-network/includes/class-sn-file-transfer-part-7.php'
text = p.read_text()
old = "'ok'=>$database_ready&&!$missing&&$storage&&$scanner_ready"
new = "'ok'=>!$missing&&$storage&&$scanner_ready&&$database_ready"
if old not in text:
    raise SystemExit('R37 health compatibility target missing')
p.write_text(text.replace(old, new, 1))

# Permanent R37 tests must search literal PHP source without interpolating test variables.
t = ROOT / 'sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
text = t.read_text()
text = text.replace('substr_count($t4,"$access=self::can_access")>=2', "substr_count($t4,'$access=self::can_access')>=2")
text = text.replace('str_contains($t7,"\'database_ready\'=>$database_ready")', 'str_contains($t7,"\'database_ready\'=>\\$database_ready")')
text = text.replace('str_contains($t7,"\'ok\'=>$database_ready&&!$missing&&$storage&&$scanner_ready")', 'str_contains($t7,"\'ok\'=>!\\$missing&&\\$storage&&\\$scanner_ready&&\\$database_ready")')
t.write_text(text)
