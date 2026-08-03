#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT_ROOT="$(cd "$ROOT/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export TZ=UTC
export LC_ALL=C
export LANG=C

EPOCH="${SOURCE_DATE_EPOCH:-$(stat -c %Y "$ROOT/sabri-network.php")}"
if (( EPOCH < 315532800 )); then
  EPOCH=315532800
fi
STAMP="$(date -u -d "@$EPOCH" +'%Y%m%d%H%M.%S')"

mkdir -p "$WORK/sabri-network"

while IFS= read -r file; do
  rel="${file#$ROOT/}"
  mkdir -p "$WORK/sabri-network/$(dirname "$rel")"
  cp "$file" "$WORK/sabri-network/$rel"
done < <(find "$ROOT" -type f \
  ! -path "$ROOT/tests/*" \
  ! -path "$ROOT/tools/*" \
  ! -name 'CHECKSUMS.sha256' \
  ! -name '*.zip' \
  ! -name '*.log' \
  ! -name '*.tmp' \
  ! -name '.DS_Store' \
  -print | LC_ALL=C sort)

find "$WORK/sabri-network" -exec touch -h -t "$STAMP" {} +

TMP_ZIP="$WORK/sabri-network-2.0.1.zip"
(
  cd "$WORK"
  find sabri-network -type f -print | LC_ALL=C sort | zip -X -q "$TMP_ZIP" -@
)

OUT_DIR="$PROJECT_ROOT/dist"
mkdir -p "$OUT_DIR"
OUT="$OUT_DIR/sabri-network-2.0.1.zip"
cp "$TMP_ZIP" "$OUT"
printf '%s  %s\n' "$(sha256sum "$OUT" | awk '{print $1}')" "$(basename "$OUT")" > "$OUT.sha256"
printf '%s\n' "$OUT"
