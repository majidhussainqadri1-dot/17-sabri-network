# File 17 — Yet Another Fresh 10-Round Review — R4 Ledger Freeze

Reviewed parent/source candidate: `073c9aac34c69cc0c35b52141ac7ec5d7c10f431`

Method: the entire R4 scope was reviewed before correction. Final route precedence was resolved first: the active spaces/community mutation owner is `SN_Fourth_Fresh_Space_Hardening` at REST priority 2200, delegating to `SN_Spaces`; the global `SN_Space_Runtime_Hardening` advisory-lock boundary was also included. Earlier message-visibility posting reservation code was not treated as final message-route truth because later message route owners override it.

R4 scope: spaces, membership/join/invite, role hierarchy, bans, ownership transfer, lifecycle, space governance audit, File-00 eligibility rechecks, relationship locks, member capacity and transactional mutation semantics.

## Frozen defects

### R4-D01 — Space governance ledger insertion failure is silently ignored
`SN_Spaces_Part_9::record()` inserts the required `space_governance` row without checking the database result, then proceeds to `SN_DB::audit()`. The active mutation paths can therefore commit role, membership, invite, ban, lifecycle or ownership changes while their File-17 governance ledger row was not durably written.

### R4-D02 — Settings update and unban are not atomic with the governance ledger
`SN_Spaces::update_space()` updates the space row and only afterward calls `record()` without a database transaction. The `unban` branch similarly revokes the ban and then calls `record()` outside a transaction. Once R4-D01 is made fail-closed, these two paths could expose a mutation that has already committed while governance evidence failed. They must bind mutation + governance evidence to one checked transaction.

### R4-D03 — Expired-invite transition can return terminal expiry after failed update/commit
In `decide_invite()`, the already-expired branch performs the invitation `expired` update and `COMMIT` without checking either result, then returns HTTP 410. A database failure can therefore leave the invite pending while the API reports it terminally expired.

## Ledger state
`FROZEN — 3 defects. No R4 production correction began before this ledger freeze.`
