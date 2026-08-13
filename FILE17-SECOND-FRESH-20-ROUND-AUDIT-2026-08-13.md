# File 17 — Second Fresh 20-Round Review Audit — 13 Aug 2026

## Governing review method

This audit is a fresh repository-level review cycle for File 17 — Sabri Network and Messages. Each round followed the requested sequence strictly:

**complete the whole review round → record all defects found in that round → fix all proven defects only after the review round is complete → run regression/quality verification → begin the next round.**

No defect was intentionally patched in the middle of the review scope that discovered it. A later failure of a regression assertion was treated as verification evidence for the completed round and corrected before the next round could be accepted.

This document records repository truth only. It does not claim staging acceptance, deployment parity, live deployment or operational acceptance.

## Frozen repository basis

- Repository: `majidhussainqadri1-dot/17-sabri-network`
- Fresh review branch: `review/file17-additional-20-round-r2-2026-08-13`
- Starting exact SHA: `d74d5c0af360b23b2976c39f768aace0b157c3ef`
- Pre-report final reviewed code SHA: `5e1783fae1afb59b59ca80e5a7f89981d3df6849`
- Plugin candidate version: `2.1.0`
- Governing architecture: File 17 remains the single canonical communication/realtime owner; File 00/02 remains identity/account authority; File 19 notification delivery owner; File 26 global Search/Discovery owner; provider-dependent capabilities remain fail-closed/provider-gated.

## First ten rounds — mandatory checkpoint

Defects were found in **Round 1 and Round 5**.

Rounds **2, 3, 4, 6, 7, 8, 9 and 10** completed without a new proven repository-level defect after reviewing their assigned scope.

## Complete 20-round ledger

| Round | Result | Review scope / finding | End-of-round correction / verification |
|---|---|---|---|
| 1 | **DEFECT** | Exact-baseline truth, PHP 8.1 compatibility, release/test truth. The prior branch was not actually green: File-transfer privacy used a trait constant invalid for the declared PHP 8.1 floor. Several stale exact-string regression assertions also no longer represented the hardened implementation. | Replaced the trait constant with a PHP-8.1-compatible method and reconciled affected transfer, relationship, outbox and Future-24 regression contracts with the actual hardened behavior. The round was not accepted until the relevant quality chain advanced. |
| 2 | CLEAN | File 00 identity authority, raw-phone ownership, age/guardian, verification, suspension, fail-closed assertion contracts. | No new proven defect. File 17 consumes File-00 owner assertions/projections rather than becoming a parallel identity authority. |
| 3 | CLEAN | Contacts, follows, blocks, pair-lock ordering, optimistic versions and relationship races. | No new proven defect. Canonical pair locks/transactions/CAS remained in place. |
| 4 | CLEAN | Conversation membership, member add/remove, owner/moderator authority and membership transition integrity. | No new proven defect. |
| 5 | **DEFECT** | Canonical message send/idempotency/outbox/search reconciliation. An existing idempotent message could be reconciled without revalidating current membership/contact authorization. | `SN_Message_Runtime_Hardening::reconcile_existing()` now revalidates sender ownership, active conversation, current membership, posting policy and direct-contact/block policy, including a transactional recheck before search/outbox reconciliation. |
| 6 | CLEAN | Message visibility, hidden-for-self state, private search/context, signed cursor scope and receipts. | No new proven defect. Hidden-message overlays and viewer/conversation/filter-bound private-search cursors remained enforced. |
| 7 | CLEAN | Private attachments, voice notes, scanner fail-closed behavior, SHA-256 integrity and private-storage containment. | No new proven defect. |
| 8 | CLEAN | Message requests, scheduled messages, disappearing-message expiry and transient translation/provider gates. | No new proven defect. Delivery-time authorization and legal-hold/provider boundaries remained enforced. |
| 9 | CLEAN | Spaces/communities/groups/channels, QR invitations, temporary membership, bans/capacity/guardian/expiry. | No new proven defect. |
| 10 | CLEAN | Presence, typing, realtime, signaling, device state/revocation and realtime authorization races. | No new proven defect. |
| 11 | **DEFECT** | Calls/Sabri Meet/TURN-STUN-SFU credential boundary. The before/after File-00 eligibility checks in media credential issuance could reuse the per-request assertion cache, so the supposed fresh checks were not guaranteed fresh. | The call runtime now clears the File-00 assertion cache immediately before provider issuance and again before credential delivery, then requires current `can_call=true` and non-suspended state. |
| 12 | CLEAN | Smail canonical send/state/draft/mailbox projection/privacy lifecycle. | No new proven defect. |
| 13 | CLEAN | Verified private file transfer up to exactly 1 GiB: chunks, SHA-256, finalization, scanning, grants, revoke, download, retention and cleanup. | No new proven source defect in the assigned scope. |
| 14 | CLEAN | WordPress privacy export/erasure, encrypted update content, revoke-before-delete ordering and legal/safety holds. | No new proven defect. |
| 15 | CLEAN | Safety reports, appeals, legal holds, retention, high-risk controls and moderation evidence. | No new proven defect. |
| 16 | CLEAN | Future-24 E2EE-provider claims, device keys, safety numbers, key transparency and sensitive-conversation locks. | No new proven defect. Unsupported E2EE claims remain provider-gated. |
| 17 | CLEAN | Team Inbox, handoff, internal notes, templates, reminders and bulk jobs; delegation/CAS/transaction behavior. | No new proven defect. |
| 18 | CLEAN | AI assistance, private semantic search, citations, de-identified case discussions, interoperability and network-quality telemetry. | No new proven defect. File-16/File-26/provider ownership boundaries remained explicit. |
| 19 | **DEFECT** | Release/package/QA/documentation truth. The package script syntax-checked only seven staged JS entry points, its independent required-surface list omitted several current runtime-hardening files, README contained stale QA and Smail-runtime statements, and the critical R5/R11 corrections lacked permanent explicit regression assertions. A Meet package regression still expected an obsolete one-off JS command. | Package validation now checks all eight JS entry points and governed runtime-hardening surfaces; README was corrected; R5/R11 regression assertions were added; the Meet package test now validates the complete staged-JS loop. Exact-head `2ae9233f10df6d9b9e3e60ca61e8246c613814c4` passed both PHP 8.1 boundary and PHP 8.3 full quality/package jobs in run `31667513042`. |
| 20 | **DEFECT** | Final adversarial exact-head review found an idempotent concurrent-send attachment leak: a losing request could create a private attachment, lose the message insert race to the canonical idempotency winner, then immediately return the winner without deleting its own orphan attachment. | On a race winner, the losing attachment is revoked/deleted when its attachment ID differs from the winner's. A permanent regression assertion was added. Exact-head `5e1783fae1afb59b59ca80e5a7f89981d3df6849` passed both GitHub Actions jobs in run `31667676850`. |

## Defect-bearing rounds

**1, 5, 11, 19 and 20**

Total defect-bearing rounds: **5 / 20**.

## Clean rounds

**2, 3, 4, 6, 7, 8, 9, 10, 12, 13, 14, 15, 16, 17 and 18**

Total clean rounds: **15 / 20**.

“Clean” means no new proven repository-level defect was found in that round's assigned review scope after reviewing the corrected state. It is not a claim of mathematical infallibility or live-production acceptance.

## Exact-head verification before this audit-record commit

The final code-changing Round-20 SHA was:

`5e1783fae1afb59b59ca80e5a7f89981d3df6849`

GitHub Actions run:

`31667676850`

Results:

- PHP 8.1 syntax and current boundary contracts: **SUCCESS**
- PHP 8.3 full quality and deterministic package: **SUCCESS**
- PHP files checked by full gate: **145**
- JavaScript entry points syntax-checked: **8**
- Explicit PHP review suites: **47**
- CSS/accessibility baseline: **PASS**
- Private communication/current-plan/Future-24 invariants: **PASS**
- Exact staged source manifest: **PASS**
- Reproducible package: **PASS**
- Pre-report plugin ZIP SHA-256: `136c89f7caa91a57eb8ce904bc78a583c22276154a29ea9a711183075cc07c0c`
- Pre-report Actions artifact ID: `9168531364`

Because this audit document itself creates one further repository commit, the branch HEAD after this document is committed must receive its own exact-head GitHub Actions verification before the review cycle is reported as repository Automated-QA Green. The package stages only the `sabri-network/` release tree, but the package hash must still be verified from that final workflow rather than assumed.

## Status boundary

| Status field | Evidence state |
|---|---|
| Repository HEAD | Final report-inclusive SHA to be populated by the commit that creates this audit record and verified by its exact-head CI run |
| Deployed Version | **Unverified** |
| DB Version | **Unverified** |
| Migration State | **Unverified** |
| Live Verification Status | **Not performed / unverified** |

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

A green repository workflow or deterministic ZIP is not proof of staging acceptance, deployment parity, live deployment or operational acceptance. The next environment gate remains deployment-parity/staging verification against the exact reviewed artifact, database/schema/migration state, dependencies/providers and real integration behavior.