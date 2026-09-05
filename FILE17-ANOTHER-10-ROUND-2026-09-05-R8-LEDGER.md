# File 17 — Another Fresh 10-Round Review — Round 8 Ledger Freeze

Branch: `review/file17-another-10-round-2026-09-05`
Reviewed parent: `e1ab9e1a2ccc11a3589bf2ff18454386048d8f97`
Discipline: the entire Round-8 review scope below was completed before any Round-8 correction. This ledger freezes the defects found in that completed review.

## Round 8 review scope

- Final canonical message-send owner (`SN_Fourth_Fresh_Review_Hardening` → `SN_Message_Runtime_Hardening`).
- Direct/group message authorization, idempotency, reply, attachment and notification paths.
- Active secure-forward owner (`SN_Compatibility_Hardening`).
- Active voice-note owner (`SN_Fifth_Fresh_Feature_Hardening`).
- Smail runtime and final privacy ownership (`SN_Fifth_Fresh_Privacy_Hardening`).
- Core privacy guard/order and sixth-cycle Future erasure.
- Private attachment delivery/integrity and message-body encryption.
- Message search plus the final lossless rebuild owner (`SN_Fourth_Fresh_Search_Hardening`).
- Active message-operation corrections (`SN_Next_Message_Operations_Hardening`).

## Frozen defects

### R8-D01 — Canonical message `client_id` reuse does not prove the same request
`SN_Message_Runtime_Hardening::send_message()` hashes sender + conversation + caller `client_id` and immediately returns/reconciles the existing message. Unlike hardened Smail, it does not verify that body, reply target, attachment presence/content and effective message type are the same request. Reusing a caller key with materially different content can therefore return an unrelated prior message as a successful duplicate.

Required correction: every pre-existing and race-reconciliation path must compare the committed message against a normalized request fingerprint/material payload and return HTTP 409 on mismatch. An incoming attachment must be compared by content hash against the committed private attachment without creating duplicate bytes.

### R8-D02 — Group-message block authorization can fail open when recipient-ledger read fails
`SN_Message_Runtime_Hardening::recipients()` converts `wpdb->get_col()` failure into an empty array. For non-direct conversations `contact_check()` then performs zero block checks and returns true. A failed membership-recipient read can therefore allow a group/channel message while the set of blocked peers is unavailable.

Required correction: authorization-time recipient enumeration must be error-aware and fail closed. Direct and non-direct contact checks must propagate a retryable error when recipient truth cannot be read.

### R8-D03 — Final Smail privacy eraser can publish completion after authoritative read failure
At priority 9500 `SN_Fifth_Fresh_Privacy_Hardening::erase_smail()` is the final Smail eraser before the global guard. Initial state/draft ID reads use `get_col(... ) ?: []`, and completion probes bool-cast `get_var()`, without testing database error state. Failed reads can therefore look like an empty mailbox state and produce `done=true`.

Required correction: initial batch reads and post-commit completion probes must explicitly verify database success and return retryable `done=false`, `items_retained=true` when truth is unavailable.

### R8-D04 — Voice-note duplicate request can silently mutate transcript metadata
The final active voice-note owner delegates its audio message to the canonical idempotent message path and then always rewrites protected voice-note metadata. If a retry reports the canonical message as `duplicate` but supplies a different transcript, the same caller idempotency key can mutate transcript metadata instead of producing an idempotency conflict.

Required correction: when a duplicate canonical audio message already has finalized voice-note metadata, compare the normalized protected transcript request; mismatch must return 409. A duplicate may finalize metadata only when prior metadata finalization is absent/incomplete and the audio request itself has already been proven identical.

### R8-D05 — Lossless private-search rebuild can falsely complete on DB read failure
`SN_Fourth_Fresh_Search_Hardening::finish_rebuild()` casts its `SELECT id ... LIMIT 1` result directly to int and does not inspect `wpdb->last_error`. A failed read becomes zero; with no stored prior error it can clear the rebuild flag and audit completion while unprocessed messages may remain.

Required correction: failed/invalid next-row reads must record a rebuild error and schedule retry; only a successfully verified empty next-row result may publish completion.

## Ledger state

Frozen before Round-8 fixes. No Round-8 source correction began before this ledger commit.
