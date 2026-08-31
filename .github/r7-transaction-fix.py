from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
includes = root / 'sabri-network' / 'includes'
needle = "$wpdb->query('START TRANSACTION');"
replacement = "try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');"
changed = []
count = 0

for path in sorted(includes.glob('*.php')):
    source = path.read_text(encoding='utf-8')
    if needle not in source:
        continue
    original = source
    source, paired = re.subn(r"\$wpdb->query\('START TRANSACTION'\);\s*try\s*\{", replacement, source)
    count += paired
    if needle in source:
        # The legacy transfer-owner handler is the only active occurrence without
        # a surrounding try/catch. It performs no write before BEGIN, so return
        # fail-closed before any row mutation if BEGIN itself is unavailable.
        source = source.replace(
            needle,
            "if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('transaction_start_failed', 'The request transaction could not be started.', ['status' => 503]);"
        )
        count += original.count(needle) - paired
    if source != original:
        path.write_text(source, encoding='utf-8')
        changed.append(path.relative_to(root).as_posix())

# Permanent regression: every runtime BEGIN must be checked at the call site.
test = root / 'sabri-network' / 'tests' / 'eighth-fresh' / 'eighth-fresh-ten-round-contracts.php'
source = test.read_text(encoding='utf-8')
marker = '// Round 7 — every runtime transaction start must fail closed before writes.'
if marker not in source:
    block = r'''
// Round 7 — every runtime transaction start must fail closed before writes.
$transactionFiles = glob($root . '/includes/*.php') ?: [];
$uncheckedBegin = '$wpdb->query(\'START TRANSACTION\');';
$protectedBegins = 0;
foreach ($transactionFiles as $transactionFile) {
    $transactionSource = (string) file_get_contents($transactionFile);
    $check(!str_contains($transactionSource, $uncheckedBegin), 'Round 7: unchecked START TRANSACTION remains in ' . basename($transactionFile) . '.');
    $protectedBegins += substr_count($transactionSource, "transaction_start_failed");
}
$check($protectedBegins >= 40, 'Round 7: repository-wide fail-closed transaction-start coverage unexpectedly regressed.');
'''
    source = source.replace('\nif ($fail) {', block + '\nif ($fail) {')
    test.write_text(source, encoding='utf-8')

print(f'R7 patched transaction starts: {count}; files: {len(changed)}')
if count < 50 or len(changed) < 20:
    raise SystemExit('R7 coverage unexpectedly smaller than frozen inventory')
