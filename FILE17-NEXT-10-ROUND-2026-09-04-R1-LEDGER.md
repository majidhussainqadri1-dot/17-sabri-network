# File 17 — Next Fresh 10-Round Review — Round 1 Frozen Ledger

**Round:** 1  
**Reviewed parent:** `537dd265ea21141308a9fb2f3f59b0efe6806f39`  
**Scope:** bootstrap/init ordering, governed migration ownership, installer coverage, schema verification and rollback-version truth.  
**Discipline:** the entire Round-1 review was completed before any correction began.

## Verified clean areas before freeze

- `SN_Fifth_Fresh_Migration_Hardening::enforce()` is registered at `init` priority `-1000`, so its governed upgrade runs before the base File-17 `init` callback and module-level upgrade hooks.
- The governed path takes a MySQL advisory lock, republishes complete state on the verified post-lock fast path, runs its installer set, verifies schema before publishing `sn_plugin_version`, restores version-option truth on failure and releases the lock in `finally`.
- The base `Sabri_Network::init()` no longer executes its historical direct installer block after the governed migration has successfully published the current plugin version.

## Frozen defects

### R1-D01 — Step-up grant schema is omitted from governed migration verification

`SN_High_Risk::install()` creates both `sn_step_up_grants` and `sn_high_risk_actions`, but `SN_Fifth_Fresh_Migration_Hardening::verify_schema()` verifies only `high_risk_actions`. A missing/corrupt `sn_step_up_grants` table can therefore coexist with `sn_plugin_version=2.1.0` and migration state `complete`, even though high-risk step-up issuance depends on that table.

**Severity:** High migration-completeness / high-risk-security dependency defect.

**Required correction:** require `step_up_grants` in governed table verification and verify its mutation-critical columns.

### R1-D02 — Two-plan idempotency firewall schema is outside the governed migration transaction boundary

`SN_Two_Plan_Contract_Firewall::install()` creates `sn_two_plan_idempotency` and publishes `sn_two_plan_firewall_schema_version`, but that installer is absent from `SN_Fifth_Fresh_Migration_Hardening::installers()`, the table is absent from `verify_schema()`, and the firewall version option is absent from the rollback snapshot. Its independent `init` priority-24 `maybe_upgrade()` runs only after the governed migration has already published File-17 version/migration completion truth.

Consequently File 17 can report the current version and a complete governed migration while the idempotency firewall table is missing or its installer has failed; mutating completion/Future-24 routes then do not have proven persistence for the global request-replay reservation layer.

**Severity:** High migration-completeness / mutation-idempotency defect.

**Required correction:** move the firewall installer into the governed installer set, verify `two_plan_idempotency` table/critical columns, include `sn_two_plan_firewall_schema_version` in rollback version truth, and keep the later module hook only as a harmless already-current fallback.

## Correction gate

No Round-2 review may begin until both frozen defects are corrected, permanent regression coverage passes, and the resulting exact branch HEAD has green PHP 8.1 plus PHP 8.3/full-quality deterministic-package CI.
