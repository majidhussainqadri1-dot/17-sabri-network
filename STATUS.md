# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.0  
**State:** coded, packaged and CI-green candidate under draft review  
**Known failures in included suites:** 0  
**Included contract checks:** 421/421 PASS  
**Installable source checksum coverage:** 26/26 files verified  
**Deterministic double-build byte comparison:** PASS  
**Installable ZIP SHA-256:** maintained in Pull Request #2 after current-head CI.
**Staging/live/operational:** not completed

Exact current head, CI run and workflow artifact identifiers are maintained in Pull Request #2. They are intentionally not embedded here because every source edit creates a new head and a new CI run; embedding those future identifiers inside the commit would create a self-referential evidence loop.

## Latest corrective batch

- Replaced stale report-retention lock delete-then-add takeover with exact-value compare-and-swap.
- Replaced owner release with exact-value compare-and-delete.
- Prevented an expired former worker from deleting a newly acquired replacement lock.
- Added behavioral race tests for acquisition, stale takeover, competing takeover, release race, active-lock rejection and malformed-lock recovery.
- Rebuilt `CHECKSUMS.sha256` as the complete installable payload manifest.
- Added file-list coverage and exact SHA-256 verification to the quality gate.
- Corrected stale repository status, review and safety evidence documents.

## Current scope evidence

The branch includes privacy-scoped presence, expiring typing state, native mute/archive preferences, mute-aware fallback notifications, channel publishing authority, current-membership call/signaling enforcement, polling-state preservation, private files, reports, appeals, legal/safety holds, category-aware retention and hold-aware privacy operations.

## Remaining gates

- Production File 00/File 02 identity adapter and real-role acceptance
- File 19, File 20, File 24 and File 25 integration acceptance
- Approved malware scanner/private-storage operations and production STUN/TURN/SFU
- Staging fresh install, upgrade/migration, rollback, backup/restore and real-content testing
- Penetration, dependency, load/race, browser/device, RTL/LTR and accessibility acceptance
- Founder approval, merge, live deployment and operational monitoring

The included green suites are evidence for their defined contracts; they are not a claim that undiscovered defects are impossible or that production acceptance has occurred.
## Third forensic review — integration and concurrency corrections

- Corrected the published File-25 contact and block routes and added explicit HTTP-method metadata.
- Replaced rate-limit read/replace accounting with atomic insert-once and conditional SQL mutation.
- Made conversation last-message pointers monotonic under concurrent sends.
- Made empty malformed retention-lock values recoverable without weakening exact-value takeover/release.
- Routed public-update attachment minor checks through canonical `SN_Policy::is_minor`.
- Added 33 fresh static, runtime and adversarial checks. Current configured total: **421 checks** (Round 1: **245**; Round 2: **176**).

