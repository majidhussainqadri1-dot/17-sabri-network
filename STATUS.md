# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.0  
**State:** substantial coded candidate under draft review  
**Coding completion against governing specification:** **NOT 100%**  
**Configured included contract checks:** **693**  
**Installable source checksum coverage:** generated from the exact package source tree  
**Staging/live/operational:** not completed

Current implemented candidate scope includes the earlier Network, safety, relationship, realtime, Sabri Meet, dedicated Messages/settings and recipient/device receipt work, plus indexed conversation-local message search and reliable transactional event delivery.

The search domain uses File-17-owned hashed tokens, active-member authorization, bounded query/term/scan/result/context limits, signed short-lived viewer/conversation/filter/snapshot cursors, hidden-state exclusion and no plaintext query persistence. The event domain uses metadata-only outbox/inbox records, payload integrity hashes, idempotency, atomic claims, stale-lock recovery, bounded retries, dead-letter visibility and optimistic manual retry.

Message send/edit/delete and delivered/read receipt mutations now commit their message/receipt truth, search-index change and outbox event together. Two independent review/fix suites add 41 comprehensive checks and 38 fresh adversarial checks; the earlier completion-truth suite was also updated to distinguish newly implemented search/event scope from genuine remaining gaps.

Exact current head, GitHub Actions run, artifact and package hash must be maintained in Pull Request #2 after current-head CI, because embedding them in source would create a self-referential evidence loop.

The authoritative remaining-scope record is `CODING-COMPLETENESS.md`. Major remaining areas are spaces governance, general per-device presence/revocation, advanced message organization, space abuse controls, File 08/18/21 context integrations, production conference-media infrastructure, high-risk dual approval and full staging/operational acceptance.
