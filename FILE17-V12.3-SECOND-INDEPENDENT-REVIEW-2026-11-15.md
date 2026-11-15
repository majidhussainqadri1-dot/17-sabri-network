# File 17 V12.3 — Second independent source review (2026-11-15)

## Authority / source and bounds
- Governing attachment: `Sabri-Website-Master-Plan-V12.3.docx`, particularly File17 paragraphs 255–258, QA 541–549 and F17-FUT-07 paragraphs 1904; latest supersedes historical source/PR contracts when in conflict.
- Review source SHA: `d050c720d5945c9f2d58545751070ed41413fa9d` (unmerged PR #24), with `main` separately at `8ae656e51796d1f05865d8be5dca2480443d79ca`.
- **Not a live incident diagnosis:** Jetpack marked site disconnected and WPVibe had no connected site. Deployed plugin/DB/schema/migrations/options/logs/versions remain unverified. No production writes authorized.
- File17 owns communication and reminder state; File19 owns delivery transport. A Reminder must not be accepted or declared dispatched if no authorized recipient/context or no actual dispatch consumer.

## Independent Review A — full findings freeze (before corrections)
| ID | Severity | Reproduced by directly following effective source path | Required correction / regression |
|---|---|---|---|
| F17-V123-R07-01 | P1, false success / privacy boundary | Effective `SN_Future24_Review_Hardening_M::create_reminder()` delegates to `SN_Future_Superset::create_reminder()`, which accepts conversation ID zero because it tests `if ($c && !member)` and persists an orphan reminder; it also accepts arbitrary message IDs without verifying same conversation, deleted/hidden status or direct sender blocks. Due-time preflight can subsequently cancel this accepted invalid record, but cannot undo false initial HTTP 201. | Require positive active conversation membership, and optional selected message belonging to same conversation, not deleted, not hidden, not blocked. Test invalid + valid creation and no DB writes on rejection. |
| F17-V123-R07-02 | P1, lost dispatch / false final state | `SN_Future_Superset::cleanup()` calls WordPress `do_action('sn_network_notification_requested', ...)` and marks reminder `fired` even with zero registered listeners; `do_action` has no durable acceptance result. With File19 absent, the required actual delivery transport never receives a request despite a terminal state. | At minimum fail closed and retry rather than setting fired when there is no registered notification listener; preserve stable downstream idempotency key. Lack of File19 durable acceptance must remain a staging blocker, not be silently declared repaired. |
| F17-V123-R07-03 | P2, lost user update / ambiguous conflict | `SN_Future24_Review_Hardening_M::reschedule()` and `cancel()` permit absent or zero `expected_version`; they can operate on a state newer than the client's view. `$wpdb` row-version CAS protects server-side race between read/write but does not prove the user reviewed that version. | Require a positive client-provided expected_version on changes to an existing active reminder; refuse stale expected values. Add tests for missing, stale, current version, and terminal states. UI contract review required before activation. |

## False positive control
- A superficial semantic-search visibility concern in superseded `SN_Future_Superset_Part_3::semantic_search` is **not** classified active; the registered priority-3100 owner invokes Future24-G and checks consent/hidden-message visibility.
- Working `SN_Outbox` is not assumed to be File19 notification delivery. We must not invent adapter readiness, webhook acknowledgement or live dispatch.
- WordPress HTTP 530 access failure from earlier task does not establish origin, DNS, Cloudflare rule, plugin or hosting root cause.

## Review A process
1. Freeze all proved source-level defects R07-01..03 before edits.
2. Change only the exact corrective paths, without altering File19 ownership or caller rights.
3. Add deterministic regression coverage to the exhaustive gate, run PHP 8.1 and PHP 8.3 CI on a new immutable head.
4. Then begin an independent **Review B** of concurrency, missing providers, retries, downgrade, migrations, privacy and rollback.
5. Count the **number discovered, fixed, retested, open and blocked**. Only exact-SHA green CI supports automated-QA claim.

## Release acceptance ledger (not already passed)
| Item | Status |
|---|---|
| Repository correction | Pending from this frozen review |
| Exact-head CI on corrected branch | Pending |
| WordPress/MySQL staging installation/upgrade | Blocked — no staging access |
| File19 companion accepted durable notification event / retry | Blocked — external contract unverified |
| Live deployed version/checksum | Unverified |
| DB version/migration/options | Unverified |
| Founder approval, rollout, rollback and live re-test | Pending |
| Operational | **NO** |

Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

## Review A — corrections and exact-source retest (2026-11-15)
- R07-01 fixed in `includes/class-sn-future-superset-part-1.php`, tested in `v12-3-reminder-authorized-create-runtime-contracts.php`: rejects orphan/nonmember/foreign/deleted/hidden/blocked/malformed targets before insert.
- R07-02 partially mitigated in `includes/class-sn-future-superset.php`: absent File19 notification hook now retries instead of marking fired; failed finalization is not treated as successful. `v12-3-reminder-dispatch-failclosed-runtime-contracts.php` verifies missing-listener and retry stability. **Actual File19 durable acceptance is not demonstrated and remains an open integration blocker.**
- R07-03 fixed in `includes/class-sn-future24-review-hardening-m.php`: reschedule and cancel require exact client expected_version; tested in `v12-3-reminder-versioned-mutations-runtime-contracts.php`.
- Regression-discovered UX dependency R07-04 corrected: `SN_Future_Superset_Core::owned()` now includes `version` in list projections so the client can supply a real expected_version; assertion added to the authorized-create suite.
- `main` not changed, no staging/live deployment.
- **Exact correction head `6b09a8561ffdf46ced145eb308caf7027056b74c`**: GitHub Actions run `43664518846`, PHP 8.1 boundary **success**, PHP 8.3 full quality and reproducible package **success**. Current inventory **63 PHP suites, 10 JavaScript entry points**. This is automated-QA evidence only; not a WordPress/MySQL or File19 provider acceptance result.

## Independent Review B — findings freeze before new changes
Review lens: privacy-at-output, mid-request revocation, provider callbacks, stale state, cross-file authority and independent negative cases; focus on `SN_Fifth_Fresh_Knowledge_Hardening` (effective priority-3100 REST override) delegating to `SN_Future24_Review_Hardening_G` rather than auditing superseded legacy handlers.

| ID | Severity | Proved code-path discrepancy | Bounded correction/test |
|---|---|---|---|
| F17-V123-AI-POST-01 | P1, private AI output after revocation | `G::ai_assistant()` validates member, File16 authorization, minor policy and selected message visibility **before** `apply_filters('sn_network_ai_assistant_result',...)`, but after the provider returns it immediately emits the result without a fresh owner/membership/selected-message check. Provider may take long enough for membership/approval or a message to change. | Recheck conversation membership, File16 governance and every selected message's deletion/hidden status **after** provider callback but **before** returning results; refuse stale context, never emit generated content on revoke. Runtime test simulates revocation inside provider. |
| F17-V123-SEM-POST-02 | P1, private search result after mid-projection revoke | `G::semantic_search()` rechecks consent and membership after provider returns but before up to 50 DB projections, then emits accumulated snippets without a final check. A revoke during projection may make the returned private snippets stale. | Revalidate consent and member status immediately before emitting the result, including provenance of current message visibility as needed. Test simulates an authorization change during message retrieval. |
| F17-V123-F19-ACK-OPEN | External integration blocker, **not a new code defect** | An installed `sn_network_notification_requested` action hook is not proof that File19 durably accepted the event or that the delivery transport succeeded. | Require File19 companion/staging acceptance and explicit durable delivery evidence before `Operational` status; do not invent File19 code or claim delivery. |

**Review B count:** 2 proved additional source-level privacy timing gaps; 1 provider-contract blocker. Freeze this finding set before any new code edits. The previous A findings are not recounted.

## Reviews/release controls
Two distinct *source* review passes are documented. They do **not** constitute the V12.3 two complete **independent end-to-end reviews**, because staging, real database, cross-file provider, browser, accessibility, backup/restore and deployed-parity acceptance remain absent. No evidence from a previous successful source SHA may be reused for a later code change.

Mandatory final fields: Repository HEAD / Deployed Version / DB Version / Migration State / Live Verification Status.

## Review B corrections / Review C DST edge-case finding freeze
- B01 and B02 source corrections introduced in `includes/class-sn-future24-review-hardening-g.php`. The regression `v12-3-private-results-post-provider-revocation-runtime-contracts.php` tests mid-provider File16/member/minor/hidden/deleted revocation and mid-projection semantic consent/member/hidden revocation. Exact green 64-suite SHA: `7d4eb8eeb9c9095b6b3ee55621a7d749fcd89279`, GitHub Actions `43664899000` (PHP 8.1 and 8.3 jobs successful). This does not prove companion runtime lock consistency or actual provider.
- Additional V12.3 deliverables: `FILE17-V12.3-26-ID-TRACEABILITY-ACCEPTANCE-MATRIX-2026-11-15.md` and `FILE17-V12.3-DEPLOYED-REALITY-FREEZE-RUNBOOK-2026-11-15.md`. These map all 26 stable File17 IDs to source and staging requirements; no live claim.

**Review C confirmed source concern, before changing code:**
| ID | Severity | Evidence | Required test/correction |
|---|---|---|---|
| F17-V123-R07-DST-05 | P1/P2, wrong scheduled instant | Effective `SN_Future24_Review_Hardening_M::normalize_time()` currently creates `new DateTimeImmutable($value,new DateTimeZone($timezone))` without validating the requested wall time. PHP normalizes the nonexistent `America/New_York 2027-03-14 02:30` to `03:30` silently; the repeated fall-back `2027-11-07 01:30` accepts one offset without disambiguation. V12.3 F17-FUT-07 explicitly requires DST/timezone correctness. | Reject invalid/nonexistent/ambiguous **local wall times** with a clear error; require an explicit UTC offset or Z to select an ambiguous instant. Preserve valid UTC/offset-based requests and normal local wall time. Test next-year real IANA transition windows and dynamic validity. |

The no-listener safeguard for F17-FUT-07 still does **not** prove File19 durable acceptance; that remains an independent integration blocker.
