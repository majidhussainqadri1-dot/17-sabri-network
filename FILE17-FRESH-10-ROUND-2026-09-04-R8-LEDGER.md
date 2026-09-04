# File 17 — Fresh 10-Round Review — Round 8 Frozen Ledger

**Round:** 8  
**Reviewed parent:** `aa733552e85ef28eee26e3e6a6866dcdd652777b`  
**Scope:** final AI-assistant/private-semantic route precedence, consent and purge lifecycle, private-result visibility, provider fail-closed behavior, interoperability bridge creation/listing, inbound/outbound replay and payload binding, kill-switch/reconciliation, and cross-file knowledge/citation authority.  
**Discipline:** the entire Round-8 review was completed before any correction began.

## Frozen defects

### R8-D01 — Final interoperability bridge creation treats materially different requests as an idempotent success

`SN_Future24_Review_Hardening_H::create_bridge()` derives its bridge uniqueness/client key from conversation + protocol + remote endpoint + remote room, but does not bind the committed request to other material bridge parameters such as `direction` and `credential_ref`. `create_record_once()` returns an existing record as `created=false` without proving that its encrypted payload matches the current request, while the response is built from the newly requested `$data` rather than the committed bridge payload.

Therefore a retry/reuse against the same bridge identity can request different direction/credential-reference semantics, receive HTTP success/idempotent response describing the new request, while the canonical stored bridge remains the older configuration.

**Severity:** High idempotency / configuration-truth defect.

**Required correction:** on existing bridge identity, decode the committed bridge and compare every stable material configuration field; return explicit HTTP 409 on mismatch and return the committed bridge on a true duplicate. Add permanent regression coverage.

### R8-D02 — Semantic-consent withdrawal accepts ambiguous purge-adapter results as successful deletion confirmation

`SN_Future24_Review_Hardening_G::semantic_consent()` and `retry_semantic_purges()` currently consider any purge adapter result that is non-null, non-false and not a `WP_Error` to mean purge completed. An empty array, arbitrary string, or unconfirmed structured response can therefore clear `purge_pending` even though the private semantic provider never explicitly confirmed deletion.

For withdrawal of consent, purge completion must be fail-closed and evidence-bearing.

**Severity:** High consent-revocation / private-data lifecycle defect.

**Required correction:** require an explicit confirmed purge result (for example boolean `true`, or a structured response with `confirmed=true` and a terminal purged/deleted state); ambiguous results must keep `purge_pending=true` for scheduled retry. Apply the same rule to the hourly retry path and add permanent regression coverage.

## Correction gate

Round 9 must not begin until both defects are corrected, permanent regressions pass, and exact-head PHP 8.1 + PHP 8.3 CI is green.
