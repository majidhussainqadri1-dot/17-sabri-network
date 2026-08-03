=== Sabri Network and Messages ===
Contributors: sabrihomeopathy
Tags: network, messages, private chat, calls, communities
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 2.0.1
License: Proprietary project software

Canonical File-17 communication system for the Sabri Social Homeopathy Platform.

== Description ==
Provides accepted-contact relationships, direct and group conversations, private messages and attachments, updates, WebRTC call signaling, blocking, reports, privacy export/erasure, and versioned integration contracts.

Identity/authentication remain owned by File 00/File 02. Global shell remains owned by File 20. Public profiles remain owned by File 25. Clinical records, treating relationships, consent and prescriptions remain owned by CF-01 after its separate activation. This plugin does not claim audited end-to-end encryption.

== CF-01 communication-context contract ==

File 17 2.0.1 adds `sn.cf01.communication-context` contract 1.0.0.

The contract issues a revocable File 17-owned opaque reference to current communication context only. It does not copy message bodies, message-search results, attachments, call payloads, transcripts, participant contact details or clinical content. It does not write a chart, create a treating relationship, prove patient/guardian consent by itself, grant chart access, authorize a prescription or grant break-glass authority.

Reference issuance requires all of the following:

* active File 17 conversation membership and no applicable direct-conversation block;
* a bounded approved purpose and idempotency key;
* external professional-issuer authorization through the canonical owner contract;
* external opaque consent-reference authorization through the canonical consent owner;
* File 17-owned retention classification rather than caller-declared legal hold.

Every assertion and destination resolution rechecks active membership, conversation state, block state, exact purpose and external read authorization. A destination URL is same-origin HTTPS navigation only and is never bearer authorization. CF-01 must separately revalidate current File 00/02 identity and authentication, File 09 professional eligibility, treating relationship, consent/guardian, purpose, object, field and record version before any clinical action.

== Installation ==
Install and test on staging. Connect the identity authority, private storage, malware scanner, notifications, approved call infrastructure and the accepted CF-01 companion contracts. Complete migration, rollback, security, privacy, accessibility, real-role and reference-revocation acceptance before live deployment.

== Changelog ==
= 2.0.1 =
* Added `sn.cf01.communication-context` 1.0.0.
* Added a minimal revocable opaque-reference registry with expiry, idempotency, consent hash, retention class and optimistic version.
* Added fail-closed external issuer, consent, read and revoke authorization boundaries.
* Added current conversation membership/state/block revalidation and non-bearer same-origin destination resolution.
* Added explicit no-message/no-attachment/no-call/no-clinical-write contract invariants.
* Added privacy export, erasure-time revocation, cleanup and metadata-only outbox/audit evidence.
* Added two review-and-fix contract suites and PHP 8.1/8.3 exact-head CI.

= 2.0.0 =
Major reviewed architecture, security, privacy, reliability, and interface correction. See CHANGELOG.md.

Sabri Meet: File-17 owned private meeting control plane with waiting-room admission, host invitations/moderation, raised hand, conversation-backed chat, bounded sessions and provider-gated conference media. Recording and E2EE are not claimed.

= Indexed message search and reliable events =
Conversation-local search uses hashed tokens and signed viewer-scoped cursors. Message mutations, search index changes and metadata-only outbox events commit atomically. Notification delivery remains the responsibility of the platform notification module.
