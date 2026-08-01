# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.0  
**State:** coded, packaged and CI-green candidate under draft review  
**Known failures in included suites:** 0  
**Included contract checks:** 388/388 PASS  
**Installable source checksum coverage:** 26/26 files verified  
**Deterministic double-build byte comparison:** PASS  
**Current installable ZIP SHA-256:** `d12f3b94e6583ef716085bca5dc7fe95b1b85e7354cb429122f94ce8264cee65`  
**Current final CI run:** `30717235534`  
**Current workflow artifact:** `8823712761`  
**Artifact container SHA-256:** `6d23ac7bc5ad4adffce3f63d045e6bc7c6c8a9db763345af63260be2f2ff2d49`  
**Staging/live/operational:** not completed

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
