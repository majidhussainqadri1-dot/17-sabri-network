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
