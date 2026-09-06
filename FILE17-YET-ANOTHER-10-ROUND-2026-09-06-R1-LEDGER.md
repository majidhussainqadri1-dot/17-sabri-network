# File 17 — Yet Another Fresh 10-Round Review — Round 1 Ledger Freeze

Branch: `review/file17-yet-another-10-round-2026-09-06`
Reviewed parent: `9e568378deedfdf78a46e6c1da572b6d84ace1cc`
Discipline: the complete Round-1 migration/schema/bootstrap review was completed before any Round-1 correction. This ledger freezes all proved findings before fixes.

## Round 1 review scope

- Plugin bootstrap/activation/deactivation and loader ordering.
- Serialized migration governor, schema verification, installer list and version-state publication.
- Core `SN_DB::install()` data migrations/backfills and legacy OTP retirement path.
- Activation-time storage/page/rewrite sequencing.
- Duplicate init-time migration/version path and post-lock migration state.
- Current exact-head quality/package loader expectations.

## Clean areas confirmed

- The migration governor is loaded before activation use and registers an `init` priority `-1000` enforcement gate.
- Schema verification covers all governed installer tables and critical mutation columns and fails closed on missing schema.
- The governed installer list includes core, high-risk, Spaces, presence devices, message organization, context/CF-01, conference, receipts, transfer, Smail, search, outbox, Meet, Two-Plan and Future-24 owners.
- Activation delegates schema/version publication to the serialized migration governor rather than directly publishing a release version.
- Global migration locking and failure-state publication are present.

## Frozen defects

### R1-D01 — legacy OTP preservation fails open on authoritative table-discovery read failure

`SN_Fifth_Fresh_Migration_Hardening::preserve_legacy_otp_table()` casts each `SHOW TABLES LIKE` result directly into a boolean equality without checking `wpdb->last_error`. A database read failure can therefore be interpreted as “legacy table absent.” The governed installer sequence then invokes `SN_DB::install()`, whose `drop_legacy_otp_table()` executes `DROP TABLE IF EXISTS sn_phone_otps`. If the table really existed but the preservation probe failed, rollback evidence can be destroyed without first creating the retired backup table.

Severity: critical migration/rollback-evidence integrity defect.

Required correction: clear/check DB error state around both discovery reads, reject invalid/unavailable discovery truth, and fail the migration before any installer can retire the legacy table.

### R1-D02 — version publication is not verified and can reactivate the legacy ungoverned init migration path

`SN_Fifth_Fresh_Migration_Hardening::upgrade()` does not verify that `update_option('sn_plugin_version', SN_VERSION, false)` and final migration-state publication actually became durable. The same plugin still retains a second direct installer/version block in `Sabri_Network::init()` guarded only by `sn_plugin_version !== SN_VERSION`. If governor version publication fails while schema verification succeeds, `upgrade()` can return success; later in the same `init` request the stale version makes the legacy block execute installers directly and publish version truth outside the serialized governor.

Severity: high migration-governance / exact-state defect.

Required correction: make version/state publication provable and fail closed on persistence failure, and remove the duplicate direct installer/version publication path from the normal `init()` owner so the serialized migration governor is the sole migration authority.

## Ledger state

Frozen before all Round-1 fixes. Round 2 must not begin until both defects are corrected, permanently regression-protected, and exact-head PHP 8.1 plus PHP 8.3/full-quality CI are green.