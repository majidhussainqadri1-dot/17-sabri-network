#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
echo '== Required canonical surfaces =='
required=(sabri-network.php readme.txt CHANGELOG.md SECURITY.md CF01-COMMUNICATION-CONTEXT-CONTRACT.md includes/class-sn-db.php includes/class-sn-auth.php includes/class-sn-messages.php includes/class-sn-outbox.php includes/class-sn-privacy.php includes/class-sn-cf01-clinical-context.php includes/class-sn-communication-crypto.php includes/class-sn-smail.php includes/class-sn-file-transfer.php templates/network-standalone.php templates/messages-standalone.php templates/meet-app.php templates/smail-app.php templates/file-transfer-app.php assets/js/smail.js assets/js/file-transfer.js assets/css/smail.css assets/css/file-transfer.css tools/quality-check.sh tools/package.sh)
for file in "${required[@]}"; do test -f "$file" || { echo "Missing required file: $file" >&2; exit 1; }; done
echo 'Required canonical surfaces: PASS'
echo '== PHP syntax =='
php_count=0
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; printf 'PASS %s\n' "$file"; php_count=$((php_count+1)); done < <(find . -path './build' -prune -o -name '*.php' -type f -print0 | sort -z)
(( php_count >= 75 )) || { echo "Unexpected PHP inventory contraction: $php_count" >&2; exit 1; }
echo '== JavaScript syntax =='
js_files=(assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js)
for file in "${js_files[@]}"; do node --check "$file"; printf 'PASS %s\n' "$file"; done
bash -n tools/quality-check.sh; bash -n tools/package.sh

echo '== All File 17 review and correction suites =='
# Explicit independent-review evidence retained for legacy/adversarial contracts:
# php tests/static-contracts.php
# php tests/adversarial-contracts.php
tests=(
 static-contracts.php adversarial-contracts.php
 realtime-static-contracts.php realtime-adversarial-contracts.php
 package-static-contracts.php package-adversarial-contracts.php
 safety-static-contracts.php safety-runtime-contracts.php safety-adversarial-contracts.php
 relationships-static-contracts.php relationships-runtime-contracts.php relationships-adversarial-contracts.php
 forensic-review-3-static-contracts.php forensic-review-3-adversarial-contracts.php
 rate-limit-runtime-contracts.php retention-lock-empty-runtime-contracts.php
 fourth-review-static-contracts.php fourth-review-adversarial-contracts.php policy-age-runtime-contracts.php
 meet-review-1-auth-state-contracts.php meet-review-2-concurrency-contracts.php meet-review-3-privacy-abuse-contracts.php meet-review-4-ui-package-contracts.php
 messages-review-1-static-contracts.php messages-review-2-adversarial-contracts.php
 search-outbox-review-1-static-contracts.php search-outbox-review-2-adversarial-contracts.php
 completion-review-1-architecture-governance-contracts.php completion-review-2-spaces-presence-message-contracts.php completion-review-3-context-conference-privacy-contracts.php completion-review-4-fresh-adversarial-release-contracts.php
 cf01-clinical-context-static-contracts.php cf01-clinical-context-runtime-contracts.php
 smail-static-contracts.php smail-adversarial-contracts.php transfer-static-contracts.php transfer-adversarial-contracts.php
)
for test_file in "${tests[@]}"; do php "tests/$test_file"; done
mapfile -t expected_tests < <(find tests -maxdepth 1 -type f -name '*.php' -printf '%f\n' | LC_ALL=C sort)
mapfile -t invoked_tests < <(printf '%s\n' "${tests[@]}" | LC_ALL=C sort -u)
if [[ "$(printf '%s\n' "${expected_tests[@]}")" != "$(printf '%s\n' "${invoked_tests[@]}")" ]]; then echo 'The explicit quality gate does not invoke every PHP review suite exactly by name.' >&2; diff -u <(printf '%s\n' "${expected_tests[@]}") <(printf '%s\n' "${invoked_tests[@]}") >&2 || true; exit 1; fi
echo "Review-suite inventory: PASS (${#expected_tests[@]} suites)"

echo '== CSS integrity and accessibility baselines =='
python3 - <<'PY'
from pathlib import Path
paths=('assets/css/network.css','assets/css/meet.css','assets/css/messages.css','assets/css/message-search.css','assets/css/smail.css','assets/css/file-transfer.css')
for path in paths:
 text=Path(path).read_text(encoding='utf-8'); assert text.count('{')==text.count('}'),f'{path}: unbalanced braces'
for path in ('assets/css/meet.css','assets/css/messages.css','assets/css/message-search.css','assets/css/smail.css','assets/css/file-transfer.css'):
 text=Path(path).read_text(encoding='utf-8'); assert 'prefers-reduced-motion' in text; assert '44px' in text
for path in ('assets/css/smail.css','assets/css/file-transfer.css'):
 text=Path(path).read_text(encoding='utf-8'); assert '#137a46' in text; assert 'max-width:' in text
print('CSS integrity: PASS')
PY

echo '== Repository hygiene and private-communication invariants =='
if grep -RInE --exclude-dir=.git --exclude-dir=build --exclude='quality-check.sh' --exclude='static-contracts.php' --exclude='realtime-static-contracts.php' --exclude='safety-static-contracts.php' --exclude='relationships-static-contracts.php' '(TODO|FIXME|HACK|console\.log\(|debugger;)' .; then echo 'Repository hygiene check failed.' >&2; exit 1; fi
if find . -path './.git' -prune -o -path './build' -prune -o -type f -size +5M -print | grep -q .; then echo 'Unexpected source file larger than 5 MB.' >&2; exit 1; fi
python3 - <<'PY'
from pathlib import Path
main=Path('sabri-network.php').read_text(); smail='\n'.join(Path(p).read_text() for p in ['includes/class-sn-smail.php',*sorted(str(x) for x in Path('includes').glob('class-sn-smail-part-*.php'))]); transfer='\n'.join(Path(p).read_text() for p in ['includes/class-sn-file-transfer.php',*sorted(str(x) for x in Path('includes').glob('class-sn-file-transfer-part-*.php'))]); crypto=Path('includes/class-sn-communication-crypto.php').read_text()
for marker in ('SN_Smail::register()','SN_File_Transfer::register()',"'file_transfer_max_bytes' => SN_File_Transfer::MAX_FILE_BYTES"):
 assert marker in main,marker
for marker in ('SN_REST::create_conversation','SN_REST::send_message','encrypted_payload LONGTEXT',"smail.sent"):
 assert marker in smail,marker
for marker in ('MAX_FILE_BYTES = 1073741824','x-chunk-sha256','sn_network_transfer_scan_result','Accept-Ranges: bytes','Cache-Control: private, no-store'):
 assert marker in transfer,marker
for forbidden in ('wp_insert_attachment','media_handle_upload','public_url'):
 assert forbidden not in transfer,forbidden
assert 'sodium_crypto_secretbox' in crypto and 'aes-256-gcm' in crypto and 'hash_equals' in crypto
print('Private communication invariants: PASS')
PY

echo '== Exact staged source manifest and reproducible package =='
BASE='17-sabri-network-and-messages-2.0.1'; rm -rf build; bash tools/package.sh >/tmp/file17-package-first.log
for suffix in zip zip.sha256 manifest.sha256; do test -f "build/${BASE}.${suffix}" || { echo "Missing build artifact: ${BASE}.${suffix}" >&2; exit 1; }; done
first_hash="$(sha256sum "build/${BASE}.zip"|cut -d' ' -f1)"; cp "build/${BASE}.zip" /tmp/file17-package-first.zip; cp "build/${BASE}.zip.sha256" /tmp/file17-package-first.zip.sha256; cp "build/${BASE}.manifest.sha256" /tmp/file17-package-first.manifest.sha256
rm -rf build; bash tools/package.sh >/tmp/file17-package-second.log; second_hash="$(sha256sum "build/${BASE}.zip"|cut -d' ' -f1)"
if [[ "$first_hash" != "$second_hash" ]]; then echo "Reproducible packaging failed: $first_hash != $second_hash" >&2; exit 1; fi
cmp -s /tmp/file17-package-first.zip "build/${BASE}.zip"; cmp -s /tmp/file17-package-first.zip.sha256 "build/${BASE}.zip.sha256"; cmp -s /tmp/file17-package-first.manifest.sha256 "build/${BASE}.manifest.sha256"
(cd build; sha256sum -c "${BASE}.zip.sha256" >/dev/null)
VERIFY="$(mktemp -d)"; trap 'rm -rf "$VERIFY" /tmp/file17-package-first.zip /tmp/file17-package-first.zip.sha256 /tmp/file17-package-first.manifest.sha256 /tmp/file17-package-first.log /tmp/file17-package-second.log' EXIT
unzip -q "build/${BASE}.zip" -d "$VERIFY"; cmp -s "build/${BASE}.manifest.sha256" "$VERIFY/sabri-network/MANIFEST.sha256"; (cd "$VERIFY"; sha256sum -c sabri-network/MANIFEST.sha256 >/dev/null)
echo "Exact staged source manifest: PASS"; echo "Reproducible package: PASS ($first_hash)"; printf 'QUALITY CHECK: PASS (%d PHP files, %d JS files, %d review suites)\n' "$php_count" "${#js_files[@]}" "${#expected_tests[@]}"
