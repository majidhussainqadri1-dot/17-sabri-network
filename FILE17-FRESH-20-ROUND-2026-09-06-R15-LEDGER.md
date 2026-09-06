# File 17 — Fresh 20-Round Review — Round 15 Ledger Freeze

Date: 2026-09-06
Review branch: `review/file17-fresh-20-round-2026-09-06`
Review-start exact HEAD: `f0208d92f43e10f1aa228466cd5186a47b5808f8`
Round discipline: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Scope completed before any correction

Round 15 completed a fresh review of File 17 event/outbox/inbox, background jobs, retry/dead-letter behavior, stale-lock recovery, idempotency, provider reconciliation, scheduled operations, notification hand-off, and interoperability uncertain-outcome handling. No coding correction was started while the review was in progress.

Primary implementation evidence reviewed included:

- `sabri-network/includes/class-sn-outbox.php`
- `sabri-network/tests/search-outbox-review-1-static-contracts.php`
- `sabri-network/tests/search-outbox-review-2-adversarial-contracts.php`
- `sabri-network/includes/class-sn-two-plan-contract-firewall.php`
- `sabri-network/includes/class-sn-fourth-fresh-interop-hardening.php`
- `sabri-network/includes/class-sn-future-superset.php`
- `sabri-network/includes/class-sn-two-plan-completion.php`
- `sabri-network/includes/class-sn-runtime-boundary-policy.php`
- File 17 / central-plan event-backbone requirements, including versioned events, schema registry, reliable outbox/inbox, deduplication, retry, dead-letter and reconciliation.

## Confirmed defects

### R15-D01 — HIGH — Outbox delivery acknowledgement is fail-open when no consumer acknowledges

`SN_Outbox::dispatch_one()` currently calls:

```php
$ack = apply_filters('sn_network_outbox_delivery_result', true, $event);
```

The default is `true`. Therefore an event can be marked `delivered` even when there is no acknowledgement provider/consumer attached to `sn_network_outbox_delivery_result`. This contradicts the class's reliable-delivery purpose, its existing static test wording (“explicit acknowledgement contract”), and the governing event-backbone rule that delivery is at-least-once with retry/dead-letter/reconciliation rather than assumed success.

Required correction after ledger freeze:

1. Make acknowledgement fail-closed by default.
2. Preserve retry/dead-letter behavior when no explicit acknowledgement is returned.
3. Add a regression contract proving zero listeners cannot produce delivered state by default.

### R15-D02 — HIGH — Published outbox events do not carry a governed versioned event-schema contract

The governing API/event constitution requires versioned events and a schema registry containing at least owner, version, required/optional fields, privacy class, retention, consumers and deprecation information. The current outbox row/envelope contains event type, aggregate, payload, attempt and timestamps, but has no governed event-schema identity/version metadata and no File-17 event schema registry. Existing event type validation permits unversioned names such as `message.sent` and `file-transfer.ready`.

This leaves File 19 / other consumers unable to prove the producer schema/version from the event envelope and leaves inbound/outbound compatibility dependent on implicit code knowledge rather than an explicit contract.

Required correction after ledger freeze:

1. Add a canonical File-17 event schema registry with an extensibility filter.
2. Give every emitted event a schema version/contract identifier while preserving existing logical event type names for compatibility.
3. Include owner, required/optional payload fields, privacy class, retention, consumers and deprecation metadata in the registry.
4. Reject enqueue of an event type without an approved schema unless an explicit registered extension provides one.
5. Include schema contract/version in the dispatched envelope.
6. Add regression coverage proving schema registry presence, versioned envelope output and fail-closed unknown event types.

## Reviewed areas with no additional confirmed defect in this round

- Outbox unique idempotency keys and race reconciliation.
- Stale processing-lock recovery.
- Bounded exponential retry and dead-letter transition.
- Lock-token guarded finalization.
- Transactional inbox deduplication and handler rollback behavior.
- Scheduled-message canonical idempotency and retry semantics.
- Interoperability provider outcome reconciliation / uncertain-state fail-closed handling.
- File 19 notification ownership hand-off (treated as request hand-off, not File-17 delivery truth).

## Ledger status

**FROZEN. 2 confirmed defects. No Round-15 correction occurred before this ledger freeze.**
