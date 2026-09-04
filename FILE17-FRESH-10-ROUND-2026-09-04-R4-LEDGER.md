# File 17 — Fresh 10-Round Review — Round 4 Frozen Ledger

**Round:** 4  
**Reviewed parent:** `eccd9d6c6dd533d215736050f15106cd05eb7de5`  
**Scope:** Smail final-route precedence, Smail send/draft/state retry semantics, canonical-message projection, verified-user resumable transfer initiation/chunks/finalization/grants/revocation, encrypted storage containment, scanner quarantine, cleanup and transfer privacy erasure.  
**Discipline:** review completed before correction.

## Frozen defect

### R4-D01 — Smail duplicate path accepts the same caller idempotency key with different message intent

The final Smail route correctly requires a caller-owned `client_id`, and `SN_Smail_Runtime_Hardening::send()` serializes on a sender/client lock. However, once a `smail_messages` row already exists for that client key, the runtime immediately returns it as `duplicate=true` without proving that the current recipients, subject and body are identical to the originally committed request.

Therefore a retried or accidentally reused `client_id` with changed recipients/content can be silently reported as success for an older Smail. This violates the exact-request identity semantics already enforced by the verified file-transfer initiation path, which explicitly returns an idempotency conflict when parameters differ.

**Severity:** High retry-safety / message-intent integrity defect.

**Required correction:** before every duplicate/race reconciliation return, compare the committed Smail projection and canonical message against the requested sender, exact recipient set, subject and body. Return an explicit 409 idempotency conflict if exact request identity cannot be proven. Add permanent regression coverage and exact-head CI before Round 5.

## Other reviewed boundaries

No additional unresolved transfer defect was proven after final route and hardening precedence were accounted for. Transfer initiation already checks exact duplicate parameters, sender initiation is serialized, chunks are encrypted and checksum-bound, finalization revalidates every chunk and scanner state, download grants are user/version/expiry-bound, and cleanup keeps chunk ledger rows until encrypted bytes are actually removed.
