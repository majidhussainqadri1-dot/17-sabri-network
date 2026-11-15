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
