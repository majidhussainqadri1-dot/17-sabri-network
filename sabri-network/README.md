# File 17 — Sabri Network and Messages

**Plugin version:** 2.1.0  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Repository status:** coding/review candidate; staging/live/operational acceptance remains separate.

File 17 is the Sabri Social Homeopathy Platform's single canonical communication/realtime owner: relationships, contacts/follows, message requests, communities/groups/channels, conversations/messages, private attachments, internal Smail, verified-user private transfer, temporary updates, presence/typing, calls/signaling/Sabri Meet, blocks/reports, native privacy lifecycle, receipts, private search and communication-event evidence. Network and Messages are distinct experiences over the same backend.

## Governing basis

2.1.0 is reconciled to the current consolidated central governing plan, the current File 17 Final Harmonized Master Plan and the Founder-approved Future Communication Superset 24. Earlier review ledgers remain historical evidence for their own exact commits.

The explicit full quality gate now contains **48 PHP review suites**, PHP 8.1/8.3 checks, and **8 JavaScript entry points**. The fourth fresh cycle's permanent regression gate is `tests/fourth-fresh-twenty-round-contracts.php`.

## Fourth fresh review boundary

The 17 August 2026 independent cycle began from `main` `04ba7406cf06ecba48d24e36f13a16dfe5ccb044`. Every review round is completed before its defects are corrected; the next round starts only after exact-head retesting. First-ten defect rounds are **2, 4, 5, 6, 7, 8, 9, 10**; clean rounds are **1, 3**.

Current fourth-cycle hardening covers early object authorization before REST side effects, high-risk conversation ownership transfer, caller idempotency, exact message/draft/report versions, lossless search rebuild, private-media validation, encrypted voice transcripts, lifecycle/space/realtime serialization, meeting token/eligibility boundaries, transfer storage/quota safety, native legal-hold erasure blocking, report dual control, strong communication-key sources, AI/semantic visibility, canonical File 06/12 scholarly citations, case de-identification and interoperability replay/uncertain-outcome reconciliation.

## Canonical boundaries

- File 00/File 02: identity/authentication/current verification authority.
- File 19: single notification center/preferences/delivery fabric; File 17 emits metadata-only events.
- File 20: global shell/navigation/layout owner.
- File 24: assurance evidence consumer; native File-17 controls stay native.
- File 25: visual/public action presentation.
- File 26: global Search/Discovery/Ranking owner. Private messages/contacts are excluded; authorized private-message search remains File 17.
- File 08/CF-01 and other clinical owners retain clinical truth.

## Confidentiality and key lifecycle

Canonical message bodies use authenticated server-side encryption at rest (`SNE1`), not an audited E2EE claim. New durable File-17 ciphertext uses a File-17-specific master secret independent from WordPress authentication salts. Staging/production should inject an approved shared secret; otherwise the plugin's private-storage fallback must remain outside every public document root and pass strict key-file hygiene. Legacy material is decrypt-only compatibility. Backup/restore and rotation must prove old-ciphertext decryptability before staging acceptance.

## Calls and providers

Sabri Meet and direct-call state/signaling are canonical File-17 capabilities. Real media transport is provider-gated. Approved STUN/TURN/SFU credentials must be short-lived and scoped; File-00 calling eligibility is revalidated before credential delivery. Provider-dependent capabilities fail closed when approved providers are unavailable or unaccepted.

## Verified private transfer

Maximum is **1,073,741,824 bytes** per file. Transfer is resumable, SHA-256 checked, authenticated-encrypted, outside public roots, MIME/archive/media/scanner governed, and authorized through expiring grants with revocation/retention/audit controls.

## Quality and packaging

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The gate verifies all 48 PHP review suites, 8 JavaScript entry points, shell syntax, CSS/accessibility, repository hygiene, required fourth-cycle runtime surfaces, exact staged-source manifest and deterministic byte-for-byte package reproduction.

## Completion truth

**Specified:** current governing-plan File-17 scope represented.  
**Coded:** 2.1.0 repository candidate.  
**Packaged / Automated-QA Green:** only after exact-head workflow success for the exact commit.  
**Staging-Accepted:** pending real environment/integration acceptance.  
**Live-Deployed:** not claimed.  
**Operational:** not claimed.

A green CI or ZIP is not production completion. Final current-cycle SHA/run/package evidence is recorded only after Round 20 closes.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
