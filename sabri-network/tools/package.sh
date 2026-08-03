#!/usr/bin/env bash
set -euo pipefail

export LC_ALL=C
export TZ=UTC

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/stage"
BASE="17-sabri-network-and-messages-2.0.1"
PACKAGE="$BUILD/$BASE.zip"
PACKAGE_SHA="$BUILD/$BASE.zip.sha256"
SOURCE_MANIFEST="$BUILD/$BASE.manifest.sha256"
FIXED_TIMESTAMP="198001010000.00"

rm -rf "$STAGE" "$PACKAGE" "$PACKAGE_SHA" "$SOURCE_MANIFEST"
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

find "$STAGE/sabri-network" -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l >/dev/null
node --check "$STAGE/sabri-network/assets/js/network.js"
node --check "$STAGE/sabri-network/assets/js/meet.js"
node --check "$STAGE/sabri-network/assets/js/messages.js"
node --check "$STAGE/sabri-network/assets/js/message-search.js"
grep -q 'Version: 2.0.1' "$STAGE/sabri-network/sabri-network.php"
grep -q "define('SN_CF01_COMMUNICATION_CONTEXT_VERSION', '1.0.0')" "$STAGE/sabri-network/sabri-network.php"
test -f "$STAGE/sabri-network/includes/class-sn-cf01-clinical-context.php"
test -f "$STAGE/sabri-network/CF01-COMMUNICATION-CONTEXT-CONTRACT.md"

(
  cd "$STAGE"
  find sabri-network -type f ! -name 'MANIFEST.sha256' -print | sort | while IFS= read -r file; do
    sha256sum "$file"
  done > sabri-network/MANIFEST.sha256
  sha256sum -c sabri-network/MANIFEST.sha256 >/dev/null
)
cp "$STAGE/sabri-network/MANIFEST.sha256" "$SOURCE_MANIFEST"

find "$STAGE/sabri-network" -exec touch -h -t "$FIXED_TIMESTAMP" {} +

(
  cd "$STAGE"
  find sabri-network -type f -print | sort | zip -X -q "$PACKAGE" -@
)
unzip -t "$PACKAGE" >/dev/null
(
  cd "$BUILD"
  sha256sum "$BASE.zip" > "$BASE.zip.sha256"
)
rm -rf "$STAGE"

printf 'Package: %s\n' "$PACKAGE"
printf 'Source manifest: %s\n' "$SOURCE_MANIFEST"
printf 'SHA-256: '
cut -d' ' -f1 "$PACKAGE_SHA"
