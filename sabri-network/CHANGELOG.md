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
- Extended privacy export/erasure and added 70 new static/adversarial realtime-state checks.

### Status

Code-reviewed candidate only. Two independent local review suites currently report zero known failures in 119 static/adversarial contract checks. This is evidence for the included checks, not a claim of absolute defect-freedom. Staging migration, real-role acceptance, penetration/load testing, rollback rehearsal, and live deployment remain separate gates.
