# File 17 — Fresh 20-Round Review — Round 8 Ledger Freeze

Date: 2026-09-06
Reviewed parent HEAD: `531587f8eb34a7f82ea857c3eace23182de62cc0`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before fix

Round 8 completed a fresh whole-candidate review with deep emphasis on calls, Meet, WebRTC/conference-provider boundaries, exact-request meeting idempotency, media credential eligibility, relationship/space/call advisory-lock composition, transaction guards, positive moderation eligibility, provider issuance, and post-commit confirmation. Final route precedence through `SN_Call_Runtime_Hardening` and `SN_R6_Transaction_Hardening` was traced before classifying findings.

## Frozen defect ledger

### R8-D01 — call/Meet lock-discovery SQL failure can be mistaken for “no related object”, yielding an incomplete lock set
Severity: High
Status: PROVED

`SN_Call_Runtime_Hardening::lock_mutation()` and its lock-composition helpers use authoritative `get_row()` / `get_var()` reads to discover meeting conversation, call conversation, space ownership projection, and direct peer identity. Those reads do not consistently clear/check `$wpdb->last_error`. A transient SQL read failure therefore collapses to `null`/`0`/empty-string and the mutation can continue with only a subset of the canonical call/conversation/space/relationship locks.

The later canonical callback may then perform successful reads/writes after the transient failure, so this is not equivalent to a fully failed request: a membership/block/space-owner transition can race the media mutation because its shared lock was silently omitted.

## Required correction frozen

Every authoritative database read used to decide which call/Meet locks must be held must fail closed on SQL error. The mutation must stop with a retryable 503-style error before any side-effecting callback runs; genuine “row absent” remains distinct from “database truth unavailable”. Existing exit/leave/decline safety semantics must remain intact.

## Regression requirement

Permanent call/Meet regression coverage must prove that lock-discovery reads are guarded, that database read failure returns a stable fail-closed error, and that canonical call/space/relationship lock namespaces remain unchanged.

## Freeze statement

No production code was changed during Round 8 review. This ledger freezes the complete Round 8 finding set before correction.
