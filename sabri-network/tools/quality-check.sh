#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo '== Required canonical surfaces =='
required=(
  sabri-network.php
  readme.txt
  CHANGELOG.md
  SECURITY.md
  CF01-COMMUNICATION-CONTEXT-CONTRACT.md
  includes/class-sn-db.php
  includes/class-sn-auth.php
  includes/class-sn-messages.php
  includes/class-sn-outbox.php
  includes/class-sn-privacy.php
  includes/class-sn-cf01-clinical-context.php
  templates/network-standalone.php
  templates/messages-standalone.php
  templates/meet-app.php
  tools/quality-check.sh
  tools/package.sh
)
for file in "${required[@]}"; do
  test -f "$file" || { echo "Missing required file: $file" >&2; exit 1; }
done
echo 'Required canonical surfaces: PASS'

echo '== PHP syntax =='
php_count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  printf 'PASS %s\n' "$file"
  php_count=$((php_count + 1))
done < <(find . -path './build' -prune -o -name '*.php' -type f -print0 | sort -z)
if (( php_count < 70 )); then
  echo "Unexpected PHP inventory contraction: $php_count" >&2
  exit 1
fi

echo '== JavaScript syntax =='
js_files=(assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js)
for file in "${js_files[@]}"; do
  node --check "$file"
  printf 'PASS %s\n' "$file"
done

echo '== Shell syntax =='
bash -n tools/quality-check.sh
bash -n tools/package.sh
echo 'PASS shell scripts'

echo '== Inherited independent review round 1 =='
php tests/static-contracts.php

echo '== Inherited independent review round 2 =='
php tests/adversarial-contracts.php

echo '== Remaining inherited File 17 review and correction suites =='
for test_file in $(find tests -maxdepth 1 -type f -name '*.php' \
  ! -name 'static-contracts.php' \
  ! -name 'adversarial-contracts.php' \
  ! -name 'cf01-clinical-context-static-contracts.php' \
  ! -name 'cf01-clinical-context-runtime-contracts.php' \
  -print | LC_ALL=C sort); do
  php "$test_file"
done

echo '== CF-01 review round 1: static ownership and no-copy contracts =='
php tests/cf01-clinical-context-static-contracts.php

echo '== CF-01 review round 2: fresh/adversarial runtime contracts =='
php tests/cf01-clinical-context-runtime-contracts.php

echo '== CSS integrity =='
python3 - <<'PY'
from pathlib import Path
for path in ('assets/css/network.css', 'assets/css/meet.css', 'assets/css/messages.css', 'assets/css/message-search.css'):
    text = Path(path).read_text(encoding='utf-8')
    assert text.count('{') == text.count('}'), f'{path}: CSS brace count is unbalanced'
assert '@media (max-width: 900px)' in Path('assets/css/network.css').read_text(encoding='utf-8')
meet = Path('assets/css/meet.css').read_text(encoding='utf-8')
assert '@media (max-width: 900px)' in meet
assert '@media (prefers-reduced-motion: reduce)' in meet
assert 'min-height: 44px' in meet
messages = Path('assets/css/messages.css').read_text(encoding='utf-8')
assert '@media (max-width: 900px)' in messages
assert '@media (prefers-reduced-motion: reduce)' in messages
assert 'min-height: 44px' in messages
search = Path('assets/css/message-search.css').read_text(encoding='utf-8')
assert '@media(max-width:900px)' in search
assert '@media(prefers-reduced-motion:reduce)' in search
assert 'min-height:44px' in search
assert '.snms-result' in search
assert '.snms-context-message.is-target' in search
print('CSS integrity: PASS')
PY

echo '== Repository hygiene and CF-01 public-safety invariants =='
if grep -RInE --exclude-dir=.git --exclude-dir=build --exclude='quality-check.sh' --exclude='static-contracts.php' --exclude='realtime-static-contracts.php' --exclude='safety-static-contracts.php' --exclude='relationships-static-contracts.php' '(TODO|FIXME|HACK|console\.log\(|debugger;)' .; then
  echo 'Repository hygiene check failed.' >&2
  exit 1
fi
if find . -path './.git' -prune -o -path './build' -prune -o -type f -size +5M -print | grep -q .; then
  echo 'Unexpected source file larger than 5 MB.' >&2
  exit 1
fi
python3 - <<'PY'
from pathlib import Path
source = Path('includes/class-sn-cf01-clinical-context.php').read_text(encoding='utf-8')
required = (
    "public const CONTRACT_NAME = 'sn.cf01.communication-context'",
    "'message_body_included' => false",
    "'attachment_included' => false",
    "'call_transcript_included' => false",
    "'automatic_chart_write' => false",
    "'chat_membership_is_not_treating_relationship' => true",
    "'requires_click_time_file17_authorization' => true",
)
for marker in required:
    if marker not in source:
        raise SystemExit(f'Missing CF-01 invariant: {marker}')
for forbidden in ("SN_DB::table('messages')", "SN_DB::table('attachments')", "SN_DB::table('calls')", 'SELECT body'):
    if forbidden in source:
        raise SystemExit(f'CF-01 provider reads prohibited communication content: {forbidden}')
print('Repository and CF-01 public-safety checks: PASS')
PY

echo '== Exact staged source manifest and reproducible package =='
BASE='17-sabri-network-and-messages-2.0.1'
rm -rf build
bash tools/package.sh >/tmp/file17-package-first.log
for suffix in zip zip.sha256 manifest.sha256; do
  test -f "build/${BASE}.${suffix}" || { echo "Missing build artifact: ${BASE}.${suffix}" >&2; exit 1; }
done
first_hash="$(sha256sum "build/${BASE}.zip" | cut -d' ' -f1)"
cp "build/${BASE}.zip" /tmp/file17-package-first.zip
cp "build/${BASE}.zip.sha256" /tmp/file17-package-first.zip.sha256
cp "build/${BASE}.manifest.sha256" /tmp/file17-package-first.manifest.sha256

rm -rf build
bash tools/package.sh >/tmp/file17-package-second.log
second_hash="$(sha256sum "build/${BASE}.zip" | cut -d' ' -f1)"
if [[ "$first_hash" != "$second_hash" ]]; then
  echo "Reproducible packaging failed: $first_hash != $second_hash" >&2
  exit 1
fi
cmp -s /tmp/file17-package-first.zip "build/${BASE}.zip"
cmp -s /tmp/file17-package-first.zip.sha256 "build/${BASE}.zip.sha256"
cmp -s /tmp/file17-package-first.manifest.sha256 "build/${BASE}.manifest.sha256"
(
  cd build
  sha256sum -c "${BASE}.zip.sha256" >/dev/null
)
VERIFY="$(mktemp -d)"
trap 'rm -rf "$VERIFY" /tmp/file17-package-first.zip /tmp/file17-package-first.zip.sha256 /tmp/file17-package-first.manifest.sha256 /tmp/file17-package-first.log /tmp/file17-package-second.log' EXIT
unzip -q "build/${BASE}.zip" -d "$VERIFY"
cmp -s "build/${BASE}.manifest.sha256" "$VERIFY/sabri-network/MANIFEST.sha256"
(
  cd "$VERIFY"
  sha256sum -c sabri-network/MANIFEST.sha256 >/dev/null
)

echo "Exact staged source manifest: PASS"
echo "Reproducible package: PASS ($first_hash)"
printf 'QUALITY CHECK: PASS (%d PHP files, %d JS files)\n' "$php_count" "${#js_files[@]}"
