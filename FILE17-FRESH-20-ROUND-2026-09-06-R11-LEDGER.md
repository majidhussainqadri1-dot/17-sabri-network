# File 17 — Fresh 20-Round Review — Round 11 Ledger Freeze

Date: 2026-09-06
Reviewed parent HEAD: `b12dd3777db834b3257faf0bdcf8de9c4600fda0`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before fix

Round 11 completed a fresh whole-candidate safety/reliability review with deep emphasis on reports, legal/safety holds, high-risk step-up/dual control, action claim/complete state, outbox/inbox idempotency, delivery retries, dead-letter recovery and safety-related privacy retention. `SN_Fourth_Fresh_Safety_Hardening`, `SN_High_Risk`, `SN_Outbox`, `SN_Safety_Runtime_Hardening` and the final privacy retention filter chain were traced together.

## Frozen defect ledger

### R11-D01 — native legal-hold discovery fails open when its database read fails
Severity: Critical
Status: PROVED

`SN_Fourth_Fresh_Privacy_Hardening::native_legal_hold()` casts the result of its authoritative report/message legal-hold query directly to an integer without clearing/checking `$wpdb->last_error`. If that query fails, the result collapses to `0` and the filter returns `false`. File-17 erasers use this filter as the retention gate; therefore a transient database-read failure can be interpreted as “no legal/safety hold” and erasure can proceed even though a hold may exist.

The later privacy completion wrapper cannot repair this because the destructive erasure decision has already been authorized by the false-negative hold result.

## Required correction frozen

Legal-hold discovery must be fail-closed: clear the prior DB error, execute the authoritative query, and if database truth is unavailable return `true` (retain) while emitting minimized audit evidence. Only a successful query proving no matching held record may return `false`.

## Regression requirement

Permanent privacy/safety regression coverage must require explicit `$wpdb->last_error` handling in `native_legal_hold()` and a fail-closed `return true` path on legal-hold read failure.

## Freeze statement

No production code was changed during Round 11 review. This ledger freezes the complete Round 11 finding set before correction.
