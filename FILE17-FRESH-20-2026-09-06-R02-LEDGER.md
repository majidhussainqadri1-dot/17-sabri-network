# File 17 Fresh 20-Round Review — Round 02 Frozen Ledger

Parent exact HEAD: `d1c0aa210c0a6363f5b3614ecbc81cf858e7a555`
Round-01 exact-head CI: PHP 8.1 PASS; PHP 8.3 PASS.

## Review scope
Authentication/identity authority boundary, contact requests/decisions, direct-conversation creation, follow/unfollow/follow-decision lifecycle, block/unblock safety transitions, pair locking, reconciliation, and final REST route ownership.

## Frozen defects

### R02-D01 — Positive contact/direct-conversation mutations could reuse a stale File-00 assertion cached by the permission callback
`SN_REST::access()` can populate `SN_Membership_Assertions` before the callback. The positive locked mutations did not previously refresh that request-scoped assertion at the mutation point.

### R02-D02 — Follow/follow-accept positive mutations had the same point-of-action assertion staleness window
The final follow/follow-accept paths acquired their pair lock but did not refresh File-00 assertions immediately before the positive policy transition.

### Safety distinction
Unfollow, follow rejection, contact decline, block and other protective/exit paths intentionally remain available without renewed positive eligibility.

## Frozen fix applied
Production correction commit: `087267d567901370252203144af57a6bae796d96`.

The fix refreshes canonical File-00 assertion state only on positive contact/direct-conversation/follow/follow-accept transitions after the relationship lock is held and before the policy decision. Permanent static regression assertions were added to the existing current-boundary suite `seventh-fresh-ten-round-contracts.php`.

## Ledger status
`DEFECT-BEARING — 2 frozen defects; both corrected after ledger freeze.`

This documentation-only commit is the exact Round-02 regression/CI head. Round 03 may begin only after both declared quality jobs pass on this exact head.
