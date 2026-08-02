#!/usr/bin/env bash
set -euo pipefail

export LC_ALL=C
export TZ=UTC

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/stage"
PACKAGE="$BUILD/17-sabri-network-and-messages-2.0.0.zip"
FIXED_TIMESTAMP="198001010000.00"

rm -rf "$STAGE" "$PACKAGE" "$PACKAGE.sha256"
mkdir -p "$STAGE/sabri-network"

rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.gitignore' \
  --exclude='build/' \
  --exclude='tests/' \
  --exclude='tools/' \
  --exclude='REVIEW-REPORT.md' \
  "$ROOT/" "$STAGE/sabri-network/"

if find "$STAGE/sabri-network" -type l -print -quit | grep -q .; then
  echo 'Packaging refused: symbolic links are not permitted in the release tree.' >&2
  exit 1
fi

find "$STAGE/sabri-network" -type d -exec chmod 0755 {} +
find "$STAGE/sabri-network" -type f -exec chmod 0644 {} +
find "$STAGE/sabri-network" -exec touch -h -t "$FIXED_TIMESTAMP" {} +

find "$STAGE/sabri-network" -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l >/dev/null
node --check "$STAGE/sabri-network/assets/js/network.js"
node --check "$STAGE/sabri-network/assets/js/meet.js"
grep -q 'Version: 2.0.0' "$STAGE/sabri-network/sabri-network.php"

(
  cd "$STAGE"
  find sabri-network -type f -print | sort | zip -X -q "$PACKAGE" -@
)
unzip -t "$PACKAGE" >/dev/null
sha256sum "$PACKAGE" > "$PACKAGE.sha256"
rm -rf "$STAGE"

printf 'Package: %s\n' "$PACKAGE"
printf 'SHA-256: '
cut -d' ' -f1 "$PACKAGE.sha256"
