# File 17 — Fresh 10-Round Review — Round 6 Frozen Ledger

**Round:** 6  
**Reviewed parent:** `b30813300683be868b71dae6c1e199d521008e39`  
**Scope:** call/Meet mutation locking, meeting creation and retry semantics, admission/moderation, media-credential issuance, provider health/capability boundaries, realtime typing, presence-device lifecycle and post-delivery File-00 revalidation.  
**Discipline:** the complete Round-6 review was finished before any correction began.

## Frozen defects

### R6-D01 — Sabri Meet creation reuses an idempotency key without proving the retry is the same request

`SN_Meet::create_meeting()` hashes the host plus caller idempotency key and immediately returns any matching meeting as `duplicate=true`. It does not compare the retry's meeting title, description, bound conversation, access mode, lobby flag, participant limit or explicitly supplied schedule with the committed meeting.

A caller can therefore reuse the same idempotency key for materially different meeting parameters and receive success for the old object rather than an explicit idempotency conflict. The same unqualified reconciliation is also used after an uncertain insert/commit path.

**Severity:** High retry-safety / exact-request identity defect.

**Required correction:** make the final meeting-create route owner prove that an existing idempotency record matches the normalized current request before delegating to the canonical creation path; mismatches must fail with HTTP 409. Preserve same-request retry success and add permanent regression coverage.

### R6-D02 — Host admission/promotion can positively transition a target whose File-00 call eligibility changed after waiting/joining

The final call runtime refreshes the authenticated moderator's File-00 assertion, but `SN_Meet::moderate()` can perform positive target transitions such as `admit` and `promote` without a fresh point-of-action assertion for that target. A waiting participant who had `can_call=true` at join time can lose File-00 call eligibility before a host admits them, yet the host can still mark that participant/session as joined. A participant can likewise be promoted after losing current call eligibility.

Removal/deny/mute/lower-hand/demotion must remain possible for safety and exit control, so the target gate belongs only on positive call-authority transitions.

**Severity:** High identity-authority / meeting-admission defect.

**Required correction:** after the meeting/relationship locks are held, refresh the target's File-00 communication assertion for `admit` and `promote`; require `eligible=true`, `can_call=true`, and `suspended=false`, otherwise fail closed. Add regression coverage proving negative/safety transitions remain outside this positive target gate.

## Correction gate

Round 7 must not begin until both defects are corrected, permanent regressions pass, and exact-head PHP 8.1 + PHP 8.3 CI is green.
