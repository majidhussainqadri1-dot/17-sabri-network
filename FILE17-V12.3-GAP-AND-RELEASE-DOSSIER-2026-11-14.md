# File 17 — V12.3 exact-evidence gap and release dossier
Date: 2026-11-14 (Asia/Karachi)  
Scope: Sabri Network and Messages (File 17), **repository and live are different realities**.

## Authority and precedence
- Normative: `Sabri-Website-Master-Plan-V12.3.docx` (13 Nov 2026), especially File 17 charter (chapter 3), F17-CEN-01/02 and F17-FUT-01..24 (chapter 11), acceptance (chapter 8), cross-file release handoff (chapter 9), deployed proof (chapter 12).
- Earlier File17 Final Harmonized Master Plan, Future Communication Superset 24 and historical review ledgers are subordinate to later explicit V12.3 requirements on conflict.
- A document, code, historical PR, green workflow, staged ZIP and the actual deployed system are **seven different statuses**. Do not infer one from another.
- This dossier records **repository evidence**, and does not approve production deployment.

## Baseline B0: distinct realities
| Field | Evidence on 2026-11-14 | Status |
|---|---|---|
| GitHub repository | `majidhussainqadri1-dot/17-sabri-network` | Accessible |
| Repository default `main` | `8ae656e51796d1f05865d8be5dca2480443d79ca` | Frozen starting source |
| `main` plugin header | `2.1.0` | Source version only |
| `main` automated CI | run 39201819466 passed 2026-10-19 | Historical exact-SHA evidence |
| Prior corrective PR #23 | `607704de4b36277c04d7b5e12dee324a1d4cae47`, draft, unmerged | Separate repository candidate |
| PR #23 automated CI | runs 39211541082 and 39211545739 passed, 56 PHP/10 JS as recorded | Historical exact-SHA evidence |
| Draft PRs #17, #18, #19 | Separate historical branch chain | Not equivalent to main or PR #23 |
| New V12.3 audit branch | `audit/file17-v12-3-handoff-20261114`, based on PR #23 SHA above | New, unaccepted candidate |
| WPVibe connected sites | Zero | Cannot inspect site via WPVibe yet |
| WPVibe connect attempt | `https://www.sabrihomeopathy.com`, HTTP 530 | Cause not established |
| WordPress.com/Jetpack inventory | site 256120819, `https://www.sabrihomeopathy.com`; `site_disconnected` | Site-scoped tools unavailable |
| Installed live File17 version/bytes/hash | No read-only authenticated evidence | **Unverified** |
| Live PHP/WP/plugin/DB/schema/options/migrations/logs | No authenticated read-only evidence | **Unverified** |
| Staging acceptance, provider contracts and live E2E | Not demonstrated | **Pending** |

**Mandatory:** Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

An HTTP 530 does not prove an origin outage, specific Cloudflare configuration, malware, attack, or application-code defect. The Jetpack disconnected flag is a connection state, not proof of site unavailability. Reconnect/diagnose through host/WPVibe before claiming live access.

## Finding ledger — evidence and honest status
| ID | Severity / scope | Exact source observation | Change status | Live status |
|---|---|---|---|---|
| F17-FUT24-RETRY-02 | P1, repository Future-24 creation idempotency | Previous `SN_Future_Superset::client()` could synthesize identical idempotency key for two distinct requests sharing payload. PR #23 removes fallback, enforces caller-owned key; regression `eighth-fresh-future-idempotency-runtime-contracts.php`. | Corrected in draft PR #23 SHA `607704de...`; not merged to main | Unverified |
| F17-V123-TEAM-01 | P1, repository F17-FUT-05/06 | `SN_Future_Superset_Part_1::set_team_inbox()` committed an F17-FUT-05 state, **ignored** WP_Error from F17-FUT-06 upsert, then returned success. This allows partial handoff and false acknowledgement. | Fixed on new audit branch using a database transaction, explicit second-result check, rollback, commit check; new runtime regression | Unverified |
| F17-V123-REMINDER-OBS-01 | Requires further runtime verification, **not a proved defect** | `SN_Future_Superset::cleanup()` sends an action to File19 integration and marks reminder fired. Verify whether the approved delivery bridge gives durable acceptance evidence and whether no-listener/provider-failure conditions are safe. | Investigation only; no patch | Unverified |

**Count policy:** Two source-proven defects, both corrected in separate **unmerged repository candidates**; neither is proven resolved in `main` or production. All other items are test obligations or unresolved questions, **not counted as defects** until reproduced.

## File 17 V12.3 mapping
| V12.3 ID or family | Current source proof | Required acceptance still missing |
|---|---|---|
| F17-CEN-01 / RCD-025 | One File17 communication owner; relationships, messages, communities, Smail, transfers, calls and presence in the source tree | Live parity, provider, DB/schema, independent end-to-end journeys |
| F17-CEN-02 | Sabri Green `#087A4E` override and stylesheet tokens in source | Contrast/RTL/mobile/dark/reduced-motion tested on real environments |
| AJ-18 | Community spaces, roles and reporting implemented as source candidates | Real moderation, expiry, block/guardian/revoke and appeal |
| AJ-19 | Smail seven mailbox projections coded | Draft/version/recipient/visibility/offline + real WordPress roles |
| AJ-20 | Message request, accept/decline/report paths coded | Replay, rate limits, blocked/unknown senders, stale permission |
| AJ-30 | Verified-user max `1,073,741,824` bytes; resumable file-transfer source present | Real scanner/storage/chunk interruption/hash/grant revoke and malicious-file tests |
| UX-06, UX-09 | Access controls and message/transfer states represented | End-to-end human journeys with current File00/02 and File20/25 contracts |
| QA-15–17 | Source stylesheet and quality gates | Real 320/390/768/1024/1440px, keyboard/screen reader, lab+field p75 |
| QA-19, QA-20, QA-22, QA-24 | Security/privacy/communication/release sections in V12.3 | Fresh stage/live evidence, restore, parity, real provider and device tests |
| CEN-REL-001 | Documented release law: zero known active-scope defects | Two full independent reviews; backup, rollback, staging, founder approval, live retest |

## All 24 Future Communication Superset paths
A declared slug, method or test is **coded candidate representation**, not proof of operational capability.

| ID | Phase | Feature | Proof needed before activation |
|---|---|---|---|
| F17-FUT-01 | ADVANCED TRUST | Audited E2EE | Independent cryptographic protocol audit, device revoke/re-key, downgrade refusal, stage tests |
| F17-FUT-02 | ADVANCED TRUST | Device Key Verification | Device binding, safety-number consistency, changed-key warning |
| F17-FUT-03 | ADVANCED TRUST | Key Transparency Log | Append-only consistency evidence, tamper test |
| F17-FUT-04 | ADVANCED TRUST | Sensitive Conversation Lock | File02 step-up, history/preview/deep-link denial, recovery |
| F17-FUT-05 | NEXT | Delegated Team Inbox | Role-bound visibility/revoke + atomic updates |
| F17-FUT-06 | NEXT | Assignment & Handoff | Concurrent handoff, stale CAS, atomic commit, no false success |
| F17-FUT-07 | NEXT | Snooze/Remind Later | Time zone/DST, durable File19 bridge, expiry, retry |
| F17-FUT-08 | NEXT | Saved Replies/Templates | Escaping, privilege, clinical safeguards, no mass-send |
| F17-FUT-09 | NEXT | Message Version History | Authorized visibility, retention, legal hold, erasure |
| F17-FUT-10 | NEXT | Bulk Conversation Operations | Per-item authorization, partial-failure, idempotency |
| F17-FUT-11 | NEXT | Smart Private Views | Revocation invalidation and query isolation |
| F17-FUT-12 | NEXT | Expiring QR Invitations | Expiry, forged/replay token, guardian/ban guards |
| F17-FUT-13 | NEXT | Temporary Membership | Real expiry, revocation and downstream authorization |
| F17-FUT-14 | NEXT | Mentor–Student Mode | File05 role/supervision contract |
| F17-FUT-15 | NEXT | Scholarly Citation Cards | Live File06/12 canonical/versioned refs and withdrawal |
| F17-FUT-16 | NEXT | De-identified Case Discussion | Clinical privacy, consent, no exposed identifiers |
| F17-FUT-17 | SCALE | Call Lobby | Real approved SFU and participant revalidation |
| F17-FUT-18 | SCALE | Hand Raise Queue | Reliable realtime/SFU moderation |
| F17-FUT-19 | SCALE | Breakout Rooms | Room isolation and real media transfer |
| F17-FUT-20 | SCALE | Host Transfer | Host integrity/revoke and reconnect |
| F17-FUT-21 | SCALE | Network Quality Assistant | Actual network metrics/device testing |
| F17-FUT-22 | ADVANCED TRUST | Opt-in AI Assistant | File16 provider, explicit consent, no autonomous send |
| F17-FUT-23 | ADVANCED TRUST | Private Semantic Search | Permission-scoped indexing, deletion/expiry sync |
| F17-FUT-24 | ADVANCED TRUST | Interoperability Gateway | Approved adapters, origin identity, spam/replay/kill switch |

## Cross-file authority and required owner evidence
| Owner | Domain data it alone controls | File17 integration proof required |
|---|---|---|
| File00/02/09 | Eligibility, sessions, credential/verification authority | Current assertions and revocation; no locally invented identity |
| File03 | Public/profile details | Privacy-safe contact and relay, never disclose raw protected address |
| File05/06/12 | Courses and source/citation records | Authorized references only; revocation propagation |
| File08/CF01 | Appointment/clinical truth | Bounded context, fresh permission, no raw chart duplication |
| File16 | AI | Provider-gated, consented request with minimal data |
| File19 | Notifications | Durable event bridge; source-of-truth delivery state remains File19 |
| File20 | Shell/settings/navigation | Single approved routes, RTL, Back/Home, state ownership |
| File21/22 | Publishing/editor | Canonical source references; no parallel message backend |
| File24 | Independent assurance | Evidence and exact deployment review |
| File25 | Design/components | Sabri Green token, accessible controls, mobile/dark |
| File26 | Global Search | Private messages and contacts excluded; private search remains File17 |
| CF04/CF06 | Approved media and language contracts | Clear public/private media lifecycle; versioned translations |

## Exact next execution gates
1. **G0** Live reality inventory: deployed plugin PHP header, active flags, real DB/version/options/migration/logs/checksum; stop on unknown parity for any live bug.
2. **G1** Requirement-to-code map: direct path + owner + expected behavior + negative tests per required ID.
3. **G2** Separate source defects from live symptoms; freeze the review findings; fix and regress only confirmed candidate-source defects.
4. **G3** Reconcile PR #17/#18/#19/#23 without blind merges; exact commit SHA, duplicate change, CI and release inventory.
5. **G4** Security/permissions, message request, Smail, 1 GiB transfer and call provider contracts, with explicit no-E2EE overclaim.
6. **G5** V12.3 UI/RTL/low-data/accessibility + browser/assistive tests; route-specific performance budgets.
7. **G6** Two **complete** independent review/fix/retest cycles; no review round is interrupted with a speculative patch.
8. **G7** Real WordPress/PHP/MySQL new install + upgrade/migration/race/rollback + plugin companion tests in staging.
9. **G8** Deterministic package/manifest, exact-SHA CI, restore rehearsal, owner acceptance and written Founder release approval.
10. **G9** Controlled deploy, exact deployed-artifact parity, fresh live retest, observation and rollback; only then label `Operational`.

## Release decision
**NOT APPROVED FOR LIVE DEPLOYMENT.** Unverified deployed bytes, DB/schema/migrations, missing staging/provider/restore acceptance, and fresh V12.3 UX/security/live evidence are release blockers. Optional features may be explicitly disabled *only when independent isolation and safe fallback are proven*; they remain open in the defect/backlog register. No source/CI-only result is a live resolution.

## Required final incident fields (every relevant report)
- Repository HEAD: exact SHA, timestamp, CI.
- Deployed Version: exact live plugin build/hash, or `UNVERIFIED`.
- DB Version: exact live version and schema, or `UNVERIFIED`.
- Migration State: exact options/checkpoint and verification, or `UNVERIFIED`.
- Live Verification Status: exact successful functional/safety retest, or `UNVERIFIED`.

## Evidence integrity notes
- The new atomicity regression is an isolated PHP mocked-DB check; it is **not** a replacement for actual MySQL transaction isolation/deadlock/concurrency testing.
- Historical CI and package checksums apply only to their immutable SHAs. New branch changes require new CI evidence.
- No code was deployed live in this audit. No authorization bypass, dangerous provider enabling or migration is allowed on this provisional repository track.
