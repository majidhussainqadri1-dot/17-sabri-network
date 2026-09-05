# File 17 — Next Fresh 10-Round Review — Round 9 Frozen Ledger

**Round:** 9  
**Reviewed parent:** `99a0b1d3387a209241fec942641aa2e864fa724a`  
**Scope:** remaining Future-24 mutation owners, key/team/template/reminder lifecycles, final Future privacy eraser precedence, scheduler recovery and transaction truth.  
**Discipline:** the full Round-9 review was completed before any Round-9 correction began.

## Verified clean areas before freeze

- Device-key registration/revocation in `SN_Future24_Review_Hardening_J` proves transaction start, verifies append-only ledger integrity before commit, and fails closed on commit/write errors.
- Team-inbox internal-note creation and assignment handoff prove transaction start and commit, revalidate delegated authority under locks, and use optimistic versions.
- Reminder reschedule/cancel uses state/version CAS and does not resurrect terminal reminders.
- Host-transfer/breakout provider side effects use explicit provider idempotency/reconciliation states and compensation paths rather than blindly retrying uncertain external side effects.
- The later Future privacy callback at priority 9600 is the actual final `sabri-network-future` eraser before the global privacy wrapper; therefore earlier priority-2000 erasure behavior cannot be treated as final runtime truth.

## Frozen defects

### R9-D01 — speaker-queue mutations use transactional row locks without proving that the transaction started

`SN_Future24_Review_Hardening_D::hand_raise()` and `manage_speaker_queue()` both call `START TRANSACTION` without checking for `false`, then rely on `FOR UPDATE`, multi-row mutation and rollback semantics. Commit is checked, but if MySQL refuses transaction start the protected reads/writes can proceed in autocommit mode and rollback cannot restore atomicity.

**Severity:** High call/speaker-state concurrency and authorization-integrity defect.

**Required correction:** both active speaker-queue transaction entries must fail closed before locked reads/writes when transaction start is not confirmed.

### R9-D02 — template update/delete use transactional authority revalidation without proving transaction start

`SN_Future24_Review_Hardening_N::update_template()` and `delete_template()` call `START TRANSACTION` without checking its result and then rely on `FOR UPDATE`, team-delegation revalidation, revision writes and CAS mutation. A refused transaction start can invalidate the atomic revision/edit/delete contract.

**Severity:** High delegated-content/version-history integrity defect.

**Required correction:** fail closed before any locked template mutation when transaction start cannot be proven.

### R9-D03 — final Future privacy eraser supersedes device-key erasure

`SN_Future24_Review_Hardening_I` originally erased `sn_future_device_keys` for the requesting user (while retaining append-only transparency history with disclosure). The later active `SN_Sixth_Fresh_Privacy_Hardening` replaces the same `sabri-network-future` eraser at priority 9600, but its `erase_future()` handles future records and message-version rows only; it never deletes the user-owned `sn_future_device_keys` rows.

Because the later callback is the final owner, a privacy erasure can report completion while revocable/user-owned device-key rows remain present solely due route/callback precedence.

**Severity:** High privacy-erasure completeness/truth defect.

**Required correction:** the final Future eraser must include checked, bounded device-key erasure and must not report `done=true` after an ambiguous/failed device-key delete. Append-only key-transparency integrity entries may remain retained, but the receipt must state that retention truthfully.

### R9-D04 — bulk Future scheduler recovery silently ignores database failures

`SN_Future24_Review_Hardening_O::bulk_job_preflight()` performs three state-recovery UPDATEs (`queued→expired`, stale `processing→expired`, and stale `processing→queued`) and discards every query result. A database error can therefore leave stale bulk jobs stranded with no audit/operational evidence, defeating the intended bounded scheduler recovery path.

**Severity:** Medium operational-recovery and observability defect.

**Required correction:** check every recovery UPDATE; audit a deterministic failure if any query returns `false`, and avoid presenting the recovery job as silently successful.

## Correction gate

No Round-10 review may begin until R9-D01 through R9-D04 are corrected, permanent regression coverage passes, and the exact resulting branch HEAD has green PHP 8.1 plus PHP 8.3/full-quality deterministic-package CI.