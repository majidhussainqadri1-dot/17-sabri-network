#!/usr/bin/env bash
# Synthetic read-only parity fixture. It MUST NOT connect to or change WordPress.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/sabri-network"
printf '<?php /* verified plugin */\n' > "$tmp/sabri-network/sabri-network.php"
printf 'visible, approved content\n' > "$tmp/sabri-network/test.txt"
(
  cd "$tmp"
  sha256sum sabri-network/sabri-network.php sabri-network/test.txt > sabri-network/MANIFEST.sha256
  zip -X -q approved.zip sabri-network/sabri-network.php sabri-network/test.txt sabri-network/MANIFEST.sha256
)
parity_result="$(bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip")"
grep -q 'EXACT DEPLOYED ARTIFACT PARITY: PASS' <<< "$parity_result"

# An unlisted member exists only in the archive, not on the installed filesystem.
cp -- "$tmp/approved.zip" "$tmp/archive-only-extra.zip"
mkdir -p "$tmp/archive-fixture/unlisted"
printf 'unauthorized archive-only payload\n' > "$tmp/archive-fixture/unlisted/payload.php"
(cd "$tmp/archive-fixture" && zip -X -q "$tmp/archive-only-extra.zip" unlisted/payload.php)
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/archive-only-extra.zip" >/dev/null 2>&1; then
  printf 'FAIL: unmanifested ZIP member passed despite matching installed files.\n' >&2
  exit 1
fi

# A listed ZIP member has been replaced without updating its embedded manifest.
cp -- "$tmp/approved.zip" "$tmp/archive-content-forged.zip"
mkdir -p "$tmp/archive-fixture/sabri-network"
printf 'forged archive bytes\n' > "$tmp/archive-fixture/sabri-network/test.txt"
(cd "$tmp/archive-fixture" && zip -X -q "$tmp/archive-content-forged.zip" sabri-network/test.txt)
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/archive-content-forged.zip" >/dev/null 2>&1; then
  printf 'FAIL: modified ZIP file bytes passed an old manifest.\n' >&2
  exit 1
fi

# A ZIP symlink may contain the same literal bytes as the regular file it replaces.
cp -- "$tmp/approved.zip" "$tmp/archive-symlink-forged.zip"
rm -f -- "$tmp/archive-fixture/sabri-network/test.txt"
ln -s $'visible, approved content\n' "$tmp/archive-fixture/sabri-network/test.txt"
(cd "$tmp/archive-fixture" && zip -X -y -q "$tmp/archive-symlink-forged.zip" sabri-network/test.txt)
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/archive-symlink-forged.zip" >/dev/null 2>&1; then
  printf 'FAIL: hash-matching symlink member passed regular-file ZIP parity.\n' >&2
  exit 1
fi

# Filesystem mutation, extra installed code and forged local manifest must each fail.
printf 'changed\n' > "$tmp/sabri-network/test.txt"
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip" >/dev/null 2>&1; then
  printf 'FAIL: changed installed code passed package parity.\n' >&2
  exit 1
fi
printf 'visible, approved content\n' > "$tmp/sabri-network/test.txt"
printf 'stray source\n' > "$tmp/sabri-network/extra.php"
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip" >/dev/null 2>&1; then
  printf 'FAIL: extra installed source passed package parity.\n' >&2
  exit 1
fi
rm -f -- "$tmp/sabri-network/extra.php"
printf 'altered manifest\n' >> "$tmp/sabri-network/MANIFEST.sha256"
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip" >/dev/null 2>&1; then
  printf 'FAIL: changed installed manifest passed package parity.\n' >&2
  exit 1
fi

# Migration-state inventory must include each named first-party owner.
grep -q 'sn_two_plan_schema_version' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'sn_future_superset_schema_version' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'sn_meet_db_version' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'INFORMATION_SCHEMA.COLUMNS' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'INFORMATION_SCHEMA.STATISTICS' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'INFORMATION_SCHEMA.TABLE_ROWS' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'TABLE_ROWS estimates only' "$ROOT/tools/file17-live-freeze.sh"
printf 'File-17 exact ZIP parity and migration-inventory regression: PASS\n'
