#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"

echo '== Required canonical surfaces =='
required=(
  sabri-network.php readme.txt CHANGELOG.md SECURITY.md CF01-COMMUNICATION-CONTEXT-CONTRACT.md
  includes/class-sn-db.php includes/class-sn-auth.php includes/class-sn-messages.php includes/class-sn-outbox.php includes/class-sn-privacy.php
  includes/class-sn-cf01-clinical-context.php includes/class-sn-communication-crypto.php includes/class-sn-message-body.php
  includes/class-sn-central-plan-hardening.php includes/class-sn-compatibility-hardening.php
  includes/class-sn-two-plan-completion.php includes/class-sn-two-plan-contract-firewall.php includes/class-sn-two-plan-presentation.php includes/class-sn-two-plan-runtime-hardening.php
  includes/class-sn-future-superset.php includes/class-sn-future24-review-hardening.php
  includes/class-sn-membership-assertions.php includes/class-sn-relationship-runtime-hardening.php includes/class-sn-message-runtime-hardening.php
  includes/class-sn-attachment-runtime-hardening.php includes/class-sn-space-runtime-hardening.php includes/class-sn-realtime-runtime-hardening.php
  includes/class-sn-call-runtime-hardening.php includes/class-sn-smail-runtime-hardening.php includes/class-sn-privacy-runtime-hardening.php includes/class-sn-safety-runtime-hardening.php
  includes/class-sn-runtime-boundary-policy.php includes/class-sn-round20-correction.php
  includes/class-sn-fourth-fresh-review-hardening.php includes/class-sn-fourth-fresh-search-hardening.php includes/class-sn-fourth-fresh-media-hardening.php
  includes/class-sn-fourth-fresh-lifecycle-hardening.php includes/class-sn-fourth-fresh-space-hardening.php includes/class-sn-fourth-fresh-realtime-hardening.php
  includes/class-sn-fourth-fresh-call-hardening.php includes/class-sn-fourth-fresh-smail-hardening.php includes/class-sn-fourth-fresh-transfer-hardening.php
  includes/class-sn-fourth-fresh-privacy-hardening.php includes/class-sn-fourth-fresh-safety-hardening.php includes/class-sn-fourth-fresh-crypto-hardening.php
  includes/class-sn-fourth-fresh-knowledge-hardening.php includes/class-sn-fourth-fresh-interop-hardening.php
  includes/class-sn-fifth-fresh-privacy-hardening.php includes/class-sn-fifth-fresh-integration-hardening.php
  includes/class-sn-fifth-fresh-feature-hardening.php includes/class-sn-fifth-fresh-knowledge-hardening.php
  includes/class-sn-fifth-fresh-migration-hardening.php includes/class-sn-fifth-fresh-ui-hardening.php
  includes/class-sn-sixth-fresh-privacy-hardening.php
  templates/network-standalone.php templates/messages-standalone.php templates/meet-app.php templates/smail-app.php templates/file-transfer-app.php
  assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/js/fifth-fresh-ui.js assets/js/round20-correction.js
  assets/css/network.css assets/css/messages.css assets/css/meet.css assets/css/message-search.css assets/css/smail.css assets/css/file-transfer.css assets/css/brand-green-overrides.css assets/css/two-plan-ui.css assets/css/future-superset.css
  tools/quality-check.sh tools/package.sh
)
for file in "${required[@]}"; do test -f "$file" || { echo "Missing required file: $file" >&2; exit 1; }; done
echo 'Required canonical surfaces: PASS'

echo '== PHP syntax =='
php_count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  printf 'PASS %s\n' "$file"
  php_count=$((php_count+1))
done < <(find . -path './build' -prune -o -name '*.php' -type f -print0 | sort -z)
(( php_count >= 90 )) || { echo "Unexpected PHP inventory contraction: $php_count" >&2; exit 1; }

echo '== JavaScript and shell syntax =='
js_files=(assets/js/network.js assets/js/meet.js assets/js/messages.js assets/js/message-search.js assets/js/smail.js assets/js/file-transfer.js assets/js/two-plan-ui.js assets/js/future-superset.js assets/js/fifth-fresh-ui.js assets/js/round20-correction.js)
for file in "${js_files[@]}"; do node --check "$file"; printf 'PASS %s\n' "$file"; done
bash -n tools/quality-check.sh
bash -n tools/package.sh

echo '== All File 17 review and correction suites =='
tests=(
 another-fresh-r6-spaces-contracts.php another-fresh-r7-relationship-contracts.php
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
 two-plan-completion-contracts.php future24-forty-round-corrective-static-contracts.php fourth-fresh-twenty-round-contracts.php
 fifth-fresh-twenty-round-contracts.php fifth-fresh-migration-contracts.php fifth-fresh-closure-contracts.php fifth-fresh-release-truth-contracts.php
 sixth-fresh-twenty-round-contracts.php seventh-fresh-ten-round-contracts.php
)
for test_file in "${tests[@]}"; do php "tests/$test_file"; done
mapfile -t expected_tests < <(find tests -maxdepth 1 -type f -name '*.php' -printf '%f\n' | LC_ALL=C sort)
mapfile -t invoked_tests < <(printf '%s\n' "${tests[@]}" | LC_ALL=C sort -u)
if [[ "$(printf '%s\n' "${expected_tests[@]}")" != "$(printf '%s\n' "${invoked_tests[@]}")" ]]; then
  echo 'The explicit quality gate does not invoke every PHP review suite exactly by name.' >&2
  diff -u <(printf '%s\n' "${expected_tests[@]}") <(printf '%s\n' "${invoked_tests[@]}") >&2 || true
  exit 1
fi
echo "Review-suite inventory: PASS (${#expected_tests[@]} suites)"

echo '== CSS integrity and accessibility baselines =='
python3 - <<'PY'
from pathlib import Path
paths=('assets/css/network.css','assets/css/meet.css','assets/css/messages.css','assets/css/message-search.css','assets/css/smail.css','assets/css/file-transfer.css','assets/css/brand-green-overrides.css','assets/css/two-plan-ui.css','assets/css/future-superset.css')
for path in paths:
    text=Path(path).read_text(encoding='utf-8')
    assert text.count('{')==text.count('}'),f'{path}: unbalanced braces'
for path in ('assets/css/meet.css','assets/css/messages.css','assets/css/message-search.css','assets/css/smail.css','assets/css/file-transfer.css','assets/css/two-plan-ui.css','assets/css/future-superset.css'):
    text=Path(path).read_text(encoding='utf-8')
    assert 'prefers-reduced-motion' in text
    assert '44px' in text
green=Path('assets/css/brand-green-overrides.css').read_text(encoding='utf-8').lower()
assert '#087a4e' in green and '#ff8a1f' in green
assert '#sn-notifications-button' in green and 'display: none !important' in green
assert ':focus-visible' in green
print('CSS integrity: PASS')
PY

echo '== Repository hygiene and private-communication invariants =='
if grep -RInE --include='*.php' --include='*.js' '(TODO|FIXME|HACK|console\.log\(|debugger;)' sabri-network.php includes templates assets/js; then echo 'Production source hygiene check failed.' >&2; exit 1; fi
if find . -path './.git' -prune -o -path './build' -prune -o -type f -size +5M -print | grep -q .; then echo 'Unexpected source file larger than 5 MB.' >&2; exit 1; fi
python3 - <<'PY'
from pathlib import Path
read=lambda p: Path(p).read_text(encoding='utf-8')
main=read('sabri-network.php'); membership=read('includes/class-sn-membership-assertions.php'); auth=read('includes/class-sn-auth.php')
smail='\n'.join(read(str(p)) for p in [Path('includes/class-sn-smail.php'),*sorted(Path('includes').glob('class-sn-smail-part-*.php'))])
transfer='\n'.join(read(str(p)) for p in [Path('includes/class-sn-file-transfer.php'),*sorted(Path('includes').glob('class-sn-file-transfer-part-*.php'))])
crypto=read('includes/class-sn-communication-crypto.php'); body=read('includes/class-sn-message-body.php'); hard=read('includes/class-sn-central-plan-hardening.php'); compat=read('includes/class-sn-compatibility-hardening.php'); integrity=read('includes/class-sn-message-integrity.php'); search=read('includes/class-sn-message-search.php')
complete=read('includes/class-sn-two-plan-completion.php'); firewall=read('includes/class-sn-two-plan-contract-firewall.php'); presentation=read('includes/class-sn-two-plan-presentation.php'); runtime=read('includes/class-sn-two-plan-runtime-hardening.php')
future='\n'.join(read(p) for p in ['includes/class-sn-future-superset.php','includes/class-sn-future-superset-part-1.php','includes/class-sn-future-superset-part-2.php','includes/class-sn-future-superset-part-3.php','includes/class-sn-future-superset-core.php'])
future_ui=read('assets/js/future-superset.js'); ui=read('assets/js/two-plan-ui.js')
relationship=read('includes/class-sn-relationship-runtime-hardening.php'); message_runtime=read('includes/class-sn-message-runtime-hardening.php'); attachment_runtime=read('includes/class-sn-attachment-runtime-hardening.php'); space_runtime=read('includes/class-sn-space-runtime-hardening.php'); realtime_runtime=read('includes/class-sn-realtime-runtime-hardening.php'); call_runtime=read('includes/class-sn-call-runtime-hardening.php'); smail_runtime=read('includes/class-sn-smail-runtime-hardening.php'); privacy_runtime=read('includes/class-sn-privacy-runtime-hardening.php'); safety_runtime=read('includes/class-sn-safety-runtime-hardening.php'); fourth=read('includes/class-sn-fourth-fresh-review-hardening.php'); interop=read('includes/class-sn-fourth-fresh-interop-hardening.php')
fifth_loader=read('includes/class-sn-future24-review-hardening.php'); fifth_privacy=read('includes/class-sn-fifth-fresh-privacy-hardening.php'); fifth_integration=read('includes/class-sn-fifth-fresh-integration-hardening.php'); fifth_feature=read('includes/class-sn-fifth-fresh-feature-hardening.php'); fifth_knowledge=read('includes/class-sn-fifth-fresh-knowledge-hardening.php'); fifth_migration=read('includes/class-sn-fifth-fresh-migration-hardening.php'); fifth_ui=read('includes/class-sn-fifth-fresh-ui-hardening.php'); sixth_privacy=read('includes/class-sn-sixth-fresh-privacy-hardening.php')
for marker in ('SN_Smail::register()','SN_File_Transfer::register()',"'file_transfer_max_bytes' => SN_File_Transfer::MAX_FILE_BYTES",'SN_Central_Plan_Hardening::register()','SN_Compatibility_Hardening::register()',"define('SN_VERSION', '2.1.0')"):
    assert marker in main,marker
for marker in ('smc_communication_assertions','smc_membership_assertions','MIN_CONTRACT_VERSION'):
    assert marker in membership,marker
assert "get_user_meta($user_id, 'sn_phone_e164'" not in auth
assert "'phone' => $can_see_phone ? mb_substr(sanitize_text_field((string) $projection['phone'])" in auth
assert "'verified' => (bool) $projection['verified']" in auth
for marker in ('SN_Central_Plan_Hardening::resolve_smail_conversation','SN_Message_Integrity::send_message','encrypted_payload LONGTEXT','smail.sent'):
    assert marker in smail,marker
for marker in ('SN_Message_Runtime_Hardening::send_message','smail_projection_commit_failed','ERASE_BATCH'):
    assert marker in smail_runtime,marker
for marker in ('MAX_FILE_BYTES = 1073741824','x-chunk-sha256','sn_network_transfer_scan_result','Accept-Ranges: bytes','Cache-Control: private, no-store','random_bytes(12)','existing_storage_path','received_indices'):
    assert marker in transfer,marker
for forbidden in ('wp_insert_attachment','media_handle_upload','public_url'):
    assert forbidden not in transfer,forbidden
for marker in ('SNC3','SNC4','sn_network_communication_previous_secrets','needs_rotation'):
    assert marker in crypto,marker
assert 'sodium_crypto_secretbox' in crypto and 'aes-256-gcm' in crypto and 'hash_equals' in crypto
assert "PREFIX = 'SNE1:'" in body and 'SN_Communication_Crypto::encrypt' in body and 'SN_Communication_Crypto::decrypt' in body
assert 'SN_Message_Body::encrypt' in integrity and 'SN_Message_Body::decrypt_row' in search
assert 'SN_Message_Operations::is_hidden($viewer_id' in search and '$page_tail' in search
assert "'notification_owner' => 'file-19'" in main and "'global_search_owner' => 'file-26'" in main and 'sn_network_notification_requested' in hard
assert 'override_privacy_exporter' in compat and 'secure_forward_message' in compat and 'SN_Two_Plan_Completion::register()' in compat and 'SN_Two_Plan_Contract_Firewall::register()' in compat and 'SN_Two_Plan_Presentation::register()' in compat
for marker in ('START TRANSACTION','SELECT GET_LOCK','contact_request_conflict','space_membership_managed'):
    assert marker in relationship,marker
for marker in ('message_atomic_send_failed','SN_Message_Search::index_message','SN_Outbox::enqueue','caller-supplied message idempotency key'):
    assert marker in message_runtime,marker
for marker in ('scanner_required','attachment_integrity_mismatch','voice_note_metadata_finalize_failed'):
    assert marker in attachment_runtime,marker
assert 'sn:f17:space:' in space_runtime and 'sn:f17:presence:' in realtime_runtime
assert 'sn_call_eligibility_denied' in call_runtime and 'SN_Membership_Assertions::communication' in call_runtime
assert 'erase_message_batch' in privacy_runtime and 'SN_Safety_Runtime_Hardening::erase_user_report_data' in privacy_runtime
assert 'report_privacy_commit_failed' in safety_runtime
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
assert 'caller-supplied message idempotency key' in fourth and 'sn_interop_reconciliation_required' in interop
for class_name in ('SN_Fifth_Fresh_Privacy_Hardening','SN_Fifth_Fresh_Integration_Hardening','SN_Fifth_Fresh_Feature_Hardening','SN_Fifth_Fresh_Knowledge_Hardening','SN_Fifth_Fresh_Migration_Hardening','SN_Fifth_Fresh_UI_Hardening','SN_Sixth_Fresh_Privacy_Hardening'):
    assert class_name+'::register()' in fifth_loader,class_name
assert "'sabri-network-transfers' => 'erase_transfers'" in fifth_privacy and "SN_DB::table('transfer_sessions')" in fifth_privacy
assert 'context_attribution_erase_commit_failed' in fifth_integration and 'cf01_reference_erase_commit_failed' in fifth_integration
assert 'transcript_cipher' in fifth_feature and 'migrate_legacy_voice_transcripts' in fifth_feature
assert 'SN_Future24_Review_Hardening_G::ai_assistant' in fifth_knowledge and 'SN_Future24_Review_Hardening_G::semantic_search' in fifth_knowledge
assert 'SELECT GET_LOCK' in fifth_migration and 'verify_schema()' in fifth_migration and 'restore_version_snapshot' in fifth_migration and 'sn_meet_db_version' in fifth_migration
assert "wp_enqueue_style('sn-file17-brand-green')" in fifth_ui and "get_query_var('sn_messages_app')" in fifth_ui
assert 'if ($deleted !== 1)' in sixth_privacy and 'Message-version privacy erasure must be retried.' in sixth_privacy
print('Private communication, current-plan, Future-24 and sixth-cycle invariants: PASS')
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