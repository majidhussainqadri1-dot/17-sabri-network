# File 17 — Sabri Network and Messages 2.0.2

This branch is the four-plan/four-round corrective File-17 candidate for the Sabri Social Homeopathy Platform. The audit basis is: Definitive Master Plan v3.0, Consolidated Recovered Directives, Continuous Value / Top-20 Superset Master Plan, and the File 17 Final Harmonized Master Plan.

## Canonical scope

File 17 owns consent-based Network relationships, communities/groups/channels, conversations, private messages and attachments, **internal Smail**, **verified-user private file transfer up to 1 GiB per file**, temporary updates, presence, direct-call state/signaling, Sabri Meet, blocks, reports, appeals, native retention enforcement, privacy operations, and communication audit/event records.

It does not duplicate File 00/02 identity and verification, File 19 notification center/delivery, File 20 global shell/navigation, File 24 assurance governance, File 25 public-profile presentation, CF-01 clinical records, or CF-04 secure binary-processing ownership.

## 2.0.2 corrective scope

- File 19 is now enforced as the single notification-center/delivery owner: File 17 emits metadata-only facts, does not create new local notification rows, exposes compatibility-only historical notification routes and suppresses its old local bell.
- Canonical message bodies are authenticated-encrypted at rest with the `SNE1` envelope. Legacy plaintext migrates in bounded batches; authorized search indexes transient plaintext into server-secret HMAC hashes only.
- Smail Inbox, Sent, Drafts, Starred, Archive, Spam and Trash use the same canonical message backend; sends now pass through `SN_Message_Integrity` and multi-recipient retries reuse the same idempotent conversation reservation.
- Verified transfers retain the exact 1 GiB/file ceiling, resumable encrypted chunks, checksum/MIME/archive/scanner/quarantine/grant/revocation/privacy controls; a newly corrected concurrent same-index race prevents a losing retry from deleting the winner's bytes.
- Forwarding now decrypts authorized source content only in memory, re-encrypts for the target audience and avoids cross-audience source-identifier disclosure.
- Green remains the primary File-17 visual identity; orange is a secondary accent only.

## Four review rounds

1. **Governance/ownership:** notification duplication and stale ownership documentation found and corrected.
2. **Transfer concurrency:** destructive same-index retry race found and corrected.
3. **Message/Smail privacy-integrity:** plaintext canonical message bodies, Smail integrity bypass, retry duplication exposure and forward-path encryption gap found and corrected.
4. **Fresh release/packaging truth:** version, documentation, explicit QA inventory and deterministic package contract reconciled to 2.0.2.

The explicit repository quality inventory is **41 PHP review suites**, plus PHP/JavaScript/shell syntax, CSS/accessibility integrity, repository hygiene, complete installable-source manifest verification and deterministic byte-for-byte packaging.

## Top-20 status law

The Top-20 plan itself distinguishes `NOW`, `NEXT` and `SCALE`. Version 2.0.2 treats current-wave File-17 requirements as release requirements and keeps later provider-/maturity-dependent capabilities behind their declared gates; it does not label future-wave media/provider functionality as already live.

## Truthful status

- **Specified:** complete for the reviewed repository-owned/current-wave File-17 scope.
- **Coded:** 2.0.2 code-complete candidate after correction.
- **Packaged / Automated-QA Green:** established only by the exact-head workflow and deterministic artifact from this branch/head.
- **Staging-Accepted:** pending real Hostinger/WordPress/MySQL/roles/companions/providers/security/rollback evidence.
- **Live-Deployed:** not claimed.
- **Operational:** not claimed.

See `FOUR-PLAN-FOUR-ROUND-AUDIT-2026-08-07.md`, `CODING-COMPLETENESS.md`, `STATUS.md`, and `sabri-network/SYSTEM-STATUS.txt`.
