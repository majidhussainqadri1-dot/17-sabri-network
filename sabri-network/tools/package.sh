#!/usr/bin/env bash
set -euo pipefail
export LC_ALL=C
export TZ=UTC
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"; STAGE="$BUILD/stage"; BASE="17-sabri-network-and-messages-2.1.0"
PACKAGE="$BUILD/$BASE.zip"; PACKAGE_SHA="$BUILD/$BASE.zip.sha256"; SOURCE_MANIFEST="$BUILD/$BASE.manifest.sha256"; FIXED_TIMESTAMP="198001010000.00"
rm -rf "$STAGE" "$PACKAGE" "$PACKAGE_SHA" "$SOURCE_MANIFEST"; mkdir -p "$STAGE/sabri-network"
rsync -a --exclude='.git/' --exclude='.github/' --exclude='.gitignore' --exclude='build/' --exclude='tests/' --exclude='tools/' --exclude='REVIEW-REPORT.md' "$ROOT/" "$STAGE/sabri-network/"
if find "$STAGE/sabri-network" -type l -print -quit | grep -q .; then echo 'Packaging refused: symbolic links are not permitted in the release tree.' >&2; exit 1; fi
find "$STAGE/sabri-network" -type d -exec chmod 0755 {} +
find "$STAGE/sabri-network" -type f -exec chmod 0644 {} +
find "$STAGE/sabri-network" -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l >/dev/null
for file in network.js meet.js messages.js message-search.js smail.js file-transfer.js two-plan-ui.js future-superset.js; do
  node --check "$STAGE/sabri-network/assets/js/$file"
done
grep -q 'Version: 2.1.0' "$STAGE/sabri-network/sabri-network.php"
grep -q "define('SN_CF01_COMMUNICATION_CONTEXT_VERSION', '1.0.0')" "$STAGE/sabri-network/sabri-network.php"
required=(
  includes/class-sn-cf01-clinical-context.php CF01-COMMUNICATION-CONTEXT-CONTRACT.md
  includes/class-sn-smail.php includes/class-sn-file-transfer.php
  includes/class-sn-communication-crypto.php includes/class-sn-message-body.php
  includes/class-sn-central-plan-hardening.php includes/class-sn-compatibility-hardening.php
  includes/class-sn-two-plan-completion.php includes/class-sn-two-plan-contract-firewall.php
  includes/class-sn-two-plan-presentation.php includes/class-sn-two-plan-runtime-hardening.php
  includes/class-sn-membership-assertions.php includes/class-sn-relationship-runtime-hardening.php
  includes/class-sn-message-runtime-hardening.php includes/class-sn-attachment-runtime-hardening.php
  includes/class-sn-space-runtime-hardening.php includes/class-sn-realtime-runtime-hardening.php
  includes/class-sn-call-runtime-hardening.php includes/class-sn-smail-runtime-hardening.php
  includes/class-sn-privacy-runtime-hardening.php includes/class-sn-safety-runtime-hardening.php
  includes/class-sn-future-superset.php includes/class-sn-future24-review-hardening.php
  includes/class-sn-runtime-boundary-policy.php
  includes/class-sn-fourth-fresh-review-hardening.php includes/class-sn-fourth-fresh-search-hardening.php
  includes/class-sn-fourth-fresh-media-hardening.php includes/class-sn-fourth-fresh-lifecycle-hardening.php
  includes/class-sn-fourth-fresh-space-hardening.php includes/class-sn-fourth-fresh-realtime-hardening.php
  includes/class-sn-fourth-fresh-call-hardening.php includes/class-sn-fourth-fresh-smail-hardening.php
  includes/class-sn-fourth-fresh-transfer-hardening.php includes/class-sn-fourth-fresh-privacy-hardening.php
  includes/class-sn-fourth-fresh-safety-hardening.php includes/class-sn-fourth-fresh-crypto-hardening.php
  includes/class-sn-fourth-fresh-knowledge-hardening.php includes/class-sn-fourth-fresh-interop-hardening.php
  assets/js/two-plan-ui.js assets/js/future-superset.js
  assets/css/two-plan-ui.css assets/css/future-superset.css
  templates/smail-app.php templates/file-transfer-app.php
)
for file in "${required[@]}"; do test -f "$STAGE/sabri-network/$file" || { echo "Missing governed package surface: $file" >&2; exit 1; }; done
(
 cd "$STAGE"
 find sabri-network -type f ! -name 'MANIFEST.sha256' -print | sort | while IFS= read -r file; do sha256sum "$file"; done > sabri-network/MANIFEST.sha256
 sha256sum -c sabri-network/MANIFEST.sha256 >/dev/null
)
cp "$STAGE/sabri-network/MANIFEST.sha256" "$SOURCE_MANIFEST"
find "$STAGE/sabri-network" -exec touch -h -t "$FIXED_TIMESTAMP" {} +
(cd "$STAGE"; find sabri-network -type f -print | sort | zip -X -q "$PACKAGE" -@)
unzip -t "$PACKAGE" >/dev/null
(cd "$BUILD"; sha256sum "$BASE.zip" > "$BASE.zip.sha256")
rm -rf "$STAGE"
printf 'Package: %s\nSource manifest: %s\nSHA-256: ' "$PACKAGE" "$SOURCE_MANIFEST"; cut -d' ' -f1 "$PACKAGE_SHA"
