# File 17 — Fresh 10-Round Review — Round 10 Frozen Ledger

**Round:** 10  
**Reviewed parent:** `10437a38ccbe1fafad116f2f3531459ef801f4d6`  
**Scope:** final browser/UI accessibility and surface ownership, packaged release surfaces, deterministic package integrity, exact-head workflow wiring, current QA inventory, release/candidate documentation truth and final-cycle closure.  
**Discipline:** the entire Round-10 review was completed before any correction began.

## Verified clean areas before freeze

- `.github/workflows/quality.yml` checks out the exact immutable event head and runs current boundary contracts on PHP 8.1 plus full quality/deterministic packaging on PHP 8.3.
- `tools/quality-check.sh` enumerates every PHP review suite under `tests/`, requires exact inventory parity, syntax-checks all 10 governed JavaScript entry points, verifies CSS/accessibility baselines and performs deterministic double-build package verification.
- `tools/package.sh` stages only the governed installable tree, rejects symlinks, normalizes permissions/timestamps, lints staged runtime files, generates/verifies the staged `MANIFEST.sha256`, emits a ZIP SHA-256 and produces reproducible package bytes.
- Current explicit inventory remains 54 PHP review suites and 10 JavaScript syntax entry points; no new test file was added during R1–R9, only permanent assertions were expanded inside the existing suite.

## Frozen defects

### R10-D01 — Current release-truth documents still describe an obsolete review cycle/branch

`README.md`, `STATUS.md`, `CODING-COMPLETENESS.md`, `sabri-network/readme.txt`, `sabri-network/CHANGELOG.md` and `sabri-network/CURRENT-CANDIDATE-BOUNDARY.txt` still present the 3-September seventh-fresh cycle and/or `review/file17-next-fresh-10-round-2026-09-03` as the current/latest repository review truth. The actual branch under review is `review/file17-fresh-10-round-2026-09-04`, with R1–R9 already completed on its own exact-head CI chain.

This is a release-truth defect because installable plugin metadata and repository status can describe historical evidence as current evidence.

**Severity:** Medium release-evidence / candidate-truth defect.

**Required correction:** update all current-status surfaces to the 4-September fresh 10-round cycle, preserve the rule that exact CI belongs only to its own SHA, and avoid hard-coding a self-referential final SHA into tracked release docs.

### R10-D02 — Final UI asset ownership trusts a raw query-string sentinel outside canonical rewrite truth

`SN_Fifth_Fresh_UI_Hardening::is_file17_surface()` returns `isset($_GET['sn-network-safe'])` after its owned-page/query-var checks. The canonical Network standalone surface is governed by the registered `sn_network_app` rewrite query variable; arbitrary `?sn-network-safe` on an unrelated page must not be sufficient to classify that request as a File-17 surface.

This can enqueue File-17 brand/accessibility assets outside an owned File-17 page and reintroduces a raw-GET routing heuristic that the repository otherwise removed from standalone route truth.

**Severity:** Medium surface-boundary / presentation-isolation defect.

**Required correction:** remove the raw `$_GET` sentinel and rely only on owned page IDs and registered File-17 query variables.

### R10-D03 — Two-plan modal focus restoration hardening is wired to nonexistent selectors while the active modal drops focus on close

The active `two-plan-ui.js` modal is `#sntp-modal`, closes through `[data-sntp-close]`, traps Tab and closes on Escape, but `closeModal()` removes the dialog without restoring focus to the invoking control.

The later `fifth-fresh-ui.js` accessibility layer attempts to solve modal focus restoration through `#sn-two-plan-modal`, `[data-sn-modal]` and `[data-sn-close-modal]`. Those selectors do not match the active two-plan modal implementation, so the intended restoration code does not protect the actual dialog.

**Severity:** Medium keyboard-accessibility / focus-management defect.

**Required correction:** make the active two-plan modal capture the pre-dialog focused element and restore it on every close path; remove or stop relying on the dead mismatched modal selector logic while retaining the useful search ARIA synchronization.

### R10-D04 — Permanent release-truth regression still pins the prior candidate SHA instead of current-cycle semantics

`seventh-fresh-ten-round-contracts.php` still requires the installable readme to contain historical candidate `f832f7b2d4bb4cf67fc9749e1eb9d3219f5fc0a2`. That assertion makes truthful advancement of the current review cycle conflict with the permanent quality gate.

**Severity:** Medium regression-governance defect.

**Required correction:** replace the historical-SHA requirement with semantic guards for the current 4-September cycle/branch, absence of stale current-branch claims, exact inventory truth, canonical UI surface detection and active modal focus restoration.

## Corrections applied after ledger freeze

- **R10-D01:** reconciled `README.md`, `STATUS.md`, `CODING-COMPLETENESS.md`, installable `readme.txt`, `CHANGELOG.md` and `CURRENT-CANDIDATE-BOUNDARY.txt` to the 4-September branch/cycle. They now record R1–R10 as defect-bearing, preserve the 54-PHP/10-JS executable inventory and explicitly classify prior candidate `f832f7b2...` as historical evidence only. No tracked document claims a self-referential final SHA as current proof.
- **R10-D02:** removed the raw `$_GET['sn-network-safe']` surface heuristic. Final File-17 UI assets now rely on owned page IDs plus canonical registered `sn_network_app`, `sn_messages_app` and `sn_meet_app` query truth.
- **R10-D03:** rebound accessibility hardening to the active `#sntp-modal` / `[data-sntp-close]` implementation, capture the invoking control before the dynamic modal opens, and restore focus after button/backdrop/Escape or programmatic modal removal while retaining message-search ARIA synchronization.
- **R10-D04:** the prior SHA is no longer permitted to function as current release proof. It remains only as explicitly labelled historical evidence so older historical-presence contracts remain compatible, while stronger current-cycle semantic guards in `fifth-fresh-release-truth-contracts.php` require the current branch, all-ten-round result, 54/10 inventory, absence of the stale prior branch, canonical UI surface ownership and active modal focus restoration. This removes the historical pin's ability to block or masquerade as current candidate truth without adding another test file.

## Regression state

No new PHP test file was added, so the executable inventory remains **54 PHP review suites** and **10 JavaScript syntax entry points**. Current-cycle permanent guards were added to the already-governed release-truth suite.

## Final correction gate

All four frozen R10 defects have repository corrections. The cycle reaches repository-reviewed closure only after the resulting exact branch HEAD receives green PHP 8.1 current-boundary CI and green PHP 8.3 full-quality/deterministic-package CI. Exact-head CI is pending for the ledger-inclusive correction HEAD.
