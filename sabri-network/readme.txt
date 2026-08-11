=== Sabri Network and Messages ===
Contributors: sabrihomeopathy
Tags: network, messages, private chat, smail, private file transfer, calls, communities
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 2.1.0
License: Proprietary project software

Canonical File-17 communication system for the Sabri Social Homeopathy Platform.

== Description ==
Provides consent-based relationships, unknown-sender message requests, communities/groups/channels, direct/group/channel conversations, private messages/attachments, scheduled messages, polls, collaborative checklists, disappearing-message lifecycle, indexed private search and receipts, internal Smail, verified-user encrypted resumable file transfer up to 1 GiB/file, multi-device presence, Sabri Meet/direct-call signaling, encrypted temporary updates, voice-note workflow, governed translation adapters, community rules/onboarding/forum/AMA/wiki/events, blocks/reports/appeals, privacy export/erasure, reliable outbox delivery and versioned integration contracts.

Identity/authentication/verification remain File 00/File 02 authority. File 19 is the single notification-center/delivery owner. File 20 owns global shell/navigation. File 26 owns global federated search/ranking; File 17 exports only public/explicitly-consented people/space projections and never exports private messages or contacts into global search. Clinical truth remains with its clinical owners.

Canonical private message bodies use authenticated server-side encryption at rest (`SNE1`), not an E2EE claim. The underlying File-17 communication crypto has versioned key identifiers, bounded previous-key support and lazy authorized re-encryption for message bodies, private transfer chunks and Smail drafts. The 2.1.0 completion layer also encrypts queued scheduled-message payloads, pending message-request bodies, community artifact/response bodies and new temporary-update text at rest.

Smail supplies Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over the same canonical conversations/messages backend. Sends pass through `SN_Message_Integrity`; multi-recipient conversation reservation is retry-safe. Mailbox and privacy-export projections decrypt only authorized canonical values; draft payload fingerprints use a keyed HMAC blind hash.

Private transfer requires a current File-00 verification assertion for sender and recipients, uses the exact 1,073,741,824-byte limit, resumable encrypted chunks, SHA-256, server MIME/magic and archive controls, fail-closed malware quarantine, signed expiring grants, byte ranges, revocation, retention and audit. Storage is outside the public WordPress tree, validated by realpath containment, and scanner plaintext leases are destroyed synchronously.

== Current two-plan completion basis ==

2.1.0 is the repository completion candidate for the newly governing consolidated central plan plus the current File 17 Final Harmonized Master Plan. It closes the remaining repository-owned communication gaps without creating parallel identity, chat, calls, notification, global-search or clinical backends. The current full quality gate contains 46 explicit PHP review suites, including a dedicated two-plan completion contract suite. Exact-head CI must be green before this candidate is merged.

The earlier 2.0.3 forty-round ledger remains historical evidence: 40 sequential review rounds, defects corrected in 18 rounds and no new defect in 22 rounds. See `FORTY-ROUND-AUDIT-2026-08-07.md`.

== CF-01 communication-context contract ==

File 17 includes `sn.cf01.communication-context` contract 1.0.0. It issues a revocable opaque communication-context reference only; it does not copy message bodies, attachments, call payloads, participant contact details or clinical content, and it grants no treating, chart, prescription or break-glass authority.

== Installation ==
Install and test on staging. Connect the current identity authority, private storage, approved malware scanner, File 19 notification fabric, File 20 shell, File 26 federated-search consumer where applicable, approved translation and call infrastructure, and accepted companion contracts. Complete migration, rollback, security/privacy, accessibility, real-role, browser/device, load and backup/restore acceptance before live deployment.

== Changelog ==
= 2.1.0 =
* Completed remaining repository-owned requirements from the newly governing central plan and File 17 plan.
* Added unknown-sender message requests with accept/decline/report/cancel, sender/recipient rate protection, cooldown and transactional canonical contact/direct-conversation acceptance.
* Added encrypted scheduled messages with delivery-time authorization revalidation, cancellation and bounded retry state.
* Added canonical polls and collaborative checklists without granting clinical decision authority.
* Added disappearing-message expiry with legal-hold preservation, private-search removal and private-attachment revocation/deletion.
* Added fail-closed transient translation adapter and voice-note workflow on the existing canonical message/private-file backend.
* Replaced new temporary-update plaintext persistence with authenticated encryption and lazy legacy plaintext migration.
* Added community rules/onboarding, forum questions, AMA sessions, wiki pages, events/cohorts, moderated responses/best-answer and privacy-minimized community health.
* Added privacy export/erasure participation for new personal-data domains and explicit File 00/02, 19, 26 and clinical-owner boundaries.
* Promoted deterministic candidate identity to 2.1.0 and expanded the explicit PHP review inventory to 46 suites.

= 2.0.3 =
* Completed forty sequential review/fix rounds: 18 rounds found defects and corrected them; 22 found no new defect.
* Added File 26 global search/ranking ownership and excluded private communication corpus.
* Made phone/avatar projections block-safe and transfer verification strictly File-00-authoritative.
* Added versioned rotatable communication keys and lazy re-encryption for messages, private files and Smail drafts.
* Hardened scanner plaintext cleanup/entropy errors, canonical legacy presence, device-count privacy and transfer path containment.
* Made Smail mailbox, Smail draft export and core message privacy exports user-readable without weakening storage encryption.
* Replaced ordinary draft plaintext hashes with keyed blind HMACs and strengthened behavioral QA.

= 2.0.2 =
* Enforced File 19 single notification ownership, corrected transfer chunk concurrency, added canonical message at-rest encryption, canonical Smail integrity/idempotency and audience-safe forwarding.
* Expanded the explicit automated review inventory to 41 suites with deterministic packaging.

= 2.0.1 =
* Added CF-01 communication-context contract, internal Smail and verified-user private transfer up to 1 GiB/file.

= 2.0.0 =
Major reviewed architecture, security, privacy, reliability and interface correction. See CHANGELOG.md.

This repository is a code/package/automated-QA candidate only after exact-head CI succeeds. Staging, live and operational acceptance remain separate gates.