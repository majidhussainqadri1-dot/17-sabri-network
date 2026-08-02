# Architecture — File 17

## Canonical ownership

File 17 owns:

- contact requests, accepted relationships, blocks, and relationship state;
- direct, group, community, and channel conversation records;
- conversation memberships and roles;
- message records, reactions, read state, private attachment references, typing state, and presence projections;
- temporary updates and their view state;
- call records, call membership, and WebRTC signaling;
- File-17 abuse reports and privacy-safe audit events.

It does not own account creation, passwords, OTP, doctor verification, global navigation, public profiles, publication feeds, clinical records, payment records, or the platform-wide incident register.

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

File 17 emits:

```php
do_action('sn_network_route_registered', $route_contract);
```

File 20 decides whether and where the route appears in global navigation.

### Notifications — File 19

`sn_network_notification_handled` may consume a privacy-safe event. Returning `true` prevents the bounded local fallback record.

### Security and assurance — File 24

File 17 exposes administrator-only health evidence at `sabri-network/v2/admin/health`. It does not expose secrets, message bodies, filesystem paths, or internal incident details through the public health route.

### Public profiles — File 25

File 25 may present relationship and messaging actions. All mutations must call File-17 REST contracts; presentation state is never authorization.

### Private files

- `sn_network_private_storage_dir`
- `sn_network_attachment_scan_result`
- `sn_network_allowed_private_attachment_types`
- `sn_network_image_max_dimension`

The default storage path is a sibling of the WordPress root. Storage inside the web root fails closed.

### Calls

- `sn_network_ephemeral_turn_credentials`
- `sn_network_sfu_available`
- `sn_network_can_use_group_calls`
- `sn_network_group_call_create_result`
- `sn_network_group_call_ui_available`

TURN credentials must be short-lived. File 17 stores no long-lived TURN password. Direct calls use File-17 call state and signaling. Group calls remain unavailable unless the SFU is declared healthy, the user is authorized, a provider handles creation, and the provider explicitly declares the UI usable.

### Sabri Meet conference control plane

Sabri Meet remains inside File 17 and registers `/calls/` plus `/calls/{meeting_id}/` with File 20. It owns meeting identifiers, schedule/status, invitation-only or conversation-bound access, waiting-room admission, host/co-host authority, participant records, bounded device sessions, raised-hand/media-state aggregation, host invitations, conversation-backed meeting chat, moderation, short-lived signaling, privacy export/erasure and minimized event evidence. It does not create a second identity authority, global shell, notification transport or parallel chat database.

The runtime tables use the `sn_meet_` prefix: `meetings`, `participants`, `sessions`, `signals`, and `events`. Meeting creation is idempotent per host/request key; joins serialize on the meeting row, revalidate identity/age/block/membership state, enforce participant and device-session ceilings, and use optimistic versions for high-risk mutations. Denied/removed identities and unauthorized meeting identifiers return a generic unavailable response.

Media transport is an adapter boundary. `sn_network_meet_media_config` may return only an authorized participant's short-lived provider room token and capability flags. File 17 does not store that token. `sn_network_meet_peer_signaling_enabled` is false by default. Recording and E2EE claims remain false until separately governed, implemented and independently accepted.

## Data model

Canonical tables use the WordPress prefix and `sn_` namespace. Important invariants include:

- unique `direct_key` for one direct conversation per user pair;
- unique `pair_key` for one relationship record per user pair;
- unique message `idempotency_key`;
- unique nullable call `active_key` for one ringing/active call per conversation;
- unique message reaction per user;
- unique call membership and conversation membership;
- one presence row per user and one expiring typing row per user/conversation;
- private attachment ledger with SHA-256 and scan status;
- one active owner for each non-direct conversation, with a transactionally locked ownership-transfer route before the owner may leave.

## Failure behavior

- Missing identity authority: user mutations fail closed with HTTP 503.
- Unsafe private storage: file uploads fail closed.
- Missing scanner: document uploads fail closed; validated images/audio/video remain policy-controlled.
- Missing optional notification adapter: bounded local fallback remains available.
- Missing SFU: group calls are unavailable; direct calls are unaffected.
- Missing optional module integration: File 17 remains isolated without creating a duplicate backend.
- Expired presence becomes offline and expired typing state is deleted; neither stores IP addresses or device fingerprints.
- Channel members are read-only by default unless an explicit policy adapter grants posting authority; channel calls remain unavailable.
- Removing a conversation member revokes active call participation and queued signaling in the same transaction.

## Indexed message search and reliable event delivery

File 17 owns a hashed-token message index in `sn_message_search_tokens`. Search is restricted to active conversation members and uses bounded term/result/context budgets plus HMAC-signed viewer/conversation/filter/snapshot cursors. Plaintext search queries are not persisted. Hidden, removed, expired and deleted message states are excluded before response formatting.

Canonical message send, edit, delete and delivered/read receipt mutations are wrapped by `SN_Message_Integrity`. Their message record, search-index change and metadata-only event outbox record commit or roll back as one database unit. `SN_Outbox` provides idempotent outgoing events, transactional incoming-event consumption, atomic worker claims, bounded retry, stale-lock recovery, dead-letter visibility and optimistic manual retry. File 19 remains the notification transport owner and consumes dispatched event facts through the published hook contract.
