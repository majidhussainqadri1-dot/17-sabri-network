# File 17 — Fresh 10-Round Review — Round 5 Frozen Ledger

**Round:** 5  
**Reviewed parent:** `831b42e00bbf110a2ea79aa3fc08dde55c249441`  
**Scope:** spaces/community schema and governance, create/update/join/leave, join-request decisions, invitations, member roles/removal, bans, lifecycle, ownership transfer, canonical conversation synchronization, advisory locking, relationship interactions and File-00 target eligibility.  
**Discipline:** the full Round-5 review was completed before any correction began.

## Frozen defects

### R5-D01 — Full migration verification requires a space table that the governed installer never creates

The governed migration verifier added in the prior migration hardening requires logical table `space_audit`. The actual `SN_Spaces` installer obtains its audit table through `SN_Spaces::audit_table()`, whose physical logical name is `space_governance`. Therefore a correctly installed File-17 schema can fail `SN_Fifth_Fresh_Migration_Hardening::verify_schema()` indefinitely because `sn_space_audit` is not the table owned by the installer.

This makes the migration/repair gate fail closed for the wrong reason and can prevent activation/repair from ever reaching a verified complete state.

**Severity:** Critical migration-availability / exact-schema-truth defect.

**Required correction:** make governed verification use the exact installer-owned `space_governance` table name and add a permanent regression preventing drift between the verifier and the spaces audit-table owner.

### R5-D02 — Manager-driven space membership/role transitions can act on a target who no longer has File-00 communication eligibility

`SN_Spaces::join_eligibility()` checks suspension, ban, age safeguards and capacity, but does not require the target's current canonical File-00 assertion to remain `eligible=true` and `can_message=true`. This is material where the target is not the authenticated actor: a manager can accept a pending join request after the target lost communication eligibility. Existing stale members can likewise be promoted or made owner without an explicit point-of-action target eligibility check.

The normal REST permission gate protects the current actor only, so it does not close this target-side gap.

**Severity:** High identity-authority / membership-governance defect.

**Required correction:** add a fail-closed target File-00 communication-eligibility helper and enforce it for membership activation/invitation eligibility and positive role/ownership transitions, while still allowing removal/ban/revocation of an ineligible target. Add permanent regression coverage.

## Correction gate

Round 6 must not begin until both defects are corrected, regression coverage is permanent, and exact-head PHP 8.1 + PHP 8.3 CI passes.
