# File 17 — Fresh 20-Round Review — Round 20 Ledger

Date: 2026-09-07
Branch: `review/file17-fresh-20-round-2026-09-06`
Frozen review start HEAD: `e73e9b48e9c6d07861f637038b09bf6b44d09dc0`
Method: complete review -> freeze ledger -> fix all proved defects -> regression -> exact-head CI -> final cycle report.

## Review scope completed before any Round-20 correction

Round 20 was the final whole-repository adversarial closure review. It was completed before any Round-20 correction and covered the current central/File-17 ownership laws, active bootstrap/loader closure, identity/authorization ownership, message/space/call/privacy/search/event boundaries, migration governor, package/manifest determinism, explicit runtime-surface gates, GitHub workflow execution, PHP/JavaScript regression inventory, repository hygiene/TODO-stub markers, current-versus-historical release/status documentation, and repository/main versus review-branch evidence boundaries.

The governing plan requires one canonical File-17 communication backend, distinct Network/Messages experiences, fresh server-side authorization, seven non-equivalent completion statuses, no unsupported E2EE/production claim, and separate staging/live evidence. The current application runtime continues to preserve those ownership boundaries. No new application-domain duplicate backend, local OTP/account authority, public-private-media owner, File-19 notification transport owner, File-26 global-search owner, unsupported E2EE claim, or production-source TODO/FIXME/HACK marker was proved in this round. The R19 exact-head package gate also now explicitly requires every then-active late runtime file.

## Frozen defects

### R20-D01 — HIGH — GitHub minimum-PHP/current-boundary workflow bypasses the warning-fatal canonical regression runner and omits newest critical suites

`.github/workflows/quality.yml` defines its PHP 8.1 `run_test()` helper as direct `php sabri-network/tests/...` execution instead of `tools/run-php-test.php`. It therefore does not inherit the warning/notice/deprecation-fatal behavior established in Round 18. The same minimum-PHP job stops its explicit current-boundary list at R16 and does not execute the R17 canonical-auth/dual-approval or R18 warning-fatal regression suites. In the PHP 8.3 job, the canonical full quality gate is followed by a second direct-`php` subset of suites, reintroducing a weaker parallel test path after the authoritative gate has already run.

Required correction after ledger freeze: make the PHP 8.1 boundary helper execute tests through the canonical warning-fatal runner, include R17/R18 and the final R20 regression, and remove the redundant direct-`php` post-quality subset from PHP 8.3 so one authoritative full gate remains.

### R20-D02 — HIGH — Canonical source quality required-surface inventory omits active late runtime owners

`class-sn-future24-review-hardening.php` actively requires and registers six late runtime layers: `class-sn-next-message-operations-hardening.php`, `class-sn-r6-transaction-hardening.php`, `class-sn-r7-privacy-hardening.php`, `class-sn-r8-interop-finalization-hardening.php`, `class-sn-r9-runtime-hardening.php`, and `class-sn-fresh20-r7-message-read-hardening.php`. The deterministic package gate requires them, but the canonical source `tools/quality-check.sh` `required=(...)` list does not. Incidental PHP linting is not equivalent to proving that a required active owner remains present as a named release/source boundary.

Required correction after ledger freeze: add all six active late runtime owners to the canonical source required-surface inventory and permanently regress the loader/source/package closure.

### R20-D03 — MEDIUM — Obsolete broken write-capable historical workflow remains in repository automation

`.github/workflows/r2-yet-fix.yml` remains tracked with `contents: write`, targets a historical `review/file17-yet-another-10-round-2026-09-06` branch and invokes `.github/r2_yet_patch.py`. That patch script is absent from the current repository tree. This is stale, broken, write-capable corrective automation that no longer belongs in the ongoing canonical repository workflow surface.

Required correction after ledger freeze: delete the obsolete workflow. Preserve its history through Git history/round ledgers rather than leaving executable write automation behind.

### R20-D04 — HIGH — Historical release-truth regressions force obsolete values to masquerade as current repository truth

Root `README.md`, `STATUS.md` and `CODING-COMPLETENESS.md` still present the historical another-fresh branch and 60-suite inventory as current or latest truth; plugin-level current-state files still describe R19 as pending even though exact-head R19 CI is green and R20 has begun. The deeper cause is not prose alone: historical regression suites such as `fifth-fresh-release-truth-contracts.php`, `fifth-fresh-closure-contracts.php` and `seventh-fresh-ten-round-contracts.php` encode the prior branch/60-suite figures using moving terms such as `current`, forcing later documents to preserve obsolete values as if they were current.

Required correction after ledger freeze: preserve the old another-fresh branch, 60-suite count and prior defect-round record only as explicitly historical attribution; remove moving-current semantics from historical regression suites; add a final R20 release-truth regression for the active fresh-20 branch/current executable inventory; then reconcile root and plugin current-status documents. Do not hard-code a self-referential final SHA or claim staging/live acceptance.

## Round-20 disposition at ledger freeze

Defect-bearing round: YES.
Frozen defects: 4 (3 HIGH, 1 MEDIUM).
No Round-20 correction was made before this ledger was frozen.

Round 20 is not complete until every frozen defect is corrected, permanent regression coverage passes, the full exact-current-head PHP 8.1 and PHP 8.3/deterministic-package jobs are green, and the final repository-only evidence report is recorded.

Live boundary remains separate: deployed version, DB/schema version, migration execution state, deployed artifact parity and live verification are not established by this repository review.
