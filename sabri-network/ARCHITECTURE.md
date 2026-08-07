# Architecture — File 17 — runtime 2.0.2

## Canonical ownership

File 17 is the single communication/realtime owner and owns:

- contact requests, accepted relationships, follows, blocks, and relationship state;
- communities, groups, channels and their governance/lifecycle;
- direct, group, community, and channel conversation records;
- conversation memberships and roles;
- canonical message records, replies, reactions, pins/stars/folders, read state, message search, private attachment references, typing state, and presence projections;
- internal Smail mailbox projection over those same canonical conversations/messages;
- verified-user private file-transfer sessions up to 1 GiB per file;
- temporary updates and their view state;
- call/meeting records, call membership, WebRTC signaling and provider-gated conference controls;
- File-17 abuse reports and privacy-safe audit events.

Network and Messages are distinct user experiences over this one backend. File 17 does not create a second identity, chat, calls, notification, navigation or clinical backend.

It does not own account creation, passwords, OTP, doctor verification, global navigation, the notification center/delivery fabric, public profiles, publication feeds, clinical records, payment records, or the platform-wide incident register.

## Integration contracts

### Identity and membership — File 00/File 02

- `sn_network_identity_authority_available`
- `sn_network_user_can_access`
- `sn_network_user_is_suspended`
- `sn_network_user_is_minor`
- `sn_network_guardian_consent_valid`
- `sn_network_user_verified`
- `sn_network_public_user_projection`
- `sn_network_user_role_label`
- `sn_network_minor_contact_allowed`
- `sn_network_minor_discoverable`

The default runtime is fail-closed when no recognized identity authority or explicit adapter is present. Any contact involving a minor and any minor directory visibility require separate, explicit policy decisions; neither is enabled by default.

### Application shell — File 20

File 17 emits versioned route contracts with `sn_network_route_registered`. File 20 decides whether and where those routes appear in the one global shell/navigation. File 17 does not inject a competing global header, navigation strip or notification bell.

### Notifications — File 19

File 19 is the only notification-center and notification-delivery owner. `SN_DB::add_notification()` still exists as a compatibility call site, but runtime 2.0.2 installs a highest-priority terminal `sn_network_notification_handled` bridge which prevents every new File-17 local notification-row write after approved adapters run. If no earlier adapter consumes the event, File 17 emits the metadata-only `sn_network_notification_requested` fact for File 19/approved integration; message bodies are not included.

Historical `sn_notifications` schema/data are retained non-destructively for rollback/migration compatibility only. `/notifications` and `/notifications/read` are overridden as compatibility projections to File 19 and do not constitute a second active center. The historic File-17 bell is hidden; File 20 presents File 19's single global bell.

### Security and assurance — File 24

File 17 exposes administrator-only health evidence at `sabri-network/v2/admin/health`. It does not expose secrets, message bodies, filesystem paths, or internal incident details through the public health route. File 24 consumes assurance evidence; it does not replace File-17 native enforcement.

### Public profiles — File 25

File 25 may present relationship and messaging actions. All mutations must call File-17 REST contracts; presentation state is never authorization.

### Clinical context — File 08 / CF-01

File 17 may retain only opaque, reauthorized communication-context references. Appointment, treating relationship, clinical consent, record state and prescription truth stay with their canonical clinical owners. Context references are not clinical authorization.

### Private files and verified transfer

Message attachments use the File-17 private attachment ledger. Verified transfer adds a separate File-17 transfer/session ledger but never a second public-media source of truth.

- Per-file hard limit: 1,073,741,824 bytes (1 GiB).
- Upload chunks: 1–16 MiB, 8 MiB default, resumable and checksum-bound.
- Concurrent attempts for the same logical chunk write to independent random encrypted paths; database uniqueness chooses the winner and a loser never unlinks winner bytes.
- Private storage must be outside the public WordPress tree; fallback web-server denial guards are installed.
- Server MIME/magic/archive validation and fail-closed malware quarantine precede availability.
- Access grants are recipient-bound, transfer-version-bound, signed and short-lived; revocation invalidates prior grants.
- Download supports private no-store byte ranges after fresh identity/verification/relationship revalidation.
- No transfer object is inserted into the public WordPress Media Library and no permanent public URL exists.

CF-04 may become the approved scanning/media-processing adapter after its own activation. Until an approved scanner returns clean, transfer publication fails closed.

### Calls

- `sn_network_ephemeral_turn_credentials`
- `sn_network_sfu_available`
- `sn_network_can_use_group_calls`
- `sn_network_group_call_create_result`
- `sn_network_group_call_ui_available`

TURN credentials must be short-lived. File 17 stores no long-lived TURN password. Direct calls use File-17 call state and signaling. Group calls remain unavailable unless the SFU is declared healthy, the user is authorized, a provider handles creation, and the provider explicitly declares the UI usable. This preserves the File-17 plan's provider gate instead of falsely claiming unsupported group media.

### Sabri Meet conference control plane

Sabri Meet remains inside File 17 and registers `/calls/` plus `/calls/{meeting_id}/` with File 20. It owns meeting identifiers, schedule/status, invitation-only or conversation-bound access, waiting-room admission, host/co-host authority, participant records, bounded device sessions, raised-hand/media-state aggregation, host invitations, conversation-backed meeting chat, moderation, short-lived signaling, privacy export/erasure and minimized event evidence. It does not create a second identity authority, global shell, notification transport or parallel chat database.

The runtime tables use the `sn_meet_` prefix: `meetings`, `participants`, `sessions`, `signals`, and `events`. Meeting creation is idempotent per host/request key; joins serialize on the meeting row, revalidate identity/age/block/membership state, enforce participant and device-session ceilings, and use optimistic versions for high-risk mutations. Denied/removed identities and unauthorized meeting identifiers return a generic unavailable response.

Media transport is an adapter boundary. `sn_network_meet_media_config` may return only an authorized participant's short-lived provider room token and capability flags. File 17 does not store that token. `sn_network_meet_peer_signaling_enabled` is false by default. Recording and E2EE claims remain false until separately governed, implemented and independently accepted.

## Canonical message confidentiality, search and forwarding

Canonical `sn_messages.body` values created or edited by runtime 2.0.2 are authenticated-encryption envelopes with the `SNE1:` prefix. `SN_Message_Body` uses the existing `SN_Communication_Crypto` primitive and binds encryption context to conversation + sender. This is server-side storage encryption, not E2EE.

Pre-2.0.2 plaintext rows remain readable only for compatibility migration. `SN_Central_Plan_Hardening::migrate_message_bodies()` migrates them in bounded batches using optimistic compare-and-swap writes, then rebuilds search tokens. Search decrypts the authorized canonical row only in memory, derives HMAC token hashes and persists no plaintext query/body index.

`SN_Message_Integrity` owns new send/edit/delete/receipt mutations. The canonical message record, hashed search-index mutation and metadata-only outbox event commit or roll back as one unit. Response formatters decrypt only after current conversation authorization.

Forwarding does not copy an encrypted source envelope between audiences. The override revalidates both source and target membership, decrypts the authorized source only in memory, writes a new target-bound encrypted body, disallows private attachment identifier reuse and emits a source-minimized event that does not reveal the source message identity to the target audience.

## Internal Smail

Smail is a mailbox projection, not SMTP/email hosting and not a second chat database. Inbox, Sent, Drafts, Starred, Archive, Spam and Trash are File-17-owned views over canonical message references.

- Draft payloads remain encrypted/versioned and owner scoped.
- Sends use `SN_Message_Integrity::send_message()` rather than bypassing the canonical message path.
- Multi-recipient sends reserve a canonical group conversation by Smail idempotency key + recipient hash, so an interrupted mailbox-projection retry cannot create duplicate groups.
- Smail events and File-19 notification facts contain identifiers/metadata, not canonical message bodies.

## Data model

Canonical tables use the WordPress prefix and `sn_` namespace. Important invariants include:

- unique `direct_key` for one direct conversation per user pair and retry-safe reserved communication conversations;
- unique `pair_key` for one relationship record per user pair;
- unique message `idempotency_key`;
- unique nullable call `active_key` for one ringing/active call per conversation;
- unique message reaction per user;
- unique call membership and conversation membership;
- one presence row per user and one expiring typing row per user/conversation;
- private attachment/transfer ledgers with SHA-256 and scan state;
- one active owner for each non-direct conversation, with a transactionally locked ownership-transfer route before the owner may leave.

## Failure behavior

- Missing identity authority: user mutations fail closed with HTTP 503.
- Unsafe private storage: file uploads fail closed.
- Missing transfer malware scanner: completed transferred bytes remain quarantined and unavailable.
- Missing File 19 adapter: File 17 emits a metadata-only integration fact and does **not** revive a second local notification center.
- Missing SFU/provider: provider-gated group media is unavailable; supported direct/control-plane functions remain truthful.
- Missing optional module integration: File 17 remains isolated without creating a duplicate backend.
- Expired presence becomes offline and expired typing state is deleted; neither stores IP addresses or device fingerprints.
- Channel members are read-only by default unless an explicit policy adapter grants posting authority; channel calls remain unavailable.
- Removing a conversation member revokes active call participation and queued signaling in the same transaction.
- Message encryption failure fails the write rather than storing new plaintext.

## Release truth

Runtime 2.0.2 is a repository code/package/automated-QA candidate after four independent plan reviews. The Top-20 plan distinguishes `NOW`, `NEXT`, and `SCALE`; this release must not misrepresent provider-dependent or later-wave capabilities as already live merely because their governance boundary exists. Hostinger staging, real companion plugins/roles/providers, browser/device/RTL/accessibility/load/security acceptance, backup/restore, rollback rehearsal, Founder approval, live deployment and operational monitoring remain separate statuses.
