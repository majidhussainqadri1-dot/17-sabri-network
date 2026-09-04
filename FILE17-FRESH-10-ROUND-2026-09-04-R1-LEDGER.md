# File 17 — Fresh 10-Round Review — Round 1 Frozen Ledger

**Round:** 1  
**Reviewed parent:** `a271cbf0c5963a5bfacd8b7234d982fbdbcff64d`  
**Scope:** bootstrap/load order, activation, migration governor, installer coverage, schema verification, version rollback truth, event-delivery and module schema publication.  
**Discipline:** the Round-1 review was completed before any correction began.

## Frozen defects

### R1-D01 — Migration state can be left permanently `running` on the post-lock fast-path

`SN_Fifth_Fresh_Migration_Hardening::upgrade()` writes `sn_migration_state=status:running` immediately after acquiring the global schema lock. It then performs a second current-version/schema check inside `try` and returns `true` directly if another request completed the migration while this request was waiting for the lock. `finally` releases the lock, but that early return does not republish a completed migration state, leaving operational migration truth falsely stuck at `running`.

**Severity:** High operational/migration-truth defect.

**Required correction:** before the inside-lock fast-path returns, publish an explicit verified `complete` state (or preserve an already-complete state) and add a permanent regression assertion.

### R1-D02 — Migration rollback snapshot omits the actual Messages receipt schema-version option

`SN_Messages::install()` publishes `sn_message_receipts_schema_version`, but `SN_Fifth_Fresh_Migration_Hardening::version_snapshot()` tracks `sn_messages_schema_version` instead. If a later governed installer fails, the rollback restores other version markers but can leave the receipts marker advanced even though the overall migration failed.

**Severity:** High schema-version truth / retry-safety defect.

**Required correction:** snapshot and restore `sn_message_receipts_schema_version` as the actual installer-owned key; retain any legacy key only if independently required. Add a permanent regression assertion.

## Correction gate

No Round-2 review may begin until both defects are corrected, permanent regressions are added, and exact-head CI passes on both required PHP jobs.
