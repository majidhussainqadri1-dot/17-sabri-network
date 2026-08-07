# File 17 — Smail and Verified Private Transfer Completion Report

## Canonical decision

Both capabilities are implemented inside File 17's single communication backend. Smail is an internal mailbox experience over canonical conversations/messages. Private transfer uses File-17 recipients, relationship policy, blocks, verification, audit and retention. Neither creates a duplicate identity, email, chat, notification, public-media or clinical backend.

## Smail

- Canonical route `/smail/`, noindex/no-cache and File-20 route registration.
- Inbox, Sent, Drafts, Starred, Archive, Spam and Trash.
- Sends reuse `SN_REST::create_conversation()` and `SN_REST::send_message()`.
- Minimal message-link projection; message body truth remains canonical File 17.
- Authenticated-encrypted, versioned and owner-scoped drafts.
- Contact/block/minor/suspension policy, rate limits, idempotency and audit.
- Metadata-only `smail.sent` event; File 19 remains delivery owner.
- Privacy export and erasure with draft-ciphertext destruction.

## Verified private file transfer

- Exact maximum `1073741824` bytes per file.
- 1–16 MiB chunks, 8 MiB default, maximum 1024 chunks, resumable and idempotent.
- Required per-chunk SHA-256 and recomputed whole-file SHA-256.
- Authenticated encryption, mode 0600 chunks, mode 0700 private root, web-root and symlink-resolved public-path rejection.
- Current verified sender and every recipient; current suspension, block, membership, consent and policy revalidation.
- Server-side MIME/magic allowlist plus ZIP/Office traversal, entry-count and expansion-ratio safeguards.
- Fail-closed `sn_network_transfer_scan_result`: anything except an explicit clean result remains quarantined or rejected.
- Authenticated recipient/version-bound signed grant, ten-minute lifetime, no permanent public URL, no-store/nosniff and HTTP byte ranges.
- Revocation, expiry cleanup, retention, metadata-only events, audit, privacy export and erasure.

## Quality and truth status

The candidate is code-complete for the current known File-17 scope. Thirty-seven review suites are explicitly enumerated, with PHP 8.1/8.3, JavaScript/shell/CSS, repository-hygiene, exact-manifest and deterministic-package gates. Staging, live deployment and operational acceptance remain separate evidence states.
