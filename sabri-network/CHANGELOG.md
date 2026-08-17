# Changelog

## 2.1.0 — 2026-08-11 onward — Current governing-plan repository corrective candidate

### Governing reconciliation
- Reopened coding completeness against the governing consolidated central plan, the current File 17 Final Harmonized Master Plan and the Founder-approved Future Communication Superset 24 instead of treating historical review cycles as present-head proof.
- Preserved File 17 as the only communication owner and retained File 00/02 identity, File 19 notification, File 20 shell, File 26 global-search and File 08/CF-01 clinical ownership boundaries.
- Later fresh review cycles remain independently counted/audited; a green workflow for one SHA is never reused as evidence for another.

### Message requests and messaging completion
- Added unknown-sender message requests with encrypted first-message storage, incoming/outgoing queues, accept/decline/report/cancel decisions, sender and recipient rate limits and post-decline/report cooldown.
- Acceptance is transactional: request state, canonical contact state, canonical direct conversation membership and first canonical encrypted message succeed together or roll back.
- Added encrypted scheduled-message storage with cancellation, bounded retry state and delivery-time authorization revalidation.
- Added canonical polls and collaborative checklists while explicitly preventing clinical-decision authority.
- Added disappearing-message expiry with legal-hold preservation, private-search removal, reaction cleanup and attachment revocation/deletion.
- Added fail-closed transient message translation through an approved adapter only.
- Added voice-note workflow on the canonical message-integrity/private-file path with playback/transcript capability metadata.

### Temporary updates and community capabilities
- Replaced plaintext persistence for new temporary-update text with authenticated encryption and lazy authorized migration of legacy plaintext values.
- Added community rules/onboarding questions/orientation, forum questions, expert AMA sessions, wiki pages, events/cohorts, responses, best-answer/close/archive/reopen moderation and privacy-minimized aggregate community health.

### Fresh corrective hardening retained in 2.1.0
- Revalidated authorization on idempotent message replay and secured concurrent attachment cleanup.
- Forced genuinely fresh File-00 calling eligibility around media-credential issuance and serialized current call/direct-peer relationship state for call creation/join/media mutations.
- Removed File-17 shadow ownership of File-03 public profile fields.
- Revalidated and serialized forwarding inside the committing transaction.
- Corrected active-device cap semantics so expired historical presence rows do not consume the live device budget.
- Required exact version CAS for existing Team Inbox mutations.
- Hardened privacy erasure so File-17 erasers respect legal/safety holds, retry failed Meet erasure, do not erase File-03 fields, and do not destroy active non-direct conversation ownership.
- Decoupled new durable File-17 private encryption from WordPress authentication salts while retaining bounded legacy decrypt/lazy-rotation compatibility; staging/restore must preserve the dedicated File-17 master secret.
- Enforced current private-conversation membership plus owner/moderator scope before interoperability create/list/revoke/outbound dispatch.

### Privacy, QA and release truth
- Added privacy exporter/eraser participation for new personal-data domains.
- Current explicit full quality inventory is **47 PHP review suites** and **8 JavaScript syntax checks**, plus PHP 8.1/8.3 syntax, shell/CSS/accessibility/hygiene, exact staged-source manifest and deterministic double-build gates.
- Current deterministic artifact name remains `17-sabri-network-and-messages-2.1.0.zip`.
- Historical review evidence remains historical; staging, live and operational acceptance remain separate external gates.

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
- Added filesystem-containment validation for encrypted chunk reads, scans, downloads and deletion: absolute paths, NUL, traversal and out-of-root realpaths are rejected.
- Preserved the exact 1,073,741,824-byte limit, resumability, SHA-256, MIME/magic/archive controls, quarantine, signed expiring grants, ranges, revocation and audit.

### Messages, forwarding and Smail
- Added defense-in-depth forwarding protection using authorized transient decrypt, target-context re-encryption, idempotency and audience-minimized source metadata; private attachment IDs cannot be reused across audiences.
- Smail mailbox projection now decrypts authorized canonical message bodies rather than exposing stored ciphertext.
- Smail drafts lazily rotate encryption keys and use keyed HMAC blind hashes instead of ordinary plaintext SHA-256 fingerprints.
- Core WordPress message privacy exports and Smail draft exports now provide readable authorized data-subject values without weakening encryption at rest.

### Release truth and packaging
- Promoted substantive corrections from immutable historical 2.0.2 to **2.0.3**.
- Aligned plugin header/runtime, WordPress readme, repository status, coding assessment, package script, workflow artifact identity and release tests to 2.0.3.
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
