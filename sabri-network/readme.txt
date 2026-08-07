=== Sabri Network and Messages ===
Contributors: sabrihomeopathy
Tags: network, messages, private chat, smail, private file transfer, calls, communities
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 2.0.2
License: Proprietary project software

Canonical File-17 communication system for the Sabri Social Homeopathy Platform.

== Description ==
Provides accepted-contact relationships, communities, groups, channels, direct and group conversations, private messages and attachments, message search and receipts, internal Smail, verified-user encrypted resumable file transfer up to 1 GiB per file, presence, Sabri Meet, direct-call signaling, blocks, reports, appeals, privacy export/erasure, reliable outbox delivery and versioned integration contracts.

Smail supplies Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over the same canonical File-17 conversations/messages backend. It does not create a parallel email, chat or SMTP backend. Smail now uses the same atomic message-integrity/encryption/search/outbox path as Messages, and its multi-recipient conversation reservation is retry-safe.

Canonical private message bodies use authenticated server-side encryption at rest (`SNE1`) and are decrypted only for authorized runtime use. This is storage encryption, not an end-to-end-encryption claim. Legacy plaintext message rows are migrated in bounded retry-safe batches and their authorized hashed-token search index is rebuilt from transient in-memory plaintext.

Private file transfer requires current verified sender and recipients, uses bounded resumable chunks with SHA-256, authenticated encryption outside the public WordPress tree, server-side MIME/magic and archive checks, fail-closed malware quarantine, recipient/version-bound expiring grants, byte-range resume, revocation, retention and audit. Files never enter the public WordPress Media Library. Concurrent same-index retries use independent encrypted attempt paths so a losing request cannot delete a winner's committed bytes.

Identity/authentication remain owned by File 00/File 02. File 19 is the single notification-center/delivery owner; File 17 emits metadata-only notification facts and does not maintain a second active notification center or bell. Global shell remains owned by File 20. Public profiles remain owned by File 25. Clinical records, treating relationships, consent and prescriptions remain owned by CF-01 after its separate activation. CF-04 may provide the approved binary/malware-processing adapter after activation.

== Four-plan review basis ==

2.0.2 was reviewed in four independent rounds against: Definitive Master Plan v3.0; Consolidated All-Chats Recovered Directives; Continuous Value / Top-20 Superset Master Plan; and the File 17 Final Harmonized Master Plan. The repository-owned current-wave requirements are represented by explicit review suites. NEXT/SCALE capabilities in the Top-20 plan remain governed future-wave gates unless separately promoted by Founder change-control; they are not falsely described as live features.

== CF-01 communication-context contract ==

File 17 2.0.2 includes `sn.cf01.communication-context` contract 1.0.0.

The contract issues a revocable File 17-owned opaque reference to current communication context only. It does not copy message bodies, message-search results, attachments, call payloads, transcripts, participant contact details or clinical content. It does not write a chart, create a treating relationship, prove patient/guardian consent by itself, grant chart access, authorize a prescription or grant break-glass authority.

Every assertion and destination resolution rechecks active membership, conversation state, block state, exact purpose and external read authorization. A destination URL is same-origin HTTPS navigation only and is never bearer authorization. CF-01 must separately revalidate current File 00/02 identity and authentication, File 09 professional eligibility, treating relationship, consent/guardian, purpose, object, field and record version before any clinical action.

== Installation ==
Install and test on staging. Connect the identity authority, private storage, approved malware scanner, File 19 notification fabric, call infrastructure and accepted companion contracts. Complete migration, rollback, security, privacy, accessibility, real-role, browser/device, load, reference-revocation and backup/restore acceptance before live deployment.

== Changelog ==
= 2.0.2 =
* Completed four-plan/four-round repository audit and corrective coding against the three current central plans plus File 17 plan.
* Removed File-17 notification-center ownership at runtime: File 19 is the sole notification-center/delivery owner and receives metadata-only facts; the local bell is suppressed.
* Added authenticated at-rest canonical message-body envelopes with bounded legacy migration and authorized search reindexing.
* Routed Smail through the atomic canonical message-integrity path and added retry-safe multi-recipient conversation reservation.
* Fixed the concurrent resumable-transfer chunk race by assigning every upload attempt an independent encrypted storage path before the database uniqueness winner is chosen.
* Hardened message forwarding so authorized source content is decrypted only in memory, re-encrypted for the target audience and does not disclose the source identifier.
* Expanded the explicit automated review inventory from 37 to 41 suites and updated deterministic 2.0.2 packaging gates.

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
