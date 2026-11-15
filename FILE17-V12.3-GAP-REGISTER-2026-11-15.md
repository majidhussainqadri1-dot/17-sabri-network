# File 17 — Master Plan V12.3 Gap Register

Date: 2026-11-15. Governing document: Sabri Website Master Plan V12.3. Baseline GitHub main HEAD: 8ae656e51796d1f05865d8be5dca2480443d79ca. Plugin candidate: 2.1.0.

## Evidence boundary

- V12.3 plan pages 42–43: one communication backend for Messages, Smail, Network, Calls, request/consent/block/read receipts and resumable 1GB transfer.
- V12.3 plan pages 184–189: F17-CEN-01/02 and F17-FUT-01–24; source represents 24 named future paths but that does not mean all 24 are provider-ready or usable.
- V12.3 plan page 258: RCD-025 and RCD-026, including minor/private-contact safety.
- V12.3 plan pages 262–263: AJ-19, AJ-20, AJ-30 required actual user journeys.
- CI on baseline exact SHA succeeded 2026-10-19 (run 39201819466): PHP 8.1 and PHP 8.3 full-quality/packaging. This is not current branch or staging/live evidence.

## Conservative code-to-plan mapping

| Plan | Baseline code evidence | Still unverified |
| --- | --- | --- |
| F17-CEN-01 / AJ-19 | Smail seven folders coded | Role, idempotency, multi-device and DB migration staging tests pending |
| F17-CEN-01 / AJ-20 | Contact requests and messages coded | Real accept/decline/report/block/revoke journey pending |
| F17-CEN-01 / AJ-30 | Resumable private 1GiB transfer code and tests | Real scanner/storage/restart/quarantine/revoke staging pending |
| F17-CEN-01 calls | Sabri Meet and STUN/TURN/SFU governance coded | Approved provider, signaling, multi-device QoS and SFU acceptance pending |
| F17-CEN-02 | Exact green #087A4E CSS override | Actual mobile/RTL/keyboard/contrast/accessibility testing pending |
| F17-FUT-01–03 | E2EE/key safety number/transparency handlers represented | Audited E2EE and key-validation provider absent/unverified |
| F17-FUT-04–16 | API contracts and feature candidates | Each UX, minor, access-revocation and workflow test pending |
| F17-FUT-17–20 | Call lobby/raise/breakout/host-transfer candidates | Approved SFU integration/provider state pending |
| F17-FUT-21 | Network-quality REST candidate | Real packet-loss/jitter and fallback proof pending |
| F17-FUT-22–24 | AI, private semantic and interop candidate | Consent/provider/corpus-erasure/interop tests pending |
| RCD-025/026 | Canonical contact relay and privacy contracts | Phone-linked eligibility and minor privacy integration pending |

## Documented gaps and completion scope

1. No requirement-by-requirement V12.3 acceptance certificate exists for the newer 13-Nov plan.
2. Advanced Communication workspace previously exposed only 3 forms while listing 24 capabilities. An API inventory is not complete user interaction.
3. Root MANIFEST.md version was old (2.0.0) despite plugin version 2.1.0.
4. Packaged QA-INVENTORY.txt references historical fifth-cycle inventory; any repair requires SHA-256 manifest update.
5. Prior STATUS.md did not reflect verified 19-Oct exact-HEAD successful run.

## Completion gates

1. Verify active plugin/runtime build, deployed checksum, WordPress/PHP/MySQL versions, DB tables, wp_options and migration state, logs and dependency configuration before calling a live defect. If deployed parity differs from repo: stop for deployment parity audit.
2. Finish V12.3 clause traceability, update code and frontend only for proven source gaps; retain canonical authority boundaries and safe provider gating.
3. Add regression/negative tests; run exact branch CI, static/security/UI/RTL/a11y/lint, checksum and deterministic packaging.
4. Execute actual WordPress staging fresh-install/upgrade/race/rollback tests and companion/provider tests with user roles, minors, private transfers, call media, message retention, replay and consent.
5. Obtain staging sign-off; deploy exact built artifact; freeze live version/DB/migration/checksum again and re-test; only then mark live resolved.

## Truth ledger

| Field | Current evidence |
| --- | --- |
| Repository main HEAD | 8ae656e51796d1f05865d8be5dca2480443d79ca |
| Repository plugin version | 2.1.0 |
| Deployed version | Unverified |
| DB version | Unverified |
| Migration state | Unverified |
| Live verification | Not performed |
| Proven live issues fixed | 0 claimed |

Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

## Implementation performed in this isolated branch

- Corrected historical root MANIFEST.md version heading to 2.1.0, with explicit notice that its enumerated list is historical.
- Updated STATUS.md and CODING-COMPLETENESS.md to acknowledge the 19 October exact-main-head successful workflow while preserving the unverified live/staging boundary.
- Corrected packaged QA-INVENTORY.txt wording; source manifest is produced afresh by deterministic package job.
- Added four direct advanced workspace forms: saved private views (F17-FUT-11), manager-controlled team inbox (F17-FUT-05/06), scholarly citation references (F17-FUT-15), and mentor-student invitation (F17-FUT-14). Existing canonical endpoints enforce server-side authorization, roles and guardian policy.
- Corrected checkbox geometry and select keyboard-focus affordances in advanced workspace; added static regression assertions in the existing suite.
- This work does NOT close the full twenty-four-feature experience or provider acceptance; each branch commit requires its own exact-commit CI and staged browser validation.

## Review defect accounting at this branch

- Five initial gap **categories** were documented (not five proven live runtime defects).
- Three documentation/status categories have proposed repository corrections (root manifest version, QA inventory, baseline CI status).
- Two categories remain only partially addressed: exhaustive V12.3 clause-level acceptance and user-facing completeness across all 24 Future capabilities.
- One additional CSS control-size defect was corrected in source; browser-level verification remains pending.
- Live defects fixed and independently verified: **zero claimed**.

## V12.3 implementation cycle II — 15 November 2026
- **Total rendered actionable forms: 27** (3 original + 4 first cycle + 20 second cycle). This counts controls, not compliance with all 24 features or completed clinical/provider integrations.
- **Source modifications:** advanced JS with progressive disclosure, safe text-only readouts, separate feedback that preserves capability inventory, private invitation output, bounded/confirmed batch operation and status/cancellation, managed community invitations and temporary membership, mentor lifecycle, de-identified case discussion, hand-raise and host lobby request, explicit AI consent, private semantic consent/withdrawal, authorized personal item listing/revocation. CSS for accessible focus/checkbox, responsive disclosure, readout.
- **Automated checks:** 27-form Node DOM/fetch runtime test, no-consent early refusal, route/nonce/idempotency contract, batch preview and destructive confirmation, escaped provider and feature response, no automatic retry on 503 refusal; extended PHP static assertions; 53 existing PHP suites retained.
- **Not verified:** actual E2EE, proof transparency, audited device cryptography, approved AI/semantic/index/purge providers, SFU/TURN, clinical eligibility owner adapters, cross-file integration, real staging DB migration and private 1 GiB upload and true live deployment parity.
- **Live administration blocked:** connection attempts to both https://www.sabrihomeopathy.com and https://sabrihomeopathy.com returned HTTP 530 via the WordPress connector. This response does not establish the failing origin/hop or root cause; no live code, schema or firewall change was made.
- **Important:** old section calling out *only three advanced UI forms* is a baseline historical observation and is superseded **for source code only** by the 27-control inventory above. All separate V12.3 acceptance obligations remain open until exercised in a real environment.


## Current supplemental source evidence — V12.3 pass III (15 November 2026)

- **33** UI controls present in current PR source (the earlier 27-control count is a historical mid-review snapshot, not the final source inventory).
- **57** PHP suites in explicit quality gate (53 older + four new V12.3 runtime tests); one new Node DOM/fetch UI test and nine JS syntax entry checks.
- **Proved source-level design issues addressed in current PR:** partial shared team assignment on second-record failure / absent CAS; missing reminder cancel/reschedule and stale-access checks; absent saved-reply safe preview; unsupported translation task/locale gate; capability list overwritten by action feedback; missing native input geometry and keyboard styling; numeric-only conversation selection without contextual suggestions. These are source gaps, not demonstrated incidents in deployed website.
- **New parity utility:** `sabri-network/tools/file17-live-freeze.sh`, read-only; it must be run only against the authorized exact WordPress root, then its manifest hash reconciled with the tested ZIP. Merely adding the tool does not freeze the real website.
- **Per-clause map:** `FILE17-V12.3-ACCEPTANCE-TRACEABILITY.md` is now the governing current mapping for F17-CEN, RCD, AJ and F17-FUT acceptance gaps. Both CI and owner/prod acceptance are still distinct.
- **Unverified**: true E2EE, independent key transparency, SFU media, File16 language provider, real File19 notification queue, real scanner/private storage, WordPress database/migration, browser/mobile/RTL/a11y and live sign-off.
- **Verified live defects resolved**: NONE claimed.

## Seventh source-review defect accounting — 15 November 2026

This is **source-code / test-coverage** accounting. It does not classify failures in the deployed site.

| ID | Independently observed repository gap or defect | Source action | Repository state | Live confirmation |
| --- | --- | --- | --- | --- |
| SRC-01 | FUT-17/18/21 had no current-state readout or quality telemetry-consent form in the advanced workspace | Added three governed REST UI paths and Node/static negative tests | Patched; exact new HEAD CI required | Not verified |
| SRC-02 | High jitter and very low bandwidth were ignored in call quality grading | Added shared conservative grading, advisory thresholds and isolated PHP tests | Patched; exact new HEAD CI required | Not verified |
| SRC-03 | Bandwidth-only degradation incorrectly suggested ICE retry | Limited ICE-retry advice to severe jitter/RTT/loss | Patched; exact new HEAD CI required | Not verified |
| SRC-04 | Four zero-valued UI metric defaults permitted a misleading grade with no observed measurements | Removed defaults, retained required fields and regression | Patched; exact new HEAD CI required | Not verified |
| SRC-05 | REST-quality values were silently clamped; missing, non-numeric and invalid values could be misclassified | Added strict finite/range/whole-value server validation | Patched; exact new HEAD CI required | Not verified |
| SRC-06 | Read-only live freeze omitted the two-plan schema state option | Included `sn_two_plan_schema_version` and regression check | Patched; exact new HEAD CI required | Cannot run on live connector |
| TEST-01 | New quality PHP test accidentally interpolated a source marker instead of treating it literally | Corrected fixture string quoting following actual CI failure | Patched; needs rerun | Not a live bug |

**Source total in this increment:** six identified source/interface/audit gaps + one test-fixture defect. **Claimed live defects resolved:** 0. **Unresolved:** real provider readiness, schema/migrations, staging role and media tests, deployed artifact parity, live re-test and cross-module agreements.
