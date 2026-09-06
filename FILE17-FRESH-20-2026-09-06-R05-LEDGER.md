# File 17 Fresh 20-Round Review — Round 05 Frozen Ledger

Parent exact HEAD: `dc6980b013d3bdaaa75ce1ea80dc9d9ad416dee6`
Round-04 exact-head CI: PHP 8.1 PASS; PHP 8.3 PASS.

## Review scope
Final Smail route precedence, caller-owned idempotency and exact request reconstruction, recipient/contact authorization, direct/group conversation reservation, canonical message handoff, mailbox projection, outbox/audit coupling, draft encryption/version locking/deletion, state mutations, privacy export/erasure, retry/reconciliation behavior.

## Frozen defects

### R05-D01 — Smail can create/reuse its canonical conversation using request-scoped stale File-00 assertions
`SN_Smail_Runtime_Hardening::send()` acquires recipient pair locks, but its pre-reservation `SN_Policy::can_contact()` loop does not clear `SN_Membership_Assertions`. `resolve_smail_conversation()` may therefore create a direct/group conversation membership graph after a File-00 eligibility/suspension change that occurred after REST permission evaluation. The later canonical message send is stronger, but at that point the empty conversation side effect can already exist.

### R05-D02 — Smail group conversation reservation does not prove transaction start
`SN_Central_Plan_Hardening::resolve_smail_conversation()` invokes raw `START TRANSACTION` for a new Smail group reservation without checking for `false` before inserting the conversation and member rows.

## Ledger status
`DEFECT-BEARING — 2 frozen defects.`

No production correction was started until this ledger was frozen.
