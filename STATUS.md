# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.2  
**State:** four-plan/four-round corrective code-complete candidate  
**Coding completion against current repository-owned/current-wave File-17 obligations:** **100% candidate**  
**Configured review suites:** **41**  
**Installable source integrity:** exact staged manifest + deterministic double-build  
**Staging/live/operational:** pending

## Governing audit set

- Definitive Integrated Master Plan v3.0.
- Consolidated All-Chats Recovered Directives.
- Continuous Value / Top-20 Superset Master Plan.
- File 17 Final Harmonized Master Plan.

## Corrective findings closed in 2.0.2

1. File 19 single-notification ownership was undermined by a historical File-17 local fallback center/bell. New local writes are now terminally suppressed after approved adapters, metadata-only File-19 facts are emitted, old routes are compatibility-only and the local bell is hidden.
2. Concurrent uploads of the same transfer chunk could share one encrypted path and a losing retry could unlink the winner. Each attempt now uses an independent random path before database uniqueness selects the winner.
3. Canonical message bodies were plaintext at rest in the message table; Smail bypassed the atomic message-integrity path; interrupted multi-recipient Smail sends could duplicate a group; forwarding was not compatible with encrypted bodies. All four paths are corrected with authenticated at-rest envelopes, bounded migration, canonical Smail routing/idempotency and audience-safe re-encryption.
4. Substantive post-2.0.1 changes required a new immutable release identity, synchronized documentation, explicit four-round QA suites and deterministic package naming. Runtime/package target is now 2.0.2.

## Included engineering scope

The candidate includes canonical Network, spaces, Messages, receipts, indexed private search, reliable outbox/inbox, presence, calls, Sabri Meet, reports/privacy/safety, CF-01 communication context, internal Smail, authenticated-at-rest canonical message bodies, and verified-user private transfer up to 1 GiB per file.

Smail provides Inbox, Sent, Drafts, Starred, Archive, Spam and Trash without a parallel chat/email backend. Drafts are authenticated-encrypted; sends reuse `SN_Message_Integrity`; File 19 is the single notification-center/delivery owner.

Private transfer uses bounded resumable chunks, collision-safe concurrent-attempt storage, per-chunk and full-file SHA-256, authenticated encryption outside the web root, MIME/magic and archive-safety checks, fail-closed malware quarantine, signed recipient/version-bound ten-minute grants, byte ranges, revocation, retention, audit, export and erasure.

## Top-20 roadmap truth

The Top-20 governing plan defines `NOW`, `NEXT`, and `SCALE` as distinct waves. 2.0.2 closes repository-owned current-wave obligations and preserves provider-/maturity-dependent later-wave gates; it does not convert `NEXT/SCALE` labels into false claims of current live functionality.

## Remaining release evidence—not coding omissions in the current repository wave

- exact-head GitHub Actions and immutable 2.0.2 artifact evidence;
- real WordPress/PHP/MySQL fresh install and supported upgrade/migration;
- File 00/02/08/18/19/20/21/24/25/26, CF-01 and CF-04 adapter acceptance;
- approved scanner, private storage, cron, LiteSpeed and degraded-mode verification;
- browser/device, RTL/LTR, accessibility, load/soak and penetration testing;
- backup/restore, rollback rehearsal, Founder staging sign-off, live deployment and operations.

No staging, live, operational or audited-E2EE claim is made.
