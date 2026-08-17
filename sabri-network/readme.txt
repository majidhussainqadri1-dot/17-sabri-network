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

Canonical private message bodies use authenticated server-side encryption at rest (`SNE1`), not an E2EE claim. New durable File-17 ciphertext uses a File-17-specific master secret independent from WordPress authentication salts. Production/staging should inject a shared approved secret through `SN_COMMUNICATION_MASTER_SECRET` or the approved secret adapter. Without an injected secret, File 17 creates `communication-master.key` inside its private storage outside the public web root. Legacy `wp_salt('secure_auth')` remains decrypt-only compatibility material for old ciphertext and lazy rotation, not the authority for new durable writes. Backup/restore and multi-node deployments must preserve/provide the same File-17 key and prove restore/decrypt before staging acceptance.

Smail supplies Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over the same canonical conversations/messages backend. Sends pass through the current canonical runtime hardening path; multi-recipient conversation reservation is retry-safe. Mailbox and privacy-export projections decrypt only authorized canonical values; draft payload fingerprints use a keyed HMAC blind hash.

Private transfer requires a current File-00 verification assertion for sender and recipients, uses the exact 1,073,741,824-byte limit, resumable encrypted chunks, SHA-256, server MIME/magic and archive controls, fail-closed malware quarantine, signed expiring grants, byte ranges, revocation, retention and audit. Storage is outside the public WordPress tree, validated by realpath containment, and scanner plaintext leases are destroyed synchronously.

== Current governing-plan completion basis ==

2.1.0 is the repository corrective candidate for the governing consolidated central plan, current File 17 Final Harmonized Master Plan and Founder-approved Future Communication Superset 24. It preserves one canonical communication backend and does not create parallel identity, chat, calls, notification, global-search or clinical backends. The current full quality gate contains **47 explicit PHP review suites and 8 JavaScript syntax checks**. Exact-head CI must be green for the exact commit before automated-QA/package status is reported for that commit.

Earlier review ledgers remain historical evidence only for their own exact commits. Their round counts and green runs are not reused as proof for a later SHA.

== CF-01 communication-context contract ==

File 17 includes `sn.cf01.communication-context` contract 1.0.0. It issues a revocable opaque communication-context reference only; it does not copy message bodies, attachments, call payloads, participant contact details or clinical content, and it grants no treating, chart, prescription or break-glass authority.

== Installation ==
Install and test on staging. Connect the current identity authority, private storage, approved malware scanner, File 19 notification fabric, File 20 shell, File 26 federated-search consumer where applicable, approved translation and call infrastructure, and accepted companion contracts. Configure/restore the same File-17 durable communication master secret before migrations that need existing private ciphertext. Complete migration, key-rotation/decrypt, rollback, security/privacy, accessibility, real-role, browser/device, load and backup/restore acceptance before live deployment.

== Changelog ==
= 2.1.0 =
* Completed remaining repository-owned requirements from the governing central plan, File 17 plan and approved Future Communication Superset 24 without changing canonical ownership boundaries.
* Added unknown-sender message requests with accept/decline/report/cancel, sender/recipient rate protection, cooldown and transactional canonical contact/direct-conversation acceptance.
* Added encrypted scheduled messages with delivery-time authorization revalidation, cancellation and bounded retry state.
* Added canonical polls and collaborative checklists without granting clinical decision authority.
* Added disappearing-message expiry with legal-hold preservation, private-search removal and private-attachment revocation/deletion.
* Added fail-closed transient translation adapter and voice-note workflow on the existing canonical message/private-file backend.
* Replaced new temporary-update plaintext persistence with authenticated encryption and lazy legacy plaintext migration.
* Added community rules/onboarding, forum questions, AMA sessions, wiki pages, events/cohorts, moderated responses/best-answer and privacy-minimized community health.
* Added privacy export/erasure participation for new personal-data domains and explicit File 00/02, 19, 26 and clinical-owner boundaries.
* Decoupled new durable private-communication encryption from WordPress authentication salts while retaining legacy decrypt/rotation compatibility.
* Current explicit quality inventory is 47 PHP review suites plus 8 JavaScript syntax checks; deterministic candidate identity remains 2.1.0.

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
