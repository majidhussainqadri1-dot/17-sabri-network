# File 17 — Next Fresh 20-Round Review — Round 1 Frozen Ledger

Branch: `review/file17-next-20-round-2026-09-06`
Reviewed parent: `9e568378deedfdf78a46e6c1da572b6d84ace1cc`
Discipline: the complete Round-1 bootstrap/migration/repair/page-ownership review was completed before any Round-1 correction. This ledger freezes all proved findings before fixes.

## Governing basis

- Definitive Integrated Master Plan v3.0: File 17 is the sole communication owner; implementation truth, migration, rollback and tests must remain separately evidenced.
- File 17 Final Harmonized Master Plan + Future Communication Superset 24: owned pages, safe repair, migration, rollback, exact evidence, and fail-closed dependencies.

## Review scope completed

- plugin bootstrap and late hardening registration chain;
- activation/deactivation and cleanup scheduling;
- serialized schema governor, version publication, legacy File-17 OTP retirement/preservation;
- administrator repair workflow;
- Network, Messages, Communication Settings, Smail and File Transfer owned-page creation/repair;
- private message/transfer storage availability checks and repair truth.

## Frozen defects

### R1-D01 — Owned-page repair can publish success after `wp_update_post()` failed

`SN_Activator::ensure_network_page()`, `SN_Messages::ensure_owned_page()`, `SN_File_Transfer::ensure_page()` and `SN_Smail::ensure_page()` invoke `wp_update_post()` when repairing an existing owned page but do not inspect the return value. They then return the existing page ID as success even when WordPress rejected the update. Activation/repair callers can therefore treat a still-broken or unpublished owned surface as repaired.

Required correction: every existing-page repair must use error-aware `wp_update_post(..., true)`, return `0` on failure, and confirm the resulting owned page is published and contains the expected shortcode before reporting success.

### R1-D02 — Administrator “Complete Repair” omits transfer-storage truth and ignores multiple page-repair results

`SN_Admin::repair_network()` verifies private message storage and the Network page, but it does not verify `SN_File_Transfer::ensure_storage()`. It also calls `SN_Messages::ensure_pages(true)`, `SN_File_Transfer::ensure_page(true)` and `SN_Smail::ensure_page(true)` without checking their return values. The workflow can redirect with `sn_repaired=1` and audit success while File Transfer storage or one or more owned surfaces remain unavailable.

Required correction: fail closed on transfer-storage failure, verify both Messages-owned page IDs and all File Transfer/Smail page repairs, and only publish repair success after all required repair surfaces pass.

### R1-D03 — Legacy File-17 OTP preservation can silently skip preservation on database read failure

`SN_Fifth_Fresh_Migration_Hardening::preserve_legacy_otp_table()` compares `SHOW TABLES LIKE` results without checking database error state. A failed authoritative table-existence read can be interpreted as “legacy table absent,” allowing migration to continue without preserving the legacy OTP table required for rollback evidence.

Required correction: clear/check `$wpdb->last_error` around both existence reads and throw a migration failure if table truth cannot be established.

## Ledger state

Frozen before all Round-1 fixes. Round 2 must not begin until every finding above is corrected, regression-protected, and exact-head PHP 8.1 plus PHP 8.3/full-quality CI is green.
