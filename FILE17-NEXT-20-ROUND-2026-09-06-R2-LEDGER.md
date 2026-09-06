# File 17 — Next Fresh 20-Round Review — Round 2 Frozen Ledger

Branch: `review/file17-next-20-round-2026-09-06`
Reviewed parent: `2cd2820dcb284cbd372143fd50416f7209e7f527`
Discipline: the complete Round-2 identity/relationship/authorization review was completed before this ledger was frozen. No Round-2 source correction was started during review.

## Review scope completed

- File-00/File-02 communication assertion consumption and fail-closed identity eligibility;
- contact request/decision lifecycle and pair serialization;
- follow/unfollow policy and block precedence;
- direct-conversation creation/restoration and canonical pair uniqueness;
- Space/Smail conversation-membership ownership boundaries;
- ownership-transfer delegation to the later governed high-risk owner;
- advisory locks, transaction start/commit reconciliation and current state confirmation;
- minor/guardian/contact/message/follow policy decisions and privacy-safe target visibility;
- active-call revocation during blocking and relationship-change paths;
- REST boundary membership checks and prevention of independent Space conversation ownership.

## Clean findings

- Positive relationship mutations fail closed when policy/block truth is unavailable.
- Active contact/block/direct-conversation mutations are serialized by canonical advisory locks and use checked transaction-start/commit behavior.
- Non-direct conversation creation resolves an already-owned Space conversation instead of creating a parallel membership graph.
- Generic conversation-member mutation cannot bypass canonical Space/Smail membership owners.
- Direct messaging creation revalidates `SN_Policy::can_contact(..., 'message')` after locks are held.
- Current block cleanup uses checked mutation queries and checked active-call ledger reads before reporting success.
- Lower-level ambiguous reads found during review are either non-mutating/fail-closed or are superseded by later active route owners; no new current security/correctness defect was proved in this scope.

## Frozen defects

None.

## Round verdict

R2 is clean. Exact-head PHP 8.1 and PHP 8.3/full-quality CI must be green on this ledger HEAD before Round 3 begins.
