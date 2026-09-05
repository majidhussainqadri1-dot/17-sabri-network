# File 17 — Another Fresh 10-Round Cycle — Round 5 Frozen Defect Ledger

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent HEAD: `3ec6de4909523546175521dde23119c6a9cb263f`
Round: R5
Scope: private attachments/media, verified encrypted file transfer, transfer privacy, download integrity, communication crypto/storage boundaries.

## Review discipline
The complete R5 scope above was reviewed before this ledger was created. No R5 correction was started during review. This ledger freezes every R5 defect found before correction begins.

## Frozen defects

### R5-D01 — Daily transfer-volume enforcement fails open on DB read failure
`SN_File_Transfer::initiate()` casts the daily `SUM(total_bytes)` query directly to integer. A failed read therefore collapses to zero and can allow a new transfer to bypass the configured daily byte ceiling even though initiation is serialized by sender.

Required correction: explicitly check DB read failure/`last_error` and return a fail-closed 503 before any session insert.

### R5-D02 — Sender-side transfer revalidation can fail open when recipient ledger cannot be read
`recipient_ids()` returns the result of `get_col()` without an explicit DB-error contract. Sender revalidation then iterates the returned recipient list; a failed recipient read can collapse into an empty list and skip every recipient verification/block/relationship check.

Required correction: recipient-ledger reads used for authorization must return `WP_Error` on DB failure, and every caller that uses them for authorization/idempotency must fail closed.

### R5-D03 — Transfer privacy erasure can publish false completion after DB read failure
`erase_personal_data()` collapses sender/recipient batch reads and final `more_*`/leftover-chunk probes into empty/false values without checking DB errors. `delete_chunks()` likewise treats a failed chunk-row read as an empty successful cleanup. The eraser can therefore report `done=true` while private transfer records or encrypted bytes remain.

Required correction: every privacy batch/completion/chunk-ledger read must be error-aware; read failure must return `items_retained=true`, `done=false`, and byte cleanup must remain retryable until ledger truth proves no chunks remain.

### R5-D04 — Transfer download starts the HTTP body before all requested encrypted chunks are integrity-proven
`stream_download()` emits successful headers and begins echoing plaintext chunk slices while subsequent chunks have not yet been authenticated and SHA-256 revalidated. If a later encrypted object is missing/corrupt after finalization, the client can receive a truncated body under a 200/206 success envelope before the server discovers the integrity failure.

Required correction: preflight the complete requested chunk range (containment, decrypt/authentication, byte count, SHA-256, sequence, and total range coverage) before emitting success headers or any plaintext; only then stream the already-proven range.

## Round verdict
R5 is defect-bearing. The ledger is frozen; R5 correction may begin only after this point.
