# File 17 — Seventh Fresh 40-Round Coding Completeness and Blocker Audit
**Date:** 2026-10-19 (Pakistan time)  
**Owner:** File 17 Sabri Communication Network  
**Source repository:** `majidhussainqadri1-dot/17-sabri-network`  
**Immutable main baseline:** `8ae656e51796d1f05865d8be5dca2480443d79ca`  
**Review source before new correction:** `7983714083c6d412d85b32c15e547fcf9cbe60a3`  
**Fix:** `SN_Two_Plan_Completion::client_id()` now requires a caller-supplied stable key; a missing/invalid key produces `sn_client_id_invalid` (HTTP 400) rather than generating a server UUID.
**Governing plans:** consolidated Three Central Plans, File17 Final Harmonized Master Plan + Future24 appendix; Files 10/11/14/25/26; File00/02 identity, File19 notifications, File20 shell, File08/CF01 care authority, File24 assurance and other owner contracts.

## Method
The initial candidate's 167 shipped files were rehashed on every one of **40 serial static inspection passes**: 6,680 SHA-256 checks. Each pass had a distinct assigned domain and a rotating segment of PHP/JavaScript syntax; 363 PHP lints and 40 JavaScript syntax checks. Two of three initially flagged marker checks (rounds 15 and 27) were semantic-search false positives and were **not** counted as code defects. **Round 33 was confirmed as a real functional retry-safety defect** using a PHP runtime reproduction with two empty requests, each previously receiving a distinct UUID. The other 39 did not prove additional local source defects.

The correction was then tested via GitHub Actions on exact commit `02e88ac23619303ac3c0cd712cce3b36acfa95b6` (PHP 8.1 and PHP 8.3 passed). A new installable ZIP and its embedded 167-file manifest were downloaded and verified. A further 40 source/manifests/syntax scans were clean after replacing the misleading marker predicates with actual owner enforcement and presentation code. **The current final HEAD must have its own CI check; prior commits cannot prove a later one.**

## Forty individual reviews
| Round | Review focus | Initial finding | Disposition |
|---:|---|---|---|
| 1 | Canonical architecture/ownership | No independently proved defect in this static pass | No patch from marker-only evidence |
| 2 | Plugin bootstrap/version | No independently proved defect in this static pass | No patch from marker-only evidence |
| 3 | Migration governor | No independently proved defect in this static pass | No patch from marker-only evidence |
| 4 | Schema verification | No independently proved defect in this static pass | No patch from marker-only evidence |
| 5 | File00/02 claims | No independently proved defect in this static pass | No patch from marker-only evidence |
| 6 | Public contact privacy | No independently proved defect in this static pass | No patch from marker-only evidence |
| 7 | Minor/guardian policy | No independently proved defect in this static pass | No patch from marker-only evidence |
| 8 | Contacts and requests | No independently proved defect in this static pass | No patch from marker-only evidence |
| 9 | Blocks and transitions | No independently proved defect in this static pass | No patch from marker-only evidence |
| 10 | Spaces schema | No independently proved defect in this static pass | No patch from marker-only evidence |
| 11 | Space posting/roles | No independently proved defect in this static pass | No patch from marker-only evidence |
| 12 | Atomic messages | No independently proved defect in this static pass | No patch from marker-only evidence |
| 13 | Encryption at rest | No independently proved defect in this static pass | No patch from marker-only evidence |
| 14 | Edit/delete/unsend | No independently proved defect in this static pass | No patch from marker-only evidence |
| 15 | Private search/File26 boundary | No independently proved defect in this static pass | No patch from marker-only evidence |
| 16 | Hidden private search | No independently proved defect in this static pass | No patch from marker-only evidence |
| 17 | Private storage | No independently proved defect in this static pass | No patch from marker-only evidence |
| 18 | Authenticated attachment retrieval | No independently proved defect in this static pass | No patch from marker-only evidence |
| 19 | Malware scan | No independently proved defect in this static pass | No patch from marker-only evidence |
| 20 | Transfer storage gate | No independently proved defect in this static pass | No patch from marker-only evidence |
| 21 | Encrypted chunk transfer | No independently proved defect in this static pass | No patch from marker-only evidence |
| 22 | Smail projection | No independently proved defect in this static pass | No patch from marker-only evidence |
| 23 | Smail safe sending | No independently proved defect in this static pass | No patch from marker-only evidence |
| 24 | Transactional outbox | No independently proved defect in this static pass | No patch from marker-only evidence |
| 25 | File19 notification owner | No independently proved defect in this static pass | No patch from marker-only evidence |
| 26 | File20 shell/route owner | No independently proved defect in this static pass | No patch from marker-only evidence |
| 27 | File25 UI integration | No independently proved defect in this static pass | No patch from marker-only evidence |
| 28 | CF01/Clinic context | No independently proved defect in this static pass | No patch from marker-only evidence |
| 29 | File24 privacy export/erase | No independently proved defect in this static pass | No patch from marker-only evidence |
| 30 | File10 provider boundary | No independently proved defect in this static pass | No patch from marker-only evidence |
| 31 | Device presence | No independently proved defect in this static pass | No patch from marker-only evidence |
| 32 | Calls and Sabri Meet | No independently proved defect in this static pass | No patch from marker-only evidence |
| 33 | Caller idempotency | Confirmed: generated retry ID | Fixed: reject missing ID; regression added |
| 34 | Moderation/legal hold | No independently proved defect in this static pass | No patch from marker-only evidence |
| 35 | High-risk authorization | No independently proved defect in this static pass | No patch from marker-only evidence |
| 36 | Future24 E2EE gate | No independently proved defect in this static pass | No patch from marker-only evidence |
| 37 | Future24 AI semantic separation | No independently proved defect in this static pass | No patch from marker-only evidence |
| 38 | RTL/reduced motion | No independently proved defect in this static pass | No patch from marker-only evidence |
| 39 | Browser client key generation | No independently proved defect in this static pass | No patch from marker-only evidence |
| 40 | JS assets and ZIP integrity | No independently proved defect in this static pass | No patch from marker-only evidence |

## Proof of bug and fix
**F17-RETRY-01 — Caller-supplied request identity silently fabricated.**
Before patch, the private helper contained `$raw=strtolower(trim($raw))?:strtolower(wp_generate_uuid4());`. Isolated PHP reflection invoked it twice with an empty string and received two different generated IDs. This contradicts the governing caller-owned idempotency contract for message requests, scheduled messages, polls and checklists. A network timeout followed by a retry would no longer be guaranteed to retain the original operation identity.

**Correction:** remove server UUID fallback. Whitespace, invalid or missing `client_id` returns HTTP 400; valid identities normalize deterministically. Client-side `assets/js/two-plan-ui.js` already sends a random caller-owned ID on protected requests. Added `tests/seventh-fresh-idempotency-runtime-contracts.php`, invoked by both the PHP 8.1 boundary workflow and the PHP 8.3 full quality gate. Metadata updated to **54 PHP test suites / 10 JS assets**.

## Companion repositories checked (SOURCE only; no deployment parity)
- File19 `19-sabri-unified-notifications`: main `04078025b643ab7696e4cb4e37826bf152defa18`; cross-file contract owner and notification routes inspected. No live contract compatibility conclusion.
- File20 `20-sabri-unified-application-shell`: main `8a4dbcaf4fef8e926b9b834ecfde16c21a0f00ca`; Network/Messages detection and shell contracts inspected. No duplicate shell created in File17.
- File08 `08-worldwide-clinic-and-appointments-foundation`: main `92e2422d6a235632810ed4981f151fc3250784aa`; CF01 care-context contract files present; real File17/CF01 installed versions not validated.
- File10 `10-video-wall-and-educational-broadcasting-foundation`: main `4b8a3dda140186c01b05d998e729cb1aeeed2cf3`; plugin scope inspected; approved real media/call providers remain separate.

## Outstanding release blockers (not code flaws automatically)
1. **Live reality unavailable.** WPVibe connector list returned no registered sites. Thus exact installed plugin/package hash, WP/PHP/DB versions, relevant database tables/columns/rows, `wp_options` migration state, logs and live configuration cannot be frozen or compared.
2. **Deployment parity unverified.** GitHub main/PR ZIP, staging and production remain separate sources of truth; production does not inherit fixes automatically.
3. **Real interoperation not yet certified.** Companion File00/02/03/08/10/11/14/16/19/20/24/25/26 and CF01/CF04 deployment/builds, provider callbacks/consent/guardian/call scanning and message lifecycle require staging end-to-end tests.
4. **Operational release gates pending.** Database migration/restore, security/IDOR, RTL/accessibility on devices, load, real STUN/TURN/SFU, backup, rollback, Founder acceptance and live smoke test.
5. **Draft PR remains unmerged.** Repository correction is confined to the review branch until explicitly accepted.

## Required status table
- **Repository HEAD (main):** `8ae656e51796d1f05865d8be5dca2480443d79ca`
- **Correction branch:** verify latest exact HEAD at report time
- **Deployed Version:** unverified
- **DB Version:** unverified
- **Migration State:** unverified
- **Live Verification Status:** not performed

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

### Final count
- Current new 40-round static pass: **1 proven coding defect, 1 corrected and covered by regression**.
- Two preliminary marker alerts: **false positives; no patch**.
- Remaining defects of the same type proven by this pass: **0** (not equivalent to total defect freedom).
- Pending release blockers: **5 distinct external verification categories**, not dismissed without evidence.
