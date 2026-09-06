# File 17 Fresh 20-Round Review — Round 03 Frozen Ledger

Parent exact HEAD: `a63d4a18b913f3bbb7733f60cca283d06ac64cc4`
Round-02 exact-head CI: PHP 8.1 PASS; PHP 8.3 PASS.

## Review scope
Final message route precedence; send/retry request binding; edit/delete optimistic versions; forwarding; receipts; reactions; mentions/pins/stars/hides/folders; search/outbox coupling; encrypted body handling; membership/authorization rechecks; transaction/lock/CAS behavior.

## Frozen defects

### R03-D01 — Final message edit lacks mutation-point File-00 refresh
The final edit owner is `SN_Fourth_Fresh_Review_Hardening::edit_message()`. It serializes the message/conversation and locks membership, but it does not clear the request-scoped File-00 assertion cache and rerun canonical access after the lock is held. A suspension/eligibility change after REST permission evaluation can therefore be missed by an edit that is otherwise still inside the edit window.

### R03-D02 — Final reaction route remains an un-serialized legacy single-row mutation
`/messages/{id}/reaction` remains owned by `SN_REST::react_message()`. It reads message/membership, then performs direct `DELETE`/`REPLACE` without the conversation lock, without a mutation-point File-00 refresh, and without re-reading the message under the mutation serialization boundary. A concurrent delete/leave/suspension transition can race the reaction write.

### R03-D03 — Unsupported reaction input is silently interpreted as reaction removal
`SN_Policy::sanitize_reaction()` returns an empty string both for an intentionally empty reaction (remove) and for an unsupported non-empty emoji/string. The legacy reaction route consequently treats invalid input as a deletion of the caller's existing reaction instead of rejecting the malformed request.

## Ledger status
`DEFECT-BEARING — 3 frozen defects.`

No production correction was started until this ledger was frozen.
