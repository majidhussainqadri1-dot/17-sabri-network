# File 17 — Yet Another Fresh 10-Round Review — Round 1 Ledger Freeze

Branch: `review/file17-yet-another-10-round-2026-09-06`
Reviewed parent: `9e568378deedfdf78a46e6c1da572b6d84ace1cc`
Discipline: the complete Round-1 migration/schema/bootstrap review was completed before any Round-1 correction. This commit is the final frozen ledger; no Round-1 source/test fix preceded it.

## Round 1 review scope

- Plugin bootstrap/activation/deactivation and loader ordering.
- Serialized migration governor, schema verification, installer list and version-state publication.
- Core `SN_DB::install()` data migrations/backfills and legacy OTP retirement path.
- Activation-time storage/page/rewrite sequencing.
- Duplicate init-time migration/version path and post-lock migration state.
- Central-plan plaintext message-body migration used by init/hourly execution.
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

### R1-D02 — version publication is not verified and can reactivate legacy ungoverned schema installers

`SN_Fifth_Fresh_Migration_Hardening::upgrade()` does not verify that `update_option('sn_plugin_version', SN_VERSION, false)` and final migration-state publication actually became durable. `Sabri_Network::init()` also invokes each historical module `maybe_upgrade()` directly and retains a second direct installer/version block guarded only by `sn_plugin_version !== SN_VERSION`. If governor version publication or a module-local schema-version option write fails while schema verification succeeds, later init execution can rerun installers outside the serialized governor and can independently publish plugin-version truth.

Severity: high migration-governance / exact-state defect.

Required correction: make governor version/state publication provable and fail closed on persistence failure; normal init must stop invoking schema installers/version publication directly so the serialized migration governor is the sole schema authority.

### R1-D03 — plaintext message-body migration ignores failure to start its transaction

`SN_Central_Plan_Hardening::migrate_message_bodies()` executes `START TRANSACTION` without checking its return value before `ensure_encrypted_row()` and private-search reindexing. This path runs from init/hourly processing independently of REST transaction guards. If transaction start fails, encrypted-body mutation and search-index mutation can run under autocommit; a later failure/rollback cannot restore atomic plaintext-to-envelope/index state.

Severity: high migration atomicity/search-consistency defect.

Required correction: fail closed before the first migration mutation when transaction start cannot be proven, and retain the existing checked commit/rollback behavior.

## Ledger state

Final frozen ledger before all Round-1 fixes. Round 2 must not begin until all three defects are corrected, permanently regression-protected, and exact-head PHP 8.1 plus PHP 8.3/full-quality CI are green.