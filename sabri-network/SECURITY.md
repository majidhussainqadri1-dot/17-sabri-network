# Security and Privacy Notes

## Implemented controls

- authenticated REST mutations with WordPress REST nonce protection;
- server-side membership, object ownership, capability, contact-consent, block, suspension, minor, and guardian checks;
- lock-current authorization for high-risk relationship, space, receipt, presence, transfer, context and privacy mutations;
- rate limits for contact requests, directory search, conversations, messages, updates, reports, calls, signaling and high-risk administration;
- caller-owned idempotency/replay binding for canonical messages, forwarding, Smail and verified private transfer operations;
- bounded request sizes and allowlists for message types, reactions, report categories, call states, and WebRTC signal types;
- private file storage outside the public web root with fail-closed protected-storage checks;
- extension, MIME, image, PDF-signature, size, path, hash, malware-scanner, and authorization checks;
- private/no-store delivery, range validation, `nosniff`, and restrictive content security policy;
- privacy export and erasure integration with legal-hold-aware retry-safe progress;
- minimal public health response and administrator-only diagnostic details;
- audit entries without message bodies, patient data, tokens, or raw network identifiers;
- no new stored OTP, SMS secret, or long-lived TURN credential;
- no public legacy attachment URL returned by the 2.1.0 API.

## Durable communication-key boundary

New durable File-17 private ciphertext must not depend on WordPress authentication salts. File 17 therefore uses a dedicated communication master secret for new at-rest encryption writes. The preferred staging/production source is an approved shared secret manager exposed through `SN_COMMUNICATION_MASTER_SECRET` or the approved `sn_network_communication_secret` adapter. If neither is configured, File 17 atomically creates `communication-master.key` in the File-17 private storage directory outside the public web root and restricts file permissions.

The legacy WordPress `secure_auth` salt remains in the bounded decrypt compatibility keyring so existing ciphertext can be read and lazily rotated. It is not used as the authority for new durable encryption. A configured dedicated secret is rejected if it is too short or equals a WordPress authentication/nonce salt.

The communication master secret is disaster-recovery material. Backups, restores, cloned staging environments and multi-node deployments that need existing private ciphertext must preserve/provide the same key. Key loss can make durable private messages, private transfer chunks, encrypted queued data and other File-17 private records unreadable. Staging acceptance therefore requires a restore/decrypt/key-rotation rehearsal and recovery evidence; secrets themselves must never be committed to this repository or copied into public logs.

## E2EE boundary

Transport security and private storage are not end-to-end encryption. File 17 must not be advertised as E2EE until an independently reviewed protocol provides authenticated device keys, identity verification, forward secrecy, multi-device behavior, lost-device handling, key backup/recovery policy, and cryptographic test evidence.

## External controls still required

- HTTPS, secure cookies, WordPress hardening, WAF/CDN where approved, and restricted production administration;
- File 00/File 02 MFA and session controls;
- approved shared communication master-secret deployment/recovery or proven safe single private-key store;
- approved malware scanner for document uploads;
- approved TURN service with short-lived credentials and an approved SFU with explicit group-call capability for group calls;
- penetration testing, dependency review, load/race testing, backup/restore verification, and staging acceptance;
- private operational runbooks and incident evidence outside the public repository.

## Security reporting

Do not place patient data, message bodies, credentials, private file links, master-key material or exploit details in public issues. Use the platform's private security-reporting and incident process.

## Abuse-report evidence and retention

Reports use a client-generated UUIDv4 for idempotency, a one-way canonical target key, global and same-target throttles, bounded evidence, and a SHA-256 evidence-integrity value. Legal/safety holds block ordinary destructive retention/erasure. Report privacy minimization derives retained-data truth from the locked current report rows rather than a stale pre-lock count. High-risk report closure and legal-hold release require the separate approved high-risk action path and dual-control/separation-of-duties governance; ordinary users cannot set a hold or force the internal `expired` state.

## Sabri Meet security boundary

Sabri Meet uses authenticated File-17 REST authorization, verified-adult hosting, fail-closed unknown-age handling, explicit guardian/minor policy, block checks, cryptographically random non-enumerable meeting identifiers, required creation idempotency, waiting-room admission, host-only high-risk controls, row locks, optimistic versions, participant/device ceilings, expiring recipient-scoped signals, checked privacy-erasure transactions, observable cleanup failures and no-cache/noindex meeting pages. Raw device session identifiers are HMAC-derived before storage.

Provider media is unavailable by default. An approved adapter may issue a short-lived participant-bound token after admission; long-lived credentials, provider private keys and TURN passwords must never be returned by File 17 or stored in meeting tables. Group media requires an approved SFU provider with group-call capability and valid provider-specific endpoint semantics. Recording is disabled until a separate consent, retention, access and audit workflow is approved. Captions and screen sharing are capability-gated. The current repository candidate does not claim audited end-to-end encryption.

## Search and event controls

- Message search is never global: active conversation membership is revalidated for search and context requests.
- Viewer-hidden messages remain excluded from private search/context and visibility-safe cursors do not skip eligible results.
- Token index values are HMAC-SHA-256 derivatives; plaintext queries are absent from the index, audit and operational metrics.
- Search/context cursors are short-lived and bound to viewer, conversation, filters and snapshot.
- Event payloads are metadata-only, size/depth bounded and strip message bodies, credentials, tokens, ICE/SDP and storage paths.
- Outbox workers use atomic claim tokens, stale-lock recovery, bounded exponential retry and terminal dead-letter state.
- Incoming events are UUIDv4/idempotency bound and their failed state is persisted after business-transaction rollback.

Repository security review is not staging/live security acceptance.

Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔
