# File 17 — Next Fresh 10-Round Review — Round 4 Frozen Ledger

**Round:** 4  
**Reviewed parent:** `f88e04c7adc98bc0c6a8eb0a57daeb6f71fedc7a`  
**Scope:** active message-organization routes, mentions, folders, final message/forward owners, private-message mutation atomicity and point-of-action authorization.  
**Discipline:** the entire Round-4 review was completed before any correction began.

## Verified clean areas before freeze

- Canonical message send is owned by `SN_Fourth_Fresh_Review_Hardening` / `SN_Message_Runtime_Hardening`, which checks transaction start, refreshes File-00/access/contact policy at mutation time, serializes space posting policy and reconciles duplicate delivery/search side effects.
- Final forwarding is overridden by the later hardened owner rather than the legacy `SN_Message_Operations::forward_message()` route.
- Message edit/delete and ownership-transfer final routes already fail closed when `START TRANSACTION` cannot be established.
- Private attachment delivery remains authorization-gated before expensive integrity work and canonical send does not reuse private attachment identifiers across audiences.

## Frozen defects

### R4-D01 — Active mentions and folder deletion continue after an unproved transaction start

`SN_Message_Operations::set_mentions()` and `SN_Message_Operations::delete_folder()` are active, non-overridden routes. Both call `START TRANSACTION` without checking for `false`, then perform multi-statement mutations and later rely on rollback/commit semantics.

If transaction start fails, those writes can execute under autocommit and the later rollback cannot restore atomicity.

**Severity:** High mutation-atomicity defect.

### R4-D02 — Mention authorization is validated only before the committing transaction

`set_mentions()` checks message ownership/editability, conversation membership of every target and block state before the transaction starts. It then deletes all previous mentions and inserts the requested set without re-locking/revalidating the message and target memberships at the point of commit.

A concurrent membership removal, message deletion/edit-window change or block can therefore race between the preliminary checks and the committed mention set.

**Severity:** High point-of-action authorization / concurrency defect.

## Required correction

Fail closed on both active transaction-start sites. In `set_mentions()`, lock/reload the source message after transaction start and revalidate author/edit/deleted state plus actor/target membership and block status before deleting/inserting mentions. Preserve the existing outbox/audit/commit behavior only after these checks succeed.

## Correction gate

No Round-5 review may begin until all frozen defects are corrected, permanent regression coverage passes and the resulting exact branch HEAD has green PHP 8.1 plus PHP 8.3/full-quality deterministic-package CI.
