# File 17 — Yet Another Fresh 10-Round Review — Round 2 Ledger Freeze

Branch: `review/file17-yet-another-10-round-2026-09-06`
Reviewed parent: `2947472b9dc5f1462b3eee07915cb87afbea72a0`
Discipline: the complete Round-2 identity/policy/relationship review was completed before any Round-2 correction. This ledger freezes all proved findings before fixes.

## Review scope
- File-00 membership/communication assertion adapter, contract version/type/subject checks and request-local authority projection.
- Central Network policy: suspension, age/guardian protections, contact/follow/privacy/presence permissions.
- Phone/profile projection ownership and File-00 resolution boundary.
- Canonical contact/follow/block graph, locks, direct-conversation creation/restoration and membership ownership.
- Final relationship mutation route owner and commit reconciliation behavior.

## Clean areas confirmed
- File-00 assertion availability and malformed/old/cross-subject assertions fail closed.
- Raw phone ownership is not duplicated in File 17; public phone projection remains File-00 owned and privacy-gated.
- Block checks used by positive contact/follow policy are error-aware; boolean wrappers treat unavailable truth conservatively.
- Contact request/decision use pair locks, checked transaction starts, locked current rows and checked writes.
- Group/channel/private-team conversation membership remains owned by canonical Spaces rather than a second group-membership graph.

## Frozen defects

### R2-D01 — unblock commit reconciliation can convert a failed authoritative read into success
`SN_Relationship_Runtime_Hardening::block_user()` reconciles a failed COMMIT by casting `SELECT id FROM blocks` directly to bool. It does not clear/check `wpdb->last_error`. For an unblock request, a failed read collapses to `false`, which equals the desired `blocked=false`, so success/audit can be emitted while durable unblock truth is unknown.

Required correction: the reconciliation read must be error-aware and reject unavailable/invalid truth before comparing the desired state.

### R2-D02 — direct-conversation commit reconciliation proves only the conversation row, not the two active memberships
`create_conversation()` can restore/create the conversation plus both member rows, but when COMMIT reports failure it verifies only that the conversation row is active. An already-active conversation can satisfy that probe even if one or both membership restores did not become durable. The catch-side race reconciliation likewise accepts an active direct conversation without proving both intended participants are currently active members.

Required correction: reconciliation must verify the exact direct key, active conversation state, and both distinct active participant memberships before returning success/reconciled success; DB read failure must fail closed.

### R2-D03 — unfollow can return duplicate inactive success when its canonical follow-row read failed
`SN_Relationships::unfollow()` calls `SN_DB::follow_record()`, whose nullable contract does not distinguish DB error from absent row. `unfollow()` treats a null row as an already-inactive duplicate and returns success. A database read failure can therefore report `inactive` even while an active/pending follow row remains.

Required correction: use an error-aware authoritative follow-row read at the mutation boundary; only a successfully proven absent row may return duplicate inactive success.

## Ledger state
Frozen before all Round-2 fixes. Round 3 must not begin until all three defects are corrected, permanently regression-protected, and exact-head PHP 8.1 plus PHP 8.3/full-quality CI are green.