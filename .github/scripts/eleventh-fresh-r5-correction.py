from pathlib import Path
p=Path('sabri-network/includes/class-sn-high-risk.php')
s=p.read_text(encoding='utf-8')
def rep(old,new):
 global s
 if old not in s: raise SystemExit('missing anchor: '+old[:170])
 s=s.replace(old,new,1)
rep("""        $row = self::action($id);
        if (!$row) return self::error('sn_high_risk_not_found', 'The action is unavailable.', 404);
""","""        $row = self::action($id);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('high_risk_decision_read_failed');
        if (!$row) return self::error('sn_high_risk_not_found', 'The action is unavailable.', 404);
""")
rep("""        $changed = $wpdb->update(self::actions_table(), $data, ['id' => $id, 'status' => 'requested', 'version' => $expected]);
        if ($changed !== 1) return self::error('sn_high_risk_decision_conflict', 'A concurrent decision was detected.', 409);
""","""        $changed = $wpdb->update(self::actions_table(), $data, ['id' => $id, 'status' => 'requested', 'version' => $expected]);
        if ($changed === false) return self::storage_error('high_risk_decision_write_failed');
        if ($changed !== 1) return self::error('sn_high_risk_decision_conflict', 'A concurrent decision was detected.', 409);
""")
rep("""        $row = self::action($action_id, true);
        if (!$row || (string) $row->action_type !== $type) return self::error('sn_high_risk_scope_mismatch', 'The approved action does not match this operation.', 403);
""","""        $row = self::action($action_id, true);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('high_risk_claim_read_failed');
        if (!$row || (string) $row->action_type !== $type) return self::error('sn_high_risk_scope_mismatch', 'The approved action does not match this operation.', 403);
""")
rep("""        if ($changed !== 1) return self::error('sn_high_risk_claim_conflict', 'The approved action was claimed concurrently.', 409);
""","""        if ($changed === false) return self::storage_error('high_risk_claim_write_failed');
        if ($changed !== 1) return self::error('sn_high_risk_claim_conflict', 'The approved action was claimed concurrently.', 409);
""")
rep("""        $row = self::action($action_id, true);
        if (!$row || (string) $row->status !== 'executing' || (int) $row->executor_id !== $executor_id) return self::error('sn_high_risk_execution_lost', 'The action execution claim is unavailable.', 409);
""","""        $row = self::action($action_id, true);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('high_risk_completion_read_failed');
        if (!$row || (string) $row->status !== 'executing' || (int) $row->executor_id !== $executor_id) return self::error('sn_high_risk_execution_lost', 'The action execution claim is unavailable.', 409);
""")
rep("""        $changed = $wpdb->update(self::actions_table(), $data, ['id' => $action_id, 'status' => 'executing', 'version' => (int) $row->version]);
        return $changed === 1 ? true : self::error('sn_high_risk_completion_conflict', 'The action completion conflicted with another update.', 409);
""","""        $changed = $wpdb->update(self::actions_table(), $data, ['id' => $action_id, 'status' => 'executing', 'version' => (int) $row->version]);
        if ($changed === false) return self::storage_error('high_risk_completion_write_failed');
        return $changed === 1 ? true : self::error('sn_high_risk_completion_conflict', 'The action completion conflicted with another update.', 409);
""")
rep("""    public static function list_actions(WP_REST_Request $request): WP_REST_Response {
""","""    public static function list_actions(WP_REST_Request $request): WP_REST_Response|WP_Error {
""")
rep("""        $rows = $wpdb->get_results(\"SELECT id,action_uuid,action_type,requester_id,approver_id,executor_id,payload_hash,status,reason,expires_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM \" . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
        return rest_ensure_response(['items' => is_array($rows) ? $rows : []]);
""","""        $rows = $wpdb->get_results(\"SELECT id,action_uuid,action_type,requester_id,approver_id,executor_id,payload_hash,status,reason,expires_at,approved_at,executing_at,executed_at,released_at,version,created_at,updated_at FROM \" . self::actions_table() . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d', $limit));
        if (($wpdb->last_error ?? '') !== '' || !is_array($rows)) return self::storage_error('high_risk_list_read_failed');
        return rest_ensure_response(['items' => $rows]);
""")
rep("""        $wpdb->query($wpdb->prepare(\"UPDATE \" . self::grants_table() . \" SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at<=%s LIMIT 500\", $now, $now));
        $wpdb->query($wpdb->prepare(\"UPDATE \" . self::actions_table() . \" SET status='expired',updated_at=%s,version=version+1 WHERE status IN ('requested','approved') AND expires_at<=%s LIMIT 500\", $now, $now));
        $wpdb->query($wpdb->prepare(\"UPDATE \" . self::actions_table() . \" SET status='approved',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND expires_at>%s LIMIT 100\", $now, $stale, $now));
""","""        $expired_grants=$wpdb->query($wpdb->prepare(\"UPDATE \" . self::grants_table() . \" SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at<=%s LIMIT 500\", $now, $now));
        if($expired_grants===false)SN_DB::audit('high_risk_cleanup_grants_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
        $expired_actions=$wpdb->query($wpdb->prepare(\"UPDATE \" . self::actions_table() . \" SET status='expired',updated_at=%s,version=version+1 WHERE status IN ('requested','approved') AND expires_at<=%s LIMIT 500\", $now, $now));
        if($expired_actions===false)SN_DB::audit('high_risk_cleanup_actions_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
        $recovered=$wpdb->query($wpdb->prepare(\"UPDATE \" . self::actions_table() . \" SET status='approved',executor_id=0,claim_token_hash=NULL,executing_at=NULL,updated_at=%s,version=version+1 WHERE status='executing' AND executing_at<%s AND expires_at>%s LIMIT 100\", $now, $stale, $now));
        if($recovered===false)SN_DB::audit('high_risk_cleanup_recovery_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
""")
rep("""        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::grants_table() . ' WHERE token_hash=%s AND user_id=%d AND purpose=%s LIMIT 1 FOR UPDATE', $hash, $user_id, $purpose));
        if (!$row || (string) $row->status !== 'active' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_step_up_token_expired', 'The step-up token is invalid or expired.', 403);
""","""        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::grants_table() . ' WHERE token_hash=%s AND user_id=%d AND purpose=%s LIMIT 1 FOR UPDATE', $hash, $user_id, $purpose));
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('step_up_grant_read_failed');
        if (!$row || (string) $row->status !== 'active' || strtotime((string) $row->expires_at . ' UTC') <= time()) return self::error('sn_step_up_token_expired', 'The step-up token is invalid or expired.', 403);
""")
rep("""        $changed = $wpdb->update(self::grants_table(), ['status' => 'consumed', 'consumed_at' => $now, 'updated_at' => $now, 'version' => (int) $row->version + 1], ['id' => (int) $row->id, 'status' => 'active', 'version' => (int) $row->version]);
        return $changed === 1 ? $row : self::error('sn_step_up_token_replayed', 'The one-time step-up token was already used.', 409);
""","""        $changed = $wpdb->update(self::grants_table(), ['status' => 'consumed', 'consumed_at' => $now, 'updated_at' => $now, 'version' => (int) $row->version + 1], ['id' => (int) $row->id, 'status' => 'active', 'version' => (int) $row->version]);
        if ($changed === false) return self::storage_error('step_up_grant_write_failed');
        return $changed === 1 ? $row : self::error('sn_step_up_token_replayed', 'The one-time step-up token was already used.', 409);
""")
rep("""    private static function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, ['status' => $status]); }
""","""    private static function storage_error(string $reason): WP_Error { SN_DB::audit($reason,'high_risk',0,'failure',[],get_current_user_id()); return self::error('sn_high_risk_storage_unavailable','High-risk governance state could not be verified safely. Retry later.',503); }
    private static function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, ['status' => $status]); }
""")
p.write_text(s,encoding='utf-8')
t=Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php');ts=t.read_text(encoding='utf-8');anchor='if($fail){fwrite(STDERR,'
block="""// R5 — high-risk governance must distinguish DB failure from absence/conflict.\n$highRisk=$read($root.'/includes/class-sn-high-risk.php');\n$check(str_contains($highRisk,'sn_high_risk_storage_unavailable'),'R5 high-risk DB uncertainty must fail closed.');\n$check(str_contains($highRisk,'high_risk_list_read_failed'),'R5 high-risk inventory failures must not collapse to empty lists.');\n$check(str_contains($highRisk,'step_up_grant_read_failed'),'R5 step-up grant DB failures must not masquerade as expired tokens.');\n$check(str_contains($highRisk,'high_risk_cleanup_recovery_failed'),'R5 high-risk cleanup failures must remain observable.');\n"""
if anchor not in ts: raise SystemExit('missing test footer')
t.write_text(ts.replace(anchor,block+anchor,1),encoding='utf-8')
