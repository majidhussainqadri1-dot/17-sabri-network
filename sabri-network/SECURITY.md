# Security and Privacy Notes

## Implemented controls

- authenticated REST mutations with WordPress REST nonce protection;
- server-side membership, object ownership, capability, contact-consent, block, suspension, minor, and guardian checks;
- rate limits for contact requests, directory search, conversations, messages, updates, reports, calls, and signaling;
- idempotency keys for message submission;
- bounded request sizes and allowlists for message types, reactions, report categories, call states, and WebRTC signal types;
- private file storage outside the public web root;
- extension, MIME, image, PDF-signature, size, path, hash, malware-scanner, and authorization checks;
- private/no-store delivery, range validation, `nosniff`, and restrictive content security policy;
- privacy export and erasure integration;
- minimal public health response and administrator-only diagnostic details;
- audit entries without message bodies, patient data, tokens, or raw network identifiers;
- no stored OTP, SMS secret, or long-lived TURN credential;
- no public legacy attachment URL returned by the 2.0 API.

## E2EE boundary

Transport security and private storage are not end-to-end encryption. File 17 must not be advertised as E2EE until an independently reviewed protocol provides authenticated device keys, identity verification, forward secrecy, multi-device behavior, lost-device handling, key backup/recovery policy, and cryptographic test evidence.

## External controls still required

- HTTPS, secure cookies, WordPress hardening, WAF/CDN where approved, and restricted production administration;
- File 00/File 02 MFA and session controls;
- approved malware scanner for document uploads;
- approved TURN service with short-lived credentials and an SFU for group calls;
- penetration testing, dependency review, load/race testing, backup/restore verification, and staging acceptance;
- private operational runbooks and incident evidence outside the public repository.

## Security reporting

Do not place patient data, message bodies, credentials, private file links, or exploit details in public issues. Use the platform's private security-reporting and incident process.

## Abuse-report evidence and retention
Reports use a client-generated UUIDv4 for idempotency, a one-way canonical target key, global and same-target throttles, bounded evidence, and a SHA-256 evidence-integrity value. Legal/safety holds may only be changed through the administrator-authorized triage contract. Expired, unheld evidence is first anonymized and later deleted by a bounded locked worker. Ordinary users cannot set a hold or force the internal `expired` state.


## Sabri Meet security boundary

Sabri Meet uses authenticated File-17 REST authorization, verified-adult hosting, fail-closed unknown-age handling, explicit guardian/minor policy, block checks, cryptographically random non-enumerable meeting identifiers, required creation idempotency, waiting-room admission, host-only high-risk controls, row locks, optimistic versions, participant/device ceilings, expiring recipient-scoped signals, checked privacy-erasure transactions, observable cleanup failures and no-cache/noindex meeting pages. Raw device session identifiers are HMAC-derived before storage.

Provider media is unavailable by default. An approved adapter may issue a short-lived participant-bound token after admission; long-lived credentials, provider private keys and TURN passwords must never be returned by File 17 or stored in meeting tables. Recording is disabled until a separate consent, retention, access and audit workflow is approved. Captions and screen sharing are capability-gated. The current batch does not claim audited end-to-end encryption.

## Search and event controls

- Message search is never global: active conversation membership is revalidated for search and context requests.
- Token index values are HMAC-SHA-256 derivatives; plaintext queries are absent from the index, audit and operational metrics.
- Search/context cursors are short-lived and bound to viewer, conversation, filters and snapshot.
- Event payloads are metadata-only, size/depth bounded and strip message bodies, credentials, tokens, ICE/SDP and storage paths.
- Outbox workers use atomic claim tokens, stale-lock recovery, bounded exponential retry and terminal dead-letter state.
- Incoming events are UUIDv4/idempotency bound and their failed state is persisted after business-transaction rollback.
