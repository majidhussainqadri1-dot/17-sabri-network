# File 17 V12.3 — Independent Review F: reminder authorization at dispatch boundary

**Review frozen before corrective code on 2026-11-15.**

## Actual governing V12.3 requirements
- Plan paragraph 1904, **F17-FUT-07**: timezone-safe due, cancellation/reschedule, idempotent due emission; delivery transport owner is File19; acceptance for duplicate scheduler runs, deleted conversation, blocked sender and revoked membership.
- Plan paragraph 256–258 **File17-CEN-01**: a single canonical communication owner and safe defaults.
- Plan paragraphs 541–549 **CEN-REL-001**: two reviews, exact build, test, staging, restore/rollback, zero known active-scope defects.
- **CEN-STATUS-001**: source/QA/staging/live/operational are different claims.

## Source freeze and actual effective path
- Source candidate at opening: PR #25 HEAD `f93a2e74ce17c5a2ab56363b73d57311b04b9b00`, based on PR #24 `d050c720d5945c9f2d58545751070ed41413fa9d`, main `8ae656e51796d1f05865d8be5dca2480443d79ca` **not modified**.
- `SN_Future24_Review_Hardening_M::register()` attaches `preflight_due()` to `sn_cleanup_hourly` priority 1, which cancels disallowed active due reminders.
- `SN_Future_Superset::register()` attaches `cleanup()` to the same hook at default priority 10.
- **Confirmed repository-source gap R07-REV-F-01:** after `preflight_due()` has finished, the real `SN_Future_Superset::cleanup()` selects due rows, decrypts, claims `firing` and dispatches `sn_network_notification_requested` with recipient and message context without a fresh membership, blocked sender, hidden/deleted message or scope authorization check. Revocation in the interval is not tested/blocked by the actual dispatch method.
- **Confirmed related gap R07-REV-F-02:** `SN_Future24_Review_Hardening_M::reschedule()` checks owner, state and expected version, but not current membership in the conversation. It can acknowledge a new future date for a removed member, leaving `preflight_due()` to cancel it later. Cancellation by the owner should remain available for safe cleanup after revocation.
- Both are **source-candidate** findings, not proven live incidents. Their impact depends on exact deployed bytes, companion contracts and timing.

## Intended bounded corrections
1. Recheck current owner membership and optional selected-message validity (same conversation, not deleted/hidden or blocked) **after the due row has been claimed but before calling File19's hook**.
2. Unauthorized due reminders must transition to `cancelled` with a CAS update and audit-safe metadata, not `fired`, and emit no notification.
3. If final cancellation CAS fails, never send anyway; retain a safe retry/repair path.
4. Require current membership on **reschedule** before changing due time. Retain ability for owner to cancel an already-owned reminder after revocation.
5. Add executable PHP negative tests: authorization removed between earlier preflight and due firing; hidden/deleted/blocked message after preflight; versioned reschedule after removal; authorized positive case and stable File19 idempotency.
6. If real notification receipt cannot be confirmed, report it as a cross-file File19 release blocker, **not** as an implemented durable acknowledgement. This audit alone cannot eliminate an ultra-short TOCTOU interval; staging must test the owner transaction/provider revocation contract.

## Release reality
- Jetpack site-disconnected; WPVibe no connected site at review opening. Exact deployed code/DB/migration/host logs unavailable.
- No production writes, deploy, merger or live re-test authorized or attempted.
- **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## Review F addendum — independent schema-state finding, frozen before correction
- **R07-REV-F-03 (P1/source):** canonical `SN_DB::is_member()` checks only an active member row (`left_at IS NULL`), **not** the canonical `sn_conversations.status`. The database defines `conversations.status`, and `SN_DB::share_active_conversation()` explicitly joins `c.status='active'`. The effective F17-FUT-07 `create_reminder`, `M::preflight_due`, `cleanup` and `M::reschedule` paths currently rely on membership alone. Consequently an archived/non-active conversation with retained membership can receive a new due reminder, be rescheduled or fire. V12.3 expressly requires correct behavior for deleted conversation and revoked membership. This is distinct from revocation *between* preflight and dispatch.
- **Corrective design:** implement an explicit **active conversation + current member** predicate in the canonical File17 DB owner. Use it only in reminder create, reminder preflight, due dispatch and reschedule, rather than silently changing the meaning of `is_member()` for all File17 contracts. Test non-active conversation with membership still present, a currently active conversation, and safe cancellation after removal.
- **No live evidence:** do not call this a proven production incident; the actually deployed schema and source remain unverified.

## Review F correction record, retest and no-go handoff (15 Nov 2026)
**Repository-only corrections:** all three frozen findings have targeted source patches in PR #25's review branch:
1. R07-REV-F-01: `SN_Future_Superset::cleanup()` rechecks scope/member and optional referenced message after claiming `firing`, just before the File19 hook. A revoked owner, removed/hidden/deleted message or blocked sender cancels the reminder using version-scoped CAS; if cancellation fails the state stays retryable/held, not `fired`. No private message plaintext is logged.
2. R07-REV-F-02: the effective Future24-M `reschedule()` now refuses an inactive member while the owner may still cancel a reminder they own.
3. R07-REV-F-03: canonical `SN_DB::is_active_conversation_member()` checks the current member row **joined to active `sn_conversations.status`**. It is applied to reminder create, earlier due preflight, dispatch recheck and reschedule. `SN_DB::is_member()` retains its wider meaning for other File17 functions.

**Regression tests:** existing `v12-3-reminder-authorized-create-runtime-contracts.php` covers 22 cases; `v12-3-reminder-dispatch-failclosed-runtime-contracts.php` covers 39 cases including simulated revocation **after** earlier preflight; `v12-3-reminder-versioned-mutations-runtime-contracts.php` covers 21; new `v12-3-reminder-active-conversation-membership-runtime-contracts.php` covers 10, including archived/deleted with a retained member row. All are **isolated mocks**, not actual DB staging or live.

**Exactly verified SHA:** `a600e0d6cf1dfd32b717204b44fdff999a2af7ee`, Actions `43668502319`: PHP 8.1 boundary **SUCCESS**, PHP 8.3 full quality + deterministic package **SUCCESS**. QA log: **188 PHP files, 10 JS source files, 67 PHP review suites**, UTC DOM 28 + New York DST DOM 32; deterministic installable ZIP SHA-256 `8db3c70e1684b5266b255859f41b98e761a3e80375a9c8d7ad9eaa86563ab06c`. These facts apply to **this exact immutable source SHA only**.

**Current counts across review series (source, not deployed):** Previous reviews A–E documented **11** new source paths on PR #25; this review F confirmed **3** more: total **14 newly tracked** within PR #25's review sequence. Earlier PR #23/#24 tracked **4** separate source paths: **18 total candidate-source paths** across all three unmerged stacked drafts. **14/14 current-series paths have targeted source corrections; 0/14 have live verification**. No claim of 18 actual live incidents or complete real-environment acceptance is warranted.

**Actual live access recheck:** WordPress/Jetpack lists the site but reports `site_disconnected`, WPVibe has zero connected sites; 2026-11-15 connect attempt returned **HTTP 530** (an access symptom, no proven root cause). Web direct open also did not retrieve content. Live deployed source, package checksum, WP/PHP/MySQL, DB/schema/options/migration, File19 durable acknowledgment, providers and logs remain **UNVERIFIED**.

**Remaining blockers:** real WordPress/MySQL migration and race/rollback tests, independently audited File19 delivery receipt and deduplication, File16 and File26 contracts, real browser/RTL/a11y, real scanning/1 GiB storage and SFU, two complete independent E2E reviews, backup/restore, founder approval, staged rollout and exact live parity/monitoring.

**Mandatory status footer**
- Repository HEAD (`main`): `8ae656e51796d1f05865d8be5dca2480443d79ca`
- Candidate code HEAD at verified QA: `a600e0d6cf1dfd32b717204b44fdff999a2af7ee`
- Deployed Version: UNVERIFIED
- DB Version: UNVERIFIED
- Migration State: UNVERIFIED
- Live Verification Status: UNVERIFIED
- V12.3 release verdict: **NO-GO / NOT OPERATIONAL**
- Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

**Evidence limit:** repository correction and CI cannot be translated into 'fixed on live'. If this report receives a later documentation commit, the source SHA above remains the verified code snapshot; run the new documentation HEAD's CI before claiming all checks pass for that precise HEAD.
