# File 17 — Fresh 20-Round Review — Round 1 Ledger Freeze

Date: 2026-09-06
Branch: `review/file17-fresh-20-round-2026-09-06`
Reviewed parent HEAD: `4973997802565ae02769c2e4cede986fb4edd57f`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review scope completed before any fix

Round 1 completed a fresh whole-repository static/adversarial pass, with a deep focus on the final route-owner graph, relationship/contact/block/direct-conversation mutations, transaction boundaries, authoritative row locks, canonical state reconciliation, schema/version ownership, privacy/safety boundaries, Future-24 final overrides, package surface, and the exact quality-gate inventory. Legacy findings were not treated as current defects unless the final active route owner still exposed them.

## Frozen defect ledger

### R1-D01 — final relationship mutation owner can continue after authoritative lock-read SQL failure
Severity: High
Status at freeze: PROVED

`SN_Relationship_Runtime_Hardening` is the final active owner for contact request/decision, block/unblock, and conversation creation. Its mutation paths start transactions and use `FOR UPDATE`, but several authoritative reads do not prove DB success before their result is interpreted as canonical state:

- `request_contact()` reads the contact row `FOR UPDATE` without checking `$wpdb->last_error`.
- `decide_contact()` performs both the pre-lock probe and the in-transaction `FOR UPDATE` read without distinguishing SQL failure from a genuinely absent row.
- `block_user()` reads the contact row, follow rows, and direct-conversation row `FOR UPDATE` without checking DB read truth before block/contact/follow/call mutations continue.
- `create_conversation()` reads the canonical space/member projection and the direct conversation/member rows without a consistent DB-error gate. A failed lock-read can therefore be interpreted as absence and reach write/reconciliation paths rather than failing closed immediately.

This is a state-truth/concurrency defect: a lock statement appearing in SQL is not proof that the lock was actually acquired when the DB read itself failed.

## Required correction frozen

The fix must make final relationship read/lock truth fail closed before any dependent mutation or success classification. Read failures must not be converted into `not_found`, duplicate absence, or a new-row path. Existing transaction rollback and canonical reconciliation semantics must remain intact.

## Regression requirement

Permanent relationship regression must prove that the final owner:
1. clears/checks `$wpdb->last_error` around authoritative contact/follow/conversation/member lock reads;
2. throws/fails closed before dependent writes when those reads fail;
3. preserves the existing transaction-start, commit-reconciliation, pair-lock and exact direct-membership guarantees.

## Freeze statement

No production fix was made during this Round 1 review. This ledger freezes the complete Round 1 defect set before correction begins.
