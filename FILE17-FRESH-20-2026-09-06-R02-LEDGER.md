# File 17 Fresh 20-Round Review — Round 02 Frozen Ledger

Parent exact HEAD: `d1c0aa210c0a6363f5b3614ecbc81cf858e7a555`
Round-01 exact-head CI: PHP 8.1 PASS; PHP 8.3 PASS.

## Review scope
Authentication/identity authority boundary, contact requests/decisions, direct-conversation creation, follow/unfollow/follow-decision lifecycle, block/unblock safety transitions, pair locking, reconciliation, and final REST route ownership.

## Frozen defects

### R02-D01 — Positive contact/direct-conversation mutations can reuse a stale File-00 assertion cached by the permission callback
`SN_REST::access()` can populate `SN_Membership_Assertions` before the callback. `SN_Relationship_Runtime_Hardening::request_contact()` and direct `create_conversation()` later acquire the relationship lock and call policy, but they do not refresh the cached canonical File-00 assertion at the mutation point. A concurrent File-00 eligibility/suspension change inside the same request can therefore be missed.

### R02-D02 — Follow/follow-accept positive mutations have the same point-of-action assertion staleness window
The final `/users/{id}/follow` and `/follows/{id}` routes remain owned by `SN_REST` → `SN_Relationships`. `SN_Relationships::follow()` and the `accept` branch of `SN_Relationships::decide()` acquire the pair lock but do not clear the request-scoped File-00 assertion cache before the positive transition.

### Safety distinction
Unfollow, follow rejection, contact decline, block and other protective/exit paths are intentionally not made dependent on renewed positive eligibility; they must remain available as safety exits.

## Ledger status
`DEFECT-BEARING — 2 frozen defects.`

No production correction was started until this ledger was frozen.
