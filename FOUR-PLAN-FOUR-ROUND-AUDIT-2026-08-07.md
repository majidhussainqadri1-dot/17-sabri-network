# File 17 — Four-Plan / Four-Round Audit and Correction Ledger

Date: 7 August 2026 (Pakistan Standard Time)  
Runtime corrective target: **2.0.2**  
Repository: `majidhussainqadri1-dot/17-sabri-network`

## Governing sources

1. **Definitive Integrated Master Plan v3.0** — canonical ownership, status truth, no duplicate backend, File 17 communication ownership, File 19 notification ownership, File 20 shell boundary, security/release law.
2. **Consolidated All-Chats Recovered Directives** — Internal Smail, verified-user transfer hard limit 1 GiB/file, green primary, File 19/File 20/CF-04 boundaries, repeated review/fix law.
3. **Continuous Value / Top-20 Superset Master Plan** — single realtime owner File 17, private communication and communities benchmark, Smail, 1:1/group messaging, presence/calls, verified 1 GiB transfer, one notification fabric and explicit NOW/NEXT/SCALE status law.
4. **File 17 Final Harmonized Master Plan** — relationships/communities/groups/channels/conversations/messages/private attachments/presence/calls/signaling/blocks/reports/privacy lifecycle as one canonical backend, plus transactional/security/migration/QA/release requirements.

## Status semantics used by this audit

`Specified`, `Coded`, `Packaged`, `Automated-QA Green`, `Staging-Accepted`, `Live-Deployed`, and `Operational` are distinct statuses. A source/ZIP/green CI result does not imply Hostinger staging, live or operational acceptance.

The Top-20 plan's own status vocabulary is also preserved: `NOW` is current/current-wave; `NEXT` is the next required expansion wave; `SCALE` is maturity/operations/load work. This audit does not mislabel NEXT/SCALE provider-dependent capabilities as currently live merely to manufacture a “100%” claim.

---

## Review Round 1 — Governance, canonical ownership and central-plan reconciliation

### Audit lens

- File 17 single communication/realtime owner.
- File 19 single notification center/delivery owner.
- File 20 single global shell/navigation owner.
- File 00/02 identity authority.
- File 08/CF-01 clinical truth boundaries.
- green current primary brand.
- no second backend or duplicated global UI authority.

### Defects found

**R1-D1 — Duplicate notification ownership risk (high).**  
`SN_DB::add_notification()` could still insert into File-17's historical `sn_notifications` table when no adapter consumed the notification. This contradicted the later central single-notification-fabric rule.

**R1-D2 — Second notification UI surface (medium/high).**  
The legacy Network notification button remained renderable even though File 19/File 20 own the single global notification experience.

**R1-D3 — Architecture/documentation drift (medium).**  
Repository architecture still documented a local File-17 notification fallback and some release files described the pre-Top-20 ownership state.

### Corrections applied

- Added terminal `sn_network_notification_handled` hardening bridge after approved adapters; no new File-17 local notification rows are written.
- Added metadata-only `sn_network_notification_requested` integration fact for File 19/approved adapter use.
- Overrode historical notification REST routes as compatibility-only File-19 projections.
- Suppressed the legacy File-17 local bell; File 20/File 19 retain the one global bell.
- Updated route contract with `notification_owner=file-19` and `legacy_file17_notification_center=false`.
- Retained the historical table non-destructively for migration/rollback compatibility rather than dropping user data on activation.
- Reconciled green as primary and orange as explicitly secondary.

### Round 1 result

**Defects found: YES — 3 grouped defects. All corrected in source.**

---

## Review Round 2 — Verified 1 GiB transfer, resumability and concurrency

### Audit lens

- exact hard limit 1,073,741,824 bytes;
- bounded resumable chunks;
- SHA-256 integrity;
- encrypted private storage outside public Media Library/web root;
- server MIME/magic/archive validation;
- fail-closed scanner quarantine;
- recipient/version-bound signed grants and revocation;
- concurrency/idempotency under repeated same-index upload requests.

### Defect found

**R2-D1 — Destructive same-index upload race (critical).**  
Two concurrent requests for the same transfer/chunk index used the same deterministic encrypted path. The database unique key correctly selected one logical winner, but the losing transaction's rollback could `unlink()` that shared path and therefore delete the winner's committed bytes.

### Correction applied

Every upload attempt now receives an independent cryptographically random encrypted storage suffix before the unique `(transfer_id, chunk_index)` database key selects the logical winner. A losing retry can remove only its own bytes. Retry reconciliation validates both SHA-256 and byte count, and secure-random failure is fail-closed.

### Round 2 result

**Defects found: YES — 1 critical defect. Corrected in source and covered by dedicated concurrency checks.**

---

## Review Round 3 — Message confidentiality, Smail integrity and forwarding

### Audit lens

- canonical message truth only once;
- authenticated storage encryption for sensitive communication;
- atomic message/search/outbox mutation path;
- Smail as mailbox projection, not parallel mail/chat backend;
- retry/idempotency under partial failure;
- cross-audience forwarding privacy.

### Defects found

**R3-D1 — Canonical message body plaintext at rest (critical).**  
New/edited `sn_messages.body` values were stored in plaintext despite File-17 confidentiality/encryption requirements.

**R3-D2 — Smail bypassed canonical message integrity path (high).**  
Smail called `SN_REST::send_message()` directly instead of the higher-integrity `SN_Message_Integrity::send_message()` path, bypassing the atomic message/search/outbox/encryption layer.

**R3-D3 — Multi-recipient Smail retry could create duplicate group conversation (high).**  
If canonical message creation succeeded but Smail mailbox projection failed, an interrupted retry could create another group conversation before the unique Smail projection key reconciled.

**R3-D4 — Forwarding incompatible with encrypted canonical bodies / audience minimization (high).**  
Legacy forward behavior copied the stored body semantics directly and could not safely preserve encryption context or cross-audience minimization.

### Corrections applied

- Added `SN_Message_Body` authenticated server-side at-rest envelope (`SNE1`) using the existing communication crypto primitive. This is explicitly not an E2EE claim.
- New sends and edits encrypt before persistence; encryption failure rejects the write.
- Added bounded optimistic legacy plaintext migration plus authorized hashed-token reindexing.
- Search decrypts only the authorized canonical row in memory before term hashing; plaintext body/query is not persisted in the search index.
- Smail now uses `SN_Message_Integrity::send_message()`.
- Multi-recipient Smail group creation uses an idempotent reservation keyed by Smail request + recipient hash and validates exact current membership on retry.
- Forwarding revalidates source/target membership, decrypts the authorized source only in memory, writes a target-context encrypted body, disallows private attachment ID reuse and emits source-minimized event metadata.

### Round 3 result

**Defects found: YES — 4 grouped defects. All corrected in source.**

---

## Review Round 4 — Fresh post-correction release, QA, package and truth-status review

### Audit lens

- rerun four-plan trace after previous fixes;
- immutable version identity;
- docs/source/package/test consistency;
- deterministic package contract;
- explicit test inventory;
- no false staging/live/operational claim;
- Top-20 NOW/NEXT/SCALE truth preserved.

### Defects found

**R4-D1 — Version identity drift (high).**  
Substantive security/ownership corrections were being layered onto the previously published 2.0.1 identity. Reusing the old identity would make prior package hashes/evidence ambiguous.

**R4-D2 — QA inventory lag (medium/high).**  
The quality gate still enumerated the prior 37 suites and did not explicitly test the newly discovered four-plan ownership/concurrency/message-Smail defects.

**R4-D3 — Package contract lag (medium).**  
Deterministic package naming/version checks still targeted 2.0.1.

**R4-D4 — Documentation/status drift (medium).**  
Root/plugin status files and architecture had not yet recorded the four-plan basis, File-19 single-owner correction, encrypted canonical message truth or 2.0.2 identity.

### Corrections applied

- Promoted the corrective candidate to **2.0.2**, preserving 2.0.1 as immutable historical evidence.
- Added four named review suites: governance, transfer concurrency, message/Smail security and fresh release truth.
- Expanded explicit review-suite inventory from 37 to **41**.
- Updated package build identity to `17-sabri-network-and-messages-2.0.2` and required the new hardening/encryption files in installable source.
- Reconciled plugin/root README, architecture, changelog, coding completeness and system status.
- Preserved separate external gates for staging/live/operational acceptance and the Top-20 plan's NEXT/SCALE future-wave statuses.

### Round 4 result

**Defects found: YES — 4 grouped release-truth defects. Corrected in source; exact-head CI/package evidence must still pass before this branch is called Automated-QA Green/Packaged.**

---

## Defect-round count requested by Founder

- **Review rounds performed:** 4
- **Rounds in which defects were found:** **4**
- **Rounds in which no defects were found:** **0**
- **Grouped defects recorded:** **12** (3 + 1 + 4 + 4)
- **Known unresolved repository/current-wave coding defects after corrections:** **0**, subject to exact-head automated QA and any new evidence.

## Release gate after these corrections

The repository may be called a **2.0.2 code-complete corrective candidate** only after all branch changes are present. `Packaged` and `Automated-QA Green` require the exact-head deterministic build and CI result. `Staging-Accepted`, `Live-Deployed` and `Operational` remain external statuses and cannot be inferred from GitHub source alone.
