from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'R17 patch anchor missing: {label}')
    return text.replace(old, new, 1)

# R17-D01 — canonical File-00 assertions are authoritative; File-17 filters may only tighten.
policy = Path('sabri-network/includes/class-sn-policy.php')
s = policy.read_text(encoding='utf-8')
old = '''    public static function access(): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('authentication_required', 'Sign in through the platform account system to use Network.', ['status' => 401]);
        }
        $user_id = get_current_user_id();
        if (!$user_id || !get_user_by('id', $user_id)) {
            return new WP_Error('identity_unavailable', 'The authenticated identity is unavailable.', ['status' => 401]);
        }
        if (!self::identity_authority_available()) {
            return new WP_Error('identity_authority_unavailable', 'The platform identity authority is unavailable. Network actions are temporarily disabled.', ['status' => 503]);
        }
        if (self::is_suspended($user_id)) {
            return new WP_Error('account_restricted', 'Network access is unavailable for this account.', ['status' => 403]);
        }
        $allowed = apply_filters('sn_network_user_can_access', true, $user_id);
        return $allowed === true ? true : (is_wp_error($allowed) ? $allowed : new WP_Error('network_access_denied', 'Network access is not permitted for this account.', ['status' => 403]));
    }


    public static function identity_authority_available(): bool {
        $known = class_exists('Sabri_Membership_Core')
            || class_exists('Sabri\\Membership\\Core')
            || function_exists('sabri_membership_core');
        return (bool) apply_filters('sn_network_identity_authority_available', $known);
    }

    public static function is_suspended(int $user_id): bool {
        $filtered = apply_filters('sn_network_user_is_suspended', null, $user_id);
        if (is_bool($filtered)) {
            return $filtered;
        }
        return (bool) get_user_meta($user_id, 'sn_account_suspended', true)
            || in_array((string) get_user_meta($user_id, 'sn_account_status', true), ['suspended', 'blocked', 'deleted'], true);
    }

    public static function age_state(int $user_id): string {
        $state = apply_filters('sn_network_user_age_state', null, $user_id);
        if (is_string($state) && in_array($state, ['adult', 'minor', 'unknown'], true)) {
            return $state;
        }

        $filtered = apply_filters('sn_network_user_is_minor', null, $user_id);
        if (is_bool($filtered)) {
            return $filtered ? 'minor' : 'adult';
        }

        $dob = trim((string) get_user_meta($user_id, 'sn_date_of_birth', true));
        if ($dob === '') {
            $dob = trim((string) get_user_meta($user_id, 'date_of_birth', true));
        }
        if ($dob === '') {
            return 'unknown';
        }

        $date = substr($dob, 0, 10);
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$birth || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) || $birth->format('Y-m-d') !== $date) {
            return 'unknown';
        }
        $today = new DateTimeImmutable('today');
        if ($birth > $today) {
            return 'unknown';
        }
        return $birth->diff($today)->y < 18 ? 'minor' : 'adult';
    }
'''
new = '''    public static function access(): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('authentication_required', 'Sign in through the platform account system to use Network.', ['status' => 401]);
        }
        $user_id = get_current_user_id();
        if (!$user_id || !get_user_by('id', $user_id)) {
            return new WP_Error('identity_unavailable', 'The authenticated identity is unavailable.', ['status' => 401]);
        }
        $assertion = self::canonical_assertion($user_id);
        if (is_wp_error($assertion)) return $assertion;
        if ($assertion['suspended'] === true) {
            return new WP_Error('account_restricted', 'Network access is unavailable for this account.', ['status' => 403]);
        }
        if ($assertion['eligible'] !== true || $assertion['can_message'] !== true) {
            return new WP_Error('network_access_denied', 'The current File 00 communication assertion does not permit Network messaging.', ['status' => 403]);
        }
        // Compatibility filters are a restriction overlay only. Canonical File-00
        // denial is decided above and is therefore impossible for a later filter to grant.
        $allowed = apply_filters('sn_network_user_can_access', true, $user_id);
        return $allowed === true ? true : (is_wp_error($allowed) ? $allowed : new WP_Error('network_access_denied', 'Network access is not permitted for this account.', ['status' => 403]));
    }

    public static function identity_authority_available(): bool {
        if (!class_exists('SN_Membership_Assertions') || !SN_Membership_Assertions::available()) return false;
        // Extensions may disable a currently available authority, never manufacture one.
        return apply_filters('sn_network_identity_authority_available', true) === true;
    }

    public static function is_suspended(int $user_id): bool {
        $assertion = self::canonical_assertion($user_id);
        if (is_wp_error($assertion)) return true;
        if ($assertion['suspended'] === true) return true;
        // A true extension result may tighten an allowed canonical assertion.
        return apply_filters('sn_network_user_is_suspended', false, $user_id) === true;
    }

    public static function age_state(int $user_id): string {
        $assertion = self::canonical_assertion($user_id);
        if (is_wp_error($assertion)) return 'unknown';
        $canonical = (string) $assertion['age_state'];
        if (!in_array($canonical, ['adult', 'minor', 'unknown'], true)) return 'unknown';
        // A canonical minor/unknown state is terminal and cannot be promoted to adult.
        if ($canonical !== 'adult') return $canonical;
        $state = apply_filters('sn_network_user_age_state', 'adult', $user_id);
        if (is_string($state) && in_array($state, ['minor', 'unknown'], true)) return $state;
        $minor = apply_filters('sn_network_user_is_minor', false, $user_id);
        return $minor === true ? 'minor' : 'adult';
    }
'''
s = replace_once(s, old, new, 'policy canonical access/identity/suspension/age block')
old = '''    public static function has_guardian_consent(int $user_id): bool {
        $filtered = apply_filters('sn_network_guardian_consent_valid', null, $user_id);
        if (is_bool($filtered)) {
            return $filtered;
        }
        return (bool) get_user_meta($user_id, 'sn_guardian_consent_verified', true);
    }
'''
new = '''    public static function has_guardian_consent(int $user_id): bool {
        $assertion = self::canonical_assertion($user_id);
        if (is_wp_error($assertion) || $assertion['guardian_verified'] !== true) return false;
        // Extensions may revoke/tighten a verified grant, never create one.
        return apply_filters('sn_network_guardian_consent_valid', true, $user_id) === true;
    }

    private static function canonical_assertion(int $user_id): array|WP_Error {
        if (!class_exists('SN_Membership_Assertions')) {
            return new WP_Error('identity_authority_unavailable', 'The File 00 communication-assertion authority is unavailable.', ['status' => 503]);
        }
        $assertion = SN_Membership_Assertions::communication($user_id);
        if (is_wp_error($assertion)) return $assertion;
        foreach (['eligible','can_message','suspended','guardian_verified','age_state'] as $field) {
            if (!array_key_exists($field, $assertion)) {
                return new WP_Error('identity_assertion_incomplete', 'The File 00 communication assertion is incomplete.', ['status' => 503]);
            }
        }
        return $assertion;
    }
'''
s = replace_once(s, old, new, 'policy guardian block')
policy.write_text(s, encoding='utf-8')

# R17-D02 — two stepped-up, distinct approvers before execution.
high = Path('sabri-network/includes/class-sn-high-risk.php')
s = high.read_text(encoding='utf-8')
s = replace_once(s, "private const SCHEMA_VERSION = '1.0.0';", "private const SCHEMA_VERSION = '1.1.0';", 'high-risk schema version')
s = replace_once(s,
'''    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $grants = self::grants_table();
        $actions = self::actions_table();
''',
'''    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $grants = self::grants_table();
        $actions = self::actions_table();
        $previous_version = (string) get_option('sn_high_risk_schema_version', '');
        $actions_preexisted = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($actions))) === $actions;
''', 'high-risk install preflight')
s = replace_once(s,
'''            requester_id BIGINT UNSIGNED NOT NULL,
            approver_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            executor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
''',
'''            requester_id BIGINT UNSIGNED NOT NULL,
            approver_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            second_approver_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            approver_step_up_grant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            second_approver_step_up_grant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            executor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
''', 'high-risk second approver columns')
s = replace_once(s,
'''            expires_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            executing_at DATETIME NULL,
''',
'''            expires_at DATETIME NOT NULL,
            first_approved_at DATETIME NULL,
            approved_at DATETIME NULL,
            executing_at DATETIME NULL,
''', 'high-risk first approval timestamp')
s = replace_once(s,
'''        update_option('sn_high_risk_schema_version', self::SCHEMA_VERSION, false);
    }
''',
'''        // Never grandfather a pre-v1.1 nonterminal single-approval action into the
        // new executable state. It must be requested again under dual control.
        if ($actions_preexisted && $previous_version !== self::SCHEMA_VERSION) {
            $now = self::now();
            $wpdb->query($wpdb->prepare(
                "UPDATE $actions SET status='expired',approver_id=0,second_approver_id=0,approver_step_up_grant_id=0,second_approver_step_up_grant_id=0,executor_id=0,claim_token_hash=NULL,first_approved_at=NULL,approved_at=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status IN ('requested','approval_pending','approved','executing')",
                $now
            ));
        }
        update_option('sn_high_risk_schema_version', self::SCHEMA_VERSION, false);
    }
''', 'high-risk legacy invalidation')

start = s.index('    public static function decide_action(')
end = s.index('    /** Caller owns the surrounding transaction. */\n    public static function claim', start)
new_decide = '''    public static function decide_action(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = absint($request['id']);
        $approver = get_current_user_id();
        $decision = sanitize_key((string) $request->get_param('decision'));
        if (!in_array($decision, ['approve', 'reject'], true)) return self::error('sn_high_risk_decision_invalid', 'Select approve or reject.', 400);
        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_high_risk_transaction_failed', 'The high-risk decision transaction could not be started.', 503);
        try {
            $row = self::action($id, true);
            if (!$row) throw new DomainException('sn_high_risk_not_found');
            if ((int) $row->requester_id === $approver) throw new DomainException('sn_high_risk_separation_required');
            if (!in_array((string) $row->status, ['requested', 'approval_pending'], true) || strtotime((string) $row->expires_at . ' UTC') <= time()) throw new DomainException('sn_high_risk_not_pending');
            $expected = absint($request->get_param('version'));
            if ($expected !== (int) $row->version) throw new DomainException('sn_high_risk_version_conflict');
            $now = self::now();

            if ($decision === 'reject') {
                $changed = $wpdb->update(self::actions_table(), [
                    'status'=>'rejected','updated_at'=>$now,'version'=>$expected + 1,
                ], ['id'=>$id,'status'=>(string)$row->status,'version'=>$expected]);
                if ($changed !== 1) throw new RuntimeException('sn_high_risk_decision_conflict');
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('sn_high_risk_decision_commit_failed');
                SN_DB::audit('high_risk_action_rejected', 'high_risk_action', $id, 'success', ['action_type'=>(string)$row->action_type], $approver);
                return rest_ensure_response(['id'=>$id,'status'=>'rejected','version'=>$expected + 1]);
            }

            $grant = self::consume_grant((string) $request->get_param('step_up_token'), $approver, (string) $row->action_type);
            if (is_wp_error($grant)) {
                $wpdb->query('ROLLBACK');
                return $grant;
            }
            if ((string) $row->status === 'requested') {
                $changed = $wpdb->update(self::actions_table(), [
                    'status'=>'approval_pending','approver_id'=>$approver,'approver_step_up_grant_id'=>(int)$grant->id,
                    'first_approved_at'=>$now,'updated_at'=>$now,'version'=>$expected + 1,
                ], ['id'=>$id,'status'=>'requested','version'=>$expected]);
                if ($changed !== 1) throw new RuntimeException('sn_high_risk_decision_conflict');
                $status = 'approval_pending';
            } else {
                if ((int) $row->approver_id <= 0 || (int) $row->approver_step_up_grant_id <= 0) throw new DomainException('sn_high_risk_first_approval_missing');
                if ((int) $row->approver_id === $approver) throw new DomainException('sn_high_risk_second_approver_distinct');
                $changed = $wpdb->update(self::actions_table(), [
                    'status'=>'approved','second_approver_id'=>$approver,'second_approver_step_up_grant_id'=>(int)$grant->id,
                    'approved_at'=>$now,'updated_at'=>$now,'version'=>$expected + 1,
                ], ['id'=>$id,'status'=>'approval_pending','version'=>$expected,'second_approver_id'=>0]);
                if ($changed !== 1) throw new RuntimeException('sn_high_risk_decision_conflict');
                $status = 'approved';
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('sn_high_risk_decision_commit_failed');
            SN_DB::audit('high_risk_action_' . $status, 'high_risk_action', $id, 'success', ['action_type'=>(string)$row->action_type], $approver);
            return rest_ensure_response(['id'=>$id,'status'=>$status,'version'=>$expected + 1]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $code = $e->getMessage();
            return match ($code) {
                'sn_high_risk_not_found' => self::error($code, 'The action is unavailable.', 404),
                'sn_high_risk_separation_required' => self::error($code, 'The requester cannot approve this action.', 409),
                'sn_high_risk_not_pending' => self::error($code, 'The action is no longer awaiting approval.', 409),
                'sn_high_risk_version_conflict' => self::error($code, 'The action changed. Reload and retry.', 409),
                'sn_high_risk_first_approval_missing' => self::error($code, 'The first governed approval is incomplete.', 409),
                'sn_high_risk_second_approver_distinct' => self::error($code, 'A distinct second approver is required.', 409),
                default => self::error('sn_high_risk_decision_conflict', 'The high-risk decision could not be committed safely.', 409),
            };
        }
    }

'''
s = s[:start] + new_decide + s[end:]

old = '''        if (!$row || (string) $row->action_type !== $type) return self::error('sn_high_risk_scope_mismatch', 'The approved action does not match this operation.', 403);
        if ((string) $row->status !== 'approved' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_high_risk_not_approved', 'A current approved action is required.', 403);
        if (in_array($executor_id, [(int) $row->requester_id, (int) $row->approver_id], true)) return self::error('sn_high_risk_executor_separation', 'A distinct executor is required.', 409);
'''
new = '''        if (!$row || (string) $row->action_type !== $type) return self::error('sn_high_risk_scope_mismatch', 'The approved action does not match this operation.', 403);
        if ((string) $row->status !== 'approved' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_high_risk_not_approved', 'Two current governed approvals are required.', 403);
        if ((int)$row->approver_id <= 0 || (int)$row->second_approver_id <= 0 || (int)$row->approver_step_up_grant_id <= 0 || (int)$row->second_approver_step_up_grant_id <= 0 || (int)$row->approver_id === (int)$row->second_approver_id) return self::error('sn_high_risk_dual_approval_incomplete', 'Two distinct stepped-up approvals are required.', 403);
        if (in_array($executor_id, [(int)$row->requester_id,(int)$row->approver_id,(int)$row->second_approver_id], true)) return self::error('sn_high_risk_executor_separation', 'A distinct executor is required.', 409);
'''
s = replace_once(s, old, new, 'high-risk claim separation')
s = replace_once(s,
'''        $rows = $wpdb->get_results("SELECT id,action_uuid,action_type,requester_id,approver_id,executor_id,payload_hash,status,reason,expires_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM " . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
''',
'''        $rows = $wpdb->get_results("SELECT id,action_uuid,action_type,requester_id,approver_id,second_approver_id,executor_id,payload_hash,status,reason,expires_at,first_approved_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM " . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
''', 'high-risk list projections')
s = replace_once(s,
'''        $wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='expired',updated_at=%s,version=version+1 WHERE status IN ('requested','approved') AND expires_at<=%s LIMIT 500", $now, $now));
        $wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='approved',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND expires_at>%s LIMIT 100", $now, $stale, $now));
''',
'''        $wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='expired',updated_at=%s,version=version+1 WHERE status IN ('requested','approval_pending','approved') AND expires_at<=%s LIMIT 500", $now, $now));
        $wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='approved',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND expires_at>%s AND approver_id>0 AND second_approver_id>0 AND approver_step_up_grant_id>0 AND second_approver_step_up_grant_id>0 LIMIT 100", $now, $stale, $now));
        $wpdb->query($wpdb->prepare("UPDATE " . self::actions_table() . " SET status='expired',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND (approver_id=0 OR second_approver_id=0 OR approver_step_up_grant_id=0 OR second_approver_step_up_grant_id=0) LIMIT 100", $now, $stale));
''', 'high-risk cleanup states')
high.write_text(s, encoding='utf-8')

# Migration truth must include every new dual-control column.
mig = Path('sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php')
s = mig.read_text(encoding='utf-8')
s = replace_once(s,
"            'high_risk_actions'=>['action_type','requester_id','approver_id','executor_id','status','version'],",
"            'high_risk_actions'=>['action_type','requester_id','approver_id','second_approver_id','approver_step_up_grant_id','second_approver_step_up_grant_id','executor_id','status','first_approved_at','approved_at','version'],",
'high-risk migration verification')
mig.write_text(s, encoding='utf-8')

# Focused R17 static/adversarial contracts.
test = Path('sabri-network/tests/r17-canonical-auth-dual-approval-contracts.php')
test.write_text(r'''<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$policy=file_get_contents($root.'/includes/class-sn-policy.php');
$membership=file_get_contents($root.'/includes/class-sn-membership-assertions.php');
$high=file_get_contents($root.'/includes/class-sn-high-risk.php');
$migration=file_get_contents($root.'/includes/class-sn-fifth-fresh-migration-hardening.php');
$fails=[];$checks=0;
function r17(bool $ok,string $msg):void{global $fails,$checks;$checks++;if(!$ok)$fails[]=$msg;}

// R17-D01 — no generic File-17 projection may grant against canonical File-00 truth.
r17(substr_count($policy,'SN_Membership_Assertions::communication($user_id)')>=1,'Policy must read canonical File-00 assertions directly.');
r17(str_contains($policy,"if (!class_exists('SN_Membership_Assertions') || !SN_Membership_Assertions::available()) return false"),'Authority availability must fail closed before extension filters.');
r17(str_contains($policy,"if ($assertion['suspended'] === true) return true"),'Canonical suspension must be terminal.');
r17(str_contains($policy,"if ($canonical !== 'adult') return $canonical"),'Canonical minor/unknown age must be terminal against permissive filters.');
r17(str_contains($policy,"$assertion['guardian_verified'] !== true) return false"),'Canonical guardian denial must be terminal.');
r17(!str_contains($policy,"get_user_meta($user_id, 'sn_account_suspended'")&&!str_contains($policy,"get_user_meta($user_id, 'sn_date_of_birth'")&&!str_contains($policy,"get_user_meta($user_id, 'sn_guardian_consent_verified'"),'Legacy File-17 metadata must not become an alternate identity authority.');
r17(str_contains($policy,"apply_filters('sn_network_user_can_access', true")&&str_contains($policy,"apply_filters('sn_network_user_is_suspended', false")&&str_contains($policy,"apply_filters('sn_network_guardian_consent_valid', true"),'Compatibility filters may remain only after canonical truth as restriction overlays.');
r17(str_contains($membership,"MIN_CONTRACT_VERSION = '1.1.1'")&&str_contains($membership,'identity_assertion_subject_mismatch'),'Canonical adapter must preserve version/subject validation.');

// R17-D02 — dual, stepped-up, distinct approval before execution.
r17(str_contains($high,"SCHEMA_VERSION = '1.1.0'"),'High-risk schema must be version-bumped.');
foreach(['second_approver_id','approver_step_up_grant_id','second_approver_step_up_grant_id','first_approved_at'] as $column)r17(str_contains($high,$column),"Missing durable dual-control field: $column");
r17(str_contains($high,"'status'=>'approval_pending'")&&str_contains($high,"'status'=>'approved'"),'First approval must remain non-executable until second approval.');
r17(substr_count($high,'self::consume_grant(')>=2,'Approver decision path must consume a fresh purpose-bound step-up grant in addition to requester grant consumption.');
r17(str_contains($high,'sn_high_risk_second_approver_distinct'),'Same approver must not fill both approval slots.');
r17(str_contains($high,'sn_high_risk_dual_approval_incomplete'),'Claim must fail closed when two stepped-up approvals are incomplete.');
r17(str_contains($high,'$row->second_approver_id], true'),'Executor must be distinct from requester and both approvers.');
r17(str_contains($high,"status='expired'")&&str_contains($high,"status IN ('requested','approval_pending','approved','executing')"),'Pre-v1.1 nonterminal actions must be invalidated rather than grandfathered.');
r17(str_contains($high,"status IN ('requested','approval_pending','approved')"),'Cleanup must expire partial-approval state.');
foreach(['second_approver_id','approver_step_up_grant_id','second_approver_step_up_grant_id','first_approved_at','approved_at'] as $column)r17(str_contains($migration,"'$column'"),"Migration verification missing high-risk column: $column");

if($fails){fwrite(STDERR,"R17 canonical-auth/dual-approval failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "R17 canonical-auth/dual-approval contracts: PASS ($checks checks)\n";
''', encoding='utf-8')

# Canonical quality runner explicitly invokes every suite; add R17 by name.
qc = Path('sabri-network/tools/quality-check.sh')
s = qc.read_text(encoding='utf-8')
needle = ' sixth-fresh-twenty-round-contracts.php seventh-fresh-ten-round-contracts.php r15-event-delivery-schema-contracts.php r16-migration-rollback-contracts.php\n)'
repl = ' sixth-fresh-twenty-round-contracts.php seventh-fresh-ten-round-contracts.php r15-event-delivery-schema-contracts.php r16-migration-rollback-contracts.php r17-canonical-auth-dual-approval-contracts.php\n)'
s = replace_once(s, needle, repl, 'quality runner R17 suite inventory')
qc.write_text(s, encoding='utf-8')
