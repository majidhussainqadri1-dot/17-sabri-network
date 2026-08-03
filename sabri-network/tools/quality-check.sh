#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

required=(
  sabri-network.php
  readme.txt
  CHANGELOG.md
  SECURITY.md
  includes/class-sn-db.php
  includes/class-sn-high-risk.php
  includes/class-sn-spaces-schema.php
  includes/class-sn-spaces-part-1.php
  includes/class-sn-spaces-part-2.php
  includes/class-sn-spaces-part-3.php
  includes/class-sn-spaces-part-4.php
  includes/class-sn-spaces-part-5.php
  includes/class-sn-spaces-part-6.php
  includes/class-sn-spaces-part-7.php
  includes/class-sn-spaces-part-8.php
  includes/class-sn-spaces-part-9.php
  includes/class-sn-spaces-part-10.php
  includes/class-sn-spaces.php
  includes/class-sn-presence-devices.php
  includes/class-sn-message-operations.php
  includes/class-sn-message-visibility.php
  includes/class-sn-context-adapters.php
  includes/class-sn-cf01-clinical-context.php
  includes/class-sn-conference-provider.php
  includes/class-sn-outbox.php
  includes/class-sn-message-search.php
  includes/class-sn-safety.php
  includes/class-sn-policy.php
  includes/class-sn-private-files.php
  includes/class-sn-privacy.php
  includes/class-sn-activator.php
  includes/class-sn-auth.php
  includes/class-sn-relationships.php
  includes/class-sn-admin.php
  includes/class-sn-rest.php
  includes/class-sn-ajax.php
  includes/class-sn-shortcode.php
  includes/class-sn-messages.php
  includes/class-sn-message-integrity.php
  includes/class-sn-meet.php
  templates/network-standalone.php
  templates/messages-standalone.php
  templates/meet-standalone.php
  tests/runtime-contracts.php
  tests/relationship-runtime-contracts.php
  tests/relationship-refresh-contracts.php
  tests/file25-contracts.php
  tests/message-visibility-runtime-contracts.php
  tests/rest-delegation-runtime-contracts.php
  tests/advanced-runtime-contracts.php
  tests/message-operation-runtime-contracts.php
  tests/spaces-polling-runtime-contracts.php
  tests/concurrency-runtime-contracts.php
  tests/lifecycle-runtime-contracts.php
  tests/spaces-lifecycle-runtime-contracts.php
  tests/spaces-advanced-runtime-contracts.php
  tests/spaces-concurrency-runtime-contracts.php
  tests/spaces-privacy-runtime-contracts.php
  tests/spaces-moderation-runtime-contracts.php
  tests/spaces-invitation-runtime-contracts.php
  tests/permission-oracle-runtime-contracts.php
  tests/realtime-runtime-contracts.php
  tests/safety-runtime-contracts.php
  tests/safety-concurrency-runtime-contracts.php
  tests/safety-lifecycle-runtime-contracts.php
  tests/fourth-review-runtime-contracts.php
  tests/completion-round1-static-contracts.php
  tests/completion-round1-runtime-contracts.php
  tests/completion-round2-static-contracts.php
  tests/completion-round2-runtime-contracts.php
  tests/completion-round3-static-contracts.php
  tests/completion-round3-runtime-contracts.php
  tests/standards-boundary-contracts.php
  tests/contracts.php
  tests/package-contracts.php
  tests/package-complete-surface-contracts.php
  tests/private-files.php
  tests/privacy.php
  tests/message-search-runtime-contracts.php
  tests/message-search-adversarial-contracts.php
  tests/outbox-runtime-contracts.php
  tests/search-outbox-race-contracts.php
  tests/message-receipts-runtime-contracts.php
  tests/message-receipts-adversarial-contracts.php
  tests/presence-typing-preferences-contracts.php
  tests/channel-call-revocation-contracts.php
  tests/report-idempotency-runtime-contracts.php
  tests/safety-integrity-static-contracts.php
  tests/safety-retention-concurrency-contracts.php
  tests/file24-assurance-contracts.php
  tests/forensic-review-3-static-contracts.php
  tests/forensic-review-3-runtime-contracts.php
  tests/meet-contracts.php
  tests/meet-review-round1-contracts.php
  tests/meet-review-round2-contracts.php
  tests/meet-review-round3-contracts.php
  tests/meet-review-round4-contracts.php
  tests/meet-interaction-contracts.php
  tests/meet-final-defects-contracts.php
  tests/meet-final-qa-contracts.php
  tests/cf01-clinical-context-static-contracts.php
  tests/cf01-clinical-context-runtime-contracts.php
  tools/quality-check.sh
  tools/package.sh
)
for f in "${required[@]}"; do
  test -f "$f" || { echo "Missing required file: $f" >&2; exit 1; }
done

php_count=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null
  php_count=$((php_count + 1))
done < <(find . -name '*.php' -not -path './dist/*' -print0)

mapfile -t php_only < <(find . -name '*.php' -not -path './dist/*' -print)
if ((${#php_only[@]} > 0)); then
  if grep -nH -E 'function[[:space:]]*\([^)]*\|[^)]*\)|function[[:space:]]*\([^)]*\b(mixed|never)\b|function[[:space:]]+[^\(]+\([^)]*\)[[:space:]]*:[[:space:]]*[^[:space:]]*\|' "${php_only[@]}"; then
    echo 'PHP syntax newer than the declared minimum was detected.' >&2
    exit 1
  fi
fi

mapfile -t js_files < <(find . -name '*.js' -not -path './dist/*' -print)
for f in "${js_files[@]}"; do
  node --check "$f" >/dev/null
done

bash -n tools/quality-check.sh
bash -n tools/package.sh

python3 - <<'PY'
from pathlib import Path
for p in Path('.').rglob('*'):
    if p.is_file() and 'dist' not in p.parts:
        data = p.read_bytes()
        if b'\r\n' in data:
            raise SystemExit(f'CRLF detected: {p}')
        if data and not data.endswith(b'\n'):
            raise SystemExit(f'Missing final newline: {p}')
PY

python3 - <<'PY'
from pathlib import Path
import re
root = Path('.')
text = '\n'.join(p.read_text(errors='ignore') for p in root.rglob('*') if p.is_file() and 'dist' not in p.parts)
forbidden = [
    'sabri_msg_otp',
    'SN_OTP',
    'wp_mail(',
    'wp_create_user(',
    'SN_Sms_Provider',
    'SN_Turn_Credentials',
    'https://cdn.jsdelivr.net',
    'fonts.googleapis.com',
]
for token in forbidden:
    if token in text:
        raise SystemExit(f'Forbidden token present: {token}')
if "define('SN_VERSION', '2.0.1')" not in (root/'sabri-network.php').read_text():
    raise SystemExit('Plugin version mismatch')
if 'Stable tag: 2.0.1' not in (root/'readme.txt').read_text():
    raise SystemExit('Readme version mismatch')
if "define('SN_CF01_COMMUNICATION_CONTEXT_VERSION', '1.0.0')" not in (root/'sabri-network.php').read_text():
    raise SystemExit('CF-01 communication-context version mismatch')
main = (root/'sabri-network.php').read_text()
if 'SN_Activator::retire_legacy_secrets();' not in main:
    raise SystemExit('Legacy secret retirement missing from upgrade path')
rest = (root/'includes/class-sn-rest.php').read_text()
if 'X-WP-Total' not in rest:
    raise SystemExit('Bounded pagination headers missing')
if 'SN_Auth::require_member' not in rest:
    raise SystemExit('Central membership authorization missing')
db = (root/'includes/class-sn-db.php').read_text()
for needle in [
    'UNIQUE KEY active_direct_pair',
    'UNIQUE KEY active_request_pair',
    'UNIQUE KEY idempotency_key',
    'UNIQUE KEY active_caller',
    'UNIQUE KEY active_recipient',
    'applied_at DATETIME NULL',
]:
    if needle not in db:
        raise SystemExit(f'Missing database invariant: {needle}')
if re.search(r'add_action\(\s*[\'\"]wp_ajax_', text) and 'check_ajax_referer' not in text:
    raise SystemExit('AJAX actions without nonce verification')
cf01 = (root/'includes/class-sn-cf01-clinical-context.php').read_text()
for needle in [
    "public const CONTRACT_NAME = 'sn.cf01.communication-context'",
    "'message_body_included' => false",
    "'attachment_included' => false",
    "'call_transcript_included' => false",
    "'chat_membership_is_not_treating_relationship' => true",
    "'requires_click_time_file17_authorization' => true",
]:
    if needle not in cf01:
        raise SystemExit(f'Missing CF-01 invariant: {needle}')
for forbidden_cf01 in ["SN_DB::table('messages')", "SN_DB::table('attachments')", "SN_DB::table('calls')"]:
    if forbidden_cf01 in cf01:
        raise SystemExit(f'CF-01 provider must not read communication content table: {forbidden_cf01}')
print('Static security checks passed')
PY

php tests/runtime-contracts.php
php tests/relationship-runtime-contracts.php
php tests/relationship-refresh-contracts.php
php tests/file25-contracts.php
php tests/message-visibility-runtime-contracts.php
php tests/rest-delegation-runtime-contracts.php
php tests/advanced-runtime-contracts.php
php tests/message-operation-runtime-contracts.php
php tests/spaces-polling-runtime-contracts.php
php tests/concurrency-runtime-contracts.php
php tests/lifecycle-runtime-contracts.php
php tests/spaces-lifecycle-runtime-contracts.php
php tests/spaces-advanced-runtime-contracts.php
php tests/spaces-concurrency-runtime-contracts.php
php tests/spaces-privacy-runtime-contracts.php
php tests/spaces-moderation-runtime-contracts.php
php tests/spaces-invitation-runtime-contracts.php
php tests/permission-oracle-runtime-contracts.php
php tests/realtime-runtime-contracts.php
php tests/safety-runtime-contracts.php
php tests/safety-concurrency-runtime-contracts.php
php tests/safety-lifecycle-runtime-contracts.php
php tests/fourth-review-runtime-contracts.php
php tests/completion-round1-static-contracts.php
php tests/completion-round1-runtime-contracts.php
php tests/completion-round2-static-contracts.php
php tests/completion-round2-runtime-contracts.php
php tests/completion-round3-static-contracts.php
php tests/completion-round3-runtime-contracts.php
php tests/standards-boundary-contracts.php
php tests/contracts.php
php tests/package-contracts.php
php tests/package-complete-surface-contracts.php
php tests/private-files.php
php tests/privacy.php
php tests/message-search-runtime-contracts.php
php tests/message-search-adversarial-contracts.php
php tests/outbox-runtime-contracts.php
php tests/search-outbox-race-contracts.php
php tests/message-receipts-runtime-contracts.php
php tests/message-receipts-adversarial-contracts.php
php tests/presence-typing-preferences-contracts.php
php tests/channel-call-revocation-contracts.php
php tests/report-idempotency-runtime-contracts.php
php tests/safety-integrity-static-contracts.php
php tests/safety-retention-concurrency-contracts.php
php tests/file24-assurance-contracts.php
php tests/forensic-review-3-static-contracts.php
php tests/forensic-review-3-runtime-contracts.php
php tests/meet-contracts.php
php tests/meet-review-round1-contracts.php
php tests/meet-review-round2-contracts.php
php tests/meet-review-round3-contracts.php
php tests/meet-review-round4-contracts.php
php tests/meet-interaction-contracts.php
php tests/meet-final-defects-contracts.php
php tests/meet-final-qa-contracts.php
php tests/cf01-clinical-context-static-contracts.php
php tests/cf01-clinical-context-runtime-contracts.php

python3 - <<'PY'
from pathlib import Path
import re
css = Path('assets/css/network.css').read_text()
for token in ['@media (max-width: 768px)', '@media (max-width: 480px)', ':focus-visible', '.sn-modal[hidden]']:
    if token not in css:
        raise SystemExit(f'Missing CSS contract: {token}')
if re.search(r'outline\s*:\s*none', css, re.I):
    raise SystemExit('Focus outline removed')
print('CSS checks passed')
PY

find . -type f -not -path './dist/*' -not -path './CHECKSUMS.sha256' -not -path './tools/refresh-checksums.sh' -not -path './build-manifest.txt' -print0 \
  | LC_ALL=C sort -z \
  | xargs -0 sha256sum > build-manifest.txt
sort -c build-manifest.txt

expected_manifest="$(mktemp)"
trap 'rm -f "$expected_manifest"' EXIT
{
  sed 's#  \./#  #' build-manifest.txt
  sha256sum tools/refresh-checksums.sh
} | LC_ALL=C sort -k2 > "$expected_manifest"
LC_ALL=C sort -k2 CHECKSUMS.sha256 | diff -u - "$expected_manifest"
sha256sum -c CHECKSUMS.sha256 >/dev/null

BUILD_EPOCH="${SOURCE_DATE_EPOCH:-$(stat -c %Y sabri-network.php)}"
rm -rf ../dist
SOURCE_DATE_EPOCH="$BUILD_EPOCH" bash tools/package.sh >/dev/null
cp ../dist/sabri-network-2.0.1.zip /tmp/sn-build-a.zip
cp ../dist/sabri-network-2.0.1.zip.sha256 /tmp/sn-build-a.sha256
rm -rf ../dist
SOURCE_DATE_EPOCH="$BUILD_EPOCH" bash tools/package.sh >/dev/null
cmp -s /tmp/sn-build-a.zip ../dist/sabri-network-2.0.1.zip
cmp -s /tmp/sn-build-a.sha256 ../dist/sabri-network-2.0.1.zip.sha256
unzip -t ../dist/sabri-network-2.0.1.zip >/dev/null

printf 'Quality checks passed: %d PHP files, %d JS files.\n' "$php_count" "${#js_files[@]}"
