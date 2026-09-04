# File 17 — Fresh 10-Round Review — Round 2 Frozen Ledger

**Round:** 2  
**Reviewed parent:** `7455bbab5a5aaa40ec2d6f65fb4c29680a942799`  
**Scope:** canonical File-00 identity assertions, authentication projection, REST/AJAX/admin authorization, relationship runtime overrides, global REST mutation boundary, and administrator repair workflow.  
**Discipline:** review completed before correction.

## Frozen defect

### R2-D01 — Administrator repair bypasses the governed full-schema migration and can publish false plugin-version truth

`SN_Admin::repair_network()` directly calls `SN_DB::install()` and then unconditionally writes `sn_plugin_version = SN_VERSION`. File 17 now has many repository-owned schema installers governed by `SN_Fifth_Fresh_Migration_Hardening`. The repair action can therefore mark the plugin version current while spaces, receipts, transfer, Smail, event outbox, Meet, two-plan, Future-24 or other governed schemas remain missing or stale.

**Severity:** Critical migration/repair truth defect.

**Required correction:** administrator repair must call the serialized full migration governor with forced verification and must not independently publish `sn_plugin_version`. If governed migration fails, repair must fail closed rather than continuing to report success. Add permanent regression coverage and run exact-head CI before Round 3.
