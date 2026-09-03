# File 17 — Next Fresh 10-Round Audit — Round 9 Frozen Ledger

**Round:** 9  
**Reviewed parent:** `785dff5815c9c4cbd0dc789557f6c4e7761fde32`  
**Review discipline:** schema/migration/recovery/package review was completed before this ledger was frozen; no Round-9 correction was started during review.

## Scope reviewed

Reviewed canonical/base schema installation and backfills (`SN_DB`), serialized upgrade governor and version rollback (`SN_Fifth_Fresh_Migration_Hardening`), activation/deactivation/cron lifecycle (`SN_Activator`, `sabri-network.php`), space/presence/message-organization/message-receipt/CF-01/Smail/transfer/search/outbox/Meet/two-plan/Future installer surfaces, communication-key durability/rotation and search rebuild recovery, deterministic packaging (`tools/package.sh`), and exact-head workflow packaging (`.github/workflows/quality.yml`).

## Frozen defect ledger — R9

### R9-D01 — Migration governor can publish plugin/version truth while repository-owned installer tables are still missing

`SN_Fifth_Fresh_Migration_Hardening::upgrade()` correctly serializes the upgrade, snapshots version options and calls every repository-owned installer. It then publishes `sn_plugin_version=SN_VERSION` when `verify_schema()` returns true. However `verify_schema()` covers only a subset of installed schema surfaces.

Concrete omitted runtime tables include, among others, core support tables, `space_bans`/`space_audit`, all message-organization tables, `message_receipts`, `message_search_tokens`, `event_outbox`/`event_inbox`, the five `sn_meet_*` tables, the six two-plan extension tables, and three of the four Future-superset tables. Several installers call `dbDelta()` and then write their schema-version option; a later successful query can leave `$wpdb->last_error` empty even if an earlier table/index creation failed. Because the omitted surfaces are not post-verified, the governor can mark the plugin migration complete and the module's own version option can prevent its later `maybe_upgrade()` from retrying the missing table.

That violates the governor's own contract: publish version truth only after verified repository-owned schema completion.

**Severity:** Critical migration/version-truth and partial-deployment recovery defect.

**Correction boundary:** extend post-install verification to cover every table created by the governed installer list, while retaining critical-column verification for mutation-sensitive tables. Do not rely on module version options or final `$wpdb->last_error` as proof of table creation. Add permanent regression assertions for omitted high-value families (outbox/inbox, Meet, message search/organization/receipts, space governance, two-plan and Future tables), then exact-head CI before Round 10.
