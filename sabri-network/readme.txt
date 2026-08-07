=== Sabri Network and Messages ===
Contributors: sabrihomeopathy
Tags: network, messages, private chat, smail, private file transfer, calls, communities
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 2.0.3
License: Proprietary project software

Canonical File-17 communication system for the Sabri Social Homeopathy Platform.

== Description ==
Provides consent-based relationships, communities/groups/channels, direct/group/channel conversations, private messages/attachments, indexed private search and receipts, internal Smail, verified-user encrypted resumable file transfer up to 1 GiB/file, multi-device presence, Sabri Meet/direct-call signaling, blocks/reports/appeals, privacy export/erasure, reliable outbox delivery and versioned integration contracts.

Identity/authentication/verification remain File 00/File 02 authority. File 19 is the single notification-center/delivery owner. File 20 owns global shell/navigation. File 26 owns global federated search/ranking; File 17 exports only public/explicitly-consented people/space projections and never exports private messages or contacts into global search. Clinical truth remains with its clinical owners.

Canonical private message bodies use authenticated server-side encryption at rest (`SNE1`), not an E2EE claim. The underlying File-17 communication crypto now has versioned key identifiers, bounded previous-key support and lazy authorized re-encryption for message bodies, private transfer chunks and Smail drafts.

Smail supplies Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over the same canonical conversations/messages backend. Sends pass through `SN_Message_Integrity`; multi-recipient conversation reservation is retry-safe. Mailbox and privacy-export projections decrypt only authorized canonical values; draft payload fingerprints use a keyed HMAC blind hash.

Private transfer requires a current File-00 verification assertion for sender and recipients, uses the exact 1,073,741,824-byte limit, resumable encrypted chunks, SHA-256, server MIME/magic and archive controls, fail-closed malware quarantine, signed expiring grants, byte ranges, revocation, retention and audit. Storage is outside the public WordPress tree, validated by realpath containment, and scanner plaintext leases are destroyed synchronously.

== Forty-round review basis ==

2.0.3 was subjected to forty sequential review rounds against the three current central plans plus the File 17 Final Harmonized Master Plan. Defects were found and corrected in 18 rounds; 22 rounds found no new defect. Four dedicated forty-round suites supplement the prior review inventory, for 45 explicit PHP review suites in the full gate.

See `FORTY-ROUND-AUDIT-2026-08-07.md` for the exact ledger.

== CF-01 communication-context contract ==

File 17 includes `sn.cf01.communication-context` contract 1.0.0. It issues a revocable opaque communication-context reference only; it does not copy message bodies, attachments, call payloads, participant contact details or clinical content, and it grants no treating, chart, prescription or break-glass authority.

== Installation ==
Install and test on staging. Connect the current identity authority, private storage, approved malware scanner, File 19 notification fabric, File 20 shell, File 26 federated-search consumer where applicable, call infrastructure and accepted companion contracts. Complete migration, rollback, security/privacy, accessibility, real-role, browser/device, load and backup/restore acceptance before live deployment.

== Changelog ==
= 2.0.3 =
* Completed forty sequential review/fix rounds: 18 rounds found defects and corrected them; 22 found no new defect.
* Added File 26 global search/ranking ownership and excluded private communication corpus.
* Made phone/avatar projections block-safe and transfer verification strictly File-00-authoritative.
* Added versioned rotatable communication keys and lazy re-encryption for messages, private files and Smail drafts.
* Hardened scanner plaintext cleanup/entropy errors, canonical legacy presence, device-count privacy and transfer path containment.
* Made Smail mailbox, Smail draft export and core message privacy exports user-readable without weakening storage encryption.
* Replaced ordinary draft plaintext hashes with keyed blind HMACs and strengthened behavioral QA.
* Promoted immutable corrective runtime/package identity to 2.0.3 and expanded the explicit full gate to 45 PHP review suites.

= 2.0.2 =
* Enforced File 19 single notification ownership, corrected transfer chunk concurrency, added canonical message at-rest encryption, canonical Smail integrity/idempotency and audience-safe forwarding.
* Expanded the explicit automated review inventory to 41 suites with deterministic packaging.

= 2.0.1 =
* Added CF-01 communication-context contract, internal Smail and verified-user private transfer up to 1 GiB/file.

= 2.0.0 =
Major reviewed architecture, security, privacy, reliability and interface correction. See CHANGELOG.md.

This repository is a code/package/automated-QA candidate only after exact-head CI succeeds. Staging, live and operational acceptance remain separate gates.
