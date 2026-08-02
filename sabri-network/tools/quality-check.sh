#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo '== PHP syntax =='
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  printf 'PASS %s\n' "$file"
done < <(find . -path './build' -prune -o -name '*.php' -type f -print0 | sort -z)

echo '== JavaScript syntax =='
node --check assets/js/network.js
node --check assets/js/meet.js
echo 'PASS assets/js/network.js'
echo 'PASS assets/js/meet.js'

echo '== Shell syntax =='
bash -n tools/quality-check.sh
bash -n tools/package.sh
echo 'PASS shell scripts'

echo '== Review round 1: comprehensive static and runtime contracts =='
php tests/static-contracts.php
php tests/realtime-static-contracts.php
php tests/package-static-contracts.php
php tests/safety-static-contracts.php
php tests/safety-runtime-contracts.php
php tests/relationships-static-contracts.php
php tests/relationships-runtime-contracts.php
php tests/forensic-review-3-static-contracts.php
php tests/rate-limit-runtime-contracts.php
php tests/retention-lock-empty-runtime-contracts.php
php tests/fourth-review-static-contracts.php
php tests/policy-age-runtime-contracts.php

echo '== Review round 2: fresh/adversarial contracts =='
php tests/adversarial-contracts.php
php tests/realtime-adversarial-contracts.php
php tests/package-adversarial-contracts.php
php tests/safety-adversarial-contracts.php
php tests/relationships-adversarial-contracts.php
php tests/forensic-review-3-adversarial-contracts.php
php tests/fourth-review-adversarial-contracts.php

echo '== Sabri Meet review/fix round 1: authorization and state =='
php tests/meet-review-1-auth-state-contracts.php

echo '== Sabri Meet review/fix round 2: concurrency and idempotency =='
php tests/meet-review-2-concurrency-contracts.php

echo '== Sabri Meet review/fix round 3: privacy and abuse =='
php tests/meet-review-3-privacy-abuse-contracts.php

echo '== Sabri Meet review/fix round 4: UI and package =='
php tests/meet-review-4-ui-package-contracts.php

echo '== CSS integrity =='
python3 - <<'PY'
from pathlib import Path
for path in ('assets/css/network.css', 'assets/css/meet.css'):
    text = Path(path).read_text(encoding='utf-8')
    assert text.count('{') == text.count('}'), f'{path}: CSS brace count is unbalanced'
assert '@media (max-width: 900px)' in Path('assets/css/network.css').read_text(encoding='utf-8')
meet = Path('assets/css/meet.css').read_text(encoding='utf-8')
assert '@media (max-width: 900px)' in meet
assert '@media (prefers-reduced-motion: reduce)' in meet
assert 'min-height: 44px' in meet
print('CSS integrity: PASS')
PY

echo '== Repository hygiene =='
if grep -RInE --exclude-dir=.git --exclude-dir=build --exclude='quality-check.sh' --exclude='static-contracts.php' --exclude='realtime-static-contracts.php' --exclude='safety-static-contracts.php' --exclude='relationships-static-contracts.php' '(TODO|FIXME|HACK|console\.log\(|debugger;)' .; then
  echo 'Repository hygiene check failed.' >&2
  exit 1
fi
if find . -path './.git' -prune -o -path './build' -prune -o -type f -size +5M -print | grep -q .; then
  echo 'Unexpected source file larger than 5 MB.' >&2
  exit 1
fi

echo '== Installable source checksums =='
(
  cd "$ROOT/.."
  expected_files="$(find sabri-network -type f \
    ! -path 'sabri-network/build/*' \
    ! -path 'sabri-network/tests/*' \
    ! -path 'sabri-network/tools/*' \
    ! -name 'REVIEW-REPORT.md' \
    -print | LC_ALL=C sort)"
  listed_files="$(awk '{print $2}' CHECKSUMS.sha256 | sed 's#^\./##' | LC_ALL=C sort)"
  if [[ "$expected_files" != "$listed_files" ]]; then
    echo 'Installable source checksum manifest does not match the package source tree.' >&2
    diff -u <(printf '%s\n' "$expected_files") <(printf '%s\n' "$listed_files") >&2 || true
    exit 1
  fi
  sha256sum -c CHECKSUMS.sha256
)
echo 'Installable source checksums: PASS'

echo '== Reproducible package =='
bash tools/package.sh >/tmp/file17-package-first.log
first_hash="$(sha256sum build/17-sabri-network-and-messages-2.0.0.zip | cut -d' ' -f1)"
cp build/17-sabri-network-and-messages-2.0.0.zip /tmp/file17-package-first.zip
bash tools/package.sh >/tmp/file17-package-second.log
second_hash="$(sha256sum build/17-sabri-network-and-messages-2.0.0.zip | cut -d' ' -f1)"
if [[ "$first_hash" != "$second_hash" ]]; then
  echo "Reproducible packaging failed: $first_hash != $second_hash" >&2
  exit 1
fi
cmp -s /tmp/file17-package-first.zip build/17-sabri-network-and-messages-2.0.0.zip
rm -f /tmp/file17-package-first.zip /tmp/file17-package-first.log /tmp/file17-package-second.log
echo "Reproducible package: PASS ($first_hash)"

echo 'QUALITY CHECK: PASS'
