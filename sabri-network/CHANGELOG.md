# Changelog

## 2.1.0 — 2026-08-11 onward — Current governing-plan repository corrective candidate

### Fresh 20-round cycle — Round 19 correction set — 2026-09-07
- Round 19 completed its uninterrupted release/migration/package/runtime-closure review before any Round-19 correction was made; the frozen ledger records four proved defects.
- Added `class-sn-fresh20-r7-message-read-hardening.php` to the deterministic package's explicit governed required-surface list and added permanent package regression coverage for that runtime requirement.
- Reconciled current QA/release prose with executable quality-gate truth: every PHP suite present under `tests/` is discovered/cross-checked by the gate; at the Round-19 reviewed state there are 63 unique PHP suites and 10 governed JavaScript syntax entry points.
- Retired the completed R18 inventory and write-capable correction workflows so later review rounds cannot be modified by stale round-specific automation.
- Replaced historical-cycle wording in current status/readme surfaces with the active fresh-20 branch boundary. Exact-head CI remains required before Round 20 begins.
- Repository evidence only: no staging, deployed artifact, DB/schema, migration-execution or live-verification claim is made by this correction set.

### Fresh 20-round cycle — Round 18 correction closure — 2026-09-06 to 2026-09-07
- Round 18 was fully reviewed and its defect ledger frozen before correction work began.
- The canonical PHP review gate is now warning-fatal: all explicit PHP suites execute through `tools/run-php-test.php`, with `E_ALL` diagnostics promoted to test failures.
- Warning-fatal regression exposed and corrected latent test-harness defects in the Meet concurrency and R18 warning-contract suites before the canonical quality-gate change was committed.
- Round 18 remains repository evidence only; staging, deployed package, DB/schema, migration and live verification remain separate gates.

### Another-fresh 10-round corrective cycle — 2026-09-05 to 2026-09-06
- Completed all ten rounds on `review/file17-another-10-round-2026-09-05` under **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**.
- Defect-bearing rounds: **R1, R2, R3, R4, R5, R6, R7, R8, R9, R10**; clean rounds: **none**.
- Retained governed migration completion/rollback truth, administrative repair fail-closed behavior, lossless message-search rebuild ownership, Smail exact-request idempotency and canonical space/Meet authorization boundaries.
- Retained privacy progress and bounded erasure, semantic purge confirmation, interoperability configuration/durable-sent truth, speaker/template transaction safety, Future device-key privacy erasure and checked bulk scheduler recovery.
- Round 10 reconciled current release documentation and hardened standalone packaging so every active late runtime correction layer is an explicit required release surface.
- The previous `review/file17-fresh-10-round-2026-09-04` cycle and older candidate `f832f7b2d4bb4cf67fc9749e1eb9d3219f5fc0a2` remain historical evidence only; a prior green SHA is never current-head proof.

### Current quality and release truth
- The executable full quality gate discovers every PHP review suite under `tests/`, invokes each exactly once through the warning-fatal canonical wrapper, and fails on inventory drift. At the Round-19 reviewed state this is **63 unique PHP review suites** and **10 governed JavaScript syntax entry points**, plus PHP 8.1/8.3 syntax, shell/CSS/accessibility/hygiene, exact staged-source manifest and deterministic double-build gates.
- Deterministic artifact name remains `17-sabri-network-and-messages-2.1.0.zip`.
- Provider-dependent/high-risk capabilities remain fail-closed until their separate provider/security/staging acceptance gates pass.
- Staging, deployed artifact, DB/schema version, migration execution, live behavior and operational acceptance remain separate evidence gates.

### 2.1.0 capability line retained
- Unknown-sender message requests with encrypted first-message storage and transactional acceptance.
- Encrypted scheduled-message storage, canonical polls/checklists, disappearing-message lifecycle and legal-hold precedence.
- Communities/groups/channels with rules, onboarding, forum/AMA/wiki/events/cohorts and moderation.
- Authenticated server-side encryption at rest with dedicated communication-key lifecycle; no unsupported E2EE claim.
- Verified-user private transfer up to 1 GiB/file with resumability, SHA-256 integrity, scanning/quarantine, private storage, signed grants, revocation and retention.
- Direct calls/Sabri Meet with fresh File-00 eligibility and approved-provider STUN/TURN/SFU boundaries.
- Privacy export/erasure, reports/appeals/legal holds, reliable outbox/inbox events, File-16 AI/private semantic bridges and governed interoperability.

## 2.0.3 — 2026-08-07 — Forty-round corrective release candidate
- Historical forty-round corrective line with 18 defect-bearing and 22 clean rounds.
- Preserved File 17 communication ownership, File 00/02 identity authority, File 19 notification ownership, File 20 shell and File 26 global search boundaries.
- Added key-lifecycle, verified-transfer, forwarding/Smail privacy and deterministic release corrections.

## 2.0.2 — 2026-08-07 — Four-plan/four-round corrective release
- Hardened File 19 notification ownership, transfer concurrency, canonical message encryption, Smail retry safety and forwarding audience minimization.

## 2.0.1 — 2026-08-07 — Smail, verified transfer and recovered-directive completion
- Added CF-01 communication context, internal Smail, verified transfer and File-17 visual identity plus explicit review gates.

## 2.0.0 — 2026-08-01 — Canonical communication architecture
- Unified Network relationships and Messages under one File-17 backend with policy, privacy, presence, calls, search, outbox, communities and high-risk governance.

## Status law
A successful source review, CI run or deterministic ZIP is not equivalent to staging acceptance, live deployment or operational completion. Those states require separate real-environment evidence and Founder acceptance.
