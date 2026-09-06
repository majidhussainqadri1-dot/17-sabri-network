# File 17 — Fresh 20-Round Review — Round 12 Ledger Freeze

Date: 2026-09-06
Review exact HEAD: `f02a838184c10d88fa1a71752e0b6ef8f1e74e7f`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review scope completed before correction
Private media and file-transfer lifecycle security: upload provenance, MIME/signature/container validation, scanner fail-closed behavior, private-path containment, encrypted chunk storage, download authorization/integrity/range handling, quarantine/revocation, cleanup, retention/legal holds, privacy erasure, and retryable byte deletion.

Reviewed production surfaces include:
- `includes/class-sn-private-files.php`
- `includes/class-sn-attachment-runtime-hardening.php`
- `includes/class-sn-fourth-fresh-media-hardening.php`
- `includes/class-sn-file-transfer-part-1.php`
- `includes/class-sn-file-transfer-part-2.php`
- `includes/class-sn-file-transfer-part-3.php`
- `includes/class-sn-file-transfer-part-4.php`
- `includes/class-sn-file-transfer-part-5.php`
- `includes/class-sn-file-transfer-part-6.php`
- `includes/class-sn-file-transfer-part-7.php`
- `includes/class-sn-file-transfer-part-8.php`
- current File-17 native legal-hold filter in `includes/class-sn-fourth-fresh-privacy-hardening.php`

## Frozen defects

### R12-D01 — HIGH — transfer byte cleanup can bypass an active legal/safety hold
`SN_File_Transfer_Part_7::delete_chunks()` is the central destructive byte-deletion primitive used by expiry/revocation/rejection cleanup and privacy erasure. It reads chunk rows and deletes encrypted objects/rows without first establishing that the transfer's sender/recipients are free of the authoritative `sn_network_retention_prevents_erasure` hold condition. Consequently:
- cron `cleanup()` can expire a transfer and destroy its encrypted chunks while a sender or recipient is under an active File-17 legal/safety hold;
- `reject_corrupt()` also reaches `delete_chunks()` after changing state to rejected;
- callers outside the privacy eraser do not inherit its user-level hold precheck.

This contradicts File 17's retention/legal-hold lifecycle: destructive cleanup must fail closed when hold truth is true **or unavailable**.

Required correction after this ledger freeze:
1. Add one native transfer-level hold gate before any chunk byte/ledger deletion.
2. Resolve the transfer sender and current/retained recipient identities from canonical transfer tables.
3. Apply the existing authoritative `sn_network_retention_prevents_erasure` decision to every involved identity.
4. Treat DB read failure/unavailable hold truth as retained/fail-closed, emit privacy-safe audit evidence, and leave encrypted bytes + ledger rows intact for retry/reconciliation.
5. Add a regression contract proving `delete_chunks()` cannot unlink bytes before the hold gate succeeds.

## Other reviewed controls
The reviewed code already has substantive controls for private storage outside web root, path containment, upload-source validation, size caps, MIME/extension pairing, PDF/image checks, scanner quarantine, audio/video validator gates, encrypted chunks, chunk SHA-256, authenticated signed grants, participant revalidation, full preflight integrity before streaming, no-store/nosniff headers, bounded ranges, archive traversal/expansion checks, and retryable ledger-preserving deletion.

## Review integrity statement
**No production code was changed during Round 12 review.** The complete Round 12 review was finished first; this ledger freezes the defect before any correction begins.
