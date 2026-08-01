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
echo 'PASS assets/js/network.js'

echo '== Shell syntax =='
bash -n tools/quality-check.sh
bash -n tools/package.sh
echo 'PASS shell scripts'

echo '== Review round 1: static contracts =='
php tests/static-contracts.php

echo '== Review round 2: adversarial contracts =='
php tests/adversarial-contracts.php

echo '== CSS integrity =='
python3 - <<'PY'
from pathlib import Path
text = Path('assets/css/network.css').read_text(encoding='utf-8')
assert text.count('{') == text.count('}'), 'CSS brace count is unbalanced'
assert '@media (max-width: 900px)' in text
assert 'overflow-wrap: anywhere' in text
print('CSS integrity: PASS')
PY

echo '== Repository hygiene =='
if grep -RInE --exclude-dir=.git --exclude-dir=build --exclude='quality-check.sh' --exclude='static-contracts.php' '(TODO|FIXME|HACK|console\.log\(|debugger;)' .; then
  echo 'Repository hygiene check failed.' >&2
  exit 1
fi
if find . -path './.git' -prune -o -path './build' -prune -o -type f -size +5M -print | grep -q .; then
  echo 'Unexpected source file larger than 5 MB.' >&2
  exit 1
fi

echo 'QUALITY CHECK: PASS'
