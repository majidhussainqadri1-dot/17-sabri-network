# File 17 — Sabri Network and Messages

**Plugin version:** 2.0.3  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Repository status:** four-plan/forty-round code-complete corrective candidate; staging/live/operational acceptance remains separate

File 17 is the Sabri Social Homeopathy Platform's single canonical communication/realtime owner: relationships, contacts/follows, communities/groups/channels, conversations/messages, private attachments, internal Smail, verified-user private transfer, presence/typing, calls/signaling/Sabri Meet, blocks/reports, native privacy lifecycle, receipts, private search and communication-event evidence. Network and Messages are distinct experiences over the same backend.

## Governing basis and forty-round result

The 2.0.3 corrective cycle was reviewed against Definitive Master Plan v3.0, Consolidated Recovered Directives, Continuous Value / Top-20 Superset Master Plan, and the File 17 Final Harmonized Master Plan.

- 40 sequential review rounds completed.
- 18 rounds found defects and corrected them.
- 22 rounds found no new defect.
- 45 explicit PHP review suites are configured in the final full gate.

See `../FORTY-ROUND-AUDIT-2026-08-07.md`.

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

## 2.0.3 security/privacy hardening

- Block-safe phone/avatar projection.
- Versioned SNC3/SNC4 communication key IDs, bounded previous-key support and lazy re-encryption for message bodies, private files and Smail drafts.
- File-00-only verified-transfer eligibility; no legacy local verification fallback.
- Scanner plaintext leases always cleaned; entropy failure is fail-closed.
- Legacy `/presence` projects the canonical per-device service; device counts are self-only.
- Transfer files are contained by validated private-root realpaths before read/delete/scan/download.
- Smail mailbox and data-subject privacy exports return authorized plaintext rather than stored ciphertext.
- Smail draft fingerprints are keyed HMAC blind hashes.
- Forwarding remains decrypt-in-memory/re-encrypt-to-target, idempotent and audience-minimized.

## Calls and external media-provider limitation

Sabri Meet and direct call state/signaling are File-17 canonical capabilities, but real media transport remains **provider-gated**. STUN/TURN/SFU use approved external media-provider adapters with short-lived scoped credentials and health/capability checks. When an approved provider is unavailable or not accepted in staging, the affected media feature must be unavailable/degraded rather than simulated. This source does not claim an external provider is configured, staging-accepted or operational.

## Internal Smail

Smail is an internal communication center, not Internet email/SMTP. Inbox, Sent, Drafts, Starred, Archive, Spam and Trash reuse the canonical File-17 message backend. Sends use `SN_Message_Integrity`; multi-recipient retries reuse an idempotent canonical conversation reservation.

## Message confidentiality and private search

Canonical message bodies are authenticated-encrypted at rest through the `SNE1` envelope. This is server-side storage encryption, not an audited E2EE claim. Search decrypts only authorized content transiently and stores keyed token hashes; File 26 never receives this private corpus.

## Verified private transfer

Exact maximum is **1,073,741,824 bytes** per file. Transfer is resumable, SHA-256 checked, authenticated-encrypted outside the public WordPress tree, MIME/magic/archive validated, fail-closed to scanner quarantine, and delivered only through recipient/version-bound expiring grants with revocation/retention/audit controls.

## Quality and packaging

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The 2.0.3 gate includes **45 PHP review suites**, PHP 8.1/8.3 syntax, six JavaScript syntax checks, shell syntax, CSS/accessibility baselines, repository hygiene, exact staged-source manifest verification and deterministic byte-for-byte packaging.

## Completion truth

**Specified:** complete for reviewed repository-owned/current-wave scope.  
**Coded:** 2.0.3 code-complete corrective candidate.  
**Packaged / Automated-QA Green:** only after exact-head workflow success.  
**Staging-Accepted:** pending real environment and integration acceptance.  
**Live-Deployed:** not claimed.  
**Operational:** not claimed.

A green CI or ZIP is not production completion.
