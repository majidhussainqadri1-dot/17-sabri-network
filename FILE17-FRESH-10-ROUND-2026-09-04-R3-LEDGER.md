# File 17 — Fresh 10-Round Review — Round 3 Frozen Ledger

**Round:** 3  
**Reviewed parent:** `05243e59610814b3ca1c850a0c463957a664fb1e`  
**Scope:** canonical message mutation, message-body encryption/key rotation, private attachments/download integrity, voice-note final route precedence, private search indexing/rebuild, hidden-message visibility, and transactional outbox/inbox delivery.  
**Discipline:** review completed before correction.

## Frozen defect

### R3-D01 — Administrator private-search rebuild bypasses the active lossless rebuild owner

The active hourly/epoch rebuild path is `SN_Fourth_Fresh_Search_Hardening`, which deliberately stops on an indexing error, preserves the high-water mark, records an error, and keeps the rebuild unavailable/retryable. However, the administrator route `/admin/message-search/rebuild` still resolves to `SN_Message_Search::rebuild()`. That legacy method truncates the index and calls the legacy `SN_Message_Search::backfill()`, which advances over `index_message()` failures and can reset the high-water mark to zero when no rows are returned. It also does not publish the active rebuild/error state used by `SN_Runtime_Boundary_Policy` to fail private search closed during reconstruction.

An administrator-triggered rebuild can therefore report success while the private index has silently skipped messages or is in an incoherent reconstruction state.

**Severity:** Critical private-search completeness / operational-truth defect.

**Required correction:** make the final administrator rebuild route use the lossless fourth-fresh search owner, explicitly publish rebuild state, stop and remain retryable on any indexing failure, and preserve the monotonic cursor. Add permanent regression coverage and exact-head CI before Round 4.

## Other reviewed boundaries

No additional unresolved defect was proven in this round after final-route precedence was accounted for. Canonical message send is owned by the fourth-fresh wrapper over `SN_Message_Runtime_Hardening`; the priority-3000 voice-note route uses that owner; private-download integrity authorization precedes hashing; and revoked private-byte retry continuation is supplied by the sixth-fresh privacy owner.
