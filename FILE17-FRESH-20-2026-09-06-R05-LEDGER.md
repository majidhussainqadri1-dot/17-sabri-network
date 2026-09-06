# File 17 Fresh 20-Round Review — Round 05 Frozen Ledger

Parent exact HEAD: `dc6980b013d3bdaaa75ce1ea80dc9d9ad416dee6`
Round-04 exact-head CI: PHP 8.1 PASS; PHP 8.3 PASS.

## Review scope
Final Smail route precedence, caller-owned idempotency and exact request reconstruction, recipient/contact authorization, direct/group conversation reservation, canonical message handoff, mailbox projection, outbox/audit coupling, draft encryption/version locking/deletion, state mutations, privacy export/erasure, retry/reconciliation behavior.

## Frozen defects

### R05-D01 — Smail could create/reuse its canonical conversation using request-scoped stale File-00 assertions
The pre-reservation contact checks did not refresh canonical File-00 state after the recipient pair locks were held.

### R05-D02 — Smail group conversation reservation did not prove transaction start
The group reservation path entered inserts after an unchecked `START TRANSACTION`.

## Frozen fixes applied
Production correction commit: `a5205327f839799c892c63bceafba9d0a0efe5aa`.

The Smail runtime now refreshes sender access and every recipient File-00 assertion under the existing recipient-pair serialization before any direct/group conversation reservation side effect. The group reservation path now fails closed unless the database transaction is confirmed started. Permanent current-boundary regression checks cover both corrections.

## Ledger status
`DEFECT-BEARING — 2 frozen defects; both corrected after ledger freeze.`

This documentation-only commit is the Round-05 exact regression/CI head. Round 06 may begin only after both declared quality jobs pass on this exact head.
