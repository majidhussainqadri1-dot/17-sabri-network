# File17 V12.3 — Review G: exact File19 notification acceptance contract
Prepared 2026-11-15. **Frozen before File17 adapter correction.**

## Source boundaries and provenance
- Governing V12.3: F17-FUT-07, F17-CEN-01, CEN-REL-001, CEN-STATUS-001; File19 owns notification delivery; File17 owns reminder state.
- File17 candidate starting point: PR #25 branch `review/file17-v123-reminders-20261115`, green after review F at source SHA `a600e0d6cf1dfd32b717204b44fdff999a2af7ee`.
- File19 separate repository source HEAD: `majidhussainqadri1-dot/19-sabri-unified-notifications` SHA **`3cd8f01a4614e96c0104d0ff82faa4ad3f02f4e4`** (source, not deployed).
- File19 primary sources: `docs/CONTRACTS.md`, `includes/functions.php`, `class-sun-plugin.php`, `class-sun-notification-service.php`, `class-sun-event-validator.php`, `class-sun-producer-registry.php`, `class-sun-policy-engine.php`, `class-sun-activator.php`.

## Reproduced effective contract gap G-01
- File17 `SN_Future_Superset::cleanup()` uses `do_action('sn_network_notification_requested', ...)` and decides that a reminder has fired when an action listener exists. WordPress action callbacks have no return-ack contract. File17 cannot establish from this action that File19 **durably accepted** a versioned notification fact.
- File19 boot in `class-sun-plugin.php` does **not** advertise the above `sn_network_notification_requested` hook. Its documented PHP ingestion interface is `sun_ingest_domain_event(array $event)`; its producer registry is `sun_register_notification_producer($key,$contract)`.
- File19 `SUN_Event_Validator` mandates `producer`, `owner`, `event_id`, `event_type`, `schema_version`, `occurred_at`, `recipients`. It requires a matching registered producer/owner, an authorized event pattern and validates data allowlist. File19 default policy database includes `Communication.*` under category `messages`. Its `SUN_Notification_Service::ingest_event()` returns a durable processing receipt `status=processed` or an idempotent `status=duplicate`; otherwise it returns an error.
- Thus an **explicit File17 adapter** is required to reach the canonical File19 API and test the **returned receipt**, not a listener-presence heuristic. This source interface is proven; its deployed availability is NOT.

## Bounded File17-only corrective design
1. Retain the reviewed current-membership/source checks immediately before notification handoff.
2. Fail closed if File19 producer registration or ingestion functions are unavailable.
3. Register strictly `sabri-file17` owned by `File 17`, allowing only `Communication.ReminderDue` v1.0.0; no broad event type wildcard.
4. Send an immutable `f17-reminder-{id}` source event with canonical user ID and **generic private summary only**; do not transfer the homeopathic case, message body, phone, contact graph or arbitrary reminder label.
5. Accept only `processed` or `duplicate` as File19 persisted intake acknowledgements. On error/missing status, keep source reminder retryable with the same stable producer/event id.
6. Never interpret File19 **accepted intake** as a delivered push/SMS/email, opened notification or externally completed transport. Record delivered state separately at File19.
7. Add runtime negative/positive tests (File19 absent, failed registration, failed intake, malformed response, successful commit, same-id duplicate replay after lost File17 finalization). Preserve source/owner role boundaries and stable event identity.

## Explicit non-goals and release blockers
- No File19 repository code changes or migrations, no host-secret handling, no external push/SMS fallback.
- Real WordPress source/dependency parity, File01/File19 transactions, MySQL race/idempotency, publisher policy, plugin load-order and actual notification rendering must be tested on staging before release.
- File19 source being present on GitHub does **not** prove it is installed or has matching deployed version.
- Live WP/DB/plugin version and checksum remain UNVERIFIED. WPVibe HTTP 530 and Jetpack disconnected are access symptoms, not root causes.

**Counts at freeze:** 1 additional source-level cross-file acceptance defect (G-01); code fix not yet applied. No live deployment.
**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## Review G correction and independent retest
**G-01 corrected in File17's source candidate:** `SN_Future_Superset::ingest_file19_reminder()` uses the **documented File19 PHP entry points** and requires a real producer-specific acknowledgement; legacy `do_action('sn_network_notification_requested')` is no longer treated as an acceptance guarantee. Incoming File19 `WP_Error`, missing functions, registration failure, malformed receipt, missing `event_public_id` or thrown exception keep File17 due state retryable; `processed` / `duplicate` receipt may finalize File17. A File19 acknowledgment does **not** prove end-user delivery.
- Canonical envelope: `sabri-file17` / `File 17` / `Communication.ReminderDue` / `1.0.0` / recipient's canonical ID. Unique producer-scoped event `f17-reminder-{id}` is also the stable `idempotency_key`. No arbitrary label, message body, contact graph, clinical case or attachment is transferred; File19 may render generic in-app copy and apply its own opt-out/policy.
- Existing `tests/v12-3-reminder-dispatch-failclosed-runtime-contracts.php` now exercises **52 checks**: no File19 API even with legacy listener, strict producer scope, safe envelope, processed receipt, duplicate receipt after failed File17 finalization, File19 error/malformed/exception/registration failure and pre-dispatch membership/archived/hidden/deleted/blocked checks.
- Existing `tests/future24-forty-round-corrective-static-contracts.php` now checks the **same stable source event_id and idempotency_key** instead of outdated legacy WordPress action text.
- **Exact new source SHA:** `8f9d333a15b1e9d7de6a60099add2f4643f3a3d9`. GitHub Actions **43669265546**: PHP 8.1 **SUCCESS**, PHP 8.3 full QA/reproducible package **SUCCESS**; 188 PHP files, 10 JavaScript source checks, **67 PHP suites**; File19 runtime bridge mock **52 PASS**; active membership suite **10 PASS**.
- Deterministic **inner installable ZIP SHA-256** at this source SHA: `cd53bca420d658ab78e75c5b3e14ba13a1486a58fb51f7aba9027582fd6f313c`. The GitHub Actions artifact-wrapper digest is different; neither is evidence of deployed bytes.

## Release limits and revised defect ledger
- Exact File19 main source `3cd8f01a4614e96c0104d0ff82faa4ad3f02f4e4` was **read**; its runtime installation and File01 event bus/DB state were **not inspected**. Actual real MySQL processed/duplicate persistence/transaction semantics and notification rendering are staged acceptance requirements.
- **Review G newly found source paths: 1; targeted corrections: 1; automated regression: pass; live verified corrections: 0.**
- Cumulative newly found *candidate-source paths* within PR #25 continuation: Review A–E **11**, Review F **3**, Review G **1** = **15**. Earlier PR #23/#24 **4** distinct paths, hence **19** in total across draft source candidates, **none** is proven a live defect or resolved production change. These are findings/paths, not 19 independent root causes.
- The remaining critical path is **site access + staging actual installed File17/File19/File01 versions and schema**, real provider receipt/readability, two independent complete E2E reviews, backup/restore/rollback, founder approval and exact deployed parity. Do not label `Operational` before those gates.
- No File19 repository modification, no merge to File17 main, no production deployment.
- **Repository HEAD:** main `8ae656e51796d1f05865d8be5dca2480443d79ca`, audit source `8f9d333a15b1e9d7de6a60099add2f4643f3a3d9`.
- **Deployed Version:** UNVERIFIED. **DB Version:** UNVERIFIED. **Migration State:** UNVERIFIED. **Live Verification Status:** UNVERIFIED.
- **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**
