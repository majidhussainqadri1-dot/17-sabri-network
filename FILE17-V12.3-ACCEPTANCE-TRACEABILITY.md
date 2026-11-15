# File 17 — V12.3 Clause-Level Acceptance Traceability

**Source of requirements:** Sabri-Website-Master-Plan-V12.3.docx (13 November 2026); File-17 clauses F17-CEN-01/02, F17-FUT-01–24, AJ-19/20/30 and RCD-025/026. This is an implementation and acceptance *map*, not a release certificate.

**Repository baseline:** `main` 8ae656e51796d1f05865d8be5dca2480443d79ca (plugin header 2.1.0). **Implementation branch:** `work/file17-v12-3-completion-audit-20261115`, draft PR #27. The exact latest branch SHA and CI must be re-read before release.

## Future 24 — code mapping and remaining acceptance

| Plan clause | Defined feature | Candidate source files (`sabri-network/includes/class-sn-future-superset-*` unless specified) | Required proof | Current acceptance boundary |
| --- | --- | --- | --- | --- |
| F17-FUT-01 | Audited E2EE Mode | part-1 / future24 hardening | Cross-device audited provider, no plaintext/server authority, downgrade/key rotation | Provider and cryptographic audit pending |
| F17-FUT-02 | Device Key Verification / Safety Numbers | part-1 | Revoked device rejected; changed key invalidates verification; accessible QR/manual comparison | Independent device/crypto staging pending |
| F17-FUT-03 | Key Transparency Log | part-1 / future24 hardening | Signed checkpoints; consistency proof; no contact metadata; fail closed | Independent proof/append-only provider pending |
| F17-FUT-04 | Sensitive Conversation Lock | part-1 pre_dispatch / core | Actual File-00/02 step-up; background resume; notification/deep link and preview suppression | Real auth/device test pending |
| F17-FUT-05 | Delegated / Shared Team Inbox | part-1 get_team_inbox/set_team_inbox | Canonical conversation storage; manager scoping; revoked staff access | Versioned atomic source change in PR; real DB/cross-file test pending |
| F17-FUT-06 | Conversation Assignment & Handoff | part-1 set_team_inbox/handoff | CAS expected_version; two-record atomicity; closed conversation and revoked actor | New isolated PHP test passed in earlier branch commit; staging race test pending |
| F17-FUT-07 | Snooze / Remind Me Later | part-1 / superset cleanup | Cancel/reschedule; timezone-safe due; File-19 consumer and idempotency; revoked access checked | New isolated PHP test passed in earlier branch commit; File19 live delivery and DST pending |
| F17-FUT-08 | Saved Replies & Professional Templates | part-1 save_template/preview_template | Allowlisted variables; owner-only preview; clinical approval; no auto-send; search/favorites/history | Preview candidate coded; team/search/version workflows and staging pending |
| F17-FUT-09 | Advanced Message Version History | part-2 capture_message_version | Edit race, unsend, retention, erasure, historical-body visibility | Real WP/database lifecycle verification pending |
| F17-FUT-10 | Bulk Conversation Operations | part-2 bulk_conversations/process_bulk_jobs | ≤100 operations, per-item current authorization, cancellation, partial report/retry | New UI exists; staging concurrency and mixed-role proof pending |
| F17-FUT-11 | Saved Searches / Smart Private Views | part-2 and review-hardening-a | Revoked owner access, injected query, no File-26 global leak | Real membership-change and private index tests pending |
| F17-FUT-12 | Expiring QR Community Invitations | part-2 and review-hardening-a | Token signing, expiry, max-use, inviter revoke, minors, group capacity | Real replay/role/expiry tests pending |
| F17-FUT-13 | Temporary Scoped Membership | part-2 and review-hardening-b | Scope/expiry, guardian and sponsor, read/write/call/search recheck | Stale-cached membership and expiry worker test pending |
| F17-FUT-14 | Mentor–Student Communication Mode | part-2 and review-hardening-b | Mentor qualification, minor guardian, block/report/handoff, academic authority File05 | Cross-file File05/identity/guardian tests pending |
| F17-FUT-15 | Scholarly Citation Cards | part-2 and review-hardening-c | Canonical File06/File12 current entitlement; stale/moved/changed edition | Real canonical owner resolvers pending |
| F17-FUT-16 | De-identified Case Discussion Template | part-2 and review-hardening-c | Professional policy, consent, PII/screenshot/media/export rejection | Real clinician role/privacy tests pending |
| F17-FUT-17 | Call Waiting Room / Lobby | part-3 and review-hardening-d | Host admits with membership revalidation, reconnect/timeout/provider | Approved SFU/host provider pending |
| F17-FUT-18 | Hand Raise & Speaker Queue | part-3 and review-hardening-d | Atomic queue ordering, removal, reconnect and moderation rights | Real concurrent WebRTC devices pending |
| F17-FUT-19 | Breakout Rooms | part-3 and review-hardening-e | SFU move/rollback/expiry and user access recheck | Approved SFU and failure simulation pending |
| F17-FUT-20 | Co-host / Host Transfer | part-3 and review-hardening-f | Single authoritative host, CAS/lease, disconnected/revoked hosts | Provider host lifecycle pending |
| F17-FUT-21 | Call Network Quality Assistant | part-3 and review-hardening-g | No raw IP profiling; consented minimal telemetry; real adaptive fallback | Poor-network WebRTC/device experiment pending |
| F17-FUT-22 | Opt-in AI Conversation Assistant | part-3 and review-hardening-g | File16 task authorization, explicit selected-message consent, redaction; no autonomous send; optional approved translation locale | Translation gate candidate added; approved File16/language providers pending |
| F17-FUT-23 | Private Semantic Search | part-3 and review-hardening-g | Per-user consent/index isolation, message erasure, purge confirmation, no File26 corpus | Approved private provider and real deletion test pending |
| F17-FUT-24 | Standards-Based Interoperability Gateway | part-3 and review-hardening-h | Allowlisted protocol/identity, inbound/outbound filtering, rate/kill/replay/rollback | Approved federation adapter and adversarial remote-peer staging pending |

## Additional mandatory File-17 scope

| Plan | Defined behavior | Code owner and state | Outstanding proof |
| --- | --- | --- | --- |
| F17-CEN-01 | One communication backend for Smail, messages, communities, presence, calls and private 1-GiB resumable transfer | Existing plugin modules cover candidate APIs and migration paths; distinct code is not equivalent to provider readiness | Fresh/upgrade staging with real WordPress MySQL, scanner/private storage, revoked recipient, chunk restart and SFU |
| F17-CEN-02 | Sabri Green #087A4E primary, prior orange #FF8A1F not primary | Existing green override and advanced CSS; keyboard focus updates | Actual browser mobile/RTL/contrast/WCAG 2.2 AA and assistive technology |
| RCD-025 | Phone-linked communication truth File 17; no parallel Smail backend | Existing policy and canonical communication paths | Identity compatibility File 00/02, File 03 presentation, File 19 notifications and live contact relay |
| RCD-026 | Minor contact/phone/school/location/clinical privacy | Policy/permission and guardian gates present in code, no public claims | Minor role matrix, guardian denial, private contact CTA, blocked/consent revoked browser and REST checks |
| AJ-19 | Smail 7 folders | Code candidate | Real user Inbox/Sent/Drafts/Starred/Archive/Spam/Trash transitions and rollback |
| AJ-20 | Unknown message request accept/decline/report and block/mute/rate-limit | Code candidate | Real users with roles, cancellation, adversarial rate limiting and moderation |
| AJ-30 | 1-GiB verified chunk/resume/scan/revoke | File17 private transfer candidates | Real 1-GiB transfer with scanner, storage loss/restart, expiry, unauthorized recipient and backpressure |

## Verification constraints

- Main GitHub and exact PR HEAD are repository truths; they do not identify the WordPress deployed package, deployed file SHA-256, DB/schema version or migration state.
- A green CI proves only the assertions in that exact run against that exact commit. Isolated PHP mocks are not production MySQL concurrency or WordPress integration evidence.
- Existing provider-gated features are **not** automatically considered complete merely because the REST route, form, or a synthetic test exists.
- Do not merge/deploy until independent staging acceptance. Verify: active plugin/PHP/WP, deployed checksum, DB tables/options/migration, backups, errors, source-repository parity, real role/guardian/provider tests. After deploy re-test and re-check parity.
- WordPress administrative connector attempts to the apex and www host previously returned HTTP 530. This is a connection symptom only, not a proven diagnosis of the underlying hop.

**Live incident truth:** Deployed version UNVERIFIED; DB version UNVERIFIED; migration UNVERIFIED; live verification NOT PERFORMED; proven live fixes ZERO.

Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔
