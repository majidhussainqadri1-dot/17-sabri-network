# File 17 — Next Fresh 10-Round Audit — Round 8 Frozen Ledger

**Round:** 8  
**Reviewed parent:** `0bc5d388aa8fffa5254deb241961b75939668e9b`  
**Review discipline:** privacy/safety review completed before correction; no Round-8 fix began during review.

## Scope reviewed

Reviewed hardened communication export/erasure ordering and File-17-wide hold guard (`SN_Privacy_Runtime_Hardening`), extension-domain erasers and progress semantics (`SN_Fifth_Fresh_Privacy_Hardening`, `SN_Sixth_Fresh_Privacy_Hardening`, `SN_Fourth_Fresh_Privacy_Hardening`), native safety/report redaction and mutation serialization (`SN_Safety_Runtime_Hardening`, `SN_Fourth_Fresh_Safety_Hardening`), protected attachment revocation/physical deletion (`SN_Private_Files`), and the global mutation/access boundary relevant to privacy (`SN_Runtime_Boundary_Policy`).

## Frozen defect ledger — R8

### R8-D01 — Failed private attachment byte deletion is silently abandoned after the fifth retry

`SN_Private_Files::delete()` correctly revokes the attachment row before byte deletion and schedules `sn_network_retry_private_delete` if `unlink()` fails. `SN_Private_Files::retry_delete_bytes()` then retries with bounded backoff, but schedules another retry only while `attempts < 5`. After the fifth failed attempt the database row remains revoked and the private file may still physically exist, yet no further retry is scheduled and no durable stalled-deletion workflow remains.

This matters directly to privacy erasure because message/update erasure can legitimately finish its relational work after access revocation while relying on the byte-deletion retry workflow for physical destruction. A transient or operator-correctable filesystem permission/storage fault persisting through five attempts can therefore become permanent retained private bytes without a future self-healing attempt.

**Severity:** High privacy-erasure / private-storage recovery defect.

**Correction boundary:** never silently abandon a known revoked private object whose bytes still exist. Keep capped-backoff retries scheduled, emit explicit stalled-deletion evidence after the initial retry threshold, and add a permanent regression proving retry exhaustion cannot terminate the deletion workflow. Then run exact-head CI before Round 9.
