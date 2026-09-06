from pathlib import Path

# R02-D01: refresh canonical File-00 assertions after relationship locks are held.
p=Path('sabri-network/includes/class-sn-relationship-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
repls=[
("""        return self::with_locks([SN_Relationships::pair_lock_name($actor, $target)], function () use ($actor, $target, $wpdb) {
            $policy = SN_Policy::can_contact($actor, $target, 'request');
""",
"""        return self::with_locks([SN_Relationships::pair_lock_name($actor, $target)], function () use ($actor, $target, $wpdb) {
            SN_Membership_Assertions::clear_cache($actor);
            SN_Membership_Assertions::clear_cache($target);
            $policy = SN_Policy::can_contact($actor, $target, 'request');
"""),
("""                if ($decision === 'accept') {
                    $policy = SN_Policy::can_contact($requester, $actor, 'request');
""",
"""                if ($decision === 'accept') {
                    SN_Membership_Assertions::clear_cache($requester);
                    SN_Membership_Assertions::clear_cache($actor);
                    $policy = SN_Policy::can_contact($requester, $actor, 'request');
"""),
("""        return self::with_locks($locks, function () use ($actor,$members,$wpdb) {
            $target = $members[0];
            $policy = SN_Policy::can_contact($actor,$target,'message');
""",
"""        return self::with_locks($locks, function () use ($actor,$members,$wpdb) {
            $target = $members[0];
            SN_Membership_Assertions::clear_cache($actor);
            SN_Membership_Assertions::clear_cache($target);
            $policy = SN_Policy::can_contact($actor,$target,'message');
"""),
]
for old,new in repls:
    if old not in s:
        raise SystemExit('R02 relationship-runtime target missing')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# R02-D02: follow/follow-accept positive mutations refresh File-00 under the pair lock.
p=Path('sabri-network/includes/class-sn-relationships.php')
s=p.read_text(encoding='utf-8')
old="""        return self::with_pair_lock($follower_id, $followed_id, function () use ($follower_id, $followed_id) {
            global $wpdb;
            $policy = SN_Policy::can_follow($follower_id, $followed_id);
"""
new="""        return self::with_pair_lock($follower_id, $followed_id, function () use ($follower_id, $followed_id) {
            global $wpdb;
            SN_Membership_Assertions::clear_cache($follower_id);
            SN_Membership_Assertions::clear_cache($followed_id);
            $policy = SN_Policy::can_follow($follower_id, $followed_id);
"""
if old not in s: raise SystemExit('R02 follow target missing')
s=s.replace(old,new,1)
old="""            if ($decision === 'accept') {
                $policy = SN_Policy::can_follow((int) $row->follower_id, $target_id);
"""
new="""            if ($decision === 'accept') {
                SN_Membership_Assertions::clear_cache((int) $row->follower_id);
                SN_Membership_Assertions::clear_cache($target_id);
                $policy = SN_Policy::can_follow((int) $row->follower_id, $target_id);
"""
if old not in s: raise SystemExit('R02 follow-decision target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Permanent regression in the existing explicit current-boundary suite.
p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
anchor="$m=$read('includes/class-sn-membership-assertions.php');\n"
if "$relationships=$read('includes/class-sn-relationships.php');" not in s:
    if anchor not in s: raise SystemExit('R02 regression variable anchor missing')
    s=s.replace(anchor,anchor+"$relationships=$read('includes/class-sn-relationships.php');\n$relationshipRuntime=$read('includes/class-sn-relationship-runtime-hardening.php');\n",1)
marker='// Fresh20 R02 relationship point-of-action regressions.'
if marker not in s:
    insert="""
// Fresh20 R02 relationship point-of-action regressions.
$check(substr_count($relationshipRuntime,'SN_Membership_Assertions::clear_cache($actor);')>=3,'Fresh20 R02: positive contact/direct-conversation mutations must refresh the actor File-00 assertion at the locked mutation point.');
$check(substr_count($relationshipRuntime,'SN_Membership_Assertions::clear_cache($target);')>=2,'Fresh20 R02: positive contact/direct-conversation mutations must refresh the target File-00 assertion at the locked mutation point.');
$check(substr_count($relationships,'SN_Membership_Assertions::clear_cache($follower_id);')>=1&&substr_count($relationships,'SN_Membership_Assertions::clear_cache($followed_id);')>=1,'Fresh20 R02: follow creation must refresh both File-00 subjects under the pair lock.');
$check(str_contains($relationships,'SN_Membership_Assertions::clear_cache((int) $row->follower_id);')&&str_contains($relationships,'SN_Membership_Assertions::clear_cache($target_id);'),'Fresh20 R02: follow acceptance must refresh both subjects before positive activation.');
"""
    tail=s.rfind('if($fail){')
    if tail<0: raise SystemExit('R02 regression tail missing')
    s=s[:tail]+insert+s[tail:]
p.write_text(s,encoding='utf-8')
