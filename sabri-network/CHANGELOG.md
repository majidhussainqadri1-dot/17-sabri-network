# Changelog

## 2.0.0 — 2026-08-01

### Architecture

- Unified Network relationships and Messages under one File-17 canonical backend.
- Removed duplicate identity/OTP ownership and global-navigation injection.
- Added File 00/File 02, File 19, File 20, File 24, and File 25 integration boundaries.

### Security and privacy

- Added centralized policy enforcement, contact consent, minor/guardian restrictions, block checks, rate limits, audit events, privacy export/erasure, private attachments, and safe delivery.
- Retired legacy SMS/TURN secrets and removed the legacy OTP table.
- Withheld legacy public-media attachments pending controlled migration.
- Added call-state, signaling, signal-acknowledgement, and active-call race controls.

### Reliability

- Added unique direct-conversation, contact-pair, idempotency, and active-call keys.
- Added bounded cleanup jobs, upgrade-time cron repair, database-first expiry cleanup, shared-attachment reference protection, and safe page ownership/repair behavior.
- Added a transactionally locked conversation-ownership transfer path so group/community/channel owners can transfer authority before leaving.
- Added static contract tests, an independent adversarial review suite, CI quality checks, reproducible packaging, and checksums.

### Interface

- Removed OTP forms.
- Added centralized sign-in, responsive layout, keyboard focus, modal focus containment, visible toasts, safe timestamp formatting, ownership-transfer controls, RTL support, reduced motion, and low-width behavior.

### Continued coding and hardening batch

- Added privacy-scoped online/away/last-seen presence with bounded heartbeats, expiry, data minimization, cleanup, export and erasure.
- Added ephemeral typing indicators with active-membership, block, rate-limit, channel-authority and expiry enforcement.
- Activated native per-conversation mute and archive preferences, including archived-conversation recovery and mute-aware fallback notifications.
- Enforced owner/moderator-only channel posting by default and prohibited calls in broadcast channels.
- Revoked active call membership and pending signals transactionally when a member leaves or is removed.
- Required current conversation membership for call history visibility, call-state mutation, signaling reads/writes and acknowledgements.
- Corrected polling so list refreshes no longer discard detailed active-conversation membership and authority state.
- Extended privacy export/erasure and added static, runtime, realtime, package, safety, relationship and adversarial checks.

### Safety and retention hardening batch

- Added UUIDv4 idempotency and database uniqueness for abuse reports.
- Added canonical report target keys, same-target throttling, evidence hashes and bounded category-based retention.
- Added legal/safety holds, two-stage minimization/deletion and an administrator report/appeal workflow.
- Added administrator-only report inventory and optimistic-concurrency triage contracts.
- Added hold-aware privacy export/erasure behavior and operational report diagnostics.
- Replaced stale-lock delete-then-add takeover with an exact-value compare-and-swap update.
- Replaced lock release with exact-value compare-and-delete so an expired former worker cannot delete a replacement owner lock.
- Added runtime race simulations for initial acquisition, stale takeover, competing takeover, release races, active locks and malformed-lock recovery.
- Added complete installable-source checksum coverage and exact-hash verification to the quality gate.

### Third forensic review and concurrency correction

- Corrected the File-25 integration contract so contact creation, contact decisions, blocking, relationship state, follows and conversations advertise the actual REST paths and mutation methods.
- Replaced rate-limit read-then-`REPLACE` accounting with atomic `INSERT IGNORE` initialization and a conditional SQL update, preventing concurrent first hits or expiry rollovers from resetting counters.
- Made conversation `last_message_id` and `updated_at` monotonic under concurrent message sends.
- Made an existing empty retention-lock option recoverable as malformed state without weakening exact-value takeover/release controls.
- Routed public-update attachment minor decisions through canonical `SN_Policy::is_minor` handling.
- Added 33 fresh static, runtime and adversarial checks for these defects.

### Sabri Meet coding and four-round corrective review

- Added File-17 owned `/calls/` and `/calls/{meeting_id}/` Sabri Meet surfaces without introducing a parallel communication plugin or global navigation.
- Added opaque meeting links, idempotent creation, scheduling, invitation/conversation access, lobby admission, host/co-host governance, lock/end/remove/mute controls, bounded participants and per-device sessions.
- Added race-safe join/admission state, session expiry, recipient-scoped expiring signaling, privacy export/erasure, no-cache headers and a provider-gated media adapter.
- Added accessible prejoin, participant, host and media-control UI with truthful provider-unavailable states, reduced-motion and responsive behavior.
- Added host invitation UI, canonical conversation-backed meeting chat, raise/lower-hand state, active media indicators, idempotent leave handling and truthful partial-invitation failure reporting.
- Hardened Meet health-table discovery, privacy-erasure rollback, cleanup observability, signal acknowledgements and leave-time row locking/CAS.
- Completed four separate review-and-correction suites covering authorization/state, concurrency/idempotency, privacy/minor/abuse boundaries, and UI/package truthfulness.
- Recording and E2EE claims remain disabled; SFU/TURN/provider, load, browser, staging and operational acceptance remain external gates.

### Status

Code-reviewed candidate only. The current quality gate retains the previously verified 463 contract checks and adds four Sabri Meet review/fix suites with 86 checks, for 549 included checks in total, plus PHP/JavaScript/shell syntax, CSS integrity, repository hygiene, complete installable-source checksum verification and deterministic byte-for-byte packaging. This is evidence for the included checks, not a claim of absolute defect-freedom. Staging migration, real-role acceptance, penetration/load testing, rollback rehearsal, backup/restore, cross-file integration and live deployment remain separate gates.
## Fourth forensic review — fail-closed age and completeness truth

- Unknown age now receives protective communication defaults and cannot be treated as adult through a not-minor shortcut.
- Public update and update-attachment access require an explicit verified-adult age state.
- Unknown-age directory and presence exposure fail closed.
- Conversation ownership requires verified adult age.
- Read-pointer database failure returns an error and is audited.
- Added repository-level coding-completeness evidence and 42 new static/runtime/adversarial checks.


### 2.0.0 — indexed search and reliable delivery hardening
- Added active-member-only indexed server-side message search with hashed tokens, signed snapshot pagination and signed bounded context navigation.
- Added transactional event outbox/inbox, explicit delivery acknowledgements, bounded retry, stale-lock recovery and dead-letter operations.
- Made send/edit/delete/receipt mutations atomic with search-index and event records.
- Added responsive, RTL-ready and reduced-motion message-search UI.
- Added two independent review/fix suites and completion-truth regression coverage.

## 2.0.0 — Code-complete candidate completion batch

- Added canonical communities, groups, channels and private-team governance with consent-aware joins/invitations, hierarchy, succession, lifecycle, bans and abuse controls.
- Added general keyed per-device presence, aggregate state, revocation, cleanup and privacy lifecycle.
- Added governed mentions/forwarding, pins, stars, private folders and hide-for-self projections.
- Added opaque reauthorized File 08/18/21 conversation contexts with transactional event evidence.
- Added one-time step-up grants, distinct dual control, payload-scope hashes and stale execution recovery.
- Added secret-free conference provider governance with fresh health evidence and short-lived scoped credential contracts.
- Added four independent completion review/fix suites totaling 120 checks.
- Classification: code-complete candidate only; staging/live/production-operational acceptance remains pending.
