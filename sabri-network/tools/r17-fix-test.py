from pathlib import Path
p=Path('sabri-network/tests/r17-canonical-auth-dual-approval-contracts.php')
p.write_text(r'''<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$policy=file_get_contents($root.'/includes/class-sn-policy.php');
$membership=file_get_contents($root.'/includes/class-sn-membership-assertions.php');
$high=file_get_contents($root.'/includes/class-sn-high-risk.php');
$migration=file_get_contents($root.'/includes/class-sn-fifth-fresh-migration-hardening.php');
$fails=[];$checks=0;
function r17(bool $ok,string $msg):void{global $fails,$checks;$checks++;if(!$ok)$fails[]=$msg;}

r17(substr_count($policy,'SN_Membership_Assertions::communication($user_id)')>=1,'Policy must read canonical File-00 assertions directly.');
r17(str_contains($policy,"SN_Membership_Assertions::available()) return false"),'Authority availability must fail closed before extension filters.');
r17(str_contains($policy,"assertion['suspended'] === true) return true"),'Canonical suspension must be terminal.');
r17(str_contains($policy,"canonical !== 'adult') return canonical") || str_contains($policy,"$canonical !== 'adult') return $canonical"),'Canonical minor/unknown age must be terminal against permissive filters.');
r17(str_contains($policy,"guardian_verified'] !== true) return false"),'Canonical guardian denial must be terminal.');
r17(!str_contains($policy,"get_user_meta($user_id, 'sn_account_suspended'")&&!str_contains($policy,"get_user_meta($user_id, 'sn_date_of_birth'")&&!str_contains($policy,"get_user_meta($user_id, 'sn_guardian_consent_verified'"),'Legacy File-17 metadata must not become an alternate identity authority.');
r17(str_contains($policy,"sn_network_user_can_access', true")&&str_contains($policy,"sn_network_user_is_suspended', false")&&str_contains($policy,"sn_network_guardian_consent_valid', true"),'Compatibility filters may remain only after canonical truth as restriction overlays.');
r17(str_contains($membership,"MIN_CONTRACT_VERSION = '1.1.1'")&&str_contains($membership,'identity_assertion_subject_mismatch'),'Canonical adapter must preserve version/subject validation.');

r17(str_contains($high,"SCHEMA_VERSION = '1.1.0'"),'High-risk schema must be version-bumped.');
foreach(['second_approver_id','approver_step_up_grant_id','second_approver_step_up_grant_id','first_approved_at'] as $column)r17(str_contains($high,$column),"Missing durable dual-control field: $column");
r17(str_contains($high,"status'=>'approval_pending'")&&str_contains($high,"status'=>'approved'"),'First approval must remain non-executable until second approval.');
r17(substr_count($high,'self::consume_grant(')>=2,'Approver decision path must consume a fresh purpose-bound step-up grant in addition to requester grant consumption.');
r17(str_contains($high,'sn_high_risk_second_approver_distinct'),'Same approver must not fill both approval slots.');
r17(str_contains($high,'sn_high_risk_dual_approval_incomplete'),'Claim must fail closed when two stepped-up approvals are incomplete.');
r17(str_contains($high,'second_approver_id], true'),'Executor must be distinct from requester and both approvers.');
r17(str_contains($high,"status='expired'")&&str_contains($high,"status IN ('requested','approval_pending','approved','executing')"),'Pre-v1.1 nonterminal actions must be invalidated rather than grandfathered.');
r17(str_contains($high,"status IN ('requested','approval_pending','approved')"),'Cleanup must expire partial-approval state.');
foreach(['second_approver_id','approver_step_up_grant_id','second_approver_step_up_grant_id','first_approved_at','approved_at'] as $column)r17(str_contains($migration,"'$column'"),"Migration verification missing high-risk column: $column");

if($fails){fwrite(STDERR,"R17 canonical-auth/dual-approval failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "R17 canonical-auth/dual-approval contracts: PASS ($checks checks)\n";
''',encoding='utf-8')
