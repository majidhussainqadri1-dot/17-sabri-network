# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.1  
**State:** consolidated code-complete candidate  
**Coding completion against current File-17 + recovered directives:** **100% candidate**  
**Configured review suites:** **37**  
**Installable source integrity:** exact staged manifest + deterministic double-build  
**Staging/live/operational:** pending

## Included engineering scope

The candidate includes canonical Network, spaces, Messages, receipts, indexed private search, reliable outbox/inbox, presence, calls, Sabri Meet, reports/privacy/safety, CF-01 communication context, internal Smail, and verified-user private transfer up to 1 GiB per file.

Smail provides Inbox, Sent, Drafts, Starred, Archive, Spam and Trash without a parallel chat/email backend. Drafts are authenticated-encrypted; sends reuse canonical conversation/message commands; File 19 remains notification transport owner.

Private transfer uses bounded resumable chunks, per-chunk and full-file SHA-256, authenticated encryption outside the web root, MIME/magic and archive-safety checks, fail-closed malware quarantine, signed recipient/version-bound ten-minute grants, byte ranges, revocation, retention, audit, export and erasure.

## Remaining release evidence—not coding omissions

- exact-head GitHub Actions and immutable artifact evidence;
- real WordPress/PHP/MySQL fresh install and supported upgrade/migration;
- File 00/02/19/20/24/25, CF-01 and CF-04 adapter acceptance;
- approved scanner, private storage, cron, LiteSpeed and degraded-mode verification;
- browser/device, RTL/LTR, accessibility, load/soak and penetration testing;
- backup/restore, rollback rehearsal, Founder staging sign-off, live deployment and operations.

No staging, live, operational or audited-E2EE claim is made.
