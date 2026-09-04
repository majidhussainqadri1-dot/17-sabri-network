# File 17 — Next Fresh 10-Round Review — Round 3 Frozen Ledger

**Round:** 3  
**Reviewed parent:** `b17bec103822b3efc24aa3875d42fd9987c93d68`  
**Scope:** global two-plan/Future-24 idempotency firewall reservation, response finalization, replay state machine, cleanup and privacy erasure.  
**Discipline:** the entire Round-3 review was completed before any correction began.

## Verified clean areas before freeze

- Mutating completion/Future-24 routes require a caller-owned 8–64 character idempotency key and bind it to actor, HTTP method, route and normalized request hash.
- Primary-key reservation plus race reconciliation prevents two simultaneous requests from both creating separate firewall reservations.
- A completed replay decrypts the previously committed response and refuses key reuse when the request hash differs.
- The firewall is now inside the governed migration/schema-verification boundary after Round 1.

## Frozen defects

### R3-D01 — Successful mutation can lose its reservation when response serialization fails

After a 2xx mutation, `post_dispatch()` deletes the idempotency row when `wp_json_encode()` does not return a string. The underlying mutation has already executed successfully, so deleting the reservation makes the same caller key reusable and can allow the mutation to execute again.

**Severity:** High duplicate-mutation / retry-safety defect.

### R3-D02 — Response-cache encryption/finalization failure has no durable terminal replay state

If response encryption fails, the method audits and returns the successful response while leaving the reservation in `processing`. Likewise, the database update that changes the row from `processing` to `complete` is not checked. A failed finalization can therefore strand the key indefinitely as `sn_idempotency_in_progress` without distinguishing an active request from a completed-but-unreplayable mutation.

**Severity:** High idempotency-state / recovery-evidence defect.

### R3-D03 — Privacy erasure ignores non-complete firewall rows

The personal-data eraser deletes only rows where `state='complete'`. A user can therefore retain `actor_id`, request hash/route metadata and timestamps indefinitely in a stranded `processing`/future terminal-failure row even while the eraser reports no retained items.

**Severity:** Medium privacy-erasure truth defect.

## Required correction

Introduce a durable terminal `unreplayable` state for already-executed mutations whose response cannot be safely serialized/encrypted/finalized; never delete the reservation solely because 2xx response caching failed. Replays of that state must fail closed with a stable 503 rather than re-execute. Check the completion write and attempt terminal failure publication/audit on failure. Cleanup may expire terminal `complete`/`unreplayable` rows after the governed TTL, while active/uncertain `processing` rows remain fail-closed. Privacy erasure must cover all actor-owned firewall states and report retry truth from actual remaining rows.

## Correction gate

No Round-4 review may begin until all frozen defects are corrected, permanent regression coverage passes and the resulting exact branch HEAD has green PHP 8.1 plus PHP 8.3/full-quality deterministic-package CI.
