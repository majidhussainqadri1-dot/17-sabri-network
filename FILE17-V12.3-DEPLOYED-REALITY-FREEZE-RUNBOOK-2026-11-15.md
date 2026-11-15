# File 17 — V12.3 deployed-reality freeze and parity runbook
Prepared: 2026-11-15. Owner: File 17. Review: File 24. Release authority: Founder.
**This runbook has not been executed on live or staging. It does not report a live root cause.**

## Governing rule
The V12.3 plan (File17 paragraphs 255–258; release paragraphs 541–562; CEN-REL-001 and CEN-STATUS-001) and the Founder's Live-First Exact-Deployed-State Rule require separate evidence for **live**, **staging** and **repository**. Never equate an immutable GitHub SHA, source ZIP or CI artifact with deployed bytes.

**Current known facts (2026-11-15 verification)**
- Source `main` HEAD: `8ae656e51796d1f05865d8be5dca2480443d79ca`, File17 source plugin header 2.1.0.
- Earlier stacked PRs: #23 `607704de4b36277c04d7b5e12dee324a1d4cae47`; #24 `d050c720d5945c9f2d58545751070ed41413fa9d`; both unmerged draft repository candidates.
- Later second independent source-review branch: `review/file17-v123-reminders-20261115`. Report its final SHA/CI only after frozen.
- WordPress/Jetpack lists site ID `256120819` for `https://www.sabrihomeopathy.com` as `site_disconnected`, so site-scoped read tools cannot currently inspect it.
- WPVibe currently lists **zero** connected sites. A fresh connection attempt for the hostname returned HTTP **530**; this alone cannot determine origin DNS/routing or WordPress/plugin failure.
- Live active version, installed files, checksum, database options/schema/migration/logs, dependent provider registration, staging and live re-tests: **UNVERIFIED**.
- **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## Gate 0 — authorization, scope and read-only evidence
A site operator must first obtain **read-only** host/WordPress or approved plugin access to the **exact** site and record who granted it and when. Preserve live/staging hostnames separately; never send credentials, private message bodies, database dumps, full debug logs, phone/identity records or API secrets in a chat or PR.

Collect these fields **before modifying code or migrating schema**:
- environment and public/canonical hostname; web server/origin/WP/PHP/MySQL versions; WordPress multisite status
- exact plugin active status, plugin folder path, plugin header/version, installed companion/plugin status
- read-only deployed file list and file checksums; build manifest and package ZIP hash when available
- table prefix, table/column inventory, schema versions, migration checkpoint, outbox queue health
- redacted trace IDs/errors + timestamps; scanner/media/SFU/File19 and File00/02 adapter contract versions
- backup timestamp/location, tested restore point and rollback owner; **not** backup contents in a report

## Read-only WP-CLI commands — operator console only
These are example **inspection** commands, not executions performed by ChatGPT. Use each command independently with the correct WordPress path; WPVibe's restricted WP-CLI interface is *not* a shell and may reject these if no site is connected.

```bash
wp --info
wp core version
wp plugin list --status=active --fields=name,status,version --format=table
wp option get sn_plugin_version
wp option get sn_db_version
wp option get sn_meet_db_version
wp option get sn_file_transfer_schema_version
wp option get sn_two_plan_schema_version
wp option get sn_future_superset_schema_version
wp option get sn_event_delivery_schema_version
wp option get sn_migration_state --format=json
wp db prefix
```

Do **not** dump all wp_options: API credentials, sessions, tokens and secrets may live there. Treat a missing option as `MISSING`, not as proof that an installer failed. Compare version *and* real table/columns.

## Exact source-grounded schema checkpoints
Repository source declares:
- plugin version constant `SN_VERSION='2.1.0'` (`sabri-network/sabri-network.php`);
- core `SN_DB::DB_VERSION='2.0.4'` (`includes/class-sn-db.php`);
- `SN_Meet::DB_VERSION='1.0.0'`;
- `SN_Two_Plan_Completion::SCHEMA_VERSION='2.1.0'`;
- `SN_Future_Superset::SCHEMA_VERSION='1.0.0'`;
- `SN_Outbox::SCHEMA_VERSION='1.0.0'`;
- migration checkpoint key `sn_migration_state`, controlled by `SN_Fifth_Fresh_Migration_Hardening`.

The migration governor's `verify_schema()` checks at least 22 named table suffixes and their required columns: `conversations`, `members`, `messages`, `reports`, `calls`, `call_members`, `spaces`, `space_members`, `space_invites`, `space_join_requests`, `presence_devices`, `high_risk_actions`, `conference_providers`, `transfer_sessions`, `transfer_chunks`, `transfer_recipients`, `smail_messages`, `smail_states`, `smail_drafts`, `future_records`, `conversation_contexts`, `cf01_context_refs`. Schema truth is **never** inferred from the option alone.

On an authorized console, after confirming the prefix, read-only examples:

```sql
SHOW COLUMNS FROM wp_sn_future_records;
SHOW COLUMNS FROM wp_sn_future_message_versions;
SHOW COLUMNS FROM wp_sn_event_outbox;
SHOW COLUMNS FROM wp_sn_transfer_sessions;
SELECT feature_id, state, COUNT(*) AS records
FROM wp_sn_future_records
GROUP BY feature_id, state;
SELECT status, COUNT(*) AS events
FROM wp_sn_event_outbox
GROUP BY status;
```

The prefix `wp_` is **illustrative**: replace it with the actual value. Check table existence before a query. Aggregate counts only; do not retrieve private payloads or row-level patient details.

## Deployed-artifact parity sequence
1. Read active plugin header and physical plugin folder. Do **not** equate the version string with the same code bytes.
2. Generate a secure file hash manifest for the **actual** live plugin directory, excluding only explicitly documented host-generated files. Preserve file paths and SHA-256 in a restricted evidence store.
3. Obtain the exact CI artifact and inner plugin ZIP/manifest **for the target immutable SHA**. GitHub artifact-wrapper digest is not the inner installable ZIP digest.
4. Compare file paths, counts and hashes. Document missing/extra/drifted files; do not silently copy a repository file over production.
5. Freeze read-only DB schema/options, migration flags, errors and provider compatibility **in the same observation window**.
6. If deployed/package mismatch is discovered, STOP application-bug debugging and perform **Deployment-Parity Audit** before preparing any live patch.

## HTTP 530 / connection incident
Recorded symptoms: WPVibe connection to `https://www.sabrihomeopathy.com` returned HTTP 530, while Jetpack's site registry reports `site_disconnected`. These facts **do not prove** an origin outage, named Cloudflare security configuration, malware, WordPress fatal error, hosting fault or even the exact failing hop.

Operator evidence to request: failing request UTC time, hostname, Cloudflare 1xxx subcode/diagnostic headers if any, public DNS, origin response/health logs, hosting edge routing and Jetpack reconnection status. Do not disable WAF, uninstall security plugins, rotate secrets or deploy speculative fixes solely because of the 530.

## File17-CEN-01/F17-FUT-07/FUT-22/FUT-23 staging gates
- **One canonical communication backend:** File17 message/Smail/conversation state; File00/02 authoritative identity; File19 notification transport; File16 AI governance; File26 must not ingest private messages.
- **Reminder:** valid owner + conversation/message at creation; timezone/DST; list returns current version; stale reschedule/cancel rejection; multiple due ticks; missing File19 hook, File19 failure, idempotent downstream event, state recovery and actual delivery evidence. An installed hook alone does **not** prove File19 durable acceptance.
- **Private AI:** explicit consent, File16 authorization, redaction, minor policy and current message visibility; re-check after provider callback; no auto-send/diagnosis claims.
- **Semantic Search:** opt-in/withdraw/purge, provider no cross-user leak, member + hidden/deleted checks before and after projection; File26 exclusion; real adapter, index purge and stale results.
- **Smail / 1 GiB Transfer / Calls:** scanner-private storage, resumable chunk/hash, revoked recipient denial, interrupted upload, call admission/SFU failure and provider fallback tested with real environment and permissions.
- **Accessible UI:** 320/390/768/1024/1440 CSS px, Urdu/Arabic RTL, English LTR, keyboard + NVDA/TalkBack/VoiceOver, 200–400% zoom, reduced motion and 44x44 primary touch controls.

## Release decision record — complete separately for each environment
| Evidence | Repository candidate | Staging | Live |
|---|---|---|---|
| Exact SHA and source checksum | Record after CI | Compare to deployed | Compare to deployed |
| Active version / plugin files | Source only | UNVERIFIED | UNVERIFIED |
| DB version / schema | Expected schema only | UNVERIFIED | UNVERIFIED |
| Migration state | Source governor only | UNVERIFIED | UNVERIFIED |
| Two independent review rounds | Source audit; not E2E | PENDING | PENDING |
| Unit/CI tests | SHA-scoped only | Separate | Separate |
| Backup and **restored** rollback | Required | PENDING | PENDING |
| File19/AI/SFU/media acceptance | No live proof | PENDING | PENDING |
| Privacy/accessibility tests | Source only | PENDING | PENDING |
| Founder go/no-go | Not recorded | PENDING | PENDING |
| Live smoke/monitoring | Not applicable | Not applicable | PENDING |

**CEN-REL-001 release verdict: STOP / NOT OPERATIONAL until all active-scope blockers and exact deployed state are verified.** A feature may be omitted only with *proved* isolation, a safe fallback and documented approval; an unverified feature is not accepted by being called optional.

## Mandatory final incident footer
```text
Repository HEAD:
Deployed Version:
DB Version:
Migration State:
Live Verification Status:
New confirmed defects / corrected / retested / remaining:
Blockers / owner / next evidence:
```
