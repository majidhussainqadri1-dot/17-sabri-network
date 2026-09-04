# File 17 — Fresh 10-Round Review — Round 9 Frozen Ledger

**Round:** 9  
**Reviewed parent:** `77f4f57ed99059ed65f13dd602393939df2fd5c7`  
**Scope:** safety/report mutation serialization, legal-hold/appeal closure governance, high-risk requester/approver/executor separation, step-up grant consumption, claim/complete lifecycle, stale-claim recovery, event outbox/inbox idempotency, retry/dead-letter and administrative retry boundaries.  
**Discipline:** the complete Round-9 review was finished before any correction began.

## Frozen defect

### R9-D01 — High-risk action creation consumes a one-time step-up grant without first proving the database transaction actually started

`SN_High_Risk::request_action()` calls `START TRANSACTION` but does not test whether it succeeded before calling `consume_grant()`. `consume_grant()` performs a `SELECT ... FOR UPDATE` and changes the one-time grant from `active` to `consumed` under the assumption that an enclosing transaction exists.

If the database cannot start the transaction, grant consumption can occur outside the intended atomic boundary. A subsequent action insert/commit failure can therefore permanently consume the strong-authentication grant without creating the governed high-risk action that it was meant to authorize.

**Severity:** High high-risk transaction-integrity / one-time authorization defect.

**Required correction:** fail closed before touching the grant unless `START TRANSACTION` explicitly succeeds; retain the existing rollback path for all later failures and add permanent regression coverage.

## Correction gate

Round 10 must not begin until this defect is corrected, permanent regression coverage passes, and exact-head PHP 8.1 + PHP 8.3 CI is green.
