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
