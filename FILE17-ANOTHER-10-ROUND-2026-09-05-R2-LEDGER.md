# File 17 — Another Fresh 10-Round Review — Round 2 Frozen Ledger

**Round:** 2  
**Reviewed parent:** `4716c2a6a146ff8a8ee07e2328699f6140c570ff`  
**Scope:** CF-01 File-17 clinical-context reference issuance, idempotency, consent/issuer checks, transaction boundaries, outbox/audit coupling, assertion, destination resolution, revocation, cleanup and privacy surfaces.  
**Discipline:** the complete Round-2 review was finished before any Round-2 correction began.

## Verified clean areas before freeze

- CF-01 stores opaque communication references rather than message bodies, attachments or call transcripts.
- Issuance requires explicit purpose, opaque consent reference, caller idempotency, external issuer authorization and external consent authorization.
- Existing idempotency rows are scope-checked before replay.
- Successful issuance couples the reference write, outbox event and audit evidence in one intended transaction.
- Assertions and destination resolution revalidate current File-17 membership/conversation state and external read authorization.
- Reference revocation and privacy/export surfaces retain File-17 ownership boundaries.

## Frozen defects

### R2-D01 — CF-01 issuance ignores failure to start its transaction

`SN_CF01_Clinical_Context::issue_reference()` calls `$wpdb->query('START TRANSACTION')` without checking the result. The function then performs `FOR UPDATE`, a reference insert, outbox enqueue and audit write and finally calls `COMMIT`.

Unlike the REST mutations protected by the later request-scoped transaction guard, this owner-executed CF-01 API is also exposed through `sn_cf01_issue_communication_context()` and has no later wrapper that promotes a failed `START TRANSACTION`. If transaction start fails while autocommit remains active, mutation work can proceed outside the intended atomic boundary and a later error/rollback cannot reliably undo already committed writes.

**Severity:** Critical atomicity / cross-domain contract defect.

**Required correction:** fail closed immediately when `START TRANSACTION` is not proven, before any locking read, reference insert, outbox write or audit side effect. Add permanent regression coverage proving the start gate precedes the first `FOR UPDATE` operation.

## Correction gate

Round 3 must not begin until R2-D01 is corrected, regression-protected, and the exact resulting HEAD has green PHP 8.1 and PHP 8.3/full-quality CI.