# File 17 — Another Fresh 10-Round Review — Round 1 Frozen Ledger

**Round:** 1  
**Reviewed parent:** `d6e7a6ff65efc359abad112a7b620ddd98cc6c71`  
**Scope:** request idempotency firewall lifecycle, reservation/replay semantics, crash recovery, request hashing, response finalization, cleanup and privacy erasure.  
**Discipline:** the entire Round-1 review was completed before any Round-1 correction began.

## Verified clean areas before freeze

- Mutating completion/Future routes require a caller-supplied idempotency key at the REST pre-dispatch boundary.
- Request identity binds method, route and normalized request payload; same-key/different-request reuse fails with conflict.
- Completed results are encrypted before being stored for replay.
- Failed non-2xx handlers release the reservation rather than caching a failure as a successful mutation result.
- Unreplayable response-cache failures fail closed on retry instead of silently executing a second mutation.
- Privacy erasure is bounded and reports retryable failure if the idempotency cache cannot be deleted.

## Frozen defects

### R1-D01 — stale `processing` idempotency reservations can become permanent retry deadlocks

`SN_Two_Plan_Contract_Firewall::pre_dispatch()` reserves a mutation key in state `processing` before the route callback runs. A process crash, fatal termination or request abort can therefore leave a row in `processing` without `post_dispatch()` ever publishing a terminal state.

`existing_result()` treats every non-complete/non-unreplayable row as still in progress, while `cleanup()` deletes only old `complete` and `unreplayable` rows. It never transitions stale `processing` rows. The same caller-owned idempotency key can therefore remain permanently blocked with `sn_idempotency_in_progress`, even though no request is actually running.

**Severity:** High reliability / mutation-recovery defect.

**Required correction:** cleanup must fail closed by converting sufficiently stale `processing` reservations into a terminal reconciliation/unreplayable state, record audit evidence, and preserve the no-reexecution safety boundary. Add permanent regression coverage proving stale processing recovery exists and occurs before ordinary terminal-cache cleanup.

## Correction gate

Round 2 must not begin until R1-D01 is corrected, regression-protected, and the exact resulting HEAD has green PHP 8.1 and PHP 8.3/full-quality CI.