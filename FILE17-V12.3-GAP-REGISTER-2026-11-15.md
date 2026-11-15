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

## Exact-approved-package parity source vulnerability — 15 November 2026

**Verified issue in original read-only helper (not a proven live compromise):** The parity checker compared the deployed plugin to the manifest embedded in an approved ZIP, but did not separately verify that *all ZIP members and their decompressed bytes* matched that same manifest. An archive-only extra payload or a forged listed member could therefore be missed by the archive-check stage, even with pristine installed files. A symlink ZIP member with literal bytes equal to a manifest-listed regular file was also possible.

**Source correction:** `tools/file17-parity-check.sh` now rejects missing/unlisted/duplicate ZIP members; independently hashes each archived member against the approved manifest; checks the manifest contains no duplicate paths; and rejects Unix symbolic-link members. This is a read-only gate: it never unpacks arbitrary ZIP members into the WordPress tree and makes no deployed changes.

**Negative fixture tests:** `tests/v123-live-parity-runtime.sh` now creates (a) an archive-only unlisted extra, (b) a manifest-listed file with forged archive bytes, and (c) a symlink ZIP member with the *same literal bytes* as the approved file. All three must be rejected while a normal deterministic package still passes.

**Accounting:** SRC-07 package attestation gap identified and source-patched; this increment has **seven source/interface/audit findings and one test-fixture defect**. Live verification remains zero.

## Expanded read-only schema evidence — 15 November 2026

`tools/file17-live-freeze.sh` now additionally collects **schema metadata only**, including first-party SN table names, columns and SQL data types, index and indexed-column names, and `INFORMATION_SCHEMA.TABLE_ROWS` *estimates* (clearly marked inaccurate for some InnoDB tables). It does **not** read private message bodies, contact graphs, uploaded media, secret wp-config values or raw application logs. A fixed option inventory includes `sn_plugin_version`, `sn_db_version`, `sn_future_superset_schema_version`, `sn_two_plan_schema_version` and `sn_meet_db_version`.

A source/test pass only proves these diagnostics are coded; **no actual site snapshot has been collected** because the live WordPress connector remains unavailable. Schema columns and row-count estimates are needed to exclude stale migrations or partial deployments *before* asserting an application-code root cause. Runtime logs require a separately authorized, privacy-aware collection process.

The stronger parity helper also verifies the exact proposed ZIP *itself*: every archive path must be in the embedded manifest, all decompressed archive bytes must hash identically, duplicate paths and symbolic-link entries are rejected, and then deployed source files are compared. These negative fixtures run synthetically in the full gate, without using user data.

## Additional audit precision and negative regressions — 15 November 2026

| ID | New verified source/test observation | Resolution in isolated PR | Live proof |
| --- | --- | --- | --- |
| SRC-08 | Freeze schema list used a SQL LIKE pattern in which underscores could match unrelated table names | Replaced the table-name match with an exact fixed-prefix equality on LEFT(TABLE_NAME, CHAR_LENGTH(prefix)); it remains read-only | Not run on live |
| SRC-09 | Per-archive-member SHA-256 loop could skip the last manifest entry when it had no final newline | Changed the read loop to process a nonempty final line regardless of trailing newline; added a forged-last-member negative fixture | Not run on live |
| TEST-02 | First attempted ZIP symlink fixture was malformed by a newline escaping error, caught by exact-commit CI shell syntax check | Replaced the whole test with a clean, deterministic fixture and reran quality checks; follow-up regressions added | Not a live bug |

**Source review total in this increment:** nine distinct source/interface/audit gap identifiers SRC-01–SRC-09, all patched *in the PR*. **Test fixture errors:** two identified and corrected in the PR. **Validated live fixes:** zero. An exact-commit successful GitHub run, not a historical green run, remains the acceptance condition for repository QA; production requires separate staging and exact-live verification.


## 15 November 2026 — Follow-up security implementation and exact-HEAD evidence

**Evidence class:** source/repository findings only; not proven defects of the deployed build. Main branch remains the 8ae656e5 baseline. Isolated implementation PR #27.

### Source defects found and corrected in this branch (six distinct conditions)

1. F17-FUT-04: locked conversations were eligible to disclose sensitive last-message snippets and metadata from the multi-conversation GET listing. The post-dispatch output now redacts private preview/description/title/avatar/unread_count/can_post for locks without current step-up verification.
2. F17-FUT-04: an undecryptable conversation-lock policy was ignored (fail-open). Relevant direct requests now fail closed; a corrupt policy in the listing returns HTTP 503 without private previews.
3. F17-FUT-02/03: device-key change could report success even when the corresponding local audit-log insertion failed. Key and local log now share a transaction; log failure rolls back the key change.
4. F17-FUT-03: concurrent device rotations could compute the same key-log predecessor. A single MySQL advisory lock serializes updates to the global chain, with transaction cleanup and explicit lock release.
5. F17-FUT-01/02/03 and other provider-linked capabilities could be advertised as available based on insufficient readiness evidence. The capability contract now explicitly checks audited provider attributes and fail-closed integration hooks. This is not independent provider certification.
6. F17-FUT-03: the key-history endpoint displayed only the earliest 500 entries with no pagination, so recent changes could be invisible. It now supports bounded keyset pagination and labels the log as local audit evidence, not signed checkpoint/consistency proof.

### Regression proof

- `tests/v123-sensitive-lock-preview-runtime-contracts.php`: locked/unlocked preview redaction, corrupt-policy denial and namespace isolation.
- `tests/v123-device-key-atomic-runtime-contracts.php`: register, no-op retry, failed log rollback, one-event rotation and lock release.
- `tests/v123-provider-availability-runtime-contracts.php`: unavailable until all required provider proofs; loss of proof immediately regates advanced features.
- `tests/v123-key-history-pagination-runtime-contracts.php`: 230 ordered records without omission/duplication, DB error fail-closed, blocked contact and unsigned-proof labeling.
- These tests are invoked by `tools/quality-check.sh` alongside the pre-existing File-17 suites. During development, the new pagination test exposed a defect in its own SQL mock; the mock was fixed and the full exact-commit workflow passed.
- **Verified CI:** commit `82011e2826b7b18b2486f4c2546147d84e2c9ea2`, workflow 43737533452, both PHP 8.1 and PHP 8.3 full quality/deterministic package: SUCCESS. Any later doc commit has to pass its own exact-commit CI.

### Unresolved acceptance and external blockers

- Live deployed package checksum/version, DB version, schema/migration state and runtime evidence have not been obtained. The separate WordPress connector attempts previously returned HTTP 530; root cause remains unknown.
- The real environment still needs actual 1 GiB resumable transfer/scanning, multi-device calls/STUN/TURN/SFU, independently audited E2EE and transparency provider, File 16/private semantic AI approval and erasure, File 00/02/03/08/19/20/24/25/26 interop, real WP/MySQL migration/rollback, and RTL/mobile/accessibility/security/load acceptance.
- New repository fixes are **not merged, not deployed, and not independently verified live**. Live fixes claimed: **0**.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
