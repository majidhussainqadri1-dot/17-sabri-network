# File 17 — Fresh 20-Round Review — Round 3 Ledger Freeze

Date: 2026-09-06
Reviewed parent HEAD: `256a27589a841dac679b3ac08c7d642eeeb485bf`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before fix

Round 3 completed a fresh whole-candidate review with emphasis on canonical message send/edit/delete/forward ownership, request idempotency, reply/attachment state, message search indexing, search epoch reconstruction, outbox coupling, final route precedence, and post-commit behavior. Existing R1/R2 corrections were included in the reviewed candidate. No code was changed during review.

## Frozen defect ledger

### R3-D01 — search rebuild can lose durable continuation state after destructive reset
Severity: High
Status: PROVED

`SN_Fourth_Fresh_Search_Hardening::rebuild()` and `reconcile_epoch()` truncate the private message-search token table and then publish rebuild/epoch state with unchecked `update_option()` calls. In particular, neither path verifies that `sn_message_search_epoch_rebuilding=true` was durably stored before beginning bounded backfill.

Because `backfill()` is intentionally bounded, a failed rebuild-state publication can leave only the first batch reconstructed. `finish_rebuild()` will then see the rebuild flag as false and return without scheduling continuation. The manual API can consequently report `rebuild_complete=true` even though the token table was destructively reset and only a bounded prefix was rebuilt.

This is not a merely cosmetic option-write issue: the option is the durable state machine that controls continuation after a destructive `TRUNCATE TABLE`.

## Required correction frozen

Both manual and epoch-triggered destructive reset paths must verify durable publication of rebuild state before processing bounded backfill. If state publication cannot be verified, they must record fail-closed rebuild error truth and not claim completion. No successful response may be derived from a missing rebuild flag after a destructive reset.

## Regression requirement

Permanent regression must prove the manual and epoch reset paths verify the rebuild flag after publication and that manual completion cannot be reported from an unverified state transition.

## Freeze statement

No production fix was made during Round 3 review. This ledger freezes the complete Round 3 finding set before correction.
