# Repository Status

**Target:** File 17 — Sabri Network and Messages 2.0.0  
**State:** reviewed and coded candidate; draft PR  
**Known failures in included suites:** 0  
**Included contract checks:** 205/205 PASS  
**Latest corrective QA run:** `30695265916` — success  
**Current deterministic package SHA-256:** `48a2b4e6089b66f2085c816786dcc25f1b3b2a331ef588b5d894f889895b7c3d`  
**Staging/live/operational:** not completed

The continued coding adds privacy-scoped presence, expiring typing state, native mute/archive preferences, mute-aware fallback notifications, channel publishing authority, active-call membership revocation, signaling membership enforcement, polling-state preservation, and expanded privacy export/erasure coverage.

A subsequent artifact comparison exposed non-deterministic ZIP metadata. Packaging now fixes locale, timezone, timestamps, modes and entry ordering; strips extra ZIP metadata; rejects symlinks; and performs two independent builds with hash and byte comparison inside the quality gate.

Remaining gates include production identity and integration adapters, scanner/TURN/SFU operations, staging migration and rollback, real-role acceptance, penetration/dependency/load/race testing, backup/restore verification, browser/device/accessibility acceptance, and Founder approval.
