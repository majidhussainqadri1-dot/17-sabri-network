#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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
printf 'changed\n' > "$tmp/sabri-network/test.txt"
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip" >/dev/null 2>&1; then
  printf 'FAIL: altered installed code must never match exact-zip parity.\n' >&2
  exit 1
fi
printf 'visible, approved content\n' > "$tmp/sabri-network/test.txt"
printf 'stray source\n' > "$tmp/sabri-network/extra.php"
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip" >/dev/null 2>&1; then
  printf 'FAIL: extra installed source must never pass strict exact-zip parity.\n' >&2
  exit 1
fi
rm -f -- "$tmp/sabri-network/extra.php"
printf 'altered manifest\n' >> "$tmp/sabri-network/MANIFEST.sha256"
if bash "$ROOT/tools/file17-parity-check.sh" "$tmp/sabri-network" "$tmp/approved.zip" >/dev/null 2>&1; then
  printf 'FAIL: modified installed manifest must never pass parity.\n' >&2
  exit 1
fi
# The freeze must collect each first-party schema version separately without changing the live site.
grep -q 'sn_two_plan_schema_version' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'sn_future_superset_schema_version' "$ROOT/tools/file17-live-freeze.sh"
grep -q 'sn_meet_db_version' "$ROOT/tools/file17-live-freeze.sh"
printf 'File-17 exact ZIP parity read-only regression: PASS\n'
