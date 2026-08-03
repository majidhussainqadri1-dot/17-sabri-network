# File 17 — Sabri Network and Messages

**Plugin version:** 2.0.0  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Status:** 100% coded candidate against the current File 17 specification; not staging-accepted, live-deployed, and not production-operational

File 17 is the canonical communication owner for the Sabri Social Homeopathy Platform. It owns Network relationships, conversations, messages, private attachments, presence, calls/signaling, Sabri Meet, reports, native privacy lifecycle, message receipts, indexed private message search, and communication-event evidence. It does not create a parallel identity, notification, public-profile, clinical, appointment, marketplace, or global-shell backend.

## Governing boundaries

- File 00/File 02 remain identity and authentication authorities.
- File 19 remains the notification center, preferences, channel transport, provider retry, and digest owner. File 17 emits metadata-minimized communication facts through the reliable event contract.
- File 20 owns global navigation and application shell.
- File 24 consumes assurance evidence but does not replace File 17 native enforcement.
- File 25 may render Follow/Connect/Message actions; File 17 authorizes and executes them.

## Implemented 2.0.0 candidate scope

- accepted-contact relationships, follows, blocks, direct/group-style conversations, messages, reactions, read state, edit/delete windows, private attachments, temporary updates, bounded presence/typing, direct calls, reports, appeals, legal/safety holds, privacy export/erasure, rate limits, and audit evidence;
- dedicated `/messages/` and `/messages/{conversation_id}/` surfaces plus File-17-owned Communication Settings;
- native recipient/device delivered/read receipts with bounded contiguous reconciliation and monotonic read-pointer advancement;
- Sabri Meet control plane at `/calls/` and `/calls/{meeting_id}/` with opaque identifiers, schedule/live/end lifecycle, waiting room, host/co-host governance, participant/device ceilings, recipient-scoped signaling, accessible UI, and provider-gated media;
- conversation-local indexed message search with HMAC-hashed tokens, signed viewer/conversation/filter/snapshot cursors, signed bounded context navigation, hidden-state exclusion, and no plaintext-query persistence;
- transactional outbox/inbox delivery with outgoing and incoming idempotency, payload integrity, atomic claims, stale-lock recovery, bounded retry, dead-letter visibility, and optimistic manual retry;
- atomic send/edit/delete/read-delivered mutation boundaries: canonical message/receipt truth, search-index change, and outbox event commit or roll back together;
- complete communities/groups/channels/private-team governance, multi-device presence and revocation, governed mentions/forwarding, pins/stars/folders/hide-for-self, File 08/18/21 context adapters, high-risk step-up/dual control and secret-free STUN/TURN/SFU provider governance.

## Private message search

Search is available only to an active conversation member. Query text is normalized in memory and converted to server-secret HMAC token hashes; the index has no message-body or plaintext-query column. Pagination cursors bind the viewer, conversation, filters, and snapshot and expire after a short interval. Context cursors bind the authorized target and snapshot. Quarantined, moderation-removed, removed, unsent, expired, rejected, and deleted states are excluded before response formatting.

Search is deliberately bounded: 160 query characters, 8 query terms, 128 indexed terms per message, 500-row scan budget, 50-result page ceiling, and 25 messages on either side of a context target.

## Reliable event delivery

File 17 records metadata-only communication events in its transactional outbox. Consumers receive canonical facts through `sn_network_event_dispatched` and explicitly acknowledge them through `sn_network_outbox_delivery_result`.

The outbox strips message bodies, generic content, credentials, tokens, ICE/SDP/candidates, and storage paths. File 19 remains the channel/provider delivery owner. Incoming companion events use producer plus UUIDv4 idempotency and execute their handler transactionally; failures remain operator-visible after rollback.

## Spaces, presence and advanced messages

Communities, groups, channels and private teams use one File-17-owned domain with explicit roles, join requests, invitation consent, succession, lifecycle, bans, slow mode, new-member delay, anti-raid restrictions and conversation synchronization. General presence uses bounded user-scoped keyed device records, short heartbeat TTLs, signed revocation references and privacy-aware aggregate state. Message operations add governed mentions and forwarding, conversation pins, private stars/folders and hide-for-self projections without duplicating message truth.

## Context and conference boundaries

File 08 appointment, File 18 marketplace and File 21 content contexts are opaque, reauthorized pointers. File 17 does not copy companion-domain truth. Conference providers are configured through protected step-up, distinct approval and distinct execution. Provider records contain no credentials; short-lived participant-scoped credentials come only from an approved adapter with fresh health evidence.

Conference media is provider-gated. Recording remains disabled and no audited end-to-end-encryption claim is made. Real provider governance, load/soak, browser/device, accessibility, staging and operational acceptance remain release gates.

## Private files and external controls

Private attachment storage must remain outside the public web root. Document uploads require an approved malware-scanning adapter bound to the file hash/context; an unconditional `clean` response is prohibited. Production calls require approved STUN/TURN/SFU infrastructure, short-lived credentials and provider-health evidence.

Production use also requires HTTPS/security hardening, File 00/File 02 session and MFA controls, backup/restore proof, rollback rehearsal, penetration and load testing, browser/device/RTL/accessibility acceptance, monitoring, incident runbooks and Founder approval.

## Installation

1. Back up database and files.
2. Install the verified ZIP on staging only.
3. Activate the plugin and run **Network → System Check**.
4. Connect canonical identity, notification, private-storage, scanner and approved call-provider contracts.
5. Test fresh install, upgrade, migrations, real roles, minors/guardian policy, search, event retry/dead-letter, privacy, backup/restore, rollback and Safe Mode.
6. Deploy live only after the full Definition of Done and Founder approval.

## Quality commands

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The quality workflow runs inherited File-17 contracts plus four independent completion review-and-fix rounds, followed by syntax, CSS, repository hygiene, exact installable-source checksums and deterministic byte-for-byte packaging.

## Coding completeness

This branch is a **100% coded candidate against the currently approved File 17 specification**. Communities/groups/channels governance, anti-raid controls, general per-device presence and revocation, advanced message organization and governed forwarding/mentions, File 08/18/21 context adapters, high-risk step-up/dual approval and secret-free conference-provider governance are included in the reviewable source.

Coding completion is not operational completion. Real WordPress/MySQL staging, companion contracts, provider credentials and infrastructure, load/soak, penetration, browser/device, accessibility, backup/restore, rollback and Founder acceptance remain external release gates. The repository-level `CODING-COMPLETENESS.md` is the controlling truth record.

## Explicit non-claims

Version 2.0.0 does not claim audited E2EE, an accepted production SFU/TURN service, completed penetration/load testing, staging acceptance, live deployment or operational completion.
