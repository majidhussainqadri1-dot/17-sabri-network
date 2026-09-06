# File 17 — Another Fresh 10-Round Review — Round 9 Ledger Freeze

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent: `40652f576ba70bad572c98ae02b8bc87411671e4`
Discipline: the complete Round-9 review scope below was audited before any Round-9 source correction. This file freezes the defects found in that completed review.

## Round 9 review scope

- Background cleanup and scheduled maintenance mutations (`SN_DB::cleanup_expired`).
- Native safety/report retention and the final safety privacy owner (`SN_Safety`, `SN_Safety_Runtime_Hardening`).
- High-risk step-up / dual-control queue and administrator observability (`SN_High_Risk`).
- Transactional event outbox/inbox, queue administration and health truth (`SN_Outbox`).
- Active transaction guards and later route owners (`SN_R6_Transaction_Hardening`, `SN_R9_Runtime_Hardening`) to exclude already-guarded REST mutations before classifying defects.
- Current migration/cleanup registration paths to confirm whether each finding is reachable in the active runtime.

## Frozen defects

### R9-D01 — Expired-update cleanup can run destructive deletes outside a transaction
`SN_DB::cleanup_expired()` calls `START TRANSACTION` without checking its return value and also does not verify `COMMIT`. This is an active hourly cleanup path, not a REST route wrapped by the request-scoped transaction guard. If transaction start fails, update-view deletion and update deletion can execute under autocommit; a later failure can therefore leave only one side removed. A failed commit can likewise be treated as successful before private-byte cleanup begins.

Required correction: fail closed when transaction start fails, verify commit, rollback/audit on either failure, and only perform post-commit private-byte destruction after a confirmed commit.

### R9-D02 — Safety privacy receipt can under-report retained legal-hold evidence after DB read failure
`SN_Safety_Runtime_Hardening::erase_user_report_data()` casts the initial legal-hold `COUNT(*)` directly to int without checking `wpdb->last_error`. A failed authoritative count becomes zero. If later transaction work succeeds, the privacy result can publish `retained=0` even though held report evidence exists.

Required correction: verify the retained-data count read before acquiring/mutating; any DB error must return `failed=true` and retain/retry truth instead of a zero count.

### R9-D03 — High-risk administration can publish a false empty governance queue
`SN_High_Risk::list_actions()` converts a failed `get_results()` read into `[]` and always returns HTTP success. An administrator can therefore see an empty high-risk action queue when governance truth is unavailable.

Required correction: make the authoritative queue read error-aware and return a retryable server error on DB failure; an empty list is valid only after a successful empty read.

### R9-D04 — Outbox administration/health can publish false empty or healthy queue truth
`SN_Outbox::admin_events()` converts a failed queue read to an empty event list. `SN_Outbox::health()` verifies table names but does not verify the status-count reads, so failed count queries can become zero while `ok` remains true. This masks delivery/dead-letter state precisely when operators need fail-closed truth.

Required correction: admin queue reads must fail on DB error, and health must set `ok=false` with an explicit database-read error state whenever any authoritative count query fails.

## Ledger state

Frozen before Round-9 fixes. No Round-9 source correction began before this ledger commit.
