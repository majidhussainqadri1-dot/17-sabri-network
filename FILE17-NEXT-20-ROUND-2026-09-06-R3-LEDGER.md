# File 17 — Next Fresh 20-Round Review — Round 3 Frozen Ledger

Branch: `review/file17-next-20-round-2026-09-06`
Reviewed parent: `03310d91d87af47539aff765d645e959d33cb7b2`
Discipline: the complete Round-3 canonical message/send/edit/delete/receipt review was completed before any Round-3 correction. This ledger freezes all proved findings before fixes.

## Review scope completed

- final route precedence for message list/send/edit/delete/forward and receipt mutations;
- caller-owned message idempotency and same-key/different-request binding;
- attachment request fingerprinting and duplicate reconciliation;
- current File-00 access, conversation membership, block/contact and Space posting revalidation at commit time;
- message encryption, search indexing, outbox coupling and conversation last-message pointers;
- edit/delete optimistic versioning and post-commit attachment cleanup;
- recipient/device delivered/read receipt batching, membership serialization, event publication and progress truth.

## Frozen defects

### R3-D01 — Final receipt mutation can write outside its intended transaction when transaction start fails

The final receipt route is owned by `SN_Fourth_Fresh_Review_Hardening::record_receipt()`, which serializes the conversation and delegates to `SN_Message_Integrity::record_receipt()`. The delegated owner calls `$wpdb->query('START TRANSACTION')` without checking the result, then writes receipt rows, may advance `last_read_message_id`, queues an outbox event and commits. A failed transaction start can therefore allow these mutations under autocommit while the function still assumes rollback/atomicity semantics.

Required correction: `SN_Message_Integrity::record_receipt()` must fail closed before the first receipt mutation unless transaction start is proven.

### R3-D02 — Receipt progress truth can collapse database-read failure into false completion

`SN_Message_Integrity::record_receipt()` casts the authoritative per-device `MAX(message_id)` probe directly to integer without checking database error state, and after commit casts the bounded `more` probe directly to bool. A failed initial probe can restart the batch from zero, while a failed post-commit probe can publish `more=false`, causing a client to stop advancing a receipt range even though eligible messages remain.

Required correction: make both progress reads explicitly error-aware. A failed initial progress read must return a retryable error before mutation; a failed final completion probe must return a retryable/incomplete response rather than false completion, without undoing the already committed receipt work.

## Round verdict

R3 is defect-bearing. Fixes may begin only after this ledger freeze. Round 4 must not begin until regression coverage and exact-head PHP 8.1 plus PHP 8.3/full-quality CI are green.
