# File 17 → CF-01 Communication-Context Contract

## Status

- File 17 candidate runtime: `2.0.1`.
- Contract: `sn.cf01.communication-context` `1.0.0`.
- Native owner: File 17 — Sabri Network and Messages.
- State: implementation candidate; automated, staging and owner acceptance remain separate evidence.
- This contract does not activate CF-01, create clinical records or authorize real patient-data processing.

## Purpose

The contract provides a revocable opaque reference that lets an authorized clinical workflow point back to a current File 17 communication context without copying the communication into the clinical chart.

It answers only:

> Does this opaque File 17 reference still resolve to a current communication context for this participant and approved purpose?

It does not answer whether the actor may read or write a clinical chart, whether a treating relationship exists, whether clinical consent is current, or whether any message should become a clinical note.

## Canonical ownership

File 17 remains the sole owner of:

- conversation membership and roles;
- direct/group communication state;
- block state;
- message bodies and message search;
- communication attachments;
- call signaling and call metadata;
- communication retention and audit.

CF-01 remains the future conditional owner of:

- treating relationships;
- clinical consent and guardian authority;
- patient charts, encounters and clinical attachments;
- clinician-authored summaries and signed notes;
- prescriptions and follow-up records;
- clinical access, amendment and break-glass decisions.

Neither module writes directly into the other module’s tables.

## Reference registry

File 17 owns a minimal `cf01_context_refs` registry. It stores only:

- opaque UUIDv4 reference;
- internal File 17 conversation identifier;
- internal issuer identifier;
- bounded purpose;
- keyed hashes of consent reference and idempotency key;
- File 17-owned retention class;
- active/revoked/expired state;
- expiry, timestamps and optimistic version.

It must not store:

- message ID or body;
- attachment ID, filename, path or binary;
- call ID, signaling payload, recording or transcript;
- participant email, phone, WhatsApp or public profile data;
- diagnosis, symptoms, remedy, prescription or clinical note;
- password, token, consent text or bearer credential.

## Issuance contract

```php
sn_cf01_issue_communication_context(
    int $conversation_id,
    int $actor_id,
    array(
        'purpose'           => 'care_coordination',
        'consent_reference' => 'opaque-owner-reference',
        'idempotency_key'   => 'opaque-command-key',
        'ttl_seconds'       => 3600,
    )
): array|WP_Error
```

Issuance requires:

1. current File 17 conversation membership;
2. active conversation and no applicable direct-conversation block;
3. one approved purpose;
4. valid bounded opaque consent reference and idempotency key;
5. external professional issuer authorization through `sn_cf01_clinical_context_issuer_authorized`;
6. external consent authorization through `sn_cf01_clinical_context_consent_authorized`;
7. File 17/native-owner retention classification through `sn_cf01_clinical_context_retention_class`;
8. atomic reference, metadata-only outbox event and audit creation.

Caller input cannot self-declare `legal_hold`.

A repeated issuer/idempotency key returns the same active reference only when conversation, purpose and consent hash match. Scope reuse fails conflict-safe.

## Assertion contract

```php
sn_cf01_communication_context_assertion(
    string $reference_uuid,
    int $actor_id,
    array('purpose' => 'care_coordination')
): array|WP_Error
```

Every assertion rechecks:

- reference state and expiry;
- current File 17 membership;
- current conversation state;
- current direct block state;
- exact purpose;
- external read authorization through `sn_cf01_clinical_context_read_authorized`.

The short-lived response contains communication type, privacy class, state hash, participant count, actor participant class, opaque owner reference, timestamps and explicit boundaries. It contains no communication content or participant contact details.

## Destination resolution

```php
sn_cf01_resolve_communication_destination(
    string $reference_uuid,
    int $actor_id,
    array('purpose' => 'care_coordination')
): array|WP_Error
```

The resolver repeats the assertion checks, confirms membership again and returns only a same-origin HTTPS File 17 Messages URL. URL user-info is prohibited. The destination:

- is navigation, not bearer authorization;
- requires click-time authentication and File 17 authorization;
- uses private/no-store cache semantics;
- cannot be used by CF-01 to bypass conversation permissions.

## Revocation and lifecycle

- Issuer or an externally authorized owner may revoke a reference.
- Revocation uses status plus optimistic version matching.
- Revoked or expired references immediately fail as unavailable.
- Consent withdrawal should invoke revocation through the native command.
- Privacy erasure revokes active references while retaining minimal accountable audit evidence under approved File 17 retention rules.
- Hourly cleanup marks expired references with a version increment.
- Legal holds are assigned only by an approved native owner process.

## Non-authorization constitution

Every valid assertion states:

- `treating_relationship: false`;
- `clinical_read_authority: false`;
- `clinical_write_authority: false`;
- `prescription_authority: false`;
- `break_glass_authority: false`;
- `chat_membership_is_not_treating_relationship: true`;
- `automatic_chart_write: false`.

A clinician who needs information from a conversation must create a separate clinician-authored, purpose-authorized and signed clinical summary through a future CF-01 command. Message text, attachment or transcript is never copied automatically.

## Events

The metadata-only issuance event contains:

- opaque reference UUID;
- purpose;
- reference expiry;
- File 17 conversation state hash;
- explicit false content flags.

The event is a past-tense fact. It is not a command, credential, consent, treating relationship or clinical authorization.

## Review and correction record

### Review round 1

Corrected:

- caller-controlled retention class and possible self-declared legal hold;
- a committed reference appearing failed because issuance returned a separately authorized read assertion;
- insufficiently explicit same-origin URL user-info rejection.

### Fresh adversarial review round 2

Required before owner acceptance and to cover at minimum:

- expiry/malformed timestamp behavior;
- block and membership changes after issuance;
- consent withdrawal and revocation races;
- idempotency scope conflict;
- outbox/transaction failure and retry behavior;
- retention/legal-hold authority;
- destination reauthorization and privacy leakage;
- merged File 00/02/09/17 → CF-01 consumer fixtures.

## Acceptance still required

- exact-head PHP 8.1/8.3 and inherited File 17 QA;
- reproducible 2.0.1 package and checksum evidence;
- native-owner review and merge;
- accepted File 00/02/09 contracts;
- CF-01 consumer fixtures against immutable merged versions;
- legal/professional/privacy/security review;
- Hostinger-equivalent staging, migration/rollback and backup/restore;
- Founder change-control approval.
