# File 17 — Fresh 20-Round Review — Round 7 Ledger Freeze

Date: 2026-09-06
Reviewed parent HEAD: `63b5a86ef40244979f3bc4507c59a06ee56fd5e3`
Method: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before fix

Round 7 completed a fresh whole-candidate review with deep emphasis on canonical message send/edit/delete/forward/receipt/search behavior, caller-owned idempotency, exact-request replay comparison, attachment identity, optimistic message versions, membership/block policy, output truth after commit, and final route precedence. The runtime message owner and the later fourth-fresh final route owner were traced together rather than treating an earlier callback as final.

## Frozen defect ledger

### R7-D01 — authoritative message reads can collapse SQL failure into absence/idempotency conflict
Severity: Medium-High
Status: PROVED

Several final/canonical message decisions do not distinguish a database read failure from a genuine missing/mismatching row:

- the first `smail_messages`-style pattern also exists in canonical message send: initial `messages` idempotency lookup is unchecked before deciding that no prior request exists;
- `same_message_request()` reads the canonical private attachment row without checking `$wpdb->last_error`, so SQL failure can become a false request mismatch and therefore an idempotency conflict;
- the final edit/delete owner obtains its pre-lock message probe through `message_row()` and can convert a failed authoritative read into `not_found`;
- the post-commit message re-read used for the response is not required to prove read success before formatting committed truth.

This creates misleading 404/409 outcomes and, after commit, can fail to distinguish “mutation committed but response projection read failed” from a normal successful response.

## Required correction frozen

Authoritative message reads that decide absence, replay identity, or committed response truth must explicitly clear/check `$wpdb->last_error` (or use an equivalent fail-closed helper). Database truth failure must return a retryable 503-style error rather than 404/409. Exact-request replay comparison must propagate `WP_Error` distinctly from `false` mismatch. Post-commit response reconstruction must fail with explicit committed-but-response-unavailable evidence instead of formatting an unverified row.

## Regression requirement

Permanent existing message regression suites must prove the explicit DB-read failure path, `bool|WP_Error` duplicate comparison, and checked post-commit message response read.

## Freeze statement

No production code was changed during Round 7 review. This ledger freezes the complete Round 7 finding set before correction.
