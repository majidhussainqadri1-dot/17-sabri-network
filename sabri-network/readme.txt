=== Sabri Network and Messages ===
Contributors: sabrihomeopathy
Tags: network, messages, private chat, smail, private file transfer, calls, communities
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 2.0.1
License: Proprietary project software

Canonical File-17 communication system for the Sabri Social Homeopathy Platform.

== Description ==
Provides accepted-contact relationships, communities, groups, channels, direct and group conversations, private messages and attachments, message search and receipts, internal Smail, verified-user encrypted resumable file transfer up to 1 GiB per file, presence, Sabri Meet, direct-call signaling, blocks, reports, appeals, privacy export/erasure, reliable outbox delivery and versioned integration contracts.

Smail supplies Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over the same canonical File-17 conversations/messages backend. It does not create a parallel email, chat or SMTP backend.

Private file transfer requires current verified sender and recipients, uses bounded resumable chunks with SHA-256, authenticated encryption outside the public WordPress tree, server-side MIME/magic and archive checks, fail-closed malware quarantine, recipient/version-bound expiring grants, byte-range resume, revocation, retention and audit. Files never enter the public WordPress Media Library.

Identity/authentication remain owned by File 00/File 02. Notification transport remains owned by File 19. Global shell remains owned by File 20. Public profiles remain owned by File 25. Clinical records, treating relationships, consent and prescriptions remain owned by CF-01 after its separate activation. CF-04 may provide the approved binary/malware-processing adapter after activation. This plugin does not claim audited end-to-end encryption.

== CF-01 communication-context contract ==

File 17 2.0.1 includes `sn.cf01.communication-context` contract 1.0.0.

The contract issues a revocable File 17-owned opaque reference to current communication context only. It does not copy message bodies, message-search results, attachments, call payloads, transcripts, participant contact details or clinical content. It does not write a chart, create a treating relationship, prove patient/guardian consent by itself, grant chart access, authorize a prescription or grant break-glass authority.

Every assertion and destination resolution rechecks active membership, conversation state, block state, exact purpose and external read authorization. A destination URL is same-origin HTTPS navigation only and is never bearer authorization. CF-01 must separately revalidate current File 00/02 identity and authentication, File 09 professional eligibility, treating relationship, consent/guardian, purpose, object, field and record version before any clinical action.

== Installation ==
Install and test on staging. Connect the identity authority, private storage, approved malware scanner, notifications, call infrastructure and accepted companion contracts. Complete migration, rollback, security, privacy, accessibility, real-role, browser/device, load, reference-revocation and backup/restore acceptance before live deployment.

== Changelog ==
= 2.0.1 =
* Added `sn.cf01.communication-context` 1.0.0 with revocable opaque references and fail-closed owner authorization.
* Added internal Smail with seven mailboxes, encrypted/versioned drafts, idempotent canonical sends and user-scoped mailbox states.
* Added verified-user private transfer up to 1 GiB/file with resumable encrypted chunks, SHA-256, MIME/magic/archive controls, mandatory clean scanner result, quarantine, signed ten-minute grants, range resume, revocation, retention, privacy and audit.
* Added green primary File-17 visual identity while retaining orange only as a secondary accent.
* Added fresh-install schema/page/storage completion for every File-17 domain.
* Added 37 explicitly enumerated review suites, PHP 8.1/8.3 exact-head CI and deterministic package/manifest/SHA-256 evidence.

= 2.0.0 =
Major reviewed architecture, security, privacy, reliability and interface correction. See CHANGELOG.md.

Sabri Meet is a File-17-owned private meeting control plane with waiting-room admission, host invitations/moderation, raised hand, conversation-backed chat, bounded sessions and provider-gated conference media. Recording and E2EE are not claimed.

Conversation-local search uses hashed tokens and signed viewer-scoped cursors. Message mutations, search index changes and metadata-only outbox events commit atomically. Notification delivery remains the responsibility of File 19.
