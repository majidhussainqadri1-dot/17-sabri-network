# File 17 — Dedicated Messages Surfaces and Receipt Reconciliation Report

**Target runtime:** Sabri Network and Messages 2.0.0  
**Branch:** `codex/file-17-review-2.0.0`  
**Pull request:** Draft PR #2  
**Date:** 2 August 2026  
**Production effect:** none; `main`, staging and live remain unchanged

## Coding batch

This batch closes two previously recorded coding gaps without creating a parallel communication backend.

### Dedicated Messages and settings surfaces

- Canonical `/messages/` inventory and `/messages/{conversation_id}/` deep links.
- Safe `/messages-safe/` fallback when an unrelated page occupies the preferred slug.
- File-17-owned `/communication-settings/` page with safe fallback.
- Existing conversation, message, contact, attachment and privacy APIs remain the system of record.
- Private surfaces are no-cache, noindex/noarchive and same-origin.
- File 20 receives route contracts; File 17 does not inject a second global header or navigation system.

### Native recipient/device receipts

- Native `sn_message_receipts` table.
- Unique `(message_id, user_id, device_key)` idempotency boundary.
- Opaque browser device identifiers are validated and replaced by user-scoped SHA-256 keys before persistence.
- Delivered/read timestamps are monotonic under retries.
- Receipt reconciliation is bounded to 500 records per transaction.
- Batches advance in ascending contiguous order from durable per-device progress.
- `last_read_message_id` advances only to the actual reconciled boundary, never directly to an unreconciled requested target.
- The response exposes requested target, actual through-point and whether another batch remains.
- Sender summaries count distinct recipients rather than devices.
- Historical recipient totals use membership state at message creation, not only current membership.
- Privacy export/erasure, retention cleanup, health reporting, rate limits and native audit evidence are included.

## Review and correction record

### Review round 1 — Comprehensive static contract review

**29/29 PASS** after correction.

Coverage includes canonical ownership, safe page creation, deep links, no-cache/noindex behavior, receipt schema, membership authorization, sender-only summaries, existing API reuse, responsive UI and bounded continuation semantics.

### Review round 2 — Fresh adversarial review

**35/35 PASS** after correction.

The fresh review detected a material batching defect in the first draft: a bounded newest-first slice could have advanced the member read pointer to a target beyond unreconciled messages. The implementation was corrected to resume from durable device progress, process ascending contiguous ranges, return an explicit continuation flag and advance the pointer only through the processed boundary.

The same review added keyboard modal focus containment/restoration, historical membership-aware recipient totals, raw-device minimization, monotonic retry rules, transaction rollback evidence, same-origin attachment enforcement and reduced-motion/RTL checks.

## Truthful status

This batch makes dedicated Messages/deep-link/settings surfaces and per-recipient multi-device receipt reconciliation coded and reviewable. File 17 remains **not 100% complete** against its full governing specification. Staging, real integrations, browser/device acceptance, load/security testing, rollback and operational gates remain outstanding.
