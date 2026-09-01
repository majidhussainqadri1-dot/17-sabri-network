from pathlib import Path
p=Path('sabri-network/includes/class-sn-relationships.php')
s=p.read_text(encoding='utf-8')
def rep(old,new):
 global s
 if old not in s: raise SystemExit('missing anchor: '+old[:180])
 s=s.replace(old,new,1)
rep("""        $blocked_by_viewer = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('blocks') . ' WHERE user_id=%d AND blocked_user_id=%d LIMIT 1', $viewer_id, $target_id));
        $blocked = $blocked_by_viewer || SN_DB::is_blocked($viewer_id, $target_id);
        $contact = SN_DB::contact_record($viewer_id, $target_id);
        $follow = SN_DB::follow_record($viewer_id, $target_id);
        $reverse = SN_DB::follow_record($target_id, $viewer_id);
""","""        $blocked_by_viewer = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SN_DB::table('blocks') . ' WHERE user_id=%d AND blocked_user_id=%d LIMIT 1', $viewer_id, $target_id));
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('relationship_block_state_read_failed');
        $blocked = $blocked_by_viewer || SN_DB::is_blocked($viewer_id, $target_id);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('relationship_pair_block_state_read_failed');
        $contact = SN_DB::contact_record($viewer_id, $target_id);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('relationship_contact_state_read_failed');
        $follow = SN_DB::follow_record($viewer_id, $target_id);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('relationship_follow_state_read_failed');
        $reverse = SN_DB::follow_record($target_id, $viewer_id);
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('relationship_reverse_follow_state_read_failed');
""")
rep("""            $existing = SN_DB::follow_record($follower_id, $followed_id);
            if ($existing && in_array((string) $existing->status, ['active', 'pending'], true)) return self::project($existing, true);
""","""            $existing = SN_DB::follow_record($follower_id, $followed_id);
            if (($wpdb->last_error ?? '') !== '') return self::storage_error('follow_state_read_failed');
            if ($existing && in_array((string) $existing->status, ['active', 'pending'], true)) return self::project($existing, true);
""")
rep("""                $race = SN_DB::follow_record($follower_id, $followed_id);
                if ($race && in_array((string) $race->status, ['active', 'pending'], true)) return self::project($race, true);
""","""                $race = SN_DB::follow_record($follower_id, $followed_id);
                if (($wpdb->last_error ?? '') !== '') return self::storage_error('follow_reconciliation_read_failed');
                if ($race && in_array((string) $race->status, ['active', 'pending'], true)) return self::project($race, true);
""")
rep("""            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) return new WP_Error('follow_database_error', 'The follow relationship could not be confirmed.', ['status' => 500]);
""","""            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (($wpdb->last_error ?? '') !== '') return self::storage_error('follow_confirmation_read_failed');
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) return new WP_Error('follow_database_error', 'The follow relationship could not be confirmed.', ['status' => 500]);
""")
rep("""            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) {
""","""            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (($wpdb->last_error ?? '') !== '') return self::storage_error('unfollow_state_read_failed');
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) {
""")
rep("""            if ($updated !== 1) return new WP_Error('follow_version_conflict', 'The follow relationship changed before this request was saved.', ['status' => 409]);
""","""            if ($updated === false) return self::storage_error('unfollow_write_failed');
            if ($updated !== 1) return new WP_Error('follow_version_conflict', 'The follow relationship changed before this request was saved.', ['status' => 409]);
""",)
rep("""        $probe = $wpdb->get_row($wpdb->prepare('SELECT follower_id,followed_id FROM ' . SN_DB::table('follows') . ' WHERE id=%d', $follow_id));
        if (!$probe || (int) $probe->followed_id !== $target_id) return new WP_Error('follow_request_not_found', 'This follow request is unavailable.', ['status' => 404]);
""","""        $probe = $wpdb->get_row($wpdb->prepare('SELECT follower_id,followed_id FROM ' . SN_DB::table('follows') . ' WHERE id=%d', $follow_id));
        if (($wpdb->last_error ?? '') !== '') return self::storage_error('follow_decision_probe_failed');
        if (!$probe || (int) $probe->followed_id !== $target_id) return new WP_Error('follow_request_not_found', 'This follow request is unavailable.', ['status' => 404]);
""")
rep("""            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $follow_id));
            if (!$row || (int) $row->followed_id !== $target_id || (string) $row->status !== 'pending') return new WP_Error('follow_request_not_found', 'This follow request is unavailable.', ['status' => 404]);
""","""            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $follow_id));
            if (($wpdb->last_error ?? '') !== '') return self::storage_error('follow_decision_state_read_failed');
            if (!$row || (int) $row->followed_id !== $target_id || (string) $row->status !== 'pending') return new WP_Error('follow_request_not_found', 'This follow request is unavailable.', ['status' => 404]);
""")
# second occurrence of updated !=1 belongs decide after first was replaced
old="""            if ($updated !== 1) return new WP_Error('follow_version_conflict', 'The follow request changed before this decision was saved.', ['status' => 409]);
"""
new="""            if ($updated === false) return self::storage_error('follow_decision_write_failed');
            if ($updated !== 1) return new WP_Error('follow_version_conflict', 'The follow request changed before this decision was saved.', ['status' => 409]);
"""
rep(old,new)
rep("""        if (!is_array($rows)) return new WP_Error('follow_list_error', 'The follow list could not be loaded.', ['status' => 500]);
""","""        if (($wpdb->last_error ?? '') !== '' || !is_array($rows)) return self::storage_error('follow_list_read_failed');
""")
rep("""        $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock));
        if ($acquired !== 1) return new WP_Error('relationship_busy', 'This relationship is changing. Try again.', ['status' => 409]);
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
""","""        $raw = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock));
        if (($wpdb->last_error ?? '') !== '' || $raw === null) return self::storage_error('relationship_lock_unavailable');
        if ((int)$raw !== 1) return new WP_Error('relationship_busy', 'This relationship is changing. Try again.', ['status' => 409]);
        try {
            return $callback();
        } finally {
            $released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            if (($wpdb->last_error ?? '') !== '' || $released === null) SN_DB::audit('relationship_lock_release_failed','system',0,'failure',['lock_hash'=>substr(hash('sha256',$lock),0,16),'reason'=>(string)($wpdb->last_error??'')],0);
        }
""")
# add helper before project
rep("""    private static function project(object $row, bool $duplicate = false): array {
""","""    private static function storage_error(string $reason): WP_Error {
        SN_DB::audit($reason,'relationship',0,'failure',[],get_current_user_id());
        return new WP_Error('relationship_storage_unavailable','Relationship state could not be verified safely. Retry later.',['status'=>503]);
    }

    private static function project(object $row, bool $duplicate = false): array {
""")
p.write_text(s,encoding='utf-8')
t=Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php');ts=t.read_text(encoding='utf-8');anchor='if($fail){fwrite(STDERR,'
block="""// R3 — relationship state and advisory locks preserve DB uncertainty.\n$relationships=$read($root.'/includes/class-sn-relationships.php');\n$check(str_contains($relationships,'relationship_storage_unavailable'),'R3 relationship DB uncertainty must fail closed.');\n$check(str_contains($relationships,'relationship_lock_unavailable'),'R3 relationship lock service failure must differ from contention.');\n$check(str_contains($relationships,'relationship_lock_release_failed'),'R3 relationship lock release failure must be observable.');\n$check(str_contains($relationships,'follow_list_read_failed'),'R3 follow-list DB failures must not collapse to empty lists.');\n"""
if anchor not in ts: raise SystemExit('missing test footer')
t.write_text(ts.replace(anchor,block+anchor,1),encoding='utf-8')
