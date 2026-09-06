# File 17 — Fresh 20-Round Review — Round 16 Ledger Freeze

Date: 2026-09-06
Review branch: `review/file17-fresh-20-round-2026-09-06`
Review-start exact HEAD: `35b5dcf155385dfe17933cad038a76bdaaf955c3`
Round discipline: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before correction

Round 16 completed a migration/install/upgrade/rollback/deployability review before any Round-16 product correction. The review covered the serialized migration governor, physical schema verification, activation ordering, installer/version publication, legacy OTP preservation, failure rollback semantics, outbox schema evolution introduced by Round 15, package/install assumptions, and existing migration regression contracts.

Primary evidence reviewed:

- `sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php`
- `sabri-network/includes/class-sn-activator.php`
- `sabri-network/includes/class-sn-db.php`
- `sabri-network/includes/class-sn-outbox.php`
- `sabri-network/includes/class-sn-future24-review-hardening.php`
- `sabri-network/tests/fifth-fresh-migration-contracts.php`
- `sabri-network/tests/fifth-fresh-release-truth-contracts.php`
- `sabri-network/tools/package.sh`
- Round-15 exact-head closure CI run `34037617885` (green on PHP 8.1 and PHP 8.3/full package gate).

## Confirmed defects

### R16-D01 — CRITICAL — Same-version deployed upgrades can bypass the Round-15 outbox schema migration

Round 15 changed `SN_Outbox::SCHEMA_VERSION` to `1.1.0` and added physical columns `event_contract` and `event_schema_version`. Runtime enqueue now writes those columns.

However, `SN_Fifth_Fresh_Migration_Hardening::verify_schema()` still verifies the `event_outbox` critical column set only as:

`event_uuid,event_key,event_type,status,attempts,version`

It does **not** require `event_contract` or `event_schema_version`.

The migration governor's pre-lock fast path returns success when `sn_plugin_version === SN_VERSION` and `verify_schema()` passes. Because File 17 remains plugin version `2.1.0`, a deployed 2.1.0 database lacking the new Round-15 columns can incorrectly be judged current, skip `SN_Outbox::install()`, publish migration state `complete`, and then fail at runtime when enqueue attempts to insert the missing columns.

Required correction after ledger freeze:

1. Add `event_contract` and `event_schema_version` to authoritative outbox physical-schema verification.
2. Add a regression proving the same-plugin-version fast path cannot accept the old outbox column set.
3. Ensure the governed installer path is forced when either column is absent.

### R16-D02 — HIGH — Failed migration does not restore the legacy OTP table name needed for rollback

Before installers run, `preserve_legacy_otp_table()` can rename:

`sn_phone_otps` → `sn_phone_otps_f17_retired`

On later migration failure, the catch path calls only `restore_version_snapshot()`. It does not rename the preserved table back. `SN_DB::install()` also drops only the original `sn_phone_otps` name.

Therefore a failed migration can restore version-option truth while leaving the previous-version OTP storage physically renamed. A rollback to a previous build expecting `sn_phone_otps` can consequently lose its expected table contract even though migration state says the upgrade failed and version truth was restored.

Required correction after ledger freeze:

1. Track whether this migration invocation performed the legacy OTP rename.
2. On migration failure, restore the original table name when safe.
3. Fail closed if rollback restoration itself cannot be proven.
4. Preserve an existing retired backup without destructive overwrite.
5. Add regression coverage for rename → downstream failure → physical-name restoration.

### R16-D03 — HIGH — Activation performs irreversible legacy-secret deletion before the governed migration succeeds

`SN_Activator::activate()` currently runs `retire_legacy_secrets()` before `SN_Fifth_Fresh_Migration_Hardening::upgrade(true)`. `retire_legacy_secrets()` deletes legacy SMS/TURN/OTP-related options immediately and has no snapshot/restore path.

If the governed migration then fails, activation throws, but those pre-migration option deletions have already occurred. This violates rollback-safe activation semantics: a failed upgrade can mutate the prior installation before the migration has committed successfully.

Required correction after ledger freeze:

1. Do not destructively retire legacy secrets before the governed schema migration succeeds.
2. Move retirement to the post-success activation phase, or snapshot/restore the affected options on every activation failure path.
3. Add a regression contract proving migration failure cannot perform pre-commit destructive legacy-option retirement.

## Reviewed areas with no additional confirmed defect in this round

- Global migration serialization via `GET_LOCK` / `RELEASE_LOCK`.
- Post-install physical table verification across governed installer surfaces.
- Version-option snapshot/restore mechanism itself, apart from non-option physical rollback noted above.
- Post-lock fast-path migration-state publication.
- Installer ordering under the central migration governor.
- Activation delegation to the central migration governor rather than a duplicate direct installer chain.
- Deterministic package construction and syntax checks as migration/deployability support evidence.

## Ledger status

**FROZEN. 3 confirmed Round-16 defects. No Round-16 product correction occurred before this ledger freeze.**
