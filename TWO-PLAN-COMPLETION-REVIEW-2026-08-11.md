# File 17 — Current Two-Plan Coding Completion Review

**Date:** 11 August 2026  
**Scope:** current consolidated central governing plan + current File 17 Final Harmonized Master Plan  
**Candidate runtime:** 2.1.0  
**Truth boundary:** repository coding/package/automated-QA candidate only; staging/live/operational acceptance remain separate.

## Reconciliation outcome

The historical 2.0.3 forty-round candidate was not treated as proof of current-plan completeness. The current plans were re-read as governing requirements and the repository was extended without creating a second identity, chat, calls, notification, global-search, shell or clinical backend.

## Current completion delta

- Unknown-sender message-request quarantine with accept/decline/report/cancel, sender/recipient rate protection, cooldown and transactional acceptance into the canonical contact/direct-message backend.
- Authenticated-encrypted pending request bodies.
- Scheduled messages with encrypted pending content, delivery-time authorization revalidation, cancellation and processing/recovery semantics.
- Canonical polls and collaborative checklists, explicitly non-clinical-authority.
- Disappearing-message expiry with legal/safety hold precedence, private-search cleanup and private-attachment revocation/deletion.
- Fail-closed transient translation adapter with abuse rate protection.
- Voice-note workflow reusing canonical message/private-file controls.
- Authenticated-encrypted new temporary-update bodies; public-post substitution blocked; lazy legacy plaintext migration retained.
- Community rules/onboarding, forum questions, expert AMA, wiki pages, events/cohorts, responses, moderation/best-answer and privacy-minimized aggregate health.
- Safe presentation/read contracts for starred messages, structured poll/checklist/voice-note state and authorized community responses.
- Network/Messages UI integration for message requests, Direct/Groups/Channels/Starred filters, schedule/poll/checklist/voice-note tools, translation, forwarding, reactions, stars, expiry and community collaboration.
- Cross-cutting caller-supplied idempotency firewall for new state-changing routes; successful replay payloads encrypted at rest; uncertain in-flight requests fail closed.
- Upload request fingerprinting includes SHA-256 of uploaded bytes before idempotency reservation.
- Discoverable-private community listings withhold protected bodies from non-members.
- File 00/02 identity, File 19 notification, File 20 shell, File 26 global search/ranking, File 08/CF-01 clinical ownership boundaries remain explicit.

## Fresh adversarial corrections during this completion

1. Forwarding regression temporarily lost target-context re-encryption during a compatibility edit; restored `SN_Message_Body::encrypt()` before canonical insert.
2. Current release tests contained historical hard-coded 2.0.3 identity/status wording; retargeted to 2.1.0 while preserving 2.0.3 as historical evidence.
3. New mutation handlers initially depended too heavily on per-handler client IDs; added one cross-cutting caller-supplied idempotency reservation/replay firewall.
4. Multipart idempotency initially fingerprinted only metadata; added SHA-256 of uploaded temporary bytes before request hashing.
5. Discoverable-private community artifact reads initially returned bodies to non-members; added metadata-only response minimization.
6. Scheduled processing had an uncertain-worker lease risk; added stale processing recovery and idempotent cancellation behavior.
7. New temporary updates could still request `public` visibility; blocked this because temporary updates are not File 21 public publishing.
8. Translation could be invoked without a dedicated abuse budget; added per-user translation rate limiting.
9. New backend capabilities lacked complete user-facing access paths; added accessible Network/Messages presentation contracts and UI controls.
10. Voice-record cancel and object-URL lifecycle had client-side race/leak risk; hardened recorder cancellation, stream cleanup and object URL revocation.

## Release gates

The branch must not be called Staging-Accepted, Live-Deployed, Operational, production-ready or audited-E2EE. Those gates require real WordPress/MySQL, companions, providers, browsers/devices, accessibility, load/security, backup/restore, rollback, Founder staging acceptance and deployment evidence.