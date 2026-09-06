# File 17 — Fresh 20-Round Review — Round 17 Frozen Defect Ledger

Date: 2026-09-06
Review branch: `review/file17-fresh-20-round-2026-09-06`
Reviewed parent HEAD: `1e95bac429ef395c230741390674923b35c1f034`
Review discipline: **complete review → ledger freeze → correction → regression → exact-head CI → next round**.

## Freeze statement

Round 17 was completed as a review-only pass before any Round-17 corrective edit. The review covered the canonical File-00 identity/eligibility projection, File-17 policy boundary, runtime write reauthorization, high-risk step-up/action lifecycle, ownership-transfer consumers, report/legal-hold consumers, safety/retention surfaces, migration verification, and existing adversarial/security contracts. No Round-17 defect was patched before this ledger was frozen.

Observations that were not proven as independent defects — notably the mere presence of `manage_options` in File-17 coarse administrator checks — are deliberately excluded. File 00 itself uses WordPress capability state inside its canonical authorization boundary, and the inspected File-17 transfer mutations still pass through high-risk claim enforcement; therefore those observations were not promoted without stronger evidence.

## R17-D01 — HIGH — Canonical File-00 denial/state can be relaxed by later generic File-17 filters

### Evidence

`SN_Membership_Assertions::register()` installs the canonical File-00 projection callbacks on `sn_network_identity_authority_available`, `sn_network_user_can_access`, `sn_network_user_is_suspended`, `sn_network_user_age_state`, and `sn_network_guardian_consent_valid` at `PHP_INT_MIN`. `SN_Policy` subsequently consumes the *final* value returned by those shared filters as authority.

That ordering means any later callback can convert a canonical negative result into a permissive one — for example unavailable→available, denied→allowed, suspended→not suspended, minor/unknown→adult, or guardian false→true. The comments intend later filters to retain a fail-closed ability, but the current implementation does not enforce monotonic restriction.

File 00 is the platform owner of identity, membership, verification, suspension and age/guardian truth. File-17 policy extensions may further restrict communication, but must not relax File-00 canonical denial or unknown state.

### Root cause

A canonical security assertion was projected into a mutable generic filter chain and then the final filter result was treated as authoritative, instead of first obtaining immutable canonical truth and applying local filters only as deny/tighten overlays.

### Required correction

1. `SN_Policy` must obtain File-00 communication assertions directly through `SN_Membership_Assertions` for action-time authority.
2. Canonical provider unavailability/error must fail closed.
3. Canonical access denial, suspension=true, minor/unknown age, or guardian=false must never be relaxed by a later File-17 filter.
4. Existing extension filters may remain only as monotonic restrictions: they can deny/add suspension/narrow age confidence/revoke guardian permission, never grant beyond the canonical assertion.
5. Legacy metadata heuristics must not become an alternate authority when the required File-00 contract is unavailable.

### Acceptance evidence

A focused regression contract must prove that late permissive callbacks cannot override canonical File-00 denial/state, while restrictive callbacks can still tighten an otherwise eligible canonical assertion.

---

## R17-D02 — CRITICAL — High/critical workflow reaches executable `approved` state after one approver and lacks approver step-up

### Evidence

The governing File-17 security plan classifies ownership transfer, mass moderation, legal hold, report closure and key/provider change as high-risk and requires step-up authentication plus dual approval. The platform security superset distinguishes medium-risk one-approval+step-up from high/critical dual-approval+step-up.

`SN_High_Risk` currently stores a singular `approver_id`. `request_action()` consumes only the requester's step-up grant. `decide_action()` allows one actor distinct from the requester to change `requested` directly to `approved`; no approver step-up grant is consumed. `claim()` then accepts that single `approver_id` and allows an executor distinct from requester and that one approver to execute the mutation.

Consequently the schema and lifecycle cannot represent or prove two distinct approvals, and the approving actor does not itself perform the required step-up ceremony.

### Root cause

The implementation models two-person separation (requester + one approver, followed by executor) rather than the governing high/critical ceremony of requester + two distinct approvals + separated executor, with fresh step-up bound to each authorization act.

### Required correction

1. Upgrade the high-risk schema/lifecycle so two distinct approvers are durably represented and auditable.
2. First valid approval must leave the action non-executable; only the second distinct approval may reach `approved`.
3. Each approving actor must present and atomically consume a purpose-bound, unexpired one-time step-up grant.
4. Requester, approver 1, approver 2 and executor must satisfy separation-of-duty rules; no same-actor shortcut or replay may succeed.
5. `claim()` must require both approvals and exact payload/action binding before execution.
6. Existing pre-upgrade nonterminal single-approved actions must not be silently grandfathered as executable dual-approved actions; migration/compatibility must fail closed and require renewed governance.
7. Migration verification must include the new critical columns/state so same-version fast paths cannot bypass the schema change.

### Acceptance evidence

Regression coverage must prove: one approval cannot be claimed; same approver cannot fill both approvals; requester cannot approve; approvers require valid step-up; wrong-purpose/expired/replayed step-up is denied; executor cannot be requester or either approver; two distinct stepped-up approvals allow exactly one correctly bound claim; legacy nonterminal single-approved records are non-executable after migration.

## Round 17 result

**Defects found: 2 — R17-D01 (HIGH), R17-D02 (CRITICAL).**

No other reviewed Round-17 observation is frozen as a defect. Correction begins only after this ledger commit.