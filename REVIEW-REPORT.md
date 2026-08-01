# File 17 — Sabri Network and Messages 2.0.0
## Consolidated Code Review, Defect Remediation and QA Report

**Repository:** `majidhussainqadri1-dot/17-sabri-network`  
**Branch:** `codex/file-17-review-2.0.0`  
**Review period:** 1–2 August 2026  
**Result:** coded, packaged and CI-green candidate; not staging-accepted, merged, live-deployed, or operational

Exact current head, CI run and workflow artifact identifiers are maintained in Pull Request #2. They are intentionally not embedded in this committed report because every source edit creates a new head and a new CI run.

## 1. Governing architecture

File 17 is the single canonical communication owner for Network relationships, contacts, follows, communities, groups, channels, conversations, messages, private attachments, temporary updates, presence, calls, signaling, blocks, reports, appeals, native retention enforcement and related privacy/audit records.

It does not duplicate File 00/File 02 identity, File 19 notification delivery, File 20 global shell, File 24 assurance governance, File 25 public-profile presentation, clinical records, appointments, marketplace truth or public publishing data.

Network and Messages remain distinct experiences over one shared backend and authorization model.

## 2. Principal remediation

### Identity, authorization and consent

- Removed legacy account/OTP ownership and stored SMS/TURN secrets.
- Added fail-closed identity-authority, suspension, verification, capability, minor and guardian assertions.
- Added server-side object, membership, ownership, block, relationship-consent and current-version checks.
- Required accepted contact or approved context for direct messaging and calls.
- Added conservative unknown-age and minor discoverability/contact controls.

### Database integrity and concurrency

- Added unique direct-conversation, contact-pair, message-idempotency, reaction, membership, call-membership, active-call and report-idempotency constraints.
- Added transactional conversation/call creation, state changes, ownership transfer, blocking cleanup and membership revocation.
- Added optimistic report decision and appeal versions.
- Added current-membership validation for call inventory, state changes, signaling and acknowledgements.

### Private files

- Moved new sensitive attachments outside the public Media Library path.
- Added path containment, symlink protection, signature/MIME checks, normalization, hashes, scanner contract, size limits and authorized delivery.
- Added no-store/range/content-disposition controls and safe deletion ordering.
- Withheld legacy public-media references pending controlled migration.

### Realtime and communication state

- Added privacy-scoped presence, bounded heartbeats and stale derivation.
- Added expiring typing indicators with membership, block, authority and rate-limit checks.
- Activated per-conversation mute/archive preferences and mute-aware fallback notifications.
- Enforced channel publishing authority and prohibited calls in broadcast channels.
- Preserved active conversation detail state during polling refreshes.

### Reports, appeals and retention

- Added UUIDv4 retry idempotency, target-bound hashes, evidence hashes and bounded report limits.
- Added administrator inventory, reasoned triage, legal/safety holds and category-aware retention.
- Added reported-user notice, appeal submission, reviewer separation, uphold/overturn and reopening.
- Added hold-aware privacy export/erasure and legacy migration.
- Added two-stage anonymization then delayed deletion.

## 3. Corrective findings discovered during repeated review

1. Realtime schema expectations lagged behind the report schema and were corrected.
2. Privacy adversarial tests still targeted the former inline erasure and were corrected to canonical `SN_Safety` behavior.
3. Initial package output was not byte reproducible because of timestamps and filesystem metadata; packaging now fixes locale, timezone, modes, timestamps and ordering, strips extra ZIP metadata and compares two independent builds byte-for-byte.
4. The report-retention lock used delete-then-add stale takeover. This could delete another worker's replacement lock.
   - stale takeover now uses exact-value compare-and-swap;
   - release now uses exact-value compare-and-delete;
   - direct option mutation invalidates the option cache;
   - runtime tests simulate competing takeover and release races.
5. Repository status and safety reports contained historical counts, commits, runs and hashes. They were consolidated and synchronized.
6. `CHECKSUMS.sha256` was incomplete and stale. It now lists exactly all 26 installable source files, while the quality gate verifies both manifest coverage and every digest.

## 4. QA Round 1 — comprehensive/static/runtime

- Initial static: **60/60 PASS**
- Realtime static: **37/37 PASS**
- Package static: **9/9 PASS**
- Safety static: **36/36 PASS**
- Safety runtime: **25/25 PASS**
- Relationship static: **45/45 PASS**
- Relationship runtime: **8/8 PASS**

**Round 1: 220/220 PASS.**

## 5. QA Round 2 — fresh/adversarial

- Initial adversarial: **59/59 PASS**
- Realtime adversarial: **33/33 PASS**
- Package adversarial: **8/8 PASS**
- Safety adversarial: **31/31 PASS**
- Relationship adversarial: **37/37 PASS**

**Round 2: 168/168 PASS.**

> **Total included contract checks: 388/388 PASS.**

## 6. Additional verification

- All included PHP syntax: PASS
- JavaScript syntax: PASS
- Shell syntax: PASS
- CSS integrity: PASS
- Repository hygiene: PASS
- Installable source file coverage: **26/26 PASS**
- Exact installable source SHA-256 verification: PASS
- Deterministic double-build hash and byte comparison: PASS
- Artifact creation and upload: PASS

## 7. Verified package

**Installable ZIP SHA-256:**

```text
d12f3b94e6583ef716085bca5dc7fe95b1b85e7354cb429122f94ce8264cee65
```

This is evidence for the current installable payload. It is not evidence of staging or production acceptance.

## 8. Remaining gates

- Production File 00/File 02 identity adapter and real-role authorization
- File 19, File 20, File 24 and File 25 integration acceptance
- Approved malware scanner and private-storage operations
- Production STUN/TURN and approved SFU
- Staging fresh install, upgrade/migration, rollback and backup/restore
- Real WordPress/MySQL concurrency and privacy-erasure tests
- Penetration, dependency, load, browser/device, RTL/LTR and accessibility tests
- Real-content and Founder acceptance
- Merge, live deployment and operational monitoring

## 9. Truthful completion status

The current deliverable is a reviewed, corrected, checksum-governed, deterministically packaged and CI-green 2.0.0 candidate with no known failure in its included suites. Production completion remains contingent on the external and staging gates above.
