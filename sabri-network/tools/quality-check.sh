#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
echo '== Required canonical surfaces =='
required=(sabri-network.php readme.txt CHANGELOG.md SECURITY.md CF01-COMMUNICATION-CONTEXT-CONTRACT.md includes/class-sn-db.php includes/class-sn-auth.php includes/class-sn-messages.php includes/class-sn-outbox.php includes/class-sn-privacy.php includes/class-sn-cf01-clinical-context.php includes/class-sn-communication-crypto.php includes/class-sn-message-body.php includes/class-sn-central-plan-hardening.php includes/class-sn-compatibility-hardening.php includes/class-sn-two-plan-completion.php includes/class-sn-two-plan-contract-firewall.php includes/class-sn-two-plan-presentation.php includes/class-sn-two-plan-runtime-hardening.php includes/class-sn-future-superset.php includes/class-sn-future-superset-part-1.php includes/class-sn-future-superset-part-2.php includes/class-sn-future-superset-part-3.php includes/class-sn-future-superset-core.php includes/class-sn-smail.php includes/class-sn-file-transfer.php templates/network-standalone.php templates/messages-standalone.php templates/meet-app.php templates/smail-app.php templates/file-transfer-app.php assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/css/smail.css assets/css/file-transfer.css assets/css/two-plan-ui.css assets/css/future-superset.css tools/quality-check.sh tools/package.sh)
for file in "${required[@]}"; do test -f "$file" || { echo "Missing required file: $file" >&2; exit 1; }; done
echo 'Required canonical surfaces: PASS'
echo '== PHP syntax =='
php_count=0
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; printf 'PASS %s\n' "$file"; php_count=$((php_count+1)); done < <(find . -path './build' -prune -o -name '*.php' -type f -print0 | sort -z)
(( php_count >= 80 )) || { echo "Unexpected PHP inventory contraction: $php_count" >&2; exit 1; }
echo '== JavaScript syntax =='
js_files=(assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js)
for file in "${js_files[@]}"; do node --check "$file"; printf 'PASS %s\n' "$file"; done
bash -n tools/quality-check.sh; bash -n tools/package.sh

echo '== All File 17 review and correction suites =='
tests=(
 static-contracts.php adversarial-contracts.php
 realtime-static-contracts.php realtime-adversarial-contracts.php
 package-static-contracts.php package-adversarial-contracts.php
 safety-static-contracts.php safety-runtime-contracts.php safety-adversarial-contracts.php
 relationships-static-contracts.php relationships-runtime-contracts.php relationships-adversarial-contracts.php
 forensic-review-3-static-contracts.php forensic-review-3-adversarial-contracts.php
 rate-limit-runtime-contracts.php retention-lock-empty-runtime-contracts.php
 fourth-review-static-contracts.php fourth-review-adversarial-contracts.php policy-age-runtime-contracts.php
 meet-review-1-auth-state-contracts.php meet-review-2-concurrency-contracts.php meet-review-3-privacy-abuse-contracts.php meet-review-4-ui-package-contracts.php
 messages-review-1-static-contracts.php messages-review-2-adversarial-contracts.php
 search-outbox-review-1-static-contracts.php search-outbox-review-2-adversarial-contracts.php
 completion-review-1-architecture-governance-contracts.php completion-review-2-spaces-presence-message-contracts.php completion-review-3-context-conference-privacy-contracts.php completion-review-4-fresh-adversarial-release-contracts.php
 cf01-clinical-context-static-contracts.php cf01-clinical-context-runtime-contracts.php
 smail-static-contracts.php smail-adversarial-contracts.php transfer-static-contracts.php transfer-adversarial-contracts.php
 four-plan-review-1-governance-contracts.php four-plan-review-2-transfer-concurrency-contracts.php four-plan-review-3-message-smail-security-contracts.php four-plan-review-4-fresh-release-contracts.php
 forty-round-review-1-governance-identity-crypto-contracts.php forty-round-review-2-transfer-smail-privacy-contracts.php forty-round-review-3-canonical-safety-resilience-contracts.php forty-round-review-4-release-truth-contracts.php
 two-plan-completion-contracts.php
)
for test_file in "${tests[@]}"; do php "tests/$test_file"; done
mapfile -t expected_tests < <(find tests -maxdepth 1 -type f -name '*.php' -printf '%f\n' | LC_ALL=C sort)
mapfile -t invoked_tests < <(printf '%s\n' "${tests[@]}" | LC_ALL=C sort -u)
if [[ "$(printf '%s\n' "${expected_tests[@]}")" != "$(printf '%s\n' "${invoked_tests[@]}")" ]]; then echo 'The explicit quality gate does not invoke every PHP review suite exactly by name.' >&2; diff -u <(printf '%s\n' "${expected_tests[@]}") <(printf '%s\n' "${invoked_tests[@]}") >&2 || true; exit 1; fi
echo "Review-suite inventory: PASS (${#expected_tests[@]} suites)"

echo '== CSS integrity and accessibility baselines =='
python3 - <<'PY'
from pathlib import Path
paths=('assets/css/network.css','assets/css/meet.css','assets/css/messages.css','assets/css/message-search.css','assets/css/smail.css','assets/css/file-transfer.css','assets/css/brand-green-overrides.css','assets/css/two-plan-ui.css','assets/css/future-superset.css')
for path in paths:
 text=Path(path).read_text(encoding='utf-8'); assert text.count('{')==text.count('}'),f'{path}: unbalanced braces'
for path in ('assets/css/meet.css','assets/css/messages.css','assets/css/message-search.css','assets/css/smail.css','assets/css/file-transfer.css','assets/css/two-plan-ui.css','assets/css/future-superset.css'):
 text=Path(path).read_text(encoding='utf-8'); assert 'prefers-reduced-motion' in text; assert '44px' in text
for path in ('assets/css/smail.css','assets/css/file-transfer.css','assets/css/two-plan-ui.css','assets/css/future-superset.css'):
 text=Path(path).read_text(encoding='utf-8'); assert ('#137a46' in text or '#087a4e' in text); assert ('max-width:' in text or 'width:min(' in text or 'max-width' in text)
overrides=Path('assets/css/brand-green-overrides.css').read_text(encoding='utf-8'); assert '#sn-notifications-button' in overrides and 'display: none !important' in overrides
print('CSS integrity: PASS')
PY

echo '== Repository hygiene and private-communication invariants =='
if grep -RInE --include='*.php' --include='*.js' '(TODO|FIXME|HACK|console\.log\(|debugger;)' sabri-network.php includes templates assets/js; then echo 'Production source hygiene check failed.' >&2; exit 1; fi
if find . -path './.git' -prune -o -path './build' -prune -o -type f -size +5M -print | grep -q .; then echo 'Unexpected source file larger than 5 MB.' >&2; exit 1; fi
python3 - <<'PY'
from pathlib import Path
main=Path('sabri-network.php').read_text(); smail='\n'.join(Path(p).read_text() for p in ['includes/class-sn-smail.php',*sorted(str(x) for x in Path('includes').glob('class-sn-smail-part-*.php'))]); transfer='\n'.join(Path(p).read_text() for p in ['includes/class-sn-file-transfer.php',*sorted(str(x) for x in Path('includes').glob('class-sn-file-transfer-part-*.php'))]); crypto=Path('includes/class-sn-communication-crypto.php').read_text(); body=Path('includes/class-sn-message-body.php').read_text(); hard=Path('includes/class-sn-central-plan-hardening.php').read_text(); compat=Path('includes/class-sn-compatibility-hardening.php').read_text(); integrity=Path('includes/class-sn-message-integrity.php').read_text(); search=Path('includes/class-sn-message-search.php').read_text(); complete=Path('includes/class-sn-two-plan-completion.php').read_text(); firewall=Path('includes/class-sn-two-plan-contract-firewall.php').read_text(); presentation=Path('includes/class-sn-two-plan-presentation.php').read_text(); runtime=Path('includes/class-sn-two-plan-runtime-hardening.php').read_text(); future='\n'.join(Path(p).read_text() for p in ['includes/class-sn-future-superset.php','includes/class-sn-future-superset-part-1.php','includes/class-sn-future-superset-part-2.php','includes/class-sn-future-superset-part-3.php','includes/class-sn-future-superset-core.php']); ui=Path('assets/js/two-plan-ui.js').read_text(); future_ui=Path('assets/js/future-superset.js').read_text()
for marker in ('SN_Smail::register()','SN_File_Transfer::register()',"'file_transfer_max_bytes' => SN_File_Transfer::MAX_FILE_BYTES",'SN_Central_Plan_Hardening::register()','SN_Compatibility_Hardening::register()',"define('SN_VERSION', '2.1.0')"):
 assert marker in main,marker
for marker in ('SN_Central_Plan_Hardening::resolve_smail_conversation','SN_Message_Integrity::send_message','encrypted_payload LONGTEXT',"smail.sent"):
 assert marker in smail,marker
assert 'SN_REST::send_message' not in smail
for marker in ('MAX_FILE_BYTES = 1073741824','x-chunk-sha256','sn_network_transfer_scan_result','Accept-Ranges: bytes','Cache-Control: private, no-store','random_bytes(12)','existing_storage_path'):
 assert marker in transfer,marker
for forbidden in ('wp_insert_attachment','media_handle_upload','public_url'):
 assert forbidden not in transfer,forbidden
for marker in ('SNC3','SNC4','sn_network_communication_previous_secrets','needs_rotation'):
 assert marker in crypto,marker
assert 'sodium_crypto_secretbox' in crypto and 'aes-256-gcm' in crypto and 'hash_equals' in crypto
assert "PREFIX = 'SNE1:'" in body and 'SN_Communication_Crypto::encrypt' in body and 'SN_Communication_Crypto::decrypt' in body
assert 'SN_Message_Body::encrypt' in integrity and 'SN_Message_Body::decrypt_row' in search
assert "'notification_owner' => 'file-19'" in main and "'global_search_owner' => 'file-26'" in main and 'sn_network_notification_requested' in hard
assert 'override_privacy_exporter' in compat and 'secure_forward_message' in compat and 'SN_Two_Plan_Completion::register()' in compat and 'SN_Two_Plan_Contract_Firewall::register()' in compat and 'SN_Two_Plan_Presentation::register()' in compat
for marker in ('/message-requests','/scheduled-messages','/polls','/checklists','/translate','community-health','SN_Communication_Crypto::encrypt'):
 assert marker in complete,marker
assert 'wp_insert_attachment' not in complete and 'media_handle_upload' not in complete
for marker in ('sn_idempotency_key_required','response_cipher','discoverable_private','/future/e2ee-policy','/future/ai-assistant','/future/interop'):
 assert marker in firewall,marker
for marker in ('/messages/starred','/structured','/responses','enqueue_messages_assets'):
 assert marker in presentation,marker
for marker in ('Message requests','Schedule','Poll','Checklist','Voice note','Community center','Idempotency-Key'):
 assert marker in ui,marker
assert "require_once SN_DIR . 'includes/class-sn-future-superset.php'" in runtime and 'SN_Future_Superset::register()' in runtime
assert 'FEATURE_COUNT=24' in future
for i in range(1,25): assert f'F17-FUT-{i:02d}' in future,f'F17-FUT-{i:02d}'
for marker in ('sn_network_e2ee_provider_status','sn_network_step_up_verified','sn_network_notification_requested','sn_network_ai_assistant_result','sn_network_private_semantic_search_result','sn_network_interop_provider_ready'):
 assert marker in future,marker
assert "'exported_to_file26'=>false" in future and "'ai_owner'=>'file-16'" in future
assert 'future/capabilities' in future_ui and 'future/reminders' in future_ui and 'future/templates' in future_ui
print('Private communication, current-plan and Future-24 invariants: PASS')
PY

echo '== Exact staged source manifest and reproducible package =='
BASE='17-sabri-network-and-messages-2.1.0'; rm -rf build; bash tools/package.sh >/tmp/file17-package-first.log
for suffix in zip zip.sha256 manifest.sha256; do test -f "build/${BASE}.${suffix}" || { echo "Missing build artifact: ${BASE}.${suffix}" >&2; exit 1; }; done
first_hash="$(sha256sum "build/${BASE}.zip"|cut -d' ' -f1)"; cp "build/${BASE}.zip" /tmp/file17-package-first.zip; cp "build/${BASE}.zip.sha256" /tmp/file17-package-first.zip.sha256; cp "build/${BASE}.manifest.sha256" /tmp/file17-package-first.manifest.sha256
rm -rf build; bash tools/package.sh >/tmp/file17-package-second.log; second_hash="$(sha256sum "build/${BASE}.zip"|cut -d' ' -f1)"
if [[ "$first_hash" != "$second_hash" ]]; then echo "Reproducible packaging failed: $first_hash != $second_hash" >&2; exit 1; fi
cmp -s /tmp/file17-package-first.zip "build/${BASE}.zip"; cmp -s /tmp/file17-package-first.zip.sha256 "build/${BASE}.zip.sha256"; cmp -s /tmp/file17-package-first.manifest.sha256 "build/${BASE}.manifest.sha256"
(cd build; sha256sum -c "${BASE}.zip.sha256" >/dev/null)
VERIFY="$(mktemp -d)"; trap 'rm -rf "$VERIFY" /tmp/file17-package-first.zip /tmp/file17-package-first.zip.sha256 /tmp/file17-package-first.manifest.sha256 /tmp/file17-package-first.log /tmp/file17-package-second.log' EXIT
unzip -q "build/${BASE}.zip" -d "$VERIFY"; cmp -s "build/${BASE}.manifest.sha256" "$VERIFY/sabri-network/MANIFEST.sha256"; (cd "$VERIFY"; sha256sum -c sabri-network/MANIFEST.sha256 >/dev/null)
echo "Exact staged source manifest: PASS"; echo "Reproducible package: PASS ($first_hash)"; printf 'QUALITY CHECK: PASS (%d PHP files, %d JS files, %d review suites)\n' "$php_count" "${#js_files[@]}" "${#expected_tests[@]}"
