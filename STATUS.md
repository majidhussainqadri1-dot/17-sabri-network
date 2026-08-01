# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.0  
**State:** reviewed and coded candidate; draft PR  
**Known failures in included suites:** 0  
**Included contract checks:** 241/241 PASS  
**Latest confirmed source QA run:** `30698113280` — quality, deterministic build, and artifact upload PASS  
**Safety hardening code commit:** `ffc36c80611b4cee5591b23397bd26bec63828e8`  
**Read-only workflow cleanup commit tested by that run:** `dbaef05198bcaab5c0e890bc098b450a3416dd04`  
**Current deterministic package SHA-256:** `f32160217e98d7d69ab7fc263c442c08b97492b082fa2be6dde2dcbd11e28529`  
**Runtime double-build byte comparison:** PASS  
**Staging/live/operational:** not completed

The continued coding now includes privacy-scoped presence, expiring typing state, native mute/archive preferences, mute-aware fallback notifications, channel publishing authority, active-call membership revocation, signaling membership enforcement, polling-state preservation, and expanded privacy export/erasure coverage.

The latest safety hardening adds report submission idempotency, canonical report target keys, bounded global and same-target limits, evidence hashes, administrator triage with optimistic version checks, legal/safety holds, category-aware retention deadlines, locked retention cleanup, staged anonymization/deletion, legacy report migration, privacy minimization, and operational safety counts. File 24 may consume assurance evidence but does not replace File 17's native enforcement.

Packaging fixes locale, timezone, timestamps, modes and entry ordering; strips extra ZIP metadata; rejects symlinks; and performs two independent builds with hash and byte comparison inside the quality gate.

Remaining gates include production identity and integration adapters, scanner/TURN/SFU operations, staging migration and rollback, real-role acceptance, penetration/dependency/load/race testing, backup/restore verification, browser/device/accessibility acceptance, and Founder approval.
