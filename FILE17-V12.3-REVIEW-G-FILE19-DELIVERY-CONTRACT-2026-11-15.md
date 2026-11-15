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
