# File 17 — Sabri Network and Messages

**Plugin version:** 2.0.0  
**WordPress:** 6.5 or later  
**PHP:** 8.1 or later  
**Status:** reviewed code candidate; not yet staging-accepted or production-approved

File 17 is the canonical communication owner for the Sabri Social Homeopathy Platform. It provides consent-based relationships, direct and group conversations, private messaging, updates, WebRTC call signaling, blocking, reporting, privacy operations, and integration contracts.

## Governing architecture

- **One communication domain:** Network relationships and Messages share one File-17 backend.
- **No duplicate identity:** File 00/File 02 remain the identity and authentication authorities.
- **No duplicate shell:** File 20 owns global navigation and application shell. File 17 publishes a route contract and does not inject another global menu.
- **No duplicate notification service:** File 19 may consume `sn_network_notification_handled`; the local notification table is a bounded fallback.
- **No duplicate public-profile system:** File 25 may present Follow/Connect/Message controls, but File 17 authorizes and executes those actions.
- **Native security remains active:** File 24 receives assurance evidence; File 17 retains native authorization, privacy, file-delivery, rate-limit, and abuse controls.

## Major 2.0.0 corrections

- Removed the legacy File-17 OTP/account-creation pathway and retired stored SMS/TURN secrets.
- Added fail-closed identity-authority integration.
- Added accepted-contact consent before direct messages and calls.
- Added server-side object authorization for conversations, messages, calls, signals, contacts, updates, files, and reports.
- Added private attachment storage outside the public web root, MIME/signature checks, image normalization, malware-scanner contract, and authorization-gated delivery.
- Added idempotent message submission, bounded rate limits, audit events, privacy export/erasure, signal acknowledgement, and call-state validation.
- Added race-resistant active-call uniqueness and duplicate direct-conversation keys.
- Withheld legacy public-media attachments until controlled migration.
- Removed global navigation injection and unsafe page overwriting.
- Rebuilt the responsive, keyboard-operable, RTL-ready interface.

## Required integrations

Production use requires a reviewed File 00/File 02 adapter that reports identity-authority availability **only after** the canonical identity service, suspension state, minor/guardian state, verification claims, capabilities, and public projections are actually reachable. A blanket `true` override is unsafe and is not an acceptable integration.

Document uploads require an approved malware-scanning adapter for `sn_network_attachment_scan_result`. The adapter must invoke a real scanner, bind the result to the supplied file hash/context, and return a verified status such as `clean`, `infected`, `suspicious`, `rejected`, or `WP_Error`. An unconditional `clean` response is prohibited. Without scanner evidence, document uploads fail closed.

For production calls, provide approved STUN URLs in settings and short-lived TURN credentials through `sn_network_ephemeral_turn_credentials`. Group calls remain disabled until an approved SFU adapter passes policy, authorization, UI-availability, and provider-result contracts.

## Installation

1. Back up the database and files.
2. Install the ZIP on staging only.
3. Activate the plugin.
4. Open **Network → System Check**.
5. Connect File 00/File 02 and File 19 contracts.
6. Configure private storage and the attachment scanner.
7. Run role, privacy, migration, call, and rollback acceptance tests.
8. Deploy live only after Founder approval.

## Quality commands

```bash
bash tools/quality-check.sh
bash tools/package.sh
```

The package script creates `build/17-sabri-network-and-messages-2.0.0.zip` and its SHA-256 file.

## Explicit non-claims

Version 2.0.0 does **not** claim audited end-to-end encryption, a production TURN/SFU service, native mobile applications, completed penetration testing, completed load testing, staging acceptance, live deployment, or operational completion.
