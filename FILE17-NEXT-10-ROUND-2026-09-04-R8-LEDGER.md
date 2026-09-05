# File 17 — Next Fresh 10-Round Review — Round 8 Frozen Ledger

**Round:** 8  
**Reviewed parent:** `2875f52b1e19d032a9b0cdea05e33585973d23e6`  
**Scope:** final knowledge/citation/case-discussion route precedence plus interoperability replay/receipt/reconciliation truth.  
**Discipline:** the entire Round-8 review was completed before any correction began.

## Verified clean areas before freeze

- AI-assistant and private semantic-search final route ownership is correctly restored by `SN_Fifth_Fresh_Knowledge_Hardening` after the older Fourth-Fresh owner, preserving explicit-consent, File-16 authorization, redaction, current membership, minor-policy and final visibility checks.
- Interoperability outbound uses caller-owned idempotency, payload binding, operation locking, pre-side-effect message/bridge revalidation and provider-side reconciliation before any retry of an uncertain external side effect.
- Inbound interoperability binds a stable external event identifier to a payload hash and keeps accepted local delivery idempotent through a durable receipt.
- Bridge shutdown is fail-closed: local kill-switch state is persisted before external provider shutdown and uncertain outcomes require reconciliation.

## Frozen defects

### R8-D01 — final citation and case-discussion routes regress behind the stronger Future24-C governance contract

`SN_Future24_Review_Hardening_C` at priority 1975 enforces the stronger citation/case contract: canonical source owner must report `exists/current/allowed`; case discussions must pass approved de-identification, consent sufficiency, current professional permission, locked membership revalidation and a bounded retention write.

`SN_Fourth_Fresh_Knowledge_Hardening` re-registers `/future/citations` and `/future/case-discussions` later at priority 2300 and becomes the final route owner. Its citation route uses a different/weaker resolver contract and does not require the canonical owner `exists/current/allowed` truth. Its case-discussion route checks only a boolean de-identification filter before delegating to the older superset path, bypassing the stronger consent/professional/retention transaction in Future24-C.

**Severity:** High governance/clinical-privacy/source-authority route-precedence defect.

**Required correction:** make the final route owner delegate citation and case-discussion requests to the stronger Future24-C implementations (or duplicate their full contract without weakening it), while preserving the existing conversation serialization boundary.

### R8-D02 — stronger Future24-C case-discussion transaction does not prove `START TRANSACTION`

The stronger `SN_Future24_Review_Hardening_C::create_case_discussion()` path performs a locked membership/professional-authority recheck and retention-boundary write inside a transaction, but its `START TRANSACTION` result is not checked before those protected reads/writes begin. Commit is checked, but a refused transaction start could allow autocommit writes and invalidate rollback/atomicity assumptions.

**Severity:** High case-discussion retention/privacy atomicity defect.

**Required correction:** fail closed before any transactional read/write when transaction start is not confirmed.

### R8-D03 — interoperability outbound can report confirmed success without durably persisting the `sent` receipt state

`SN_Fourth_Fresh_Interop_Hardening::outbound()` correctly requires provider confirmation, but both the reconciliation-success branch and the fresh provider-confirmed branch call `set_receipt_state(..., 'sent', ...)` without checking the returned `WP_Error`. The response can therefore report `queued=true`/success even when the durable receipt remains `sending`/`uncertain` because of a CAS/crypto/database failure.

The same helper is checked correctly in inbound `processed` finalization, proving the intended persistence contract.

**Severity:** High external-side-effect/idempotency truth defect.

**Required correction:** every transition that permits an outbound success response must prove the durable `sent` receipt update. If the provider outcome is confirmed but local finalization fails, return a reconciliation-required/fail-closed response and do not claim durable success.

## Correction gate

No Round-9 review may begin until R8-D01 through R8-D03 are corrected, permanent regression coverage passes, and the exact resulting branch HEAD has green PHP 8.1 plus PHP 8.3/full-quality deterministic-package CI.