# File 17 — Sabri Network and Messages

**Plugin version:** 2.1.0  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Repository status:** current repository coding/review candidate; staging/live/operational acceptance remains separate

File 17 is the Sabri Social Homeopathy Platform's single canonical communication/realtime owner: relationships, contacts/follows, unknown-sender message requests, communities/groups/channels, conversations/messages, private attachments, internal Smail, verified-user private transfer, temporary updates, presence/typing, calls/signaling/Sabri Meet, blocks/reports, native privacy lifecycle, receipts, private search and communication-event evidence. Network and Messages are distinct experiences over the same backend.

## Governing basis

The 2.1.0 candidate is reconciled to the current consolidated central governing plan, the current File 17 Final Harmonized Master Plan and the Founder-approved Future Communication Superset 24. Earlier review ledgers remain historical evidence for their own exact commits and do not replace fresh current-cycle QA.

The full explicit quality gate currently contains **47 PHP review suites** and validates **8 JavaScript entry points**.

## Canonical boundaries

- File 00/File 02: identity/authentication/current verification authority.
- File 09: doctor professional verification where relevant.
- File 19: single notification center/preferences/delivery fabric; File 17 emits metadata-only events.
- File 20: single global shell/navigation/layout owner.
- File 24: assurance evidence consumer; native File-17 controls remain native.
- File 25: visual/public action presentation.
- File 26: global Search/Discovery/Ranking owner. File 17 exposes only public/explicitly-consented people/space projections; private messages and contacts are excluded. Authorized private-message search remains File 17.
- File 08/CF-01: appointment/clinical truth; File 17 retains governed opaque communication-context references only.
- CF-04: optional approved secure binary-processing adapter after its own activation.

## Message confidentiality, durable key lifecycle and private search

Canonical message bodies are authenticated-encrypted at rest through the `SNE1` envelope. This is server-side storage encryption, not an audited E2EE claim. Search decrypts only authorized content transiently and stores keyed token hashes; File 26 never receives this private corpus. The 2.1.0 completion layer similarly encrypts pending message-request text, scheduled-message payloads, community artifact/response bodies and new temporary-update text.

New durable File-17 ciphertext is protected by a **File-17-specific master secret independent from WordPress authentication salts**. In staging/production, the preferred configuration is an approved shared secret-manager value exposed as `SN_COMMUNICATION_MASTER_SECRET` or through the approved `sn_network_communication_secret` adapter. If no approved external secret is injected, File 17 atomically creates `communication-master.key` in its private storage outside the public web root with restrictive permissions. Legacy `wp_salt('secure_auth')` is retained only as decrypt compatibility material so older ciphertext can be lazily rotated; it is not the authority for new durable writes.

The File-17 master secret is part of disaster-recovery state. Backup/restore, multi-node deployments and key rotation must preserve/provide the same secret and prove old-ciphertext decryptability before staging acceptance. Loss of every key capable of decrypting existing data can make durable private ciphertext unreadable.

## Calls and external media-provider limitation

Sabri Meet and direct call state/signaling are File-17 canonical capabilities, but real media transport remains **provider-gated**. STUN/TURN/SFU use approved external media-provider adapters with short-lived scoped credentials and health/capability checks. Media credential issuance forces a fresh File-00 calling-eligibility assertion both immediately before provider issuance and again before credentials are returned. When an approved provider is unavailable or not accepted in staging, the affected media feature must be unavailable/degraded rather than simulated.

## Internal Smail

Smail is an internal communication center, not Internet email/SMTP. Inbox, Sent, Drafts, Starred, Archive, Spam and Trash reuse the canonical File-17 message backend. Sends use the current `SN_Message_Runtime_Hardening` canonical message path; multi-recipient retries reuse an idempotent canonical conversation reservation.

## Verified private transfer

Exact maximum is **1,073,741,824 bytes** per file. Transfer is resumable, SHA-256 checked, authenticated-encrypted outside the public WordPress tree, MIME/magic/archive validated, fail-closed to scanner quarantine, and delivered only through recipient/version-bound expiring grants with revocation/retention/audit controls.

## Historical review records

Earlier File-17 review ledgers remain in repository history and in the dedicated audit Markdown files. Their round counts, exact SHAs and workflow runs apply only to the reviewed states recorded by those ledgers. A later green SHA must never be inferred from an older workflow run.

## Quality and packaging

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The 2.1.0 gate includes **47 PHP review suites**, PHP 8.1/8.3 syntax, **eight JavaScript syntax checks**, shell syntax, CSS/accessibility baselines, repository hygiene, exact staged-source manifest verification and deterministic byte-for-byte packaging. The package script independently validates all eight JavaScript entry points and the governed runtime-hardening surfaces before producing the ZIP.

## Completion truth

**Specified:** complete for current governing-plan File-17 repository scope.  
**Coded:** 2.1.0 repository corrective candidate.  
**Packaged / Automated-QA Green:** only after exact-head workflow success for the exact commit being reported.  
**Staging-Accepted:** pending real environment and integration acceptance.  
**Live-Deployed:** not claimed.  
**Operational:** not claimed.

A green CI or ZIP is not production completion.
