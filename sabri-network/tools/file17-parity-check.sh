#!/usr/bin/env bash
# Strict, read-only comparison of an installed File-17 plugin with an exact tested ZIP.
# Usage: bash tools/file17-parity-check.sh /path/to/wp-content/plugins/sabri-network /path/to/approved.zip
set -euo pipefail
export LC_ALL=C
if [[ $# -ne 2 ]]; then
  printf 'Usage: %s /path/to/plugins/sabri-network /path/to/exact-approved.zip\n' "$0" >&2
  exit 2
fi
for command in sha256sum unzip find cmp sort; do
  command -v "$command" >/dev/null 2>&1 || { printf 'BLOCKED: missing %s\n' "$command" >&2; exit 3; }
done
deployed="$(cd -- "$1" 2>/dev/null && pwd -P)" || { printf 'BLOCKED: deployed plugin path not readable\n' >&2; exit 4; }
approved_zip="$2"
[[ -f "$approved_zip" && -f "$deployed/sabri-network.php" && "$(basename -- "$deployed")" == "sabri-network" ]] || {
  printf 'BLOCKED: exact ZIP or canonical installed plugin path missing\n' >&2
  exit 5
}
if find "$deployed" -type l -print -quit | grep -q .; then
  printf 'PARITY BLOCKED: deployed plugin includes symbolic links; review before continuing\n' >&2
  exit 6
fi
temp="$(mktemp -d)"
trap 'rm -rf -- "$temp"' EXIT
# Inspect a single named file from the archive; do not extract arbitrary paths to the host.
if ! unzip -tqq "$approved_zip" >/dev/null 2>&1 || ! unzip -p "$approved_zip" 'sabri-network/MANIFEST.sha256' > "$temp/approved.sha256" 2>/dev/null; then
  printf 'PARITY BLOCKED: exact approved ZIP is invalid or lacks an embedded manifest\n' >&2
  exit 7
fi
if [[ ! -s "$temp/approved.sha256" ]]; then
  printf 'PARITY BLOCKED: approved manifest is empty\n' >&2
  exit 8
fi
# No absolute paths, traversal, missing hashes, or backslashes may be accepted from a package manifest.
if ! awk '
  {
    hash=substr($0,1,64);
    path=substr($0,67);
    if (length(hash)!=64 || hash !~ /^[0-9a-f]+$/ || substr($0,65,2)!="  " || path !~ /^sabri-network\/[A-Za-z0-9._\/-]+$/) bad=1;
    if ($0 ~ /\/\.\.\/|\/\.\.$|\/\.\//) bad=1;
    n++;
  }
  END { exit (bad || n<1) ? 1 : 0 }
' "$temp/approved.sha256"; then
  printf 'PARITY BLOCKED: manifest names or checksums are not safe\n' >&2
  exit 9
fi
if ! cmp -s -- "$temp/approved.sha256" "$deployed/MANIFEST.sha256"; then
  printf 'PARITY MISMATCH: installed manifest is not identical to approved package manifest\n' >&2
  exit 10
fi
# Compare every signed path and all installed file names, not just the entrypoint/version string.
sed -E 's/^[0-9a-f]{64}  //' "$temp/approved.sha256" > "$temp/expected-names"
printf 'sabri-network/MANIFEST.sha256\n' >> "$temp/expected-names"
LC_ALL=C sort "$temp/expected-names" > "$temp/expected-sorted"
# The ZIP itself, not just its embedded manifest, must be the complete approved artifact.
# Reject unlisted payloads, duplicate names and archive content that disagrees with the manifest.
if ! unzip -Z -1 "$approved_zip" > "$temp/approved-archive-names" 2>/dev/null; then
  printf 'PARITY BLOCKED: cannot read exact approved ZIP member list\n' >&2
  exit 13
fi
LC_ALL=C sort "$temp/approved-archive-names" > "$temp/approved-archive-sorted"
# A ZIP symbolic link is not equivalent to a regular deployed plugin source file,
# even if the literal link target bytes happen to match the expected file digest.
if unzip -Z -l "$approved_zip" 2>/dev/null | grep -q '^l'; then
  printf 'PARITY BLOCKED: approved ZIP contains a symbolic-link archive member\n' >&2
  exit 18
fi
if ! cmp -s -- "$temp/expected-sorted" "$temp/approved-archive-sorted"; then
  printf 'PARITY BLOCKED: approved ZIP has missing, duplicate or unmanifested members\n' >&2
  exit 14
fi
if [[ "$(LC_ALL=C sort -u "$temp/expected-names" | wc -l)" -ne "$(wc -l < "$temp/expected-names")" ]]; then
  printf 'PARITY BLOCKED: approved manifest lists a duplicate file path\n' >&2
  exit 15
fi
while IFS= read -r line; do
  advertised="${line:0:64}"
  archive_path="${line:66}"
  if ! actual="$(unzip -p "$approved_zip" "$archive_path" 2>/dev/null | sha256sum | awk '{print $1}')"; then
    printf 'PARITY BLOCKED: cannot read an approved ZIP member\n' >&2
    exit 16
  fi
  if [[ "$advertised" != "$actual" ]]; then
    printf 'PARITY BLOCKED: approved ZIP member does not match embedded manifest\n' >&2
    exit 17
  fi
done < "$temp/approved.sha256"
(
  cd -- "$(dirname -- "$deployed")"
  find sabri-network -type f -print | LC_ALL=C sort
) > "$temp/deployed-names"
if ! cmp -s -- "$temp/expected-sorted" "$temp/deployed-names"; then
  printf 'PARITY MISMATCH: installed file list differs from approved ZIP (no file contents shown)\n' >&2
  exit 11
fi
if ! (cd -- "$(dirname -- "$deployed")" && sha256sum --status -c "$temp/approved.sha256"); then
  printf 'PARITY MISMATCH: one or more installed file hashes differ from approved ZIP\n' >&2
  exit 12
fi
printf 'EXACT DEPLOYED ARTIFACT PARITY: PASS\n'
printf 'Approved ZIP digest: %s\n' "$(sha256sum "$approved_zip" | awk '{print $1}')"
printf 'Verified deployed package files: %s\n' "$(wc -l < "$temp/deployed-names" | tr -d ' ')"
printf 'NOTE: package parity does not establish DB migration state, running PHP opcache, provider readiness, or successful live user journeys.\n'
