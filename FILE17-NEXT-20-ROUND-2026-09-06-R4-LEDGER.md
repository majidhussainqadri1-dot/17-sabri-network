# File 17 — Next Fresh 20-Round Review — Round 4 Frozen Ledger

Branch: `review/file17-next-20-round-2026-09-06`
Reviewed parent: `d6e8b462e00b817aa4bd6d0f7104bb7ec904c7e1`
Discipline: the complete Round-4 message-organization/private-search review was completed before any Round-4 correction. This ledger freezes all proved findings before fixes.

## Review scope completed

- final owners for forwarding, mentions, pin/star/hide, message folders and folder membership;
- hidden-for-self enforcement across message listing, private search, context navigation, reply and advanced bridges;
- private hashed-token search snapshot/cursor/context semantics;
- lossless search rebuild owner and current high-water/error state machine;
- organization list/create/update/delete mutations and current route precedence;
- DB-failure behavior for privacy-sensitive visibility and bounded organization limits.

## Frozen defects

### R4-D01 — Hidden-for-self privacy can fail open when the hidden ledger read fails
`SN_Message_Operations::is_hidden()` casts `get_var()` directly to bool. A database read failure becomes false, and multiple current readers use `!is_hidden(...)` as authorization/visibility truth. Search, context, message-list filtering, reply visibility and advanced bridges can therefore expose content the viewer explicitly hid when the hidden ledger is unavailable.

Required correction: the shared helper must fail closed on database error; uncertainty must be treated as hidden/unavailable, never visible.

### R4-D02 — New private-search snapshot can publish a false empty result on DB failure
`SN_Message_Search::search()` casts the initial `MAX(message.id)` snapshot query directly to int. A failed read can become zero and return a successful empty result with snapshot 0 rather than a retryable search failure.

Required correction: distinguish a successful zero snapshot from a failed authoritative snapshot read and return a retryable server error on DB failure.

### R4-D03 — Search context silently converts neighbor-read failures into partial success
`SN_Message_Search::context()` uses `get_results(...) ?: []` for both before/after context windows. Database failure is therefore indistinguishable from an empty side of the conversation and can publish a misleading partial context as successful.

Required correction: both context-window reads must be explicitly error-aware; any failed neighbor read must fail the context request rather than fabricate an empty side.

### R4-D04 — Message-folder list/limit truth fails open on database read failure
`SN_Message_Operations::list_folders()` converts a failed list read into an empty successful list. `create_folder()` casts a failed authoritative folder-count read to zero, allowing a new folder when the configured maximum cannot be verified.

Required correction: list reads must return a retryable error on DB failure, and folder creation must fail closed before insert when count truth is unavailable.

### R4-D05 — Unpin, unstar and folder-item removal acknowledge success without checking destructive writes
The active organization owner ignores return values from the DELETE used by `unpin`, `unstar`, and folder-item `remove`, then returns success. A failed database mutation can therefore tell the client the preference/organization state was removed when it was not.

Required correction: each destructive mutation must check the delete result and return a retryable/server error on database failure; zero affected rows may remain idempotent success only after a successful query.

## Round verdict

R4 is defect-bearing. Round 5 must not begin until all five findings are corrected, regression-protected, temporary helpers removed, and the exact resulting HEAD has green PHP 8.1 and PHP 8.3/full-quality CI.
