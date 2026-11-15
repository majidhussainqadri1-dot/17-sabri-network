# Changelog

## V12.3 Security hardening follow-up — 2026-11-15 (unreleased)

- Verified actual InnoDB storage engines at key-change time; fail closed on MyISAM/unverified engine and declare InnoDB for new key/audit tables. No automatic conversion of existing production tables.
- Extended read-only live-freeze evidence to capture table storage engines and added nontransactional-storage regression coverage.
- Current exact full gate contains 63 explicit PHP suites; historical counts in earlier notes are not current release metrics.


- Fail closed on corrupt conversation lock state and redact sensitive previews from locked conversation lists.
- Serialize device-key registration/rotation and local audit-log append; reject and rollback unlogged changes.
- Gate E2EE, key verification/transparency and other external features on evidence of approved providers, rather than code presence.
- Paginate local device-key history and explicitly distinguish unsigned local audit from independently verified transparency proof.
- Add four runtime test suites and full quality gate coverage. Not a live/deployment acceptance statement.


## V12.3 source candidate — third code/QA pass (15 November 2026; unreleased)

- Increased advanced communication workspace to 33 governed controls; added current-authority conversation suggestions, device-key and key history read-only views without implying audited E2EE.
- Added versioned reminder cancellation/rescheduling and message/current-membership/block checks before File19 notification handoff; kept due reminders pending without a registered notification consumer and suppressed locked-conversation notification previews.
- Changed F17-FUT-05/06 assignment into one named-lock and SQL-transaction workflow, current version precondition, conversation-active and current member checks, no partial commit on second-record failure; exposed team state version in UI.
- Added owner-scoped, variable-allowlisted, manual-review-only saved reply preview, rejecting clinical directives without approved policy and forbidding autonomous message sending.
- Added opt-in translation task with File16 context authorization and File20/translation-locale provider approval, denying unapproved locale or provider.
- Added four isolated V12.3 PHP runtime/refusal suites alongside the prior 53 and expanded the Node DOM/fetch UI suite. Full quality gate now lists 57 PHP suites.
- Added read-only deployed runtime+schema+embedded-manifest SHA freeze command and V12.3 clause-to-code acceptance matrix; no WordPress config, DB or live artifact modified.
- Actual SFU/TURN/E2EE/AI/index/scanner/storage staging verification and live deployment parity remain mandatory; **not operationally accepted**.


### QoS input and grading corrective candidate — 15 Nov 2026
- Proven source issue: packet jitter and downlink were ignored in quality grading and invalid REST values were silently clamped.
- Implemented a shared conservative grade helper for authoritative/fallback handlers, limited ICE retry hints to conditions indicating transport instability, removed invented zero input defaults and fail-closed validated untrusted metrics.
- Added an isolated executable PHP regression for grades, numeric boundaries and opt-out contracts (58 PHP suites including this addition).
- No claim of actual browser-collected network speed, SFU integration or deployed fix.

## V12.3 advanced communication source candidate — 2026-11-15 (unreleased / no plugin version change)

- Updated V12.3 source-to-plan gap/release ledger; distinguished the verified 19 October 2026 baseline CI from staging/live truth.
- Added 24 advanced-workspace controls across two source review passes to the original 3 forms (27 total), reusing governed REST endpoints instead of parallel message or contact data stores.
- Added private semantic consent withdrawal and visible asynchronous purge-pending state, explicit user approval for potentially destructive operations, safe bounded bulk-preview/cancel/status, and consent refusal before sending selected AI/case context.
- Preserved capability inventory while displaying action results and errors in a dedicated accessible status; rendered authorized results as text (never as response HTML).
- Added accessible native checkbox/select sizing, keyboard focus and mobile progressive disclosure.
- Added a native Node DOM/fetch runtime suite to the full quality gate and extended the existing Future-24 PHP static contracts.
- No new custom DB tables or migrations, no change to protected message backend ownership, no live installation or production acceptance claim.
- Audited E2EE, independent key transparency, SFU calls, private semantic/AI provider integration, scanner/storage and cross-module staging remain separate fail-closed acceptance gates.


## 2.1.0 — 2026-08-11 onward — Current governing-plan repository corrective candidate

### Governing reconciliation
- Reopened coding completeness against the governing consolidated central plan, the current File 17 Final Harmonized Master Plan and the Founder-approved Future Communication Superset 24 instead of treating historical review cycles as present-head proof.
- Preserved File 17 as the only communication owner and retained File 00/02 identity, File 19 notification, File 20 shell, File 26 global-search and File 08/CF-01 clinical ownership boundaries.
- Fresh review cycles remain independently counted/audited; a green workflow for one SHA is never reused as evidence for another.

### Current sixth fresh 20-round cycle — 2026-08-18
- Frozen starting reviewed source: `a3a435b162167c9078ded22b08d6c8fb39b6ca27`.
- Defect rounds: **R2, R5, R6, R8, R9, R12, R18, R19, R20**.
- Clean rounds: **R1, R3, R4, R7, R10, R11, R13, R14, R15, R16, R17**.
- Preserved canonical File-00/09 phone and verification truth after File-03 presentation enrichment.
- Required caller-supplied stable retry identities for canonical message and Smail sends.
- Kept hidden-for-self messages out of private search/context and corrected pagination after visibility filtering.
- Bound verified-transfer idempotency to exact file/chunk/conversation/hash/recipient semantics.
- Made Future message-version privacy erasure failure-safe so cursor progress cannot skip a failed deletion.
- Restored exact Sabri Meet `sn_meet_db_version` rollback truth in migration failure handling.
- Extended exact Sabri Green/accessibility hardening to standalone Messages/communication-settings surfaces.
- Added `sixth-fresh-twenty-round-contracts.php`, restored omitted fifth closure/release-truth suites to the full gate, and required the sixth runtime privacy layer in quality/package surfaces.

### Current quality and release truth
- Current explicit full quality inventory is **53 PHP review suites** and **9 JavaScript syntax entry points**, plus PHP 8.1/8.3 syntax, shell/CSS/accessibility/hygiene, exact staged-source manifest and deterministic double-build gates.
- Current deterministic artifact name remains `17-sabri-network-and-messages-2.1.0.zip`.
- Provider-dependent/high-risk capabilities remain fail-closed until their separate provider/security/staging acceptance gates pass.
- Historical review evidence remains historical; staging, live and operational acceptance remain separate external gates.

### 2.1.0 capability line retained
- Unknown-sender message requests with encrypted first-message storage and transactional acceptance.
- Encrypted scheduled-message storage, cancellation and delivery-time authorization revalidation.
- Canonical polls/checklists and disappearing-message lifecycle with legal-hold precedence.
- Fail-closed translation adapters, encrypted temporary updates and voice-note workflow.
- Communities/groups/channels with rules, onboarding, forum/AMA/wiki/events/cohorts and moderation.
- Authenticated server-side encryption at rest, dedicated communication-key lifecycle and bounded legacy compatibility.
- Verified-user private transfer up to 1 GiB/file with resumability, integrity, scanning/quarantine, signed grants, ranges, revocation, retention and audit.
- Direct calls/Sabri Meet with fresh File-00 eligibility and approved-provider STUN/TURN/SFU boundaries.
- Privacy export/erasure, reports/appeals/legal holds, reliable outbox/inbox events, File-16 AI/private semantic bridges and governed interoperability.

## 2.0.3 — 2026-08-07 — Forty-round corrective release candidate

### Forty-round review result
- Completed **40** sequential review/fix rounds against Definitive Master Plan v3.0, Consolidated Recovered Directives, Continuous Value / Top-20 Superset Master Plan and the File 17 Final Harmonized Master Plan.
- **18** rounds found one or more defects and corrected them in the same cycle; **22** rounds found no new defect.
- Added four dedicated forty-round suites and expanded the explicit full quality inventory from 41 to **45 PHP review suites**.

### Governance and ownership
- Declared File 26 as the global Search/Discovery/Ranking owner; File 17 retains authorized private-message search only.
- Restricted federated File-17 discovery to public/explicitly-consented people/space projections; private messages and contacts are excluded.
- Preserved File 00/02 identity/verification, File 19 notification delivery, File 20 shell/navigation and native domain ownership boundaries.

### Privacy and identity projection
- Made phone and avatar projections block-aware, including privacy modes that otherwise permit broad visibility.
- Removed cross-user active-device counts from presence projection; device counts are self-only.
- Bridged the legacy `/presence` route to the canonical per-device presence service rather than maintaining a parallel state path.

### Cryptography and key lifecycle
- Added versioned `SNC3`/`SNC4` communication ciphertext formats with key identifiers and bounded previous-key support while retaining legacy `SNC1`/`SNC2` reads.
- Added lazy authorized re-encryption for canonical message bodies, private transfer chunks and Smail drafts when an older key is encountered.
- Versioned signed grants so key rotation can be performed without silently invalidating every in-flight grant.
- Retained truthful server-side authenticated encryption-at-rest wording; no E2EE claim is made.

### Verified private transfer
- Made current File-00 verification assertion the sole verified-user authority for transfer sender/recipients; removed local phone/badge fallback semantics.
- Guaranteed synchronous scanner plaintext cleanup with `finally` and fail-closed secure-random error handling.
- Added filesystem-containment validation for encrypted chunk reads, scans, downloads and deletion.
- Preserved the exact 1,073,741,824-byte limit, resumability, SHA-256, MIME/magic/archive controls, quarantine, signed expiring grants, ranges, revocation and audit.

### Messages, forwarding and Smail
- Added defense-in-depth forwarding protection using authorized transient decrypt, target-context re-encryption, idempotency and audience-minimized source metadata; private attachment IDs cannot be reused across audiences.
- Smail mailbox projection decrypts authorized canonical message bodies rather than exposing stored ciphertext.
- Smail drafts lazily rotate encryption keys and use keyed HMAC blind hashes instead of ordinary plaintext SHA-256 fingerprints.
- Core WordPress message privacy exports and Smail draft exports provide readable authorized data-subject values without weakening encryption at rest.

### Release truth and packaging
- Promoted substantive corrections from immutable historical 2.0.2 to **2.0.3**.
- Deterministic package identity: `17-sabri-network-and-messages-2.0.3.zip`.
- Staging, live and operational acceptance remain separate external gates.

## 2.0.2 — 2026-08-07 — Four-plan/four-round corrective release
- Enforced File 19 single notification-center/delivery ownership and removed File-17 active fallback notification UI/writes.
- Corrected a destructive concurrent same-index encrypted transfer chunk race.
- Added authenticated `SNE1` server-side at-rest encryption for canonical message bodies with bounded plaintext migration and private-search reindexing.
- Routed Smail through canonical message integrity and made multi-recipient reservation retry-safe.
- Hardened forwarding with authorized transient decrypt, target re-encryption and source minimization.
- Expanded the explicit quality gate from 37 to 41 PHP review suites and aligned deterministic 2.0.2 packaging.

## 2.0.1 — 2026-08-07 — Smail, verified transfer and recovered-directive completion
- Added `sn.cf01.communication-context` 1.0.0 with revocable opaque references and fail-closed authorization.
- Added internal Smail with Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over canonical File-17 conversations/messages.
- Added verified-user private transfer up to 1 GiB/file with resumable encrypted chunks, SHA-256, MIME/magic/archive controls, mandatory scanner quarantine, signed grants, range resume, revocation, retention, privacy and audit.
- Added current green primary File-17 visual identity while retaining orange as a secondary accent.
- Added 37 explicitly enumerated review suites and PHP 8.1/8.3 exact-head CI coverage.

## 2.0.0 — 2026-08-01 — Canonical communication architecture
- Unified Network relationships and Messages under one File-17 canonical backend and removed duplicate identity/OTP/global-navigation ownership.
- Added centralized policy enforcement, minor/guardian restrictions, block/rate-limit/audit/privacy controls and private attachments outside public Media Library.
- Added unique direct-conversation/contact-pair/message-idempotency/active-call constraints and transactionally locked ownership transfer.
- Added privacy-scoped presence/typing, message preferences, calls/signaling acknowledgements, reports/appeals/legal holds and retention controls.
- Added private indexed message search, transactional outbox/inbox retry/dead-letter delivery, Sabri Meet, communities/groups/channels, multi-device presence, context adapters, high-risk dual control and governed conference providers.
- Historical detailed 2.0.0 implementation batches and evidence remain available in immutable Git history.

## Status law
A successful source review, CI run or deterministic ZIP is not equivalent to staging acceptance, live deployment or operational completion. Those states require separate real-environment evidence and Founder acceptance.

### V12.3 source increment: call readouts and opt-in diagnostics (15 Nov 2026)

- Added three authorized progressive-disclosure forms for call lobby, speaker queue and manually entered network metrics, reusing protected existing File-17 REST API routes.
- Preserved opt-in telemetry default=false, range validation and clear statement that metrics are client supplied, not a measured speed test.
- Expanded Node regression to 36 forms; real SFU/WebRTC and browser-device quality verification still pending.
