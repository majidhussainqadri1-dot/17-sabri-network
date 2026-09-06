# File 17 — Fresh 20-Round Review — Round 5 Ledger Freeze

Date: 2026-09-06
Reviewed parent HEAD: `7eb9eaacf230a7c9ca37c414d0e22b90602aac4d`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before fix

Round 5 completed a fresh whole-candidate review, with deep emphasis on Smail, large-file transfer, private storage containment, malware/archive validation, caller-owned idempotency, duplicate/race reconciliation, draft optimistic concurrency, recipient projections, transfer byte cleanup, and final route precedence. The final Smail route at priority 2240 and the runtime mutation layer at priority 2150 were reviewed together; the final transfer initiation owner at priority 2260 and canonical File Transfer implementation were also traced end-to-end.

## Frozen defect ledger

### R5-D01 — final Smail paths can misclassify database read failure as not-found or idempotency conflict
Severity: Medium-High
Status: PROVED

Several authoritative Smail reads do not distinguish a failed SQL read from a genuine missing/mismatching row:

- final `SN_Fourth_Fresh_Smail_Hardening::save_draft()` pre-read;
- final `delete_draft()` pre-read;
- `SN_Smail_Runtime_Hardening::save_draft()` in-lock version read;
- `update_state()` in-lock state read;
- `same_send_request()` canonical message and recipient-state reads used to decide whether an idempotency-key replay is a true duplicate or a conflict.

A database error can therefore surface as 404 or 409 rather than a retryable database failure. In duplicate reconciliation this can incorrectly tell a caller that the same idempotency key was used for different content when the system actually failed to read committed canonical recipient/message truth.

No equally strong new transfer defect was proved after tracing the final transfer route, storage containment, scanner, chunk ledger, revocation, and cleanup owners.

## Required correction frozen

Authoritative Smail reads in the final route/runtime chain must clear and check `$wpdb->last_error`; read failure must fail closed with a retryable 503-style error, never be converted into not-found or idempotency conflict. Exact-request duplicate comparison must be able to propagate a read error distinctly from `false` mismatch.

## Regression requirement

Permanent Smail regression must prove explicit database-read failure codes exist in final draft/state paths and that `same_send_request()` supports `WP_Error` propagation for canonical duplicate truth.

## Freeze statement

No production code was changed during Round 5 review. This ledger freezes the complete Round 5 finding set before correction.
