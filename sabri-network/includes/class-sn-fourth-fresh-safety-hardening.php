<?php
/** Fourth fresh cycle: high-risk report closure and legal-hold release governance. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fourth_Fresh_Safety_Hardening {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'override_routes'], 2280);
    }

    public static function override_routes(): void {
        register_rest_route('sabri-network/v2', '/admin/reports/(?P<id>\d+)', [
            'methods'=>'POST',
            'callback'=>[self::class,'update_report'],
            'permission_callback'=>[SN_REST::class,'admin_access'],
        ], true);
    }

    public static function update_report(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $admin = get_current_user_id();
        $id = absint($request['id']);
        $expected = absint($request->get_param('version'));
        if ($id <= 0 || $expected <= 0) return new WP_Error('invalid_report_version', 'A valid report version is required.', ['status'=>400]);
        $table = SN_DB::table('reports');
        $probe = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$probe) return self::not_found();
        $status = sanitize_key((string) $request->get_param('status')) ?: (string) $probe->status;
        $hold = $request->has_param('legal_hold') ? rest_sanitize_boolean($request->get_param('legal_hold')) : (bool) $probe->legal_hold;
        $closing = (string) $probe->status !== 'closed' && $status === 'closed';
        $releasing = (bool) $probe->legal_hold && !$hold;
        if (!$closing && !$releasing) return SN_REST::admin_update_report($request);
        if ($closing && $releasing) {
            return new WP_Error('high_risk_actions_must_be_separate', 'Close the report and release its legal hold as two separately approved high-risk actions.', ['status'=>409]);
        }
        if (!SN_Policy::consume_rate_limit('admin_report_high_risk', (string) $admin, 30, HOUR_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many high-risk report changes were requested.', ['status'=>429]);
        }
        $note = mb_substr(sanitize_textarea_field((string) $request->get_param('note')), 0, 2000);
        if (mb_strlen(trim($note)) < 10) return new WP_Error('report_decision_reason_required', 'A meaningful decision reason is required.', ['status'=>400]);
        $action_id = absint($request->get_param('high_risk_action_id'));
        if ($action_id <= 0) return new WP_Error('high_risk_action_required', 'An approved high-risk action is required.', ['status'=>403]);

        if ($wpdb->query('START TRANSACTION') === false) return self::database_error();
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id));
            if (!$row) throw new DomainException('not_found');
            if ((int) $row->version !== $expected) throw new UnexpectedValueException('version_conflict');
            $current_status = (string) $row->status;
            $current_hold = (bool) $row->legal_hold;
            $status = sanitize_key((string) $request->get_param('status')) ?: $current_status;
            $hold = $request->has_param('legal_hold') ? rest_sanitize_boolean($request->get_param('legal_hold')) : $current_hold;
            $closing = $current_status !== 'closed' && $status === 'closed';
            $releasing = $current_hold && !$hold;
            if ($closing === $releasing) throw new UnexpectedValueException('scope_changed'); // exactly one must still be true.
            if ((string) $row->appeal_status === 'pending' && $status !== $current_status) throw new UnexpectedValueException('appeal_pending');
            if (!in_array($status, SN_Safety::allowed_statuses(), true) || $status === 'expired' || !SN_Safety::can_transition_status($current_status, $status)) {
                throw new UnexpectedValueException('invalid_transition');
            }

            if ($closing) {
                $type = 'mass_moderation';
                $payload = [
                    'operation'=>'report_closure', 'report_id'=>$id, 'expected_version'=>$expected,
                    'from_status'=>$current_status, 'to_status'=>'closed', 'legal_hold'=>$current_hold,
                ];
            } else {
                $type = 'legal_hold_release';
                $payload = [
                    'operation'=>'legal_hold_release', 'report_id'=>$id, 'expected_version'=>$expected,
                    'status'=>$current_status, 'from_legal_hold'=>true, 'to_legal_hold'=>false,
                ];
            }
            $claim = SN_High_Risk::claim($action_id, $admin, $type, $payload);
            if (is_wp_error($claim)) { $wpdb->query('ROLLBACK'); return $claim; }

            $now = current_time('mysql', true);
            $stored_reason = $status !== $current_status ? $note : (string) $row->decision_reason;
            $stored_by = $status !== $current_status ? $admin : (int) $row->decision_by;
            $stored_at = $status !== $current_status ? $now : ($row->decision_at ?: null);
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status=%s,legal_hold=%d,decision_reason=%s,decision_by=%d,decision_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND version=%d",
                $status, $hold ? 1 : 0, $stored_reason, $stored_by, $stored_at, $now, $id, $expected
            ));
            if ($changed !== 1) throw new RuntimeException('report_update_conflict');
            $completed = SN_High_Risk::complete($action_id, $admin, (string) $claim['claim_token'], [
                'report_id'=>$id, 'version'=>$expected+1, 'status'=>$status, 'legal_hold'=>$hold,
            ], $releasing ? 'released' : 'executed');
            if (is_wp_error($completed)) throw new RuntimeException($completed->get_error_code());
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('report_high_risk_commit_failed');

            SN_DB::audit($closing ? 'report_closed_dual_control' : 'legal_hold_released_dual_control', 'report', $id, 'success', [
                'high_risk_action_id'=>$action_id, 'previous_version'=>$expected, 'reason'=>$note,
            ], $admin);
            if ($closing && (int) $row->reported_user_id > 0) {
                SN_DB::add_notification((int) $row->reported_user_id, 'report_decision', 'A safety report decision is available', 'Open Network safety decisions to review the reason and appeal options.', 'report', $id);
            }
            do_action('sn_network_report_triage_updated', $id, $status, $hold, $admin);
            return rest_ensure_response([
                'id'=>$id, 'status'=>$status, 'legal_hold'=>$hold, 'decision_reason'=>$stored_reason,
                'decision_by'=>$stored_by, 'decision_at'=>$stored_at, 'version'=>$expected+1,
                'updated_at'=>$now, 'dual_control'=>true, 'high_risk_action_id'=>$action_id,
            ]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return match ($e->getMessage()) {
                'not_found' => self::not_found(),
                'version_conflict' => new WP_Error('report_update_conflict', 'The report changed before this decision was saved.', ['status'=>409]),
                'scope_changed' => new WP_Error('high_risk_scope_changed', 'The approved high-risk report scope no longer matches current state.', ['status'=>409]),
                'appeal_pending' => new WP_Error('report_appeal_pending', 'Decide the pending appeal before changing this report status.', ['status'=>409]),
                'invalid_transition' => new WP_Error('invalid_report_transition', 'This report status transition is not allowed.', ['status'=>409]),
                default => self::database_error(),
            };
        }
    }

    private static function not_found(): WP_Error { return new WP_Error('not_found', 'The requested report is unavailable.', ['status'=>404]); }
    private static function database_error(): WP_Error { return new WP_Error('database_error', 'The safety change could not be committed safely.', ['status'=>500]); }
}
