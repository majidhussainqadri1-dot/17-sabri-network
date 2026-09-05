# File 17 — Another Fresh 10-Round Review — Round 3 Frozen Ledger

**Round:** 3  
**Reviewed parent:** `7b88e60fe6d8aa9a60c4198b92cf9a81b2986c85`  
**Scope:** File-17 privacy/erasure, legal-hold precedence, canonical message/update/relational erasure, extension erasers (contexts, CF-01, Smail, receipts, organization, Two-Plan, Future/device keys, transfers), bounded progress and completion truth.  
**Discipline:** the complete Round-3 review was finished before any Round-3 correction began.

## Verified clean areas before freeze

- The priority-9999 File-17 eraser wrapper preserves a global legal/safety-hold boundary.
- Message/update destructive writes and the principal relational write phase use transaction boundaries and checked writes.
- Private attachment deletion remains delegated to the canonical private-file owner rather than direct unlink code inside privacy callbacks.
- Smail, receipts, message-organization, Two-Plan and Future erasers are bounded and retain retry receipts for several already-known write failures.
- Held communication evidence is intentionally retained rather than silently destroyed.

## Frozen defects

### R3-D01 — canonical privacy erasure treats critical DB read failures as an empty result

`SN_Privacy_Runtime_Hardening` uses falsey checks / `?: []` on several erasure-side `get_results()` / `get_col()` calls. A database read failure is therefore indistinguishable from “there is nothing to erase.”

This is not merely a reporting issue. In the relational phase, failure to read active non-direct conversations owned by the user can collapse `$owned_non_direct` to an empty array and allow the later transaction to delete the user's membership instead of preserving owner membership until governed transfer. Similar failure-open reads can skip message/update work or attachment/call linkage while the callback proceeds toward `done=true`.

**Severity:** Critical privacy-integrity / destructive-boundary defect.

**Required correction:** every decision-driving erasure read must distinguish a real empty result from a DB error and return `done=false` on read failure before any dependent destructive mutation.

### R3-D02 — final extension erasers can publish `done=true` when their post-commit completion probe failed

Multiple final erasers cast `get_var()` directly to bool/int for “more work remains” checks. WordPress DB read failure can therefore collapse to false/zero, so a callback may report `done=true` even though personal data remains. Affected final paths include context/CF-01 attribution, Smail, message receipts/organization, Two-Plan and Future/device-key completion truth.

**Severity:** High privacy completion / retry-termination defect.

**Required correction:** completion probes must be explicitly error-checked; a failed completion read is retryable and must never terminate WordPress erasure. Future-version/device-key scans must likewise reject invalid DB reads rather than interpreting them as an empty batch.

## Correction gate

Round 4 must not begin until R3-D01 and R3-D02 are corrected, permanently regression-protected, and the exact resulting HEAD has green PHP 8.1 plus PHP 8.3/full-quality CI.