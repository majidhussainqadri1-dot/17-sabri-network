#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT="$(dirname "$ROOT")"
BUILD="$ROOT/build"
STAGE="$BUILD/stage"
PACKAGE="$BUILD/17-sabri-network-and-messages-2.0.0.zip"

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

find "$STAGE/sabri-network" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
node --check "$STAGE/sabri-network/assets/js/network.js"
grep -q 'Version: 2.0.0' "$STAGE/sabri-network/sabri-network.php"

(
  cd "$STAGE"
  zip -q -r "$PACKAGE" sabri-network
)
unzip -t "$PACKAGE" >/dev/null
sha256sum "$PACKAGE" > "$PACKAGE.sha256"
rm -rf "$STAGE"

printf 'Package: %s\n' "$PACKAGE"
printf 'SHA-256: '
cut -d' ' -f1 "$PACKAGE.sha256"
