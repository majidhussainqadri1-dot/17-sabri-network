# File 17 — Another Fresh 10-Round Review — Round 7 Ledger Freeze

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent: `ff5adc9471c44cfba5fe14a9c1e7374f872653fd`
Discipline: the Round-7 review was completed before any Round-7 correction. This file freezes all defects found in the reviewed relationship/realtime scope.

## Round 7 review scope

- Contact, follow, block/unblock and direct-conversation relationship boundaries.
- Canonical relationship lock ownership and reconciliation paths.
- Central `SN_Policy` contact/follow/presence authorization.
- `SN_DB` block/contact/follow relationship helpers.
- Realtime presence aggregate authorization.
- Presence-device heartbeat, revocation and privacy erasure.
- Active-call cleanup triggered by a new block.

## Frozen defects

### R7-D01 — Authoritative block-state database failure can fail open
`SN_DB::is_blocked()` casts the result of `wpdb->get_var()` directly to boolean and does not distinguish a legitimate no-row result from a failed database read. `SN_Policy::can_contact()`, `can_follow()` and `can_view_presence()` depend on that helper. A failed block lookup can therefore be interpreted as “not blocked”, permitting relationship actions or presence disclosure while block truth is unavailable.

Required correction: provide an error-aware authoritative block-state read. Mutating authorization paths must return a retryable error on read failure; boolean-only privacy/display callers must conservatively deny.

### R7-D02 — Blocking can commit while active-call cleanup was skipped after a failed call-ledger read
`SN_Relationship_Runtime_Hardening::end_active_calls_locked()` obtains active call IDs through `get_col(... FOR UPDATE)` without checking database error state. If that read fails and collapses to an empty result, the function returns and the enclosing block transaction may still commit, leaving an active/ringing call alive after the relationship was blocked.

Required correction: an active-call ledger read failure must throw/fail the block transaction; cleanup may be skipped only after a successfully verified empty ledger.

### R7-D03 — Presence-device privacy erasure reports success even when deletion fails
`SN_Presence_Devices::erase_data()` performs `wpdb->delete()` and always returns `done=true`, `items_retained=false`. A database deletion failure can therefore be published as completed privacy erasure.

Required correction: deletion failure must return retryable `done=false`, `items_retained=true`; successful completion may be published only after delete success.

### R7-D04 — Presence-device admission limit can fail open when COUNT truth is unavailable
For a new heartbeat, `SN_Presence_Devices::heartbeat()` casts the active-device COUNT query directly to integer. A database read failure can become zero and allow another device beyond the governed active-device ceiling.

Required correction: verify the COUNT read succeeded before admitting a new device; database failure must return a retryable error.

## Ledger state

Frozen before Round-7 fixes. No Round-7 source correction began before this ledger was committed.
