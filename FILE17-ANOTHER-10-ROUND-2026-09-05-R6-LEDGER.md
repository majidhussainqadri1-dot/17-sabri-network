# File 17 — Another Fresh 10-Round Review — Round 6 Ledger Freeze

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent: `e7e9935f82a19bb3ac97f1d5b4234081ddc2d1e2`
Round discipline: Review completed before any Round-6 correction. This ledger freezes the defects found during the completed Round-6 review.

## Round 6 review scope

- Canonical Spaces aggregate and Parts 1–9.
- File-00 communication assertion adapter and central policy boundary.
- Space join/request/invite/member/ownership positive-state transitions.
- Space ban/capacity checks and database-failure semantics.
- Space privacy exporter/eraser completion semantics.
- Conversation synchronization paths used by Spaces.

## Frozen defects

### R6-D01 — Space ban enforcement can fail open on database read failure
`SN_Spaces_Part_8::is_banned()` casts `wpdb->get_var()` directly to bool and never distinguishes a legitimate no-row result from a database read failure. `join_eligibility()` therefore can admit an account when the authoritative active-ban read failed.

Required correction: make the ban check error-aware and require callers at positive membership boundaries to propagate a retryable error rather than treating a failed read as `not banned`.

### R6-D02 — Space member-limit enforcement can fail open on database read failure
`SN_Spaces_Part_8::member_count()` casts `wpdb->get_var()` directly to int. On a failed COUNT read this can become zero, allowing join/invite acceptance to proceed despite the capacity truth being unavailable.

Required correction: make member-count reads error-aware and require `join_eligibility()` to fail closed when capacity cannot be verified.

### R6-D03 — Space privacy erasure can falsely report completion after database read failure
`SN_Spaces_Part_7::erase_data()` treats failed owner/membership/pending-invite/pending-request reads as ordinary empty results. This can return `done=true` even though authoritative rows were not successfully inspected or erased.

Required correction: every authoritative privacy read must detect `wpdb->last_error`/invalid result and return a retryable `done=false`, `items_retained=true` response. Completion probes must also be fail-closed.

## Ledger state

Frozen before fixes. No Round-6 code correction was started until this review and ledger were complete.
