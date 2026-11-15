#!/usr/bin/env bash
# File-17 V12.3 read-only live reality freeze. Never modifies WordPress, DB, or plugins.
# Usage: bash tools/file17-live-freeze.sh /absolute/path/to/wordpress
set -euo pipefail
if [[ "${#}" -ne 1 || "${1}" == '--help' ]]; then
  printf 'Usage: %s /absolute/path/to/wordpress\n' "$0"
  exit 2
fi
if ! command -v wp >/dev/null 2>&1 || ! command -v sha256sum >/dev/null 2>&1; then
  printf 'BLOCKED: wp and GNU sha256sum must be available for an exact-site read-only freeze.\n' >&2
  exit 3
fi
WP_ROOT="$(cd -- "$1" 2>/dev/null && pwd -P)" || { printf 'BLOCKED: WordPress root is unavailable.\n' >&2; exit 4; }
if [[ ! -f "$WP_ROOT/wp-config.php" || ! -d "$WP_ROOT/wp-content/plugins" ]]; then
  printf 'BLOCKED: this does not appear to be an accessible WordPress root.\n' >&2
  exit 5
fi
PLUGIN_DIR="$WP_ROOT/wp-content/plugins/sabri-network"
if [[ ! -f "$PLUGIN_DIR/sabri-network.php" ]]; then
  printf 'BLOCKED: exact deployed File-17 directory/plugin entrypoint is absent.\n' >&2
  exit 6
fi
wp_ro(){ wp --path="$WP_ROOT" --skip-plugins --skip-themes "$@"; }
printf 'File17 live reality freeze (READ ONLY)\n'
printf 'UTC snapshot: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'WordPress root: %s\n' "$WP_ROOT"
printf 'Runtime PHP: %s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || printf 'unverified')"
printf 'WordPress core: %s\n' "$(wp_ro core version 2>/dev/null || printf 'unverified')"
printf 'Plugin version: %s\n' "$(wp_ro plugin get sabri-network --field=version 2>/dev/null || printf 'unverified')"
printf 'Plugin state: %s\n' "$(wp_ro plugin get sabri-network --field=status 2>/dev/null || printf 'unverified')"
for key in sn_plugin_version sn_future_superset_schema_version sn_two_plan_schema_version sn_meet_db_version sn_db_version; do
  value="$(wp_ro option get "$key" 2>/dev/null || printf 'absent/unverified')"
  if [[ "${#value}" -gt 128 ]]; then value='non-scalar/unverified'; fi
  printf 'wp_options %s: %s\n' "$key" "$value"
done
prefix="$(wp_ro db prefix 2>/dev/null || true)"
if [[ "$prefix" =~ ^[a-zA-Z0-9_]+$ ]]; then
  printf 'Table prefix: %s\n' "$prefix"
  # Query only table NAMES; never display rows, users, conversations, messages or secrets.
  wp_ro db query "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '${prefix}sn_%' ORDER BY TABLE_NAME" --skip-column-names 2>/dev/null || printf 'Database table inventory: unverified\n'
else
  printf 'Database schema: unverified (no trustworthy table prefix).\n'
fi
if [[ -f "$PLUGIN_DIR/MANIFEST.sha256" ]]; then
  if (cd "$WP_ROOT/wp-content/plugins" && sha256sum --status -c "$PLUGIN_DIR/MANIFEST.sha256"); then
    printf 'Exact deployed package embedded manifest: PASS\n'
  else
    printf 'Exact deployed package embedded manifest: MISMATCH; stop for parity audit\n'
  fi
else
  printf 'Exact deployed package embedded manifest: MISSING; parity unverified\n'
fi
# Hash only the names/content of source files, not WordPress secrets or user uploads.
manifest="$(mktemp)"
trap 'rm -f -- "$manifest"' EXIT
(
  cd "$PLUGIN_DIR"
  while IFS= read -r -d '' relative; do sha256sum -- "$relative"; done < <(find . -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.svg' \) -print0 | LC_ALL=C sort -z)
) > "$manifest"
printf 'Deployed source files: %s\n' "$(wc -l < "$manifest" | tr -d ' ')"
printf 'Deployed source manifest digest: %s\n' "$(sha256sum "$manifest" | awk '{print $1}')"
printf 'Runtime error logs: NOT READ (may contain private user content).\n'
printf 'Deployment parity: compare this exact site snapshot with the approved immutable package; do not infer from GitHub HEAD alone.\n'
