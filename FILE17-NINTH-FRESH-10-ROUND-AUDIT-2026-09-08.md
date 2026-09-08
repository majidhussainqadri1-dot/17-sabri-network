# File 17 — Ninth Fresh 10-Round Audit — 2026-09-08

Baseline reviewed candidate: `f3571551075db417254511b4a7f2c45ff1239ff7` (PR #18 head).

Method: each round is reviewed uninterrupted; findings are frozen here before any correction from that round; only then are fixes/regressions applied and retested before the next round.

## Round 1 — privacy/release-truth review — DEFECTS

Frozen findings before correction:

1. `SN_Message_Visibility` treated malformed message/search result rows with missing or zero canonical message IDs as visible (`$id === 0 || ...`). At a privacy boundary this is fail-open: an unexpected formatter/index row without an ID bypasses the viewer-hidden check. Correct behavior is to emit only rows with a positive canonical message ID that also pass `is_hidden()`.
2. `sabri-network/ARCHITECTURE.md` still described the active candidate as runtime `2.0.2`, while the plugin header and `SN_VERSION` are `2.1.0`. This is stale release-truth documentation and can mislead migration/deployment review.

Ledger frozen before fixes.
