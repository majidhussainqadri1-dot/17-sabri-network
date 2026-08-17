# File 17 — Third Fresh 20-Round Sequential Review / Fix / Retest Ledger

**Date:** 2026-08-17  
**Repository:** `majidhussainqadri1-dot/17-sabri-network`  
**Review branch:** `review/file17-third-fresh-20-round-2026-08-17`  
**Frozen starting main:** `c9577088f121b9d02071780ce4a326b8b746a480`  
**Implementation head before this evidence-only ledger commit:** `4014852c6ed2ab72cca775e9a3c6098e026037c6`  
**Exact-head green workflow at that implementation head:** `32022835397`  
**Green package SHA-256 at that implementation head:** `555ccf9f1495f5202320278bbdcd0b72883877259a6e1038ea766a265a8ccfcb`  
**Workflow artifact:** `9285992909` (`file-17-complete-2.1.0-4014852c6ed2ab72cca775e9a3c6098e026037c6`)  
**Artifact archive digest:** `sha256:11c64f6541e936804ea8d9849e77dc1f3e01fa94f05038009d161fc4c0c61a7f`

## Governing method

This cycle followed the Founder-mandated sequence exactly: **complete one review round first → freeze that round's defect ledger → correct every defect found in that completed round → retest → only then begin the next round.** No defect found mid-round was patched before that round's review scope was completed.

The governing basis was the current consolidated central master plan, the current File 17 Final Harmonized Master Plan, and the Founder-approved Future Communication Superset 24. Historical review ledgers remained evidence for their own exact commits only.

## First ten rounds — mandatory checkpoint

**Defect rounds:** `2, 5, 10`  
**Clean rounds:** `1, 3, 4, 6, 7, 8, 9`

| Round | Review scope | Result | End-of-round correction / evidence |
|---|---|---|---|
| 1 | Baseline/bootstrap/package/source integrity | CLEAN | Frozen 2.1.0 baseline; production PHP/requires/package manifest reviewed. |
| 2 | Identity, authorization and profile ownership | DEFECT | File 17 directly read File-03-owned `sn_about` / `sn_role_label`; removed shadow ownership and retained canonical projection filter. Fix `ee12799a14fb33bb1a7796ff63ed41810178d9a6`. |
| 3 | Relationships, contacts, follows, blocks, minors | CLEAN | No new repository defect. |
| 4 | Conversation membership, ownership and state transitions | CLEAN | No new repository defect. |
| 5 | Messages, idempotency and forwarding | DEFECT | Forwarding had a TOCTOU gap between preflight and commit. Source/target membership, File-00 state and direct-contact authorization are now revalidated under the committing transaction/locks. Fix `dce16fed5de28485e2a7955b0de0e513f74d444c`. |
| 6 | Visibility, private search, smart views and semantic visibility | CLEAN | No new repository defect. |
| 7 | Private attachments, voice files and authorized delivery | CLEAN | No new defect in this scoped round. A deeper physical web-root placement issue was independently discovered later in whole-system Round 20 and is counted there, not retroactively here. |
| 8 | Scheduled/disappearing messages, reminders and translation | CLEAN | No new repository defect. |
| 9 | Spaces, communities, QR/temp membership and invitations | CLEAN | No new repository defect. |
| 10 | Presence, realtime and device lifecycle | DEFECT | Device ceiling counted expired historical rows as active devices. Cap now counts only non-revoked, unexpired active rows. Fix `5e90c1adf0da71ab193a788e18b723a7e4513af5`. |

## Rounds 11–20

| Round | Review scope | Result | End-of-round correction / evidence |
|---|---|---|---|
| 11 | Calls, Sabri Meet, TURN/STUN/SFU, lobby, breakout, host | DEFECT | Call creation lacked call-runtime serialization against concurrent relationship/block change. Added conversation/direct-pair locks and fresh File-00 call-eligibility revalidation around media transitions. Fix `8de10d199e3670ff914952072502baf6be0ffaae`. |
| 12 | Smail, Team Inbox, handoff, templates, bulk jobs, concurrency | DEFECT | Existing Team Inbox update allowed omitted/zero `expected_version`, permitting stale overwrite. Existing mutations now require exact CAS version. Fix `024abc7d23756f4ab2bd4b1e38f74c1d06db6193`. |
| 13 | Verified private transfer ≤1 GiB, resume, hash, quarantine, grants, revoke, cleanup | CLEAN | No new repository defect. |
| 14 | Privacy export/erasure, retention, legal holds, cross-file ownership | DEFECT | Legacy erasure could delete File-03 profile data, damage active non-direct ownership, ignore DB failures, inconsistently honor File-17 holds, and stop retry after Meet erasure failure. Replaced with hold-consistent transactional owner-safe erasure and retry behavior. Fix `a26fae3927aa5b3606f930473f31a9a093bd45f3`. |
| 15 | Reports, appeals, moderation, high-risk dual control, legal-hold release, audit | CLEAN | No new repository defect. |
| 16 | Durable cryptography, key lifecycle and truthful E2EE boundary | DEFECT | Durable File-17 ciphertext was coupled to WordPress auth-salt rotation and accepted any nonempty configured secret. Added dedicated validated File-17 master-secret lifecycle, private key-file fallback, previous-key/legacy decrypt compatibility and restore/key-rotation requirements. Fix `419a7543aac09f761ce928e3ddd846bfa41c63eb`. |
| 17 | AI assistant, private semantic search, citations, case discussion and cross-file authority | CLEAN | No new repository defect. File 16 AI, File 26 global search, File 06/12 citations and clinical boundaries remain canonical. |
| 18 | Interoperability, replay/idempotency and bridge scope | DEFECT | `manage_options` could bypass private-conversation membership for bridge operations. Added current membership plus owner/moderator scope guard; unauthorized object routes remain existence-safe. Fix `44068a018bb5a90b67a0d878e853aa0effb783b2`. |
| 19 | REST/UI/docs/package/workflow/release-truth parity | DEFECT | QA inventory and release documentation drifted (46 vs actual 47 PHP suites; 6 vs actual 8 JS checks) and dedicated-key recovery boundary was undocumented. Reconciled README/readme/status/security/changelog and semantic release-truth regressions. Exact Round-19 green head `3e5e054926db9017f7956602870b6d8f40917562`, run `32021408460`. |
| 20 | Whole-system adversarial boundary review: physical storage, WordPress REST dispatch ordering, private-search derived-key epoch, full release parity | DEFECT | Three root defects were found only after the complete whole-system review: (A) default private storage could still sit inside the real document root on WordPress subdirectory installs; (B) File-17 `rest_pre_dispatch` side-effect hooks can run before ordinary route permission callbacks, so current access/object scope needed an earlier common gate; (C) private-search HMAC tokens had no auth-salt epoch drift detection, causing silent false-empty search after salt rotation. Added `SN_Runtime_Boundary_Policy`: public-root/symlink-aware storage fail-closed validation, very-early File-00/object-membership mutation gate, and explicit private-search key-epoch reset/gated rebuild/continuation. Helper creation `ec0441a9faa5ab0059fe97ac79de0677313b86e7`; runtime wiring `4f11eff6e2c05585d777964ab28658d85c85faae`; permanent regressions and retest corrections through `4014852c6ed2ab72cca775e9a3c6098e026037c6`. First Round-20 retest run `32022653968` correctly failed on a newly added test-literal parse error; that test defect was corrected, then exact-head run `32022835397` passed both PHP 8.1 and PHP 8.3/full deterministic package gates. |

## Final 20-round result

**Rounds with one or more real defects:** `2, 5, 10, 11, 12, 14, 16, 18, 19, 20` — **10 rounds**.  
**Rounds with no new defect:** `1, 3, 4, 6, 7, 8, 9, 13, 15, 17` — **10 rounds**.

Every defect above was corrected only after its own review round had been completed. The next round did not begin until the preceding round's corrections/retest were closed.

## Round-20 exact implementation QA before evidence-ledger commit

- PHP 8.1 declared-minimum syntax/current-boundary job: PASS.
- PHP 8.3 full quality + deterministic package job: PASS.
- Explicit PHP review-suite inventory: 47 suites.
- JavaScript syntax inventory: 8 entry points.
- `two-plan-completion-contracts.php`: expanded to 89 checks including Round-20 storage/predispatch/search-epoch regressions.
- Deterministic package: `17-sabri-network-and-messages-2.1.0.zip`.
- Verified package SHA-256: `555ccf9f1495f5202320278bbdcd0b72883877259a6e1038ea766a265a8ccfcb`.
- Exact implementation head: `4014852c6ed2ab72cca775e9a3c6098e026037c6`.
- Workflow run: `32022835397`.

## Release-truth boundary

This ledger establishes repository review/correction evidence only. It does **not** establish Hostinger staging acceptance, deployed-package parity, live DB/schema/migration state, real provider acceptance, production deployment or operational SLO evidence.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
