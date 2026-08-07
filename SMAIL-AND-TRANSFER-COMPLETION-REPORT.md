# File 17 — Smail and Verified Private Transfer Completion Report

## Decision and ownership

Both features remain inside File 17's single canonical communication backend. Smail is an internal experience over conversations/messages; private transfer is a File-17 recipient and authorization workflow. Neither creates a parallel identity, chat, notification or public-media backend.

## Smail implementation

- Routes and surface: `/smail/`, File-20 route registration, noindex/no-cache.
- Mailboxes: Inbox, Sent, Drafts, Starred, Archive, Spam, Trash.
- Canonical sending: `SN_REST::create_conversation()` and `SN_REST::send_message()`.
- Data: minimal canonical-message linkage, user mailbox state, authenticated-encrypted/versioned draft payloads.
- Safety: contact/block/minor/suspension policy reuse, rate limits, idempotency and audit.
- Integration: metadata-only `smail.sent` outbox fact; File 19 transport remains external.
- Privacy: export and erasure; draft ciphertext is destroyed on deletion/erasure.

## Verified private-transfer implementation

- Exact maximum: `1073741824` bytes per file.
- Upload: 1–16 MiB chunks, 8 MiB default, at most 1024 chunks, resumable and idempotent.
- Integrity: required per-chunk SHA-256 plus recomputed whole-file SHA-256.
- Storage: authenticated encryption, mode 0600, private root mode 0700, public WordPress-tree and symlink-resolved web-root rejection.
- Eligibility: current verified sender and every recipient; current suspension/block/conversation/policy state rechecked.
- Validation: server-side MIME/magic allowlist; ZIP/Office archive traversal, entry-count and expansion-ratio limits.
- Malware: `sn_network_transfer_scan_result`; anything except explicit clean remains quarantined or is rejected.
- Download: authenticated recipient/version-bound signed grant, ten-minute TTL, no permanent public URL, no-store/nosniff, byte-range resume.
- Lifecycle: revoke, expiry cleanup, retention, metadata-only events/notifications, audit, export and erasure.

## Review suites

- Smail static contracts.
- Smail fresh/adversarial contracts.
- Transfer static contracts.
- Transfer fresh/adversarial contracts.
- Existing 33 File-17 suites remain explicitly enumerated, producing 37 total suites.

## Truth boundary

Source completion is a code-complete candidate. Production acceptance still requires exact-head CI, installable artifact verification, Hostinger staging, real File 00/02/19/20/24/25/CF-04 integrations, approved scanner, backup/restore, rollback, browser/device/accessibility/security/load acceptance and Founder sign-off.
