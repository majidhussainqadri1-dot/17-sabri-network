# File 17 — V12.3 26-ID source traceability and pre-production acceptance matrix
Date: 2026-11-15. Governed source: `Sabri-Website-Master-Plan-V12.3.docx`, File17 CEN-01/02 and FUT-01..24 (paragraphs 1896–1921); AJ-19, AJ-20, AJ-30; chapter 8; CEN-REL-001. This is a **repository-candidate trace**, not a live/staging certification.

## Status legend (do not collapse)
- **Source candidate:** code/method or route is present in a specified repository commit. This does **not** prove complete UX/real-provider operation.
- **Automated QA:** only named suites that actually passed on an *exact immutable head*.
- **Provider-gated:** must deny requests or use honest degraded mode absent approved owner/adapter.
- **Staging acceptance:** actual WordPress/PHP/MySQL, required companion plugins, privacy/race/accessibility/media/equipment tests with evidence.
- **Operational:** exact live artifact + DB/migration + current tests + observation and approval. No item below presently has verified Operational status.

Primary source: current stacked candidate `review/file17-v123-reminders-20261115` based on draft PR #24 `d050c720...`. File names are relative to `sabri-network/`. Review these paths at the final exact SHA after new changes.

## Current scope and canonical ownership
| Plan ID | Source owner and implementation surface | Acceptance obligation / negative cases | Evidence state |
|---|---|---|---|
| F17-CEN-01 | `sabri-network.php`, `class-sn-db.php`, `class-sn-messages.php`, `class-sn-smail.php`, `class-sn-file-transfer.php`, `class-sn-meet.php`; same File17 domain | One message source, canonical member authority, Smail, calls, 1 GiB resume + scanner/grants; no duplicate backend | Repository source candidate; companion, provider and live parity unverified |
| F17-CEN-02 | `assets/css/brand-green-overrides.css`, File25/File20 token boundary | Main #087A4E, orange only secondary #FF8A1F; RTL/LTR, 320–1440px, WCAG2.2 AA checks, real browser/assistive tests | CSS candidate; staging UI acceptance blocked |

## Future communication 24 — exact source and acceptance
| ID | Effective source/route or governor | Required owner / critical adversarial acceptance | Current proof |
|---|---|---|---|
| F17-FUT-01 | `class-sn-future-superset-part-1.php` E2EE policy; `class-sn-future-superset.php` capability registry | File00/02 identity + independently audited crypto provider; cross-device, revoke, rekey, downgrade, secret-leak review | Provider-gated candidate; **no live audited E2EE claim** |
| F17-FUT-02 | `class-sn-future24-review-hardening-j.php` device-key register/revoke/safety number; Part1 origin | Approved device-key assertion layer; changed key invalidation, old-device denial, accessible QR/compare | Source; key/device staging unverified |
| F17-FUT-03 | `class-sn-future24-review-hardening-j.php` transparency/checkpoint/verify ledger | Append-only signed checkpoints, consistency proofs, tamper and provider outage simulation, minimal metadata | Source; cryptographic/provider acceptance unverified |
| F17-FUT-04 | `class-sn-future-superset-part-1.php` lock and `pre_dispatch`; File00/02 step-up filter | Expired step-up, background/deep link, search/notifications preview and shared device; deny unauthorized unlock | Source; live protected-route matrix unverified |
| F17-FUT-05 | `class-sn-future24-review-hardening-k.php` Team Inbox GET/POST/notes, version CAS | File00/08 delegation: no existence leak, revoke now, unread scoping; settings cannot change assignee outside governed handoff | PR #24 source fixes + static/runtime; staging blocked |
| F17-FUT-06 | `class-sn-future24-review-hardening-l.php` reasoned handoff; `class-sn-future-superset-part-2.php` bulk-assignment gate | Transfer reason, expected_version, receipt, SLA, actor/target delegation; concurrent stale and revoked staff denial | PR #24 source fixes + runtime tests; DB race/staging blocked |
| F17-FUT-07 | `class-sn-future24-review-hardening-m.php`, `class-sn-future-superset-part-1.php`, `class-sn-future-superset.php::cleanup`, `class-sn-future-superset-core.php::owned/render_workspace`, `assets/js/future-superset.js` + stylesheet | Valid member/message; strict UTC/offset/IANA DST; owner list/current version; accessible mobile/RTL UI refresh/create/reschedule/cancel; stale version rejection; File19 durable delivery proof | Source fixes + 4 PHP reminder runtime suites + 1 PHP UI suite + executable DOM suite in UTC and New York; **actual File19 durable acceptance and real browser/staging blocked** |
| F17-FUT-08 | `class-sn-future24-review-hardening-n.php` managed template CRUD/preview/history; Part1 baseline | Team scoping, variable allowlist, clinical red flags, stale variables, accidental mass-send/unauthorized editing | Source candidate; real UX and clinical policy stage unverified |
| F17-FUT-09 | `class-sn-future-superset-part-2.php` version capture/read; `class-sn-future24-review-hardening-o.php` edit lock; `class-sn-sixth-fresh-privacy-hardening.php` privacy erasure | Authorized history, message edit race, unsend, erasure, legal hold, retention, search update | Candidate tests; MySQL concurrency/data lifecycle unverified |
| F17-FUT-10 | `class-sn-future-superset-part-2.php` bounded bulk jobs and per-item action; `class-sn-future24-review-hardening-o.php` bounded recovery | Mixed authority, partial failure, exact key, cancellation, stale processing recovery; assignment receipt and no duplication | PR #24 source fixes + runtime; actual queue/recovery stage unverified |
| F17-FUT-11 | `class-sn-future24-review-hardening-a.php` smart-view results; Part2 saved criteria | Owner-scope/field allowlist, stale ACL/index rejection, cross-user inference, no File26 private indexing | Candidate; private-search index integration unverified |
| F17-FUT-12 | `class-sn-future24-review-hardening-a.php` signed QR invite/redeem/revoke | Invite expiry/max-use/replay, forged token, banned inviter, guardian and full community | Candidate; DB race and live age asserts unverified |
| F17-FUT-13 | `class-sn-future24-review-hardening-b.php` scoped membership and expiry job | File00 verified eligibility, new action authority after expiry, cached session revoke, sponsor/renewal | Candidate; real clock/revocation staging unverified |
| F17-FUT-14 | `class-sn-future24-review-hardening-b.php` mentorship create/decide/end | File05 learning truth; guardian restriction, blocked mentor, end/handoff, harassment escalation | Candidate; File05/guardian acceptance blocked |
| F17-FUT-15 | `class-sn-future24-review-hardening-c.php` scholarly citation card | File06/12 canonical, permission at click, withdrawn PDF/edition/change/source provenance | Candidate; File06/12 owner connector unverified |
| F17-FUT-16 | `class-sn-future24-review-hardening-c.php` de-identified case discussion | File08/clinical owner; consent, name/phone/ID/image leakage, clinician claim, exported artifact | Candidate; clinical policy/media scan stage blocked |
| F17-FUT-17 | `class-sn-future24-review-hardening-d.php` call lobby and moderation | Real SFU/provider, denied and revoked join, host disconnect, same-message retry/reconnect | Provider-gated; no real SFU stage acceptance |
| F17-FUT-18 | `class-sn-future24-review-hardening-d.php` speaker queue lock/reorder | Concurrent raise/reorder, removed participant, no microphone authority bypass, realtime reconnect | Candidate; provider/device test unverified |
| F17-FUT-19 | `class-sn-future24-review-hardening-e.php` breakout lifecycle/move/close/cleanup | Parent membership, host revoke, partial provider failure, rollback, new room tokens and isolation | Provider-gated; real SFU and rollback unverified |
| F17-FUT-20 | `class-sn-future24-review-hardening-f.php` cohost, transfer handshake, takeover, rollback | Single owner, simultaneous transfer, revoked cohost, stale/disconnected host, provider compensation | Provider-gated; live media/control-plane reconciliation unverified |
| F17-FUT-21 | `class-sn-future24-review-hardening-g.php` network quality | Real packet-loss/RTT/bandwidth, consent/telemetry retention, audio-only fallback, no IP profiling | Diagnostic source; real network telemetry unverified |
| F17-FUT-22 | `class-sn-fifth-fresh-knowledge-hardening.php` priority-3100 effective REST override delegates to `class-sn-future24-review-hardening-g.php` | File16 adapter, per-request consent/redaction, no autonomous clinical advice, mid-provider revoked membership/minor/hidden message output denied | Second review post-provider guards + runtime suite; actual File16 provider unverified |
| F17-FUT-23 | Same priority-3100 override to `class-sn-future24-review-hardening-g.php`; consent/purge/index hooks | Private opt-in, File26 exclusion, cross-user no-leak, delete/edit/erase/withdraw and mid-projection revocation | Second review post-projection guard + runtime suite; actual index/erasure unverified |
| F17-FUT-24 | `class-sn-future24-review-hardening-h.php` inbound/outbound allowlist, bridge, revoke/kill/receipt; `class-sn-future24-review-hardening-o.php` scope guard | Approved Matrix/ActivityPub adapter, remote identity, replay/forgery, rate/spam, remote delete mismatch, purge/kill/rollback | Provider-gated, no real federation acceptance |

## Principal V12.3 acceptance journeys
| ID | Test script / canonical owner | Required evidence not supplied by code review |
|---|---|---|
| AJ-19 | Smail Inbox/Sent/Drafts/Starred/Archive/Spam/Trash; `class-sn-smail*.php` and existing Smail static/adversarial suites | Real WordPress roles, canonical message identity, read/draft/sent/unsend/erasure, responsive UI |
| AJ-20 | Unknown message request accept/decline/report; `class-sn-two-plan-completion.php`, `class-sn-relationships.php`, security overlays | Per-person block, mute, abuse rate limit, unknown sender, guardian and appeal with actual data |
| AJ-30 | Verified 1,073,741,824-byte transfer; `class-sn-file-transfer*.php`, transfer contracts | Real 1 GiB (not universal instant upload), private scanner + quarantine, interrupted chunk/retry, revoked grant, MIME/hash failure and storage access |
| CEN-REL-001 | `FILE17-V12.3-DEPLOYED-REALITY-FREEZE-RUNBOOK-2026-11-15.md` and staged release QA | Two complete independent E2E review cycles, zero known active-scope defects, staging+restore, founder approval, exact deployed parity, live smoke and observation |
| CEN-STATUS-001 | `STATUS.md`, exact GitHub Actions SHA, stage/live receipt | Do not convert Source/Packaged/Automated-QA into Staging/Live/Operational |

## Review and coverage ledger
- **Review 1:** PR #24 source defects on caller ID/handoff/bulk/semantic retry; separate stable source SHA and successful CI.
- **Review 2A:** 3 reminder path defects corrected, plus related version-projection dependency; exact-green 63-suite SHA recorded in separate review ledger.
- **Review 2B:** post-provider/post-projection revocation: 2 source timing gaps; added one deterministic runtime test suite; final SHA and green CI must be updated separately before any acceptance.
- **Not yet performed:** full isolated WordPress integration with active companions, real migration, MySQL deadlocks, SFU/scanner, device/accessibility, File19 durable ack, live deployed state and rollback rehearsal.

## New executable front-end release evidence (not browser certification)
- `tests/v12-3-reminder-workspace-dom.js` invoked in exact PHP 8.1 job and full quality gate with `TZ=UTC` and `TZ=America/New_York`. Exercises live-response-shaped fake `fetch`, DOM mutation and status; **not** a browser test with WordPress cookie/session.
- `tests/v12-3-reminder-workspace-static-contracts.php` checks 35 accessibility/CAS/source contracts; actual NVDA/VoiceOver/TalkBack, 320–1440px and WCAG2.2 testing remain pending.
- Latest verified source inventory on preceding green SHA `8f5b9389e362dca005716ec5f4d0ed6eae35b1f1`: **66 PHP suites, 10 JS entry points**, PHP8.1/8.3 successful. A later report commit requires its own green CI, it cannot inherit this result.

## Required launch acceptance table (one row per active route)
`requirement_id | route | exact source SHA | installed build+hash | owner | dependency+min_version | actor+role | allowed result | denied result | stale/failure result | test ID | browser/device | DB/version | audit trace | screenshot/trace | review A | review B | founder approval | stage result | live result`.

Status entries must be real evidence; blank or unverified must not be filled with guesswork. **No V12.3 release sign-off by this matrix alone.**
