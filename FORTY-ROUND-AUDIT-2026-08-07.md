# File 17 — Forty-Round Review, Correction and Release Ledger

**Date:** 7 August 2026 (Pakistan Standard Time)  
**Repository:** `majidhussainqadri1-dot/17-sabri-network`  
**Corrective runtime:** **2.0.3**  
**Governing set:** Definitive Master Plan v3.0 + Consolidated Recovered Directives + Continuous Value / Top-20 Superset Master Plan + File 17 Final Harmonized Master Plan.

## Status law

This ledger evaluates repository-owned/current-wave File-17 source. `Specified`, `Coded`, `Packaged`, `Automated-QA Green`, `Staging-Accepted`, `Live-Deployed`, and `Operational` remain separate statuses. A green repository run does not establish Hostinger staging, live deployment, provider acceptance, or operations.

## Forty sequential rounds

| Round | Independent review lens | Defect? | Correction/result |
|---:|---|:---:|---|
| 1 | Latest canonical ownership; File 26 global search boundary | Yes | Declared File 26 global/federated search and ranking ownership; private messages/contacts excluded; File 17 retains authorized private-message search. |
| 2 | Public identity projection after block/privacy changes | Yes | Block now overrides phone/avatar visibility, including `everyone` phone visibility. |
| 3 | Communication encryption lifecycle and key rotation | Yes | Added versioned SNC3/SNC4 key IDs, bounded previous-key keyring, legacy reads and rotatable signing/encryption. |
| 4 | Verified-user 1 GiB transfer identity authority | Yes | Removed legacy badge/phone-meta verification fallback; File 00 current verification assertion is fail-closed authority. |
| 5 | Malware-scanner plaintext materialization lifecycle | Yes | Added tracked synchronous plaintext leases and unconditional `finally` cleanup. |
| 6 | Presence data minimization | Yes | Cross-user active-device count disclosure removed; count is self-only. |
| 7 | Legacy presence route and canonical backend | Yes | Compatibility `/presence` now projects the canonical per-device presence service; explicit offline state preserved. |
| 8 | Scan temporary-name entropy failure | Yes | `random_bytes()` failure is caught and fails closed with controlled 503 rather than fatal behavior. |
| 9 | Message-body key rotation | Yes | Authorized reads/migration now lazily re-encrypt legacy/previous-key message envelopes with optimistic CAS. |
| 10 | Private-file key rotation | Yes | Encrypted chunk reads lazily re-encrypt under the current key; deferred rotation exposes no plaintext/path. |
| 11 | Private-transfer path containment | Yes | Added absolute/traversal/NUL rejection, `realpath` containment and validation for finalize/download/scan/delete reads. |
| 12 | Forwarding defense-in-depth | Yes | A dormant/legacy forward implementation remained unsafe if reused; added a protected decrypt-in-memory/re-encrypt/idempotent route. The active later-priority central hardening path was already encrypted and remains canonical. |
| 13 | QA semantic strength | Yes | Replaced a brittle comment-string assertion with behavioral migration/encryption/forwarding checks. |
| 14 | Smail mailbox projection after canonical encryption | Yes | Authorized Smail mailbox projection now decrypts canonical message bodies and fails closed if unavailable. |
| 15 | Smail draft cryptography/privacy | Yes | Drafts rotate keys lazily; plaintext JSON fingerprint changed from ordinary SHA-256 to keyed HMAC blind hash. |
| 16 | Core WordPress privacy export after message encryption | Yes | User message exports now return authorized readable plaintext rather than stored ciphertext. |
| 17 | Smail privacy export/erasure | Yes | Own draft recipients/subject/body are exported readably; erasure uses the keyed blind-hash convention. |
| 18 | Follow/contact lifecycle and duplicate/race behavior | No | Fresh relationship static/runtime/adversarial review remained green; no new defect found. |
| 19 | Blocks, restrictions, minor/guardian and contact authorization | No | Fail-closed policy paths remained consistent; no new defect found. |
| 20 | Communities/groups/channels roles, invites and succession | No | Hierarchy, recipient consent, capacity, anti-raid and owner succession remained coherent. |
| 21 | Canonical presence TTL, revocation and aggregate privacy after corrections | No | Re-review found no further source defect. |
| 22 | Message send/edit/delete/reaction atomicity | No | Encryption/search/outbox mutation contracts remained atomic in reviewed scope. |
| 23 | Delivery/read receipts and read-pointer integrity | No | Native recipient/device receipt and optimistic read-state contracts remained coherent. |
| 24 | Private message search, cursors, snapshots and budgets | No | Authorized transient decrypt + keyed-token index and bounded cursors remained intact. |
| 25 | Pins, stars, folders and hide-for-self projections | No | Private projection domains remained separated from canonical message truth. |
| 26 | Smail canonical truth and retry/idempotency after fixes | No | Seven mailboxes still reuse canonical conversations/messages; no parallel backend found. |
| 27 | Transfer size/chunk/resume/retry/quota controls | No | Exact 1,073,741,824-byte hard limit and bounded resumable flow remained intact. |
| 28 | Transfer MIME/archive/scanner/grant/range/revocation controls | No | Fresh adversarial review found no additional repository defect. |
| 29 | Private attachment storage/scanning/delivery lifecycle | No | Web-root exclusion, quarantine, authorization and deletion ordering remained intact. |
| 30 | Typing/realtime boundedness and degraded behavior | No | Membership/block/rate/expiry controls remained bounded; no new defect found. |
| 31 | Direct calls and Sabri Meet authorization/state | No | Admission, participant privacy, minors, provider gating and truthful capability claims remained intact. |
| 32 | Call signaling replay/size/rate/acknowledgement | No | Existing signal constraints and acknowledgement contracts remained green. |
| 33 | STUN/TURN/SFU provider governance | No | Secret-free registry, fresh health and short-lived scoped credentials remained fail-closed. |
| 34 | High-risk step-up and dual control | No | One-time hashed tokens, payload binding and distinct requester/approver/executor remained intact. |
| 35 | File 08/18/21 and CF-01 context adapters | No | Opaque references, per-operation authorization and no copied domain truth remained intact. |
| 36 | Reliable outbox/inbox, retry and dead-letter | No | Idempotency, retry/dead-letter and atomic event contracts remained coherent. |
| 37 | Retention, legal/safety holds and deletion evidence | No | Hold-aware lifecycle, appeal and deletion/retention contracts remained intact. |
| 38 | REST/AJAX input, IDOR/CSRF, accessibility/RTL/green/degraded UI | No | Fresh source and existing adversarial/accessibility gates found no additional defect. |
| 39 | Immutable release identity, package/workflow/test inventory | Yes | Substantive corrections promoted to **2.0.3**; package/workflow/docs/test inventory moved to the new immutable release identity and 45 explicit suites. |
| 40 | Fresh post-correction release truth and exact-head gate | No | Final source review found no new defect; exact-head PHP 8.1/8.3, 45-suite, deterministic-package CI is the required automated confirmation before merge. |

## Founder-requested round count

- **Review rounds performed:** **40**
- **Rounds in which one or more defects were found and corrected:** **18**
- **Rounds in which no new defect was found:** **22**
- **Known unresolved repository/current-wave coding defects after round 40:** **0**, subject to the exact-head automated gate and any later evidence.

## Principal corrections introduced by the forty-round cycle

- Latest File 26 search/ranking ownership contract and private-corpus exclusion.
- Block-safe phone/avatar projections.
- Rotatable versioned communication keyring and lazy message/private-file/draft re-encryption.
- File 00-only verified-transfer eligibility.
- Scanner plaintext lease cleanup and entropy-failure containment.
- Presence data minimization and one canonical compatibility route.
- Private-transfer filesystem containment.
- Smail mailbox/draft and WordPress privacy exports made readable without weakening encryption at rest.
- Keyed draft fingerprints instead of ordinary plaintext hashes.
- Behavioral QA strengthened and four dedicated forty-round suites added.
- Runtime/package/workflow identity promoted from immutable historical 2.0.2 to corrective **2.0.3**.

## Release boundary

The repository may be called a **2.0.3 code-complete corrective candidate** only after its exact-head automated quality gate succeeds. `Staging-Accepted`, `Live-Deployed`, and `Operational` remain external gates requiring real WordPress/Hostinger, companion modules/providers, migration, accessibility/browser/device, load/security, backup/restore, rollback and Founder acceptance evidence.
