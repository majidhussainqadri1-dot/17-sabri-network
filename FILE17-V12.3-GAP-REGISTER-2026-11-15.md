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
