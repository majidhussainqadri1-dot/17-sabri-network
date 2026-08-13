# File 17 — Sabri Network and Messages

**Plugin version:** 2.1.0  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Repository status:** current two-plan repository coding-completion candidate; staging/live/operational acceptance remains separate

File 17 is the Sabri Social Homeopathy Platform's single canonical communication/realtime owner: relationships, contacts/follows, unknown-sender message requests, communities/groups/channels, conversations/messages, private attachments, internal Smail, verified-user private transfer, temporary updates, presence/typing, calls/signaling/Sabri Meet, blocks/reports, native privacy lifecycle, receipts, private search and communication-event evidence. Network and Messages are distinct experiences over the same backend.

## Governing basis

The 2.1.0 completion candidate is reconciled to the current consolidated central governing plan and the current File 17 Final Harmonized Master Plan. Earlier 2.0.3 forty-round evidence remains historical and does not replace fresh current-plan QA.

The full explicit quality gate contains **46 PHP review suites**.

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

## 2.1.0 completion features

- Unknown-sender message requests with accept/decline/report/cancel, sender/recipient rate protection, cooldown and transactional acceptance into the canonical contact/direct-conversation backend.
- Encrypted scheduled-message queue with delivery-time authorization revalidation, cancellation and bounded retry state.
- Polls and collaborative checklists represented by canonical messages; neither becomes clinical-decision authority.
- Disappearing-message lifecycle with legal-hold protection, search-index removal and private-attachment revocation/deletion.
- Fail-closed transient translation through an approved adapter only.
- Voice-note endpoint reusing canonical message-integrity/private-file controls, with playback/transcript capability metadata.
- Authenticated-encrypted new temporary-update text and lazy migration of legacy plaintext updates on authorized reads.
- Community rules/onboarding, forum questions, AMA sessions, wiki pages, events/cohorts, moderated responses/best-answer and aggregate privacy-minimized community health.
- Privacy export/erasure participation for the new personal-data domains.

## Message confidentiality and private search

Canonical message bodies are authenticated-encrypted at rest through the `SNE1` envelope. This is server-side storage encryption, not an audited E2EE claim. Search decrypts only authorized content transiently and stores keyed token hashes; File 26 never receives this private corpus. The 2.1.0 completion layer similarly encrypts pending message-request text, scheduled-message payloads, community artifact/response bodies and new temporary-update text.

## Calls and external media-provider limitation

Sabri Meet and direct call state/signaling are File-17 canonical capabilities, but real media transport remains **provider-gated**. STUN/TURN/SFU use approved external media-provider adapters with short-lived scoped credentials and health/capability checks. When an approved provider is unavailable or not accepted in staging, the affected media feature must be unavailable/degraded rather than simulated.

## Internal Smail

Smail is an internal communication center, not Internet email/SMTP. Inbox, Sent, Drafts, Starred, Archive, Spam and Trash reuse the canonical File-17 message backend. Sends use `SN_Message_Integrity`; multi-recipient retries reuse an idempotent canonical conversation reservation.

## Verified private transfer

Exact maximum is **1,073,741,824 bytes** per file. Transfer is resumable, SHA-256 checked, authenticated-encrypted outside the public WordPress tree, MIME/magic/archive validated, fail-closed to scanner quarantine, and delivered only through recipient/version-bound expiring grants with revocation/retention/audit controls.

## Historical 2.0.3 corrective record

The previous forty-round cycle recorded 40 rounds, 18 defect-bearing rounds and 22 rounds with no new defect. Its ledger remains in `../FORTY-ROUND-AUDIT-2026-08-07.md` as historical evidence.

## Quality and packaging

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The 2.1.0 gate includes **46 PHP review suites**, PHP 8.1/8.3 syntax, **eight JavaScript syntax checks**, shell syntax, CSS/accessibility baselines, repository hygiene, exact staged-source manifest verification and deterministic byte-for-byte packaging. The standalone package builder also validates the current Future-24 JavaScript and release-critical runtime surfaces before producing the ZIP.

## Completion truth

**Specified:** complete for current governing-plan File-17 repository scope.  
**Coded:** 2.1.0 repository completion candidate.  
**Packaged / Automated-QA Green:** only after exact-head workflow success.  
**Staging-Accepted:** pending real environment and integration acceptance.  
**Live-Deployed:** not claimed.  
**Operational:** not claimed.

A green CI or ZIP is not production completion.
