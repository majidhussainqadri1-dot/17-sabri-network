# File 17 — Sabri Network and Messages 2.0.0
## Code Review, Defect Remediation and QA Report

**Repository:** `majidhussainqadri1-dot/17-sabri-network`  
**Baseline package:** File 17 — Sabri Network 1.1.0  
**Baseline ZIP SHA-256:** `ebddffd4b5b157d50b58680767a6525fec84477fd91afeb58d6f257c77571400`  
**Review date:** 2026-08-01  
**Result:** reviewed 2.0.0 code candidate; not staging-accepted, live-deployed, or operational

## 1. Governing architectural decision

File 17 is the single canonical communication owner for Network relationships, contacts, conversations, messages, temporary updates, calls, signaling, blocks, reports, and File-17 privacy/audit records. It does not create a parallel identity system, global shell, public-profile backend, notification service, clinical record system, or security-governance center.

Network and Messages therefore share one backend and one authorization model. File 00/File 02 remain identity authorities; File 19 may deliver notifications; File 20 owns global navigation; File 24 receives assurance evidence without replacing native controls; File 25 may render action controls while File 17 authorizes and executes them.

## 2. Baseline defects and remediation

### Identity and ownership

- Removed the legacy OTP/account-creation pathway and retired stored SMS/TURN secrets.
- Added fail-closed identity-authority availability, suspension, minor, guardian-consent, verification, capability, and projection contracts.
- Removed duplicate global navigation injection and unsafe takeover of an unrelated `/network` page.
- Added a transactionally locked ownership-transfer endpoint and UI before a non-direct conversation owner may leave.

### Authorization and relationship consent

- Added server-side membership/object authorization across conversations, messages, calls, signaling, contacts, updates, private files, and reports.
- Required accepted contact relationships for direct messaging and calls.
- Added block enforcement and conservative minor-contact/discoverability defaults.
- Bound report targets to the reported message or conversation membership.

### Database integrity and race resistance

- Added unique direct-conversation, contact-pair, message-idempotency, reaction, membership, call-membership, and active-call keys.
- Added transactional conversation creation, call creation/state transitions, blocking cleanup, and ownership transfer.
- Added stale-call cleanup and active-call key backfill/repair.
- Added database-first expired-update deletion and protected shared private attachments from premature deletion.

### Private attachments

- Replaced new public Media Library storage with a private ledger and storage outside the WordPress web root.
- Added existing-directory symlink resolution, path containment, extension/MIME/signature checks, image normalization, hashes, scanner contract, size limits, authorization-gated delivery, no-store headers, safe range handling, and safe content disposition.
- Required scanner evidence for documents and withheld legacy public-media references pending controlled migration.
- Changed deletion order so authorization is revoked before byte removal; byte-deletion failure is audited without restoring public access.
- Prevented a zero-length file read from creating a stalled streaming loop.

### Messaging and calls

- Added message idempotency, bounded payloads, edit/delete windows, deleted-message reaction rejection, and cleanup of duplicate/failed private uploads.
- Added call state validation, one active call per conversation, short-lived membership-scoped ICE credentials, signal sanitization/acknowledgement, stale-call cleanup, and block-triggered call termination.
- Kept built-in calls direct-only. Group calls require an approved SFU, capability decision, provider create result, and explicit UI availability.
- Removed any unsupported E2EE claim.

### Privacy and interface

- Added WordPress privacy export/erasure coverage for messages, updates, contacts, calls, reports, and notifications.
- Preserved shared/group data while erasing the requesting user's attributable details where no hold applies.
- Rebuilt the interface for responsive layouts, visible focus, focus trapping/restoration, RTL, reduced motion, low-width behavior, safe dynamic URLs, safe timestamp parsing, and explicit ownership transfer.

## 3. Review Round 1 — comprehensive static contracts

Result: **60/60 PASS**.

This round covers version/API boundaries, identity ownership, REST authorization, consent, database uniqueness, idempotency, call state, signal acknowledgement, reporting, private storage and delivery, privacy hooks, File-20 route ownership, page repair, UI focus/RTL/reduced-motion, dangerous execution functions, and unsupported security claims.

## 4. Review Round 2 — fresh/adversarial contracts

Result: **59/59 PASS**.

This independent round focuses on negative paths, stale state, race conditions, authority bypass, minor exposure, shared attachment lifecycle, TURN/SFU boundaries, call cleanup, report-target integrity, erasure side effects, route/shell duplication, protocol allowlisting, release reproducibility, ownership-transfer races, symlinked storage, malformed timestamps, and upgrade cron recovery.

**Combined included checks:** **119 PASS**. This means no known failure remains in the included suites; it is not a claim that the software is incapable of containing an undiscovered defect.

## 5. Additional verification

- PHP syntax lint: PASS for all PHP files.
- JavaScript syntax check: PASS.
- Shell syntax check: PASS.
- CSS brace/responsive integrity check: PASS.
- Repository hygiene checks: PASS.
- Reproducible ZIP integrity: PASS.
- Final package SHA-256: `212e1b89f9e4c4741b10705d5fd88085068b4d0c129635c6434867094d7e50e0`.

## 6. Remaining production gates

The following are intentionally not represented as completed:

- File 00/File 02 production identity adapter and real-role authorization acceptance;
- File 19 notification delivery and File 20/File 25 integration acceptance;
- approved malware scanner and private storage operational validation;
- production STUN/TURN and approved SFU for group calls;
- staging fresh-install, upgrade/migration, rollback, backup/restore, and real-content testing;
- penetration testing, dependency review, load/race testing, browser/device/accessibility acceptance;
- private incident/runbook operations and Founder approval;
- live deployment and operational monitoring.

## 7. Truthful completion status

The code review, identified-defect correction, local syntax checks, two independent contract-review rounds, and reproducible packaging are the deliverables of this engineering pass. Production completion requires the remaining external and staging gates above.

## 8. Continued coding batch — realtime state, channel authority and call revocation

A further controlled coding batch was completed after the initial 2.0.0 review:

- added privacy-scoped `online`, `away`, `offline`, and `last_seen_at` presence with 90-second expiry, bounded heartbeats, cleanup, and no IP/device fingerprint storage;
- added expiring typing indicators limited to active conversation members, with block enforcement, posting-authority checks, and rate limits;
- activated the existing native member mute/archive fields through REST and responsive UI controls, including an archived-conversation view;
- made local fallback message notifications mute-aware while preserving mute context for the File-19 adapter;
- enforced owner/moderator-only channel publishing by default and prohibited peer calls in broadcast channels;
- required current conversation membership for call inventory, call-state changes, signaling reads/writes, and signal acknowledgements;
- transactionally revoked active call membership and queued signals when a member leaves or is removed, ending under-populated calls safely;
- corrected polling so list refreshes merge summary data instead of discarding detailed active-conversation membership and authority state;
- extended WordPress privacy export/erasure to conversation preferences, presence, and typing state.

### Added QA evidence

- Realtime static contracts: **37/37 PASS** locally.
- Realtime adversarial contracts: **33/33 PASS** locally.
- Added checks: **70 PASS**.
- Combined included contract checks after this batch: **189 PASS**, subject to GitHub Actions confirmation on the updated branch.

The candidate remains a controlled review build. Staging, external adapters, penetration/load testing, Founder approval, merge, and live deployment remain separate gates.
