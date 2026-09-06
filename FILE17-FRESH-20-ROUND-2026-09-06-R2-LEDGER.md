# File 17 — Fresh 20-Round Review — Round 2 Ledger Freeze

Date: 2026-09-06
Branch: `review/file17-fresh-20-round-2026-09-06`
Reviewed parent HEAD: `ae08bb1c7ee3085e0b62cf02df6262291a57c52d`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before fix

Round 2 re-reviewed the whole candidate with the migration/activation/schema-truth lens: installer ownership, activation path, administrator repair path, version writers, migration state publication, schema verification, lock acquisition, rollback snapshot, legacy OTP preservation, plaintext-body migration, and release/test expectations. No fix was started during the review.

## Frozen defect ledger

### R2-D01 — verified pre-lock migration fast path can leave migration-state truth stale
Severity: High
Status at freeze: PROVED

`SN_Fifth_Fresh_Migration_Hardening::upgrade()` has an initial fast path that returns `true` immediately when `sn_plugin_version === SN_VERSION` and `verify_schema()` succeeds. Unlike the later post-lock fast path, this initial path does not republish and verify `sn_migration_state = complete`.

Therefore a site can have a verified current schema/version while `sn_migration_state` remains stale as `running`, `failed`, absent, or otherwise inconsistent. That creates contradictory operational migration truth even though the function reports success.

The post-lock fast path already repairs this condition, so the defect is specifically the earlier pre-lock success return.

## Required correction frozen

The pre-lock verified fast path must durably publish `complete` migration state and verify that publication before returning success. If state publication cannot be verified, the migration API must fail closed with a retryable 503-style `WP_Error`; it must not claim success.

## Regression requirement

Permanent regression must prove both pre-lock and post-lock verified fast paths publish/verify complete state, while the serialized installer/version authority remains unique.

## Freeze statement

No production correction was made during Round 2 review. This ledger is the frozen Round 2 defect set.
