# File 17 — Sabri Network and Messages

**Plugin version:** 2.0.2  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Repository status:** four-plan/four-round code-complete candidate; staging/live/operational acceptance remains separate

File 17 is the Sabri Social Homeopathy Platform's single canonical communication/realtime owner. It owns relationships, contacts/follows, communities/groups/channels, conversations, messages, private attachments, internal Smail, verified-user file transfer, presence/typing, calls/signaling/Sabri Meet, blocks/reports, native privacy lifecycle, receipts, private search and communication-event evidence. Network and Messages are distinct experiences over the same backend.

## Four governing plans reviewed for 2.0.2

1. Definitive Integrated Master Plan v3.0.
2. Consolidated All-Chats Recovered Directives.
3. Continuous Value / Top-20 Superset Master Plan.
4. File 17 — Final Harmonized Master Plan.

The Top-20 plan itself distinguishes `NOW`, `NEXT` and `SCALE`. Runtime 2.0.2 treats the repository-owned current-wave obligations as release requirements and keeps provider-/maturity-dependent later-wave capabilities behind their declared gates rather than falsely advertising them as live.

## Canonical boundaries

- File 00/File 02: identity/authentication/verified-account authority.
- File 09: doctor professional verification where relevant.
- File 19: **single** notification center, preferences and delivery fabric. File 17 emits metadata-only notification facts and has no second active notification center/bell.
- File 20: one global shell/navigation.
- File 24: assurance evidence consumer; File 17 still enforces its native controls.
- File 25: visual/public action presentation; File 17 authorizes relationship/message mutations.
- File 08/CF-01: appointment/clinical truth; File 17 retains only governed communication-context references.
- CF-04: optional approved secure media/malware-processing adapter after its own activation.

## Core implemented scope

- Relationships, contact requests, follows, blocks and policy-aware discovery projections.
- Communities, groups, channels, roles, invitations, bans, lifecycle and anti-abuse governance.
- Direct/group/channel conversations, replies, reactions, mentions, pins/stars/folders, bounded edit/delete, private media and authorized forwarding.
- Dedicated `/messages/`, `/messages/{id}/`, `/network/`, `/smail/`, `/file-transfer/`, `/calls/` and communication-settings surfaces without a second global shell.
- Privacy-minimized presence, typing and per-device delivered/read receipt reconciliation.
- Sabri Meet/control-plane meetings with waiting room, host/co-host governance, participant/device ceilings, signaling, conversation-backed chat, raised hands and provider-gated media.
- Reports, appeals, holds, bounded retention, privacy export/erasure, rate limits and audit evidence.
- Transactional outbox/inbox with idempotency, bounded retry, stale-lock recovery and dead-letter visibility.

## Internal Smail

Smail is a Gmail-like **internal** communication center, not Internet email or SMTP hosting. It provides Inbox, Sent, Drafts, Starred, Archive, Spam and Trash over canonical File-17 message references. Drafts are encrypted/versioned. Sends use `SN_Message_Integrity`; multi-recipient retry keys reserve the same canonical conversation so interrupted retries do not create duplicate groups.

## Canonical message confidentiality and search

Runtime 2.0.2 stores new/edited canonical message bodies in authenticated server-side `SNE1` encryption envelopes via `SN_Communication_Crypto`. It makes no audited E2EE claim. Legacy plaintext rows are migrated in bounded batches. Search decrypts authorized content only transiently in memory and stores only server-secret HMAC token hashes; queries/message plaintext are not persisted in the search index.

Search is bounded: 160 query characters, 8 query terms, 128 indexed terms/message, 500-row scan budget, 50-result ceiling and 25 context messages on either side. Signed cursors bind viewer, conversation, filters and snapshot.

## Verified private transfer — hard limit 1 GiB/file

- exact maximum: 1,073,741,824 bytes;
- resumable 1–16 MiB chunks, 8 MiB default, SHA-256 per chunk and recomputed whole-file SHA-256;
- independent encrypted storage path for every concurrent upload attempt, so a losing same-index retry cannot delete winner bytes;
- authenticated encryption outside the public WordPress tree; no WordPress Media Library insertion and no permanent public URL;
- server MIME/magic/archive checks, zip traversal/bomb bounds and fail-closed malware quarantine;
- fresh verified sender/recipient, block/suspension/relationship/policy revalidation;
- recipient/version-bound signed grants, ten-minute validity, byte-range resume and revocation invalidation;
- retention, cleanup, privacy export/erasure and audit.

## File 19 notification rule

Historical File-17 notification schema/routes remain only for non-destructive compatibility/rollback. New File-17 notification calls are intercepted after approved adapters, emit only metadata-safe File-19 integration facts when needed, and never create a second local center. The historic Network bell is suppressed; File 20/File 19 own the single global notification experience.

## Quality and packaging

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The 2.0.2 gate explicitly enumerates **41** independent PHP review suites, six JavaScript syntax checks, all PHP syntax checks, CSS/accessibility baselines, repository hygiene, installable-source manifest verification and deterministic byte-for-byte packaging.

## Completion truth

**Specified:** complete for the reviewed repository-owned File-17/current-wave scope.  
**Coded:** 2.0.2 corrective candidate.  
**Packaged / Automated-QA Green:** only after the exact branch/head workflow succeeds.  
**Staging-Accepted:** pending real Hostinger/WordPress/MySQL/roles/companions/providers/browser/security/rollback evidence.  
**Live-Deployed:** not claimed.  
**Operational:** not claimed.

No repository document may equate a green CI or ZIP with production completion.
