# File 17 — Next Fresh 10-Round Audit — Round 8 Closure

**Round:** 8  
**Reviewed parent:** `0bc5d388aa8fffa5254deb241961b75939668e9b`  
**Review discipline:** privacy/safety review completed before the ledger was frozen; no Round-8 fix began during review.

## Scope reviewed

Reviewed hardened communication export/erasure ordering and File-17-wide hold guard (`SN_Privacy_Runtime_Hardening`), extension-domain erasers and progress semantics (`SN_Fifth_Fresh_Privacy_Hardening`, `SN_Sixth_Fresh_Privacy_Hardening`, `SN_Fourth_Fresh_Privacy_Hardening`), native safety/report redaction and mutation serialization (`SN_Safety_Runtime_Hardening`, `SN_Fourth_Fresh_Safety_Hardening`), protected attachment revocation/physical deletion (`SN_Private_Files`), and the global mutation/access boundary relevant to privacy (`SN_Runtime_Boundary_Policy`).

## Frozen defect ledger — R8

### R8-D01 — Failed private attachment byte deletion was silently abandoned after the fifth retry

`SN_Private_Files::delete()` correctly revokes the attachment row before byte deletion and schedules `sn_network_retry_private_delete` if `unlink()` fails. Its original retry owner stopped scheduling after the fifth failed attempt, so a revoked private object could remain physically present forever after a persistent but operator-correctable storage fault.

**Severity:** High privacy-erasure / private-storage recovery defect.

## Correction after ledger freeze

`SN_Sixth_Fresh_Privacy_Hardening` now registers an after-owner continuation on `sn_network_retry_private_delete` at `PHP_INT_MAX`. The canonical `SN_Private_Files` owner remains the only component that performs the protected unlink; the later privacy hardening only checks whether a revoked object's contained path still exists after the canonical attempt. If bytes remain, another canonical retry is scheduled with capped hourly backoff instead of abandoning cleanup. After the initial retry threshold, one bounded stalled-deletion audit/action is emitted so operators can repair the underlying storage fault while automatic cleanup continues.

The continuation verifies that the reconstructed storage path remains within File-17's private root before scheduling recovery work and does not restore authorization to the revoked object.

## Regression

Permanent current-boundary assertions were added to `seventh-fresh-ten-round-contracts.php` proving:

- the after-owner private-byte recovery hook is registered;
- the durable retry method remains present;
- a still-existing revoked private object schedules another canonical deletion attempt;
- retry exhaustion emits stalled-deletion evidence rather than terminating the workflow.

**Exact-head CI requirement:** this final Round-8 closure HEAD must pass PHP 8.1 current-boundary, PHP 8.3 full quality/deterministic packaging and governed artifact upload before Round 9 begins.
