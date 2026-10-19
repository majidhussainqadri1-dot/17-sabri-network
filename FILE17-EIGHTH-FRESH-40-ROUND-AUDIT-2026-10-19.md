# File 17 — Eighth Fresh Forty-Round Plan and Source Audit — 2026-10-19

## Immutable review boundary
- **Main HEAD:** `8ae656e51796d1f05865d8be5dca2480443d79ca` (source-repository truth, not live).
- **Package reviewed before the new correction:** exact preceding PR candidate `b0f9b65f6e9beffbe1bfc68e4fe5b5963a0d8f37`, plugin version `2.1.0`.
- **Documents compared:** Three Central Plans Consolidated Master Plan; File 17 Final Harmonized Plan and Future Communication Superset 24; Files 10, 11, 14, 25, 26 supplemental plans; File00/02 identity, File03 profile, File08/CF01 clinic, File19 notifications, File20 shell, File24 assurance and CF04 media boundaries.
- **Method:** Forty consecutive domain-scoped **static/source inspections**, each performing a complete SHA-256 verification of all **167** packaged-file manifest entries, plus three rotated PHP syntax checks and one rotated JavaScript syntax check per round. Totals **6,680 file digests, 120 PHP lints, 40 JS syntax checks**. These are NOT forty independent whole-system WordPress/MySQL/live end-to-end security examinations.
- **First-round findings:** 33 with no marker alerts, 6 marker alerts requiring semantic adjudication, 1 proven functional defect (round 33); the six token misses are not counted as defects.

## Forty individually accounted reviews
| Round | Governing requirement / examined code surface | Evidentiary conclusion |
|---:|---|---|
| 01 | Product constitution and communication canonical ownership | Further source reviewed; no independent defect proved |
| 02 | Exact plugin version and loader | No additional defect proved in bounded static review |
| 03 | Governed schema installation and verification | No additional defect proved in bounded static review |
| 04 | Zero-partial-install fail-closed activation | No additional defect proved in bounded static review |
| 05 | File00/02 external identity authority | No additional defect proved in bounded static review |
| 06 | File03 public profile privacy | No additional defect proved in bounded static review |
| 07 | Minor/guardian contact policy | No additional defect proved in bounded static review |
| 08 | Contact and follow state | No additional defect proved in bounded static review |
| 09 | Pair membership consistency | No additional defect proved in bounded static review |
| 10 | Abuse/CSRF authorization at REST boundary | No additional defect proved in bounded static review |
| 11 | Direct/group/channel space lifecycle | Further source reviewed; no independent defect proved |
| 12 | Invitations and join requests | No additional defect proved in bounded static review |
| 13 | Pinned/starred/role actions | No additional defect proved in bounded static review |
| 14 | Canonical message atomic commit | No additional defect proved in bounded static review |
| 15 | Message encryption at rest | No additional defect proved in bounded static review |
| 16 | Forward audience and nonce stability | No additional defect proved in bounded static review |
| 17 | Message search scopes and hiding | No additional defect proved in bounded static review |
| 18 | Private attachment authorization | No additional defect proved in bounded static review |
| 19 | File transfer resumed chunks and scanner | No additional defect proved in bounded static review |
| 20 | Transfer storage containment | No additional defect proved in bounded static review |
| 21 | Device presence privacy and expiry | No additional defect proved in bounded static review |
| 22 | Calls/SFU participant policy | No additional defect proved in bounded static review |
| 23 | Sabri Meet signaling gateway | No additional defect proved in bounded static review |
| 24 | Smail single canonical message backend | No additional defect proved in bounded static review |
| 25 | Report evidence minimization | No additional defect proved in bounded static review |
| 26 | Legal hold + privacy erase | Further source reviewed; no independent defect proved |
| 27 | File19 notification single owner | No additional defect proved in bounded static review |
| 28 | File20 single shell routes | No additional defect proved in bounded static review |
| 29 | File08 and CF01 clinical opaque references | No additional defect proved in bounded static review |
| 30 | File26 global search separation | Further source reviewed; no independent defect proved |
| 31 | Future24 feature count registration | No additional defect proved in bounded static review |
| 32 | Future24 E2EE consent/provider gate | No additional defect proved in bounded static review |
| 33 | Future24 caller-idempotent advanced records | Confirmed: payload-derived fallback made two distinct missing-ID operations share a key |
| 34 | Future24 metadata-only AI boundary | No additional defect proved in bounded static review |
| 35 | File10 live-chat media ownership | Further source reviewed; no independent defect proved |
| 36 | File11 short-video reuse ownership | No additional defect proved in bounded static review |
| 37 | File14 global clinic referral context | No additional defect proved in bounded static review |
| 38 | Public-profile CTA presentation File25 | Further source reviewed; no independent defect proved |
| 39 | RTL/accessible client shell | No additional defect proved in bounded static review |
| 40 | Package inventory/JS + release integrity | No additional defect proved in bounded static review |

## Proven root cause and correction
**F17-FUT24-RETRY-02 — two distinct Future-24 write requests collapsed into one identity.**
- The concrete runtime class `SN_Future_Superset::client()` overrides the similarly named trait method. The **effective class** previously returned `user:purpose:auto-[SHA256 of normalized request payload]` when no valid caller-owned `client_id` was provided. Thus two legitimate separate creates with exactly the same payload but no caller key resolve to the same persistent `client_key`, and the second create may be misrepresented as a duplicate.
- **Reproduction:** isolated execution of the actual PHP class with two different `WP_REST_Request` instances containing the same title/body and no client ID returned identical scoped keys (collision=YES). This is an unintended conflation of **same payload** and **same operation**.
- **Fixed:** the effective class `client()` and inherited helper now reject missing/malformed identity by returning the invalid sentinel; the canonical `create_record()` rejects that sentinel with HTTP 400 `sn_future_client_id_required` before encryption or database I/O. Valid key is scoped by user and operation, and is stable across genuine retry. Seven Future-24 creation paths use this shared helper. Existing browser JS supplies secure `client_id` on mutating requests.
- **Regression:** `sabri-network/tests/eighth-fresh-future-idempotency-runtime-contracts.php` checks empty/invalid IDs, scope normalization, same-key stability, distinct-key separation and early error. Wired into PHP 8.1 boundary and full PHP 8.3 quality/package gates; current test inventory **56 PHP suites and 10 JS entries**.
- **No speculative patch:** historical `SN_Message_Integrity` and legacy forwarding methods contain fallback text but their final route wrappers were separately examined: late registered `SN_Message_Runtime_Hardening` and `SN_Fourth_Fresh_Review_Hardening` already enforce caller keys. Therefore token presence alone was not called another runtime defect.

## Plan and companion repository boundary
This review examines source-level owner declarations and File17 integration seams. It does **not** prove active/real installed companion version parity. Identity remains File00/02, notifications delivery File19, shell File20, clinical context File08/CF01, public profile File25, public/global search File26, media owner File10/CF04, AI File16; File17 owns private conversations/messaging/calls. Provider-dependent E2EE and real SFU/TURN/media are not declared operational without approved adapters.

## Blocking dependencies and status
- WPVibe site discovery returned **zero registered WordPress websites**. Consequently the **exact deployed code/package/checksum, active runtime version, DB/schema version, schema rows, migration options/logs and plugin/provider configuration cannot currently be independently frozen**.
- GitHub main, draft correction PR, staging and live are **four distinct evidence states**. No deployment, merged correction, live PHP execution or live re-test occurred.
- Require connected staging/live access and read-only inventory, deployment-parity audit, real MySQL upgrade/rollback, companion-installed-contract checks, scanner/SFU/TURN, device+RTL/accessibility, authorization/load and real smoke tests before operational closure.
- The review's **new code defects: 1 confirmed, 1 corrected in branch; 6 keyword suspicions dismissed after context; additional fully proved code defects: 0** (not a universal defect-free guarantee). Operational release blockers remain and are explicitly **not resolved**.

## Required status matrix
Repository main HEAD: `8ae656e51796d1f05865d8be5dca2480443d79ca`.  
Correction branch: use exact branch HEAD + its own workflow, not a prior result.  
Deployed Version: **unverified**.  
DB Version: **unverified**.  
Migration State: **unverified**.  
Live Verification Status: **unverified**.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
