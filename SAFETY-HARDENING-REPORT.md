# File 17 — Safety, Retention and Report Integrity Hardening

**Target:** Sabri Network and Messages 2.0.0  
**Branch:** `codex/file-17-review-2.0.0`  
**Code commit:** `ffc36c80611b4cee5591b23397bd26bec63828e8`  
**Final cleanup/QA commit:** `dbaef05198bcaab5c0e890bc098b450a3416dd04`  
**GitHub Actions run:** `30698113280`  
**Result:** PASS; not staging-accepted, merged, live-deployed, or operational

## Implemented controls

- Added a native `SN_Safety` service while preserving File 17 as the canonical report-data owner.
- Added UUIDv4 client idempotency for report retries and a database uniqueness constraint per reporter/client UUID.
- Added canonical hashed target keys for user, conversation, and message report targets.
- Added bounded global and same-target reporting limits.
- Added evidence hashes and bounded report metadata.
- Added administrator report inventory and triage endpoints.
- Added optimistic record-version checks to prevent lost moderator updates.
- Added legal/safety holds that block ordinary retention deletion and privacy destruction where evidence must be retained.
- Added category-aware retention deadlines with bounded adapter overrides.
- Added an atomic option-lock around retention processing.
- Added two-stage retention: identifier/content anonymization first, then delayed deletion.
- Added checkpoint-compatible legacy report migration and operational counts.
- Expanded WordPress privacy export and erasure to declare retained evidence honestly and minimize identifiers where permitted.
- Added cryptographically generated client report UUIDs in the browser and retry-safe reuse of the same UUID.

## Corrective review findings

Two regression-contract defects were found during integration and corrected before acceptance:

1. The realtime suite still required schema `2.0.1` after the report schema advanced to `2.0.2`; the test was corrected to require the combined schema.
2. The original privacy adversarial suite looked only for the former inline report-erasure array; it was corrected to verify the new legal-hold-aware canonical erasure in `SN_Safety`.

A concurrent GitHub Actions materialization race rejected one bot push after all tests had passed. A parallel verified run had already committed the identical safety batch. Temporary payload/materializer files were then removed, and the ordinary read-only quality workflow was restored.

## Two review rounds

### Round 1 — comprehensive/static

- Initial static contracts: **60/60 PASS**
- Realtime static contracts: **37/37 PASS**
- Package static contracts: **8/8 PASS**
- Safety static contracts: **20/20 PASS**

### Round 2 — fresh/adversarial

- Initial adversarial contracts: **59/59 PASS**
- Realtime adversarial contracts: **33/33 PASS**
- Package adversarial contracts: **8/8 PASS**
- Safety adversarial contracts: **16/16 PASS**

**Total included contract checks: 241/241 PASS.**

Additional gates:

- PHP syntax: PASS for all included PHP files
- JavaScript syntax: PASS
- Shell syntax: PASS
- CSS integrity: PASS
- Repository hygiene: PASS
- Deterministic double-build and byte comparison: PASS
- Artifact creation/upload: PASS

## Verified package

**SHA-256:** `f32160217e98d7d69ab7fc263c442c08b97492b082fa2be6dde2dcbd11e28529`

The included checks have no known unresolved failure. This does not assert that undiscovered defects are impossible.

## Remaining production gates

- Production File 00/File 02 identity adapter and real-role acceptance
- File 19, File 20, File 24, and File 25 integration acceptance
- Approved malware scanner and private-storage operational validation
- Production STUN/TURN and approved SFU
- Staging fresh install, upgrade/migration, rollback, backup/restore, and real-content testing
- Penetration, dependency, load/race, browser/device, RTL/LTR, and accessibility acceptance
- Founder approval, merge, live deployment, and operational monitoring
