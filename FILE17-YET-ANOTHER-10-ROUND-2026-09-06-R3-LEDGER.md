# File 17 — Yet Another Fresh 10-Round Review — R3 Ledger Freeze

Reviewed parent/source candidate: `4973997802565ae02769c2e4cede986fb4edd57f`

Method: review completed first; this ledger freezes the complete R3 defect set before any R3 production correction.

R3 scope: final REST route precedence and mutation truth for message forwarding, mentions, pins, stars, private hides, folders, Smail and file-transfer surfaces. Later route owners were resolved before judging legacy code. The final forward owner (`SN_Fourth_Fresh_Review_Hardening` -> `SN_Compatibility_Hardening`) was confirmed to require a caller-owned idempotency key, decrypt/re-encrypt cross-conversation content, hold transactional membership/policy checks and reject private attachment reuse. The final Smail send/draft and transfer-initiation owners were also reviewed; no additional R3 defect was proved there.

## Frozen defects

### R3-D01 — Pin/unpin mutation is not serialized with current management authority and unpin can report false success
The final `/messages/{id}/pin` route remains `SN_Message_Operations::change_pin()`. It reads membership/role before mutation, does not lock the message/member authority rows through the change, and its `unpin` branch ignores a failed `$wpdb->delete()` while returning `pinned=false`. A role/membership change or SQL failure can therefore produce a success response without proved committed authority/state.

### R3-D02 — Star/unstar and private-hide removal/mutation paths can report success after failed SQL
The final `/messages/{id}/star` and `/messages/{id}/hide` routes remain on `SN_Message_Operations`. `unstar` ignores the result of `$wpdb->delete()` and returns `starred=false`; the analogous folder-item remove path also ignores deletion failure (R3-D03 below). Positive star/hide writes check query failure, but the remove direction does not preserve database-failure truth.

### R3-D03 — Folder-item removal can report `included=false` after a failed delete
The final `/message-folders/{id}/conversations` mutation calls `SN_Message_Operations::change_folder_item()`. Its remove branch does not check `$wpdb->delete()` and returns success even if the row remains.

### R3-D04 — Folder-count limit is a check-then-insert race
The final `/message-folders` POST route reads `COUNT(*)`, checks the 50-folder limit and then inserts without a per-user lock/transaction. Concurrent creates can both pass the count and commit more than the governed maximum. The unique `(user_id,slug)` key does not enforce the aggregate count.

## Ledger state
`FROZEN — 4 defects. No R3 production fix was started before this freeze.`
