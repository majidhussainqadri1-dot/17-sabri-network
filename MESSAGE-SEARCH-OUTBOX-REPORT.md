# File 17 — Indexed Message Search and Reliable Event Delivery Report

## Scope

This coding batch adds conversation-local indexed message search, signed snapshot/context cursors, transactional outgoing/incoming event persistence and atomic message/search/event mutation boundaries without creating a parallel chat, notification or identity backend.

## Implemented architecture

### Search

- File-17-owned `sn_message_search_tokens` table.
- HMAC-SHA-256 token values; no message body or plaintext query field.
- Active conversation membership checked on every search/context request.
- Query 160 characters, 8 query terms, 128 index terms, 500-row scan budget, 50-result page and 25-before/25-after context limits.
- Viewer, conversation, filters and snapshot bound in signed short-lived cursors.
- Hidden, quarantined, removed, unsent, expired, rejected and deleted states excluded.
- Authorized private-attachment formatting reused; no public storage shortcut.

### Reliable event delivery

- File-17-owned outbox and inbox tables.
- Event UUID and idempotency-key uniqueness with payload integrity hash.
- Metadata-only payload sanitizer blocks message bodies, content, credentials, tokens, ICE/SDP, candidates and storage paths.
- Atomic claim token, stale-lock recovery, bounded retry/backoff, terminal `dead` state and version-checked manual retry.
- Incoming producer/UUID idempotency with transactional handler execution and post-rollback failed-state persistence.
- File 19 remains notification transport owner; consumers receive canonical facts through `sn_network_event_dispatched` and acknowledge through `sn_network_outbox_delivery_result`.

## Review/fix round 1 — comprehensive implementation review

The first review verified route ownership, schema/index contracts, bounds, signed cursor scope, hidden-state exclusion, no plaintext query evidence, exact rebuild confirmation, outgoing/incoming idempotency, worker claims, retry/dead-letter state, atomic message mutation wrappers and accessible UI.

## Review/fix round 2 — fresh adversarial review

The second review targeted replay, stale workers, scope crossing, transaction failures, attachment rollback, edit-event collisions, deletion ordering, receipt pointer/event atomicity, raw device identifiers, dynamic-rendering injection and keyboard/reduced-motion behavior.

### Corrective findings integrated

1. **Incoming failure evidence after rollback:** a failed handler cannot rely on an inbox row created inside the rolled-back transaction. The implementation performs a separate post-rollback upsert that preserves an already processed row and records failed attempts otherwise.
2. **Distinct edit event identity:** event identity includes a hash of the revised body, preventing multiple valid edits of one message from colliding with the first edit event while still making exact retries idempotent.
3. **Receipt event atomicity:** the route override performs receipt and read-pointer writes itself so the receipt event enters the same transaction; nested transaction ambiguity is avoided.
4. **Attachment lifecycle:** a newly uploaded attachment is removed after a send transaction rolls back; message deletion removes private bytes only after the deletion/search/outbox transaction commits.
5. **Completion truth regression:** the prior completeness test still required message search and reliable delivery to remain listed as missing. It now requires implementation evidence for those domains while continuing to require disclosure of genuinely incomplete spaces, general presence, advanced message operations and operational acceptance.

## Truthful boundary

The batch is coded and testable, not staging-accepted, live-deployed or operational. Green included checks do not establish provider integrations, real traffic behavior, backup/restore, load/soak, browser/device acceptance, penetration testing or production monitoring.
