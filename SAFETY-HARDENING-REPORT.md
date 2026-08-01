# File 17 — Safety, Retention and Report Integrity Hardening

**Target:** Sabri Network and Messages 2.0.0  
**Branch:** `codex/file-17-review-2.0.0`  
**Result:** included QA and deterministic packaging PASS; not staging-accepted, merged, live-deployed, or operational

Exact current head, CI run and artifact identifiers are maintained in Pull Request #2. They are intentionally not embedded in this committed report because every source edit creates a new head and a new CI run.

## Implemented controls

- Native `SN_Safety` enforcement while preserving File 17 as the canonical report-data owner.
- UUIDv4 report idempotency and database uniqueness per reporter/client UUID.
- Canonical hashed target keys for user, conversation and message targets.
- Bounded global and same-target reporting limits.
- Canonicalized evidence hashes and active integrity verification.
- Administrator report inventory, triage, reasoned decisions and optimistic version checks.
- Reported-user notice, versioned appeals, duplicate resistance and reviewer separation.
- Legal/safety holds with fail-closed release authorization.
- Category-aware bounded retention deadlines.
- Two-stage retention: minimization first, delayed deletion later.
- Hold-aware WordPress privacy export and erasure.
- Legacy report migration and operational retention/hold counts.

## Corrective findings resolved

1. **Schema contract regression:** realtime contracts were advanced to the combined report schema.
2. **Privacy test ownership drift:** erasure tests were moved from the former inline implementation to canonical `SN_Safety` behavior.
3. **Retention stale-lock race:** delete-then-add takeover could remove another worker's newly acquired lock.
   - Takeover now uses exact observed-value compare-and-swap.
   - Release now uses exact owner-value compare-and-delete.
   - Successful direct option mutations invalidate the WordPress option cache.
4. **Missing behavioral lock proof:** runtime tests now simulate initial acquisition, stale takeover, a competing winner, release-time replacement, active-lock rejection and malformed-lock recovery.
5. **Repository evidence drift:** stale status, counts, package hashes and incomplete checksum lists were corrected.
6. **Checksum coverage gap:** `CHECKSUMS.sha256` now covers exactly every installable source file, and the quality gate verifies both coverage and exact digests.

## Review Round 1 — comprehensive/static/runtime

- Initial static contracts: **60/60 PASS**
- Realtime static contracts: **37/37 PASS**
- Package static contracts: **9/9 PASS**
- Safety static contracts: **36/36 PASS**
- Safety runtime contracts: **25/25 PASS**
- Relationship static contracts: **45/45 PASS**
- Relationship runtime contracts: **8/8 PASS**

**Round 1 total: 245/245 PASS.**

## Review Round 2 — fresh/adversarial

- Initial adversarial contracts: **59/59 PASS**
- Realtime adversarial contracts: **33/33 PASS**
- Package adversarial contracts: **8/8 PASS**
- Safety adversarial contracts: **31/31 PASS**
- Relationship adversarial contracts: **37/37 PASS**

**Round 2 total: 176/176 PASS.**

> **Combined included contract checks: 421/421 PASS.**

Additional gates:

- PHP syntax: PASS
- JavaScript syntax: PASS
- Shell syntax: PASS
- CSS integrity: PASS
- Repository hygiene: PASS
- Installable source checksum coverage and digest verification: PASS
- Deterministic double-build and byte comparison: PASS
- Artifact creation/upload: PASS

## Verified package

**Installable ZIP SHA-256:** maintained in Pull Request #2 after current-head CI.

The included checks have no known unresolved failure. This does not assert that undiscovered defects are impossible.

## Remaining production gates

- Production File 00/File 02 identity adapter and real-role acceptance
- File 19, File 20, File 24 and File 25 integration acceptance
- Approved malware scanner and private-storage operational validation
- Production STUN/TURN and approved SFU
- Staging fresh install, upgrade/migration, rollback, backup/restore and real-content testing
- Real WordPress/MySQL concurrency and privacy-erasure acceptance
- Penetration, dependency, load/race, browser/device, RTL/LTR and accessibility acceptance
- Founder approval, merge, live deployment and operational monitoring
## Third forensic review — integration and concurrency corrections

- Corrected the published File-25 contact and block routes and added explicit HTTP-method metadata.
- Replaced rate-limit read/replace accounting with atomic insert-once and conditional SQL mutation.
- Made conversation last-message pointers monotonic under concurrent sends.
- Made empty malformed retention-lock values recoverable without weakening exact-value takeover/release.
- Routed public-update attachment minor checks through canonical `SN_Policy::is_minor`.
- Added 33 fresh static, runtime and adversarial checks. Current configured total: **421 checks** (Round 1: **245**; Round 2: **176**).

