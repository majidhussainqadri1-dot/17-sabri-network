# File 17 — Another Fresh 10-Round Cycle — Round 4 Frozen Defect Ledger

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent HEAD: `d8774564fc041367911c8f6c1e2598f25144df2c`
Round: R4
Scope: lifecycle, scheduled delivery, disappearing-message retention/legal-hold serialization, structured-message transaction boundaries, translation/reminder authorization.

## Review discipline
This ledger freezes the complete R4 review before any R4 correction. No R4 source fix was started during the review.

## Frozen defects

### R4-D01 — Disappearing-message expiry can race a newly established legal hold
`SN_Two_Plan_Completion::expire_messages()` checks `message_has_legal_hold()` before its destructive transaction and does not serialize that check with the message-retention mutation boundary used by the final expiry owner. A safety/legal hold can become active after the pre-check and before message body/attachment/search evidence is erased. The helper also collapses a legal-hold query failure into `false`.

Required correction: expiry must acquire the canonical per-message retention lock, re-read the message and legal-hold truth inside the protected boundary, and fail closed on legal-hold read failure. Report/legal-hold mutations that target a message must participate in the same retention lock namespace.

### R4-D02 — Scheduled delivery can become permanently stranded in `processing`
`dispatch_due_scheduled()` claims a row as `processing`, but after the canonical message is committed it does not verify the final scheduled-row update to `sent`. There is also no stale-processing reclamation path. A crash or DB failure after claim/final message commit can leave the row permanently excluded from future dispatch scans.

Required correction: stale `processing` rows must be safely reclaimable after a bounded timeout; canonical message idempotency must prevent duplicate delivery; final state publication must be checked and failure returned to a retryable/reconcilable state.

### R4-D03 — Scheduled/structured canonical helper can mutate outside a transaction if START TRANSACTION fails
`SN_Two_Plan_Completion::insert_canonical_message()` calls `START TRANSACTION` without checking the return value when it owns the transaction. A failed transaction start can therefore allow message, pointer, search and outbox mutations to occur under autocommit despite the helper's atomicity contract.

Required correction: fail closed before the first mutation whenever transaction start fails.

### R4-D04 — Checklist mutation also ignores transaction-start failure
`toggle_checklist()` invokes `START TRANSACTION` without proving that the transaction started before locking/updating the canonical message and enqueueing its outbox event.

Required correction: fail closed before the locking read or any mutation if the transaction cannot start.

## Round verdict
R4 is defect-bearing. The above ledger is now frozen; correction may begin only after this point.
