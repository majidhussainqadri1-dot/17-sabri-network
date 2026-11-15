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
