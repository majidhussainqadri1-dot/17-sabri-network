# File 17 — Next Fresh 10-Round Audit — Round 7 Closure

**Round:** 7  
**Reviewed parent:** `7361781d088d4eb2e26d14d7506aab70848df2df`  
**Review discipline:** the call/meeting review was completed before the ledger was frozen; no Round-7 correction was started during review.

## Scope reviewed

Reviewed the call/Meet mutation lock boundary and File-00 refresh (`SN_Call_Runtime_Hardening`), meeting-object authorization/token-delivery checks (`SN_Fourth_Fresh_Call_Hardening`), provider configuration/health/short-lived media credential issuance (`SN_Conference_Provider`), Sabri Meet control-plane route and membership model (`SN_Meet`), and the canonical space/conversation membership synchronization established in Round 6.

## Frozen defect ledger — R7

### R7-D01 — Media/call/meeting locks did not join the canonical space-owner lock for space-backed conversations

`SN_Call_Runtime_Hardening` serialized call/meeting mutations with call/meeting and conversation locks, plus a pair lock for direct conversations. A space-backed group/channel membership removal/ban/lifecycle transition is serialized under `sn:f17:space:*`, so the two mutation domains did not share a lock. A media mutation could therefore race canonical space removal after a stale membership proof.

**Severity:** High authorization / media-credential race.

## Correction after ledger freeze

`SN_Call_Runtime_Hardening` now resolves a canonical File-17 space by `conversation_id` and adds the exact `sn:f17:space:*` owner lock for meeting creation, existing meeting mutations, call creation and existing call mutations. All acquired locks remain de-duplicated and globally sorted before acquisition, preserving deadlock discipline. Direct conversations retain their relationship pair lock as before.

## Regression

Permanent current-boundary assertions were added to `seventh-fresh-ten-round-contracts.php` proving:

- the canonical space-owner lock resolver exists;
- all four call/meeting conversation mutation branches join it;
- the lock uses the same `sn:f17:space:*` namespace as space governance.

**Exact-head CI requirement:** the final Round-7 correction/regression/closure HEAD must pass both CI jobs and governed package upload before Round 8 begins.
