# File 17 — Forty Fresh Sequential Repository Review Cycles and Corrective Closure

**Date:** 2026-10-19  
**Repository:** `majidhussainqadri1-dot/17-sabri-network`  
**Main source baseline:** `8ae656e51796d1f05865d8be5dca2480443d79ca`  
**Input release artifact source (before new corrections):** `48421de6b0870216fd314d3b3c366eae6a705ec3`  
**Governing documents:** Consolidated Three Central Plans; File 17 Final Harmonized Master Plan and later Future-24 amendment; companion File 10, 11, 14, 25, 26 planning documents. File 00/02, 03/09, 08/CF-01, 16, 19, 20, 24, CF-04 ownership contracts are checked as *declared File 17 interfaces*, **not** asserted operationally compatible at their deployed versions.

## Method and evidence limits

Forty **serial code-review/check rounds** were executed against the exact packaged File-17 source. In **each** of the 40 rounds the complete 167-file release-source manifest was traversed and SHA-256 checked against every included file (6,680 file/hash checks in all). Each round covered a different named plan/implementation concern. Rotating local PHP and JS syntax checks produced **484 PHP-lint invocations and 134 Node syntax invocations** in addition to exact-HEAD GitHub PHP 8.1/8.3 full suite and deterministic package checks. The artifact contained **121 PHP** and **10 JS** source files; the then-current CI runner covered only nine JS until repaired.

This is substantial repeated artifact/static assurance; it is **not** forty independent live site/WordPress/MySQL/provider/browser end-to-end acceptance tests, and it does **not** establish total code coverage. No fake percentage-complete assertion is made.

All 40 initial review scopes were closed **before** the remediation batch below. Source and test corrections were then applied on the dedicated branch, followed by new exact-HEAD GitHub CI and another full 40-round checksum/source/syntax audit on the corrected package.

## All forty review results

| Round | Review scope | Initial finding | Correction / disposition |
|---|---|---|---|
| 01 | Bootstrap, plugin version, canonical owner | No proved local source defect | No corrective patch warranted |
| 02 | Loader, initialization and runtime registration | No proved local source defect | No corrective patch warranted |
| 03 | Central governance and owner boundaries | No proved local source defect | No corrective patch warranted |
| 04 | File 00/02 identity assertions and fail-closed behavior | No proved local source defect | No corrective patch warranted |
| 05 | Phone discovery and public projection privacy | No proved local source defect | No corrective patch warranted |
| 06 | Age, guardian and minor protections | No proved local source defect | No corrective patch warranted |
| 07 | Contact request lifecycle and transactional safety | No proved local source defect | No corrective patch warranted |
| 08 | Follows versus mutual contacts | No proved local source defect | No corrective patch warranted |
| 09 | Block propagation into messaging and calls | No proved local source defect | No corrective patch warranted |
| 10 | Anti-spam / throttling / suspicious actions | No proved local source defect | No corrective patch warranted |
| 11 | Community, group and channel taxonomy | No proved local source defect | No corrective patch warranted |
| 12 | Invitations and join requests | No proved local source defect | No corrective patch warranted |
| 13 | Space governance, ownership and concurrency | No proved local source defect | No corrective patch warranted |
| 14 | Canonical conversation/message writes | No proved local source defect | No corrective patch warranted |
| 15 | Atomic sending, retries and event outbox | No proved local source defect | No corrective patch warranted |
| 16 | Viewer-specific visibility and private search | No proved local source defect | No corrective patch warranted |
| 17 | Attachment integrity pre-authorization | DEFECT F17-SEC-01 | Access + nonce gate before private-file hash; runtime negative cases pass |
| 18 | Malware scanning and quarantine | No proved local source defect | No corrective patch warranted |
| 19 | Private storage containment | No proved local source defect | No corrective patch warranted |
| 20 | Transfer initiation and storage readiness | DEFECT F17-REL-02 | Storage must be ready before new transaction; runtime negative cases pass |
| 21 | Encrypted 1 GiB chunk transfer | No proved local source defect | No corrective patch warranted |
| 22 | Transfer cleanup, expiry and revocation | No proved local source defect | No corrective patch warranted |
| 23 | Smail projection and retry semantics | No proved local source defect | No corrective patch warranted |
| 24 | Encrypted private message search | No proved local source defect | No corrective patch warranted |
| 25 | At-rest crypto and honest E2EE gating | No proved local source defect | No corrective patch warranted |
| 26 | Device presence and typing lifecycle | No proved local source defect | No corrective patch warranted |
| 27 | Direct calling and provider permission | No proved local source defect | No corrective patch warranted |
| 28 | Meet/room/lobby authority and media adapters | No proved local source defect | No corrective patch warranted |
| 29 | Reports, appeals, holds and moderation | No proved local source defect | No corrective patch warranted |
| 30 | Export/erasure and failure-safe progress | No proved local source defect | No corrective patch warranted |
| 31 | File 19 notifications and privacy-safe events | No proved local source defect | No corrective patch warranted |
| 32 | File 20 routes and single application shell | No proved local source defect | No corrective patch warranted |
| 33 | File 25 profile/contact presentation | No proved local source defect | No corrective patch warranted |
| 34 | File 08/CF-01 clinical reference separation | No proved local source defect | No corrective patch warranted |
| 35 | File 26 private message corpus exclusion | No proved local source defect | No corrective patch warranted |
| 36 | File 10 video live-chat optional bridge | No proved local source defect | No corrective patch warranted |
| 37 | File 16 AI adapter with explicit consent | No proved local source defect | No corrective patch warranted |
| 38 | Future-24 contract/feature gating | No proved local source defect | No corrective patch warranted |
| 39 | RTL/accessibility/brand CSS and client UI | No proved local source defect | No corrective patch warranted |
| 40 | Release/CI/javascript inventory and reproducible package | DEFECT F17-QA-03 | Added 10th JS entry to quality/package/PHP 8.1+8.3 gates |

**Review results:** 40 rounds; **3 defect-bearing rounds** (17, 20, 40); **37 clean rounds**. The automated per-round source sweep independently detected the first two code defects; a separate exact-script-to-package inventory comparison in review 40 identified the third release-test omission. No uncovered marker-only suspicion was counted as a defect.

## Root cause / proof / resolution

### F17-SEC-01 — Private attachment pre-authorization I/O (security)
- **Proved cause:** `SN_Attachment_Runtime_Hardening::verify_private_download_integrity()`, registered at early `template_redirect` priority, selected a storage row and hashed private attachment bytes *before* the nonce and viewer authorization gate in the later file handler.
- **Adversarial proof:** an isolated PHP/WordPress-stub test caused **one pre-authorization database read** for each of guest, invalid-nonce and unauthorized requests on the unpatched source. The corrected source caused **zero reads** in all three cases and one read for the valid authorized path.
- **Repair:** demand authenticated viewer, exact attachment nonce and native current per-attachment authorization **before** any DB query or file hash.
- **Live status:** not tested on deployed WordPress.

### F17-REL-02 — Transfer sessions created without known storage readiness (reliability)
- **Proved cause:** `SN_File_Transfer::initiate()` called `ensure_storage()` only *after* committing a persistent new session; an unsafe/unavailable private storage destination could not block session creation.
- **Adversarial proof:** isolated runtime fixture with transfer-storage root inside the WordPress web root reached one database transaction under the prior code and returned a generic initiation failure. Corrected code returned `transfer_storage_unavailable` **without starting a transaction**.
- **Repair:** enforce fail-closed private-storage readiness after idempotency reconciliation and before new-session/transaction creation.
- **Live status:** not tested on deployed WordPress.

### F17-QA-03 — Tenth shipped JS entry omitted from full release inventory (automated QA)
- **Proved cause:** installable ZIP included `assets/js/round20-correction.js` (loaded by `SN_Round20_Correction`), but `tools/quality-check.sh`, `tools/package.sh` and both independent workflow syntax lists covered only nine declared JS files.
- **Repair:** add that script to package-required file list, full Node syntax iteration, PHP 8.1 workflow and PHP 8.3 workflow. Add permanent `sixth-fresh-twenty-round-contracts.php` regression checks for all three corrections.
- **Live status:** no live claims.

**New proven engineering defects: 3; corrected in review branch: 3; remaining known actionable repository findings from these 40 checks: 0.** This is scoped to the inspected code and checks, **not a zero-defect guarantee**.

## Corrected-package retest and release evidence

The new exact-HEAD GitHub Actions source candidate was tested by PHP 8.1 syntax/boundary and PHP 8.3 full-quality/53-test inventory/deterministic packaging. The resulting immutable-HEAD artifact was downloaded and its ZIP SHA-256, embedded source manifest and all 167 source hashes verified offline. The full 40-round source/manifest/syntax script was run again on the resulting package: **40 clean rounds, 0 newly detected findings**.

The evidence file itself and other changes after that candidate **require another latest-HEAD green CI** before claiming the final branch-head pass.

## Separate release gates (not covered by source code completeness)

1. Verified running File 00/02/03/08/09/10/11/14/16/19/20/24/25/26 and CF-01/CF-04 versions, exact contract signatures and real integration tests.
2. Real WordPress/PHP/MySQL fresh-install + upgrade path, schema options, migration state, dead-letter/retries, privacy erasure and rollback rehearsals.
3. Actual approved scanners, STUN/TURN/SFU/call providers, E2EE/AI/semantic search/interop adapters where separately approved. Unavailable providers must remain safely disabled.
4. End-to-end mobile/desktop, Urdu/Arabic RTL, WCAG 2.2 AA, threat-model adversarial/penetration, load/race and recovery tests.
5. Staging sign-off, backup/restore evidence, deployment parity, Founder approval and live re-test.

**Repository HEAD (main):** `8ae656e51796d1f05865d8be5dca2480443d79ca`.  
**Deployed Version:** unknown/unverified.  
**DB Version:** unknown/unverified.  
**Migration State:** unknown/unverified.  
**Live Verification Status:** not verified.  

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
