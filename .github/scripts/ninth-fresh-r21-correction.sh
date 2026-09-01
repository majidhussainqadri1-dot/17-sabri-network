#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

def replace(path, old, new, label, count=1):
    p=root/path; t=p.read_text(encoding='utf-8')
    if old not in t: raise SystemExit(label+' anchor missing')
    p.write_text(t.replace(old,new,count),encoding='utf-8')

# Centralize action-time role scopes and permit locked revalidation.
p=root/'includes/class-sn-spaces-part-8.php'; t=p.read_text(encoding='utf-8')
old="""    private static function can_manage(int $space_id,int $user,string $scope): bool {$m=self::member($space_id,$user);if(!$m)return false;$allowed=match($scope){'settings','lifecycle','audit'=>['owner','administrator'],'members'=>['owner','administrator'],'moderation'=>['owner','administrator','moderator'],default=>['owner']};return in_array((string)$m->role,$allowed,true);}
"""
new="""    private static function role_can_manage(string $role,string $scope): bool {$allowed=match($scope){'settings','lifecycle','audit'=>['owner','administrator'],'members'=>['owner','administrator'],'moderation'=>['owner','administrator','moderator'],default=>['owner']};return in_array($role,$allowed,true);}

    private static function can_manage(int $space_id,int $user,string $scope): bool {$m=self::member($space_id,$user);return $m?self::role_can_manage((string)$m->role,$scope):false;}

    private static function can_manage_locked(int $space_id,int $user,string $scope): bool {$m=self::member($space_id,$user,true);return $m?self::role_can_manage((string)$m->role,$scope):false;}
"""
if old not in t: raise SystemExit('R21 helper anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Joining is a security/capacity decision; every DB truth source fails closed.
p=root/'includes/class-sn-spaces-part-7.php'; t=p.read_text(encoding='utf-8')
old="""        if(self::is_banned((int)$space->id,$user)||SN_Policy::is_suspended($user))return self::error('sn_space_member_unavailable','This account cannot join the space.',403);
        if(self::member((int)$space->id,$user))return self::error('sn_space_already_member','The account is already an active member.',409);
        if(SN_Policy::requires_protective_age_defaults($user)&&!(bool)apply_filters('sn_network_minor_space_allowed',false,$user,$space))return self::error('sn_space_minor_restricted','This space is not approved for the account age context.',403);
        $count=self::member_count((int)$space->id);if($count>=(int)$space->member_limit)return self::error('sn_space_capacity_reached','The space member limit has been reached.',409);
"""
new="""        global $wpdb;
        $banned=self::is_banned((int)$space->id,$user);if($wpdb->last_error!=='')return self::error('sn_space_membership_state_unavailable','Space membership eligibility could not be verified safely.',503);
        if($banned||SN_Policy::is_suspended($user))return self::error('sn_space_member_unavailable','This account cannot join the space.',403);
        $member=self::member((int)$space->id,$user);if($wpdb->last_error!=='')return self::error('sn_space_membership_state_unavailable','Space membership eligibility could not be verified safely.',503);
        if($member)return self::error('sn_space_already_member','The account is already an active member.',409);
        if(SN_Policy::requires_protective_age_defaults($user)&&!(bool)apply_filters('sn_network_minor_space_allowed',false,$user,$space))return self::error('sn_space_minor_restricted','This space is not approved for the account age context.',403);
        $count=self::member_count((int)$space->id);if($wpdb->last_error!=='')return self::error('sn_space_capacity_unavailable','Space capacity could not be verified safely.',503);if($count>=(int)$space->member_limit)return self::error('sn_space_capacity_reached','The space member limit has been reached.',409);
"""
if old not in t: raise SystemExit('R21 join eligibility anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Settings mutation revalidates manager role while the space mutation is locked.
p=root/'includes/class-sn-spaces-part-2.php'; t=p.read_text(encoding='utf-8')
old="""            $locked=self::space($id,true);
            if(!$locked||(int)$locked->version!==$expected){$wpdb->query('ROLLBACK');return self::error('sn_space_update_conflict','A concurrent space update was detected.',409);}
"""
new="""            $locked=self::space($id,true);
            if(!$locked||(int)$locked->version!==$expected){$wpdb->query('ROLLBACK');return self::error('sn_space_update_conflict','A concurrent space update was detected.',409);}
            if(!self::can_manage_locked($id,$actor,'settings')){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Space settings permission is required at action time.',403);}
"""
if old not in t: raise SystemExit('R21 update-space anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Join decisions and invitation creation revalidate manager authority in the transaction.
p=root/'includes/class-sn-spaces-part-3.php'; t=p.read_text(encoding='utf-8')
old="""            $space=self::space($space_id,true);if(!$space)throw new RuntimeException('space_missing');
            $request_row=$wpdb->get_row"""
new="""            $space=self::space($space_id,true);if(!$space)throw new RuntimeException('space_missing');
            if(!self::can_manage_locked($space_id,$actor,'members')){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Membership management permission is required at action time.',403);}
            $request_row=$wpdb->get_row"""
if old not in t: raise SystemExit('R21 join-decision auth anchor missing')
t=t.replace(old,new,1)
old="""            $space=self::space($space_id,true);$elig=self::join_eligibility($space,$invitee,true);if(is_wp_error($elig)){$wpdb->query('ROLLBACK');return $elig;}
"""
new="""            $space=self::space($space_id,true);if(!self::can_manage_locked($space_id,$actor,'members')){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Membership management permission is required at action time.',403);}$elig=self::join_eligibility($space,$invitee,true);if(is_wp_error($elig)){$wpdb->query('ROLLBACK');return $elig;}
"""
if old not in t: raise SystemExit('R21 invite auth anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Invite cancellation manager authority is locked/action-time.
p=root/'includes/class-sn-spaces-part-4.php'; t=p.read_text(encoding='utf-8')
old="""                if((int)$invite->inviter_id!==$actor&&!self::can_manage((int)$invite->space_id,$actor,'members')){$wpdb->query('ROLLBACK');return self::error('sn_invite_cancel_forbidden','Only the inviter or a space manager may cancel.',403);}
"""
new="""                if((int)$invite->inviter_id!==$actor&&!self::can_manage_locked((int)$invite->space_id,$actor,'members')){$wpdb->query('ROLLBACK');return self::error('sn_invite_cancel_forbidden','Only the inviter or a current space manager may cancel.',403);}
"""
if old not in t: raise SystemExit('R21 invite cancel anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Member/ban mutations revalidate locked actor roles.
p=root/'includes/class-sn-spaces-part-5.php'; t=p.read_text(encoding='utf-8')
old="""            $space=self::space($space_id,true);$actor_member=self::member($space_id,$actor,true);$target_member=self::member($space_id,$target,true);
            if(!$space||!$actor_member||!$target_member){$wpdb->query('ROLLBACK');return self::error('sn_space_membership_missing','The membership is unavailable.',404);}
"""
new="""            $space=self::space($space_id,true);$actor_member=self::member($space_id,$actor,true);$target_member=self::member($space_id,$target,true);
            if(!$space||!$actor_member||!$target_member){$wpdb->query('ROLLBACK');return self::error('sn_space_membership_missing','The membership is unavailable.',404);}
            if(!self::role_can_manage((string)$actor_member->role,'members')){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Membership management permission is required at action time.',403);}
"""
if old not in t: raise SystemExit('R21 change-member auth anchor missing')
t=t.replace(old,new,1)
# Unban branch: lock space and actor authority before mutation.
old="""            try{
                $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::bans_table().' WHERE id=%d FOR UPDATE',(int)$existing->id));
"""
new="""            try{
                $space_locked=self::space($space_id,true);$actor_locked=self::member($space_id,$actor,true);
                if(!$space_locked||!$actor_locked||!self::role_can_manage((string)$actor_locked->role,'moderation')){$wpdb->query('ROLLBACK');return self::error('sn_space_moderation_forbidden','Moderation permission is required at action time.',403);}
                $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::bans_table().' WHERE id=%d FOR UPDATE',(int)$existing->id));
"""
if old not in t: raise SystemExit('R21 unban auth anchor missing')
t=t.replace(old,new,1)
# Ban branch: lock manager and target membership and recompute hierarchy.
old="""        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $data=['status'=>'active'"""
new="""        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $space_locked=self::space($space_id,true);$actor_locked=self::member($space_id,$actor,true);$target_locked=self::member($space_id,$target,true);
            if(!$space_locked||!$actor_locked||!self::role_can_manage((string)$actor_locked->role,'moderation')){$wpdb->query('ROLLBACK');return self::error('sn_space_moderation_forbidden','Moderation permission is required at action time.',403);}
            if($target_locked&&!self::can_manage_target((string)$actor_locked->role,(string)$target_locked->role)){$wpdb->query('ROLLBACK');return self::error('sn_space_hierarchy_forbidden','This role cannot be banned by the current actor.',403);}
            $target_member=$target_locked;
            $data=['status'=>'active'"""
if old not in t: raise SystemExit('R21 ban auth anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Lifecycle mutation uses locked role revalidation.
p=root/'includes/class-sn-spaces-part-6.php'; t=p.read_text(encoding='utf-8')
old="""            $space=self::space($id,true);
            if(!$space||!self::can_manage($id,$actor,'lifecycle')){$wpdb->query('ROLLBACK');return self::error('sn_space_lifecycle_forbidden','Lifecycle permission is required.',403);}
"""
new="""            $space=self::space($id,true);
            if(!$space||!self::can_manage_locked($id,$actor,'lifecycle')){$wpdb->query('ROLLBACK');return self::error('sn_space_lifecycle_forbidden','Lifecycle permission is required at action time.',403);}
"""
if old not in t: raise SystemExit('R21 lifecycle auth anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Creating a child space revalidates parent governance under lock before any insert.
p=root/'includes/class-sn-spaces-part-1.php'; t=p.read_text(encoding='utf-8')
old="""        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            $ok = $wpdb->insert(self::spaces_table(), [
"""
new="""        try { if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('transaction_start_failed');
            if($parent_id>0){$parent_locked=self::space($parent_id,true);if(!$parent_locked||(string)$parent_locked->type!=='community'||!in_array((string)$parent_locked->state,['active','restricted'],true)||!self::can_manage_locked($parent_id,$actor,'settings')){$wpdb->query('ROLLBACK');return self::error('sn_space_parent_forbidden','Parent-community management permission is required at action time.',403);}}
            $ok = $wpdb->insert(self::spaces_table(), [
"""
if old not in t: raise SystemExit('R21 create-child auth anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

# Permanent R21 contracts.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
block=r'''
// Round 21 — space membership/governance is fail-closed and action-time locked.
$spaces7=$read('includes/class-sn-spaces-part-7.php');$spaces8=$read('includes/class-sn-spaces-part-8.php');
$spaces1=$read('includes/class-sn-spaces-part-1.php');$spaces2=$read('includes/class-sn-spaces-part-2.php');$spaces3=$read('includes/class-sn-spaces-part-3.php');$spaces4=$read('includes/class-sn-spaces-part-4.php');$spaces5=$read('includes/class-sn-spaces-part-5.php');$spaces6=$read('includes/class-sn-spaces-part-6.php');
$check(str_contains($spaces7,'sn_space_membership_state_unavailable') && str_contains($spaces7,'sn_space_capacity_unavailable') && substr_count($spaces7,'$wpdb->last_error')>=3, 'Round 21: ban/member/capacity DB uncertainty must fail join eligibility closed.');
$check(str_contains($spaces8,'can_manage_locked') && str_contains($spaces8,'role_can_manage'), 'Round 21: canonical space manager authorization must support locked action-time checks.');
foreach ([$spaces1,$spaces2,$spaces3,$spaces4,$spaces6] as $i=>$src) $check(str_contains($src,'can_manage_locked'), 'Round 21: a space mutation path lost locked manager revalidation #'.$i.'.');
$check(substr_count($spaces5,'role_can_manage')>=3 && str_contains($spaces5,'$actor_locked=self::member') && str_contains($spaces5,'$target_locked=self::member'), 'Round 21: member/ban mutations must recompute authority/hierarchy from locked memberships.');
'''
if anchor not in t: raise SystemExit('R21 suite anchor missing')
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
for f in sabri-network/includes/class-sn-spaces-part-{1,2,3,4,5,6,7,8}.php sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php; do php -l "$f"; done
