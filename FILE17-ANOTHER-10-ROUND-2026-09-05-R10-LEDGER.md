# File 17 — Another Fresh 10-Round Review — Round 10 Ledger Freeze

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent: `f33402616b1eb5c1614bb90cbf38c23eaa439da4`
Discipline: the complete Round-10 adversarial release/package/UI/repository-truth review was completed before any Round-10 correction. This ledger freezes the findings before fixes.

## Round 10 review scope

- `.github/workflows/quality.yml`: immutable checkout, PHP 8.1/current-boundary gate, PHP 8.3 full-quality gate, deterministic artifact identity and pinned actions.
- `sabri-network/tools/quality-check.sh`: explicit review-suite inventory, JavaScript/shell/CSS checks, source hygiene, release/package invariants and deterministic double-build behavior.
- `sabri-network/tools/package.sh`: staged-source exclusions, required late runtime surfaces, symlink refusal, manifest verification, normalized metadata and deterministic ZIP construction.
- Root release truth: `README.md`, `STATUS.md`, `CODING-COMPLETENESS.md`, `MANIFEST.md`.
- Plugin release truth: `readme.txt`, `CHANGELOG.md`, `QA-INVENTORY.txt`, `CURRENT-CANDIDATE-BOUNDARY.txt`.
- Bootstrap/runtime surface: `sabri-network.php` and late hardening inclusion/registration chain.
- UI/accessibility ownership: `class-sn-fifth-fresh-ui-hardening.php`, `assets/js/fifth-fresh-ui.js`, `assets/js/two-plan-ui.js`, `assets/js/messages.js` and governed CSS/package surfaces.
- Release-truth regression owners including `fifth-fresh-release-truth-contracts.php`, `forty-round-review-4-release-truth-contracts.php` and retained seventh-fresh/current-cycle contracts.
- Repository tree hygiene after R9: no one-time R9 correction workflow/helper remains in the final reviewed parent.

## Clean areas confirmed

- Exact-head workflow checkout and artifact SHA binding remain correct.
- Deterministic package staging/manifest/symlink protections remain intact and late runtime hardeners are explicit required package surfaces.
- Active File-17 UI surface detection uses registered query vars/owned page IDs rather than the obsolete raw query-string sentinel.
- Active `#sntp-modal` focus trap/restoration selectors remain aligned with the two-plan UI.
- `MANIFEST.md` correctly treats executable staged manifests/checksums as exact-commit evidence and does not hard-code a stale branch/SHA.
- R9 one-time correction helpers/workflows are absent from the reviewed final tree.

## Frozen defects

### R10-D01 — Current release/status documentation still publishes the previous review cycle as current truth
`README.md`, `STATUS.md`, `CODING-COMPLETENESS.md`, plugin `readme.txt`, plugin `CHANGELOG.md` and `CURRENT-CANDIDATE-BOUNDARY.txt` still identify `review/file17-next-10-round-2026-09-04` as the current/latest completed cycle and publish its old round outcome (R5 clean). The actual current source is `review/file17-another-10-round-2026-09-05`; R1–R9 in this cycle are already defect-bearing and R10 is the current frozen review. This violates exact-source/current-repository truth even though runtime code is unaffected.

Required correction: rewrite current release/status surfaces to the current branch/cycle, preserve older cycles only as historical evidence, and publish the final current-cycle round outcome only after this frozen R10 defect is corrected.

### R10-D02 — Release-truth regression contracts and QA inventory are pinned to stale branch/count semantics
`fifth-fresh-release-truth-contracts.php` and `forty-round-review-4-release-truth-contracts.php` explicitly require the previous branch, its R5-clean outcome and the old **54-suite** count. `QA-INVENTORY.txt` and several current docs also publish **54**, while the executable quality inventory already includes the permanent R8 and R9 suites and therefore has **56** PHP suites before the Round-10 regression is added. A current release-truth correction would therefore be rejected by tests designed for yesterday's truth.

Required correction: update permanent release-truth contracts to semantic current-cycle truth, add a dedicated Round-10 regression, register it in the explicit quality inventory, and publish the resulting executable suite count consistently. Historical branch names may remain only where explicitly labelled historical.

## Ledger state

Frozen before all Round-10 fixes. No Round-10 source/document/test correction began before this ledger commit.
