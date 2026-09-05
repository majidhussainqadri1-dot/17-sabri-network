# File 17 — Next Fresh 10-Round Review — Round 7 Frozen Ledger

**Round:** 7  
**Reviewed parent:** `36356bfa219d83d7cfddaa96d85bd7dbf869e1fd`  
**Scope:** File-17 privacy export/erasure, retention/legal-hold enforcement, Future/Smail/transfer/context/space/Meet/message-organization privacy callbacks, and retry/done truth.  
**Discipline:** the entire Round-7 review was completed before any correction began.

## Verified clean areas before freeze

- `SN_Privacy_Runtime_Hardening` is the final File-17 core erasure owner and serializes account erasure, uses bounded message/update batches, checks transaction start/commit, and preserves active non-direct ownership until transfer.
- Native File-17 legal holds are surfaced through `SN_Fourth_Fresh_Privacy_Hardening::native_legal_hold()` and cover reporter, reported-user and held-message-sender relationships.
- Safety/report minimization is transactional and retained report evidence is explicitly receipted.
- Fifth/Sixth privacy hardening makes Future, Smail, presence-device, transfer, context and CF-01 erasure bounded/retry-safe; the sixth layer does not advance a message-version cursor past an ambiguous delete and derives final retained truth from committed remaining rows.
- Space membership erasure is transactional, bounded and refuses to erase an active owner until ownership transfer.
- Two-Plan idempotency-cache erasure already fails closed on database delete failure and checks committed remainder.
- Private-byte deletion remains owned by `SN_Private_Files`; sixth-cycle hardening durably reschedules canonical deletion while revoked bytes still exist.
- No File-17 eraser is registered after the global priority-9999 privacy wrapper.

## Frozen defects

### R7-D01 — Sabri Meet is excluded from the final File-17 eraser wrapper, so its known transaction-failure retry repair never runs

`SN_Meet` registers its eraser under key `sabri-meet`. `SN_Privacy_Runtime_Hardening::guard_all_erasers()` only wraps keys beginning with `sabri-network`, and its special retry repair checks the nonexistent key `sabri-network-meet`.

The active Meet eraser itself returns `items_retained=true, done=true` when transaction start or commit fails, even though its message says the erasure must be retried. Because the final wrapper never reaches this eraser, WordPress can stop retrying an operationally failed Meet erasure.

**Severity:** High privacy-completion / retry-truth defect.

### R7-D02 — Message-receipt erasure can report completion after a failed delete

`SN_Messages::erase_personal_data()` issues one bounded receipt delete. If the DELETE returns `false`, it adds a failure message but computes `done` only from the number of selected IDs. With fewer than 500 rows, a failed deletion is therefore returned as `done=true`.

**Severity:** High privacy-erasure completion-truth defect.

### R7-D03 — Message-organization erasure ignores database failures and is not bounded by an erasure batch

`SN_Message_Operations::erase_data()` loads all folder IDs without a LIMIT, ignores failures deleting folder items, and combines folder/star/hide deletes with boolean expressions that can mask one or more failed writes. It always returns `done=true`.

**Severity:** High privacy progress/failure-truth and bounded-work defect.

### R7-D04 — Two-Plan extension erasure converts failed SQL writes into zero removals and can report `done=true`

`SN_Two_Plan_Completion::eraser()` casts the results of its scheduled-message DELETE and message-request UPDATE to integers. A database `false` becomes `0`, and `done` is then calculated as `$removed < 200`. `SN_Fourth_Fresh_Privacy_Hardening::erase_two_plan()` delegates to this base callback before poll-vote erasure, so the final active callback inherits the false-success condition.

**Severity:** High privacy-erasure completion-truth defect.

## Correction gate

No Round-8 review may begin until R7-D01 through R7-D04 are corrected, permanent regression coverage passes, and the exact resulting branch HEAD has green PHP 8.1 plus PHP 8.3/full-quality deterministic-package CI.