# File 17 — Fresh 20-Round Review — Round 18 Ledger Freeze

Date: 2026-09-06
Review branch: `review/file17-fresh-20-round-2026-09-06`
Immutable review-start HEAD: `f06e1da29b9709888a2ac74574ab0228578ad43c`
Review discipline: **Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

## Review completed before correction

Round 18 completed an adversarial governance/authorization/transaction review of the immutable R17-green source. No production or test correction was made while the R18 review was in progress. Review-only inventory workflows checked the immutable review-start SHA.

Reviewed surfaces included:

- canonical conversation ownership transfer and late route override;
- space ownership transfer and owner-role bypass resistance;
- high-risk action request / two-approver / execution separation;
- legal-hold release and report closure;
- conference provider configuration;
- declared but currently unexposed high-risk types (`space_emergency_recovery`, `space_destructive_purge`, `provider_key_rotation`, `retention_override`);
- direct owner-id writes and route ownership;
- quality/regression behavior, including previously observed runtime warnings.

## Findings that were investigated and NOT defects

### Ownership transfer is not bypassing dual control

The base `SN_REST::transfer_conversation_owner()` implementation is superseded by later canonical route ownership. `SN_Fourth_Fresh_Review_Hardening::override_routes()` replaces `/conversations/{id}/owner`, and its callback binds the exact payload to `SN_High_Risk::claim(..., 'conversation_ownership_transfer', ...)` and `SN_High_Risk::complete(...)`. `SN_Relationship_Runtime_Hardening` also forwards to this governed implementation and fails closed if unavailable.

Space ownership transfer similarly uses `SN_High_Risk::claim(..., 'space_ownership_transfer', ...)` and `complete(...)`, while generic role mutation rejects `owner` assignment.

### Other exposed high-risk mutations are governed

- provider configuration → high-risk claim + complete;
- report closure (`mass_moderation`) → high-risk claim + complete;
- legal-hold release → separate high-risk claim + complete.

The other declared high-risk types found in `SN_High_Risk::TYPES` have no mutation endpoint/call site in the reviewed source, so no bypass is presently exposed for them.

## Confirmed defect

### R18-D01 — HIGH — The canonical quality gate can report green while PHP test warnings/notices are emitted

Concrete evidence from the R17 exact-head quality run showed `relationships-adversarial-contracts.php` emitting runtime warnings at its Yet-R2 assertion because a double-quoted source-code needle interpolates `$wpdb` in the test process. The test still exits successfully, so the full quality workflow can remain green despite runtime warnings.

Root cause has two layers:

1. `relationships-adversarial-contracts.php` uses an interpolation-prone double-quoted code needle for `$wpdb->last_error`, producing an undefined-variable warning and weakening the intended exact source assertion.
2. `sabri-network/tools/quality-check.sh` executes PHP test scripts normally; PHP warnings/notices/deprecations do not necessarily produce a non-zero process exit, so the canonical quality gate is fail-open to this class of test-runtime defect.

Required correction after this ledger freeze:

1. Correct the interpolation-prone test assertion so it checks the literal `$wpdb->last_error` source safely.
2. Add a canonical warning-to-failure bootstrap for PHP regression scripts so warning/notice/deprecation output becomes a failing quality signal rather than a green run.
3. Route the canonical PHP regression runner through that bootstrap without weakening syntax, packaging, or deterministic-package gates.
4. Run the full suite; any newly surfaced warning-causing test defects must be corrected before Round 19 starts.

## Ledger status

**FROZEN — 1 confirmed defect. No R18 correction occurred before this freeze.**
