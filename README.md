# File 17 — Sabri Network and Messages 2.0.1

This branch is the consolidated File-17 code-complete candidate for the Sabri Social Homeopathy Platform. It includes the prior 2.0.1 CF-01 communication-context work and the later recovered directives for internal Smail and verified-user private file transfer.

## Canonical scope

File 17 owns consent-based Network relationships, communities/groups/channels, conversations, private messages and attachments, **internal Smail**, **verified-user private file transfer up to 1 GiB per file**, temporary updates, presence, direct-call state/signaling, Sabri Meet, blocks, reports, appeals, native retention enforcement, privacy operations, and audit/event records.

It does not duplicate File 00/02 identity and verification, File 19 notification delivery, File 20 global shell/navigation, File 24 assurance governance, File 25 public-profile presentation, CF-01 clinical records, or CF-04 binary-processing ownership.

## Added recovered-directive scope

- Smail Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over the canonical File-17 conversation/message backend.
- Authenticated-encrypted, versioned Smail drafts; idempotent sends; user-scoped mailbox state; metadata-only outbox/notification facts.
- Verified sender and recipient enforcement for private transfers.
- Exactly 1 GiB maximum per file; bounded resumable chunks; per-chunk and full-file SHA-256.
- Authenticated-encrypted chunks outside the public WordPress tree.
- MIME/magic allowlisting, archive traversal/entry/expansion safeguards, mandatory fail-closed malware-scanner adapter and quarantine.
- Recipient/version-bound ten-minute download grants, byte-range resume, revocation, expiry, privacy export/erasure and audit.
- Green primary visual identity with orange retained only as a secondary accent.

## Truthful status

- Specified: complete for the current File-17 and recovered-directive scope.
- Coded: code-complete candidate.
- Packaged: deterministic build is part of the quality gate.
- Automated QA: established only by the exact-head GitHub Actions run.
- Staging accepted: pending.
- Live deployed: pending Founder approval.
- Operational: pending production monitoring, support and SLO evidence.

See `CODING-COMPLETENESS.md`, `SMAIL-AND-TRANSFER-COMPLETION-REPORT.md`, `STATUS.md`, and `sabri-network/SYSTEM-STATUS.txt`.
