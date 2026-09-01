from pathlib import Path
root=Path('sabri-network'); p=root/'includes/class-sn-message-search.php'; t=p.read_text(encoding='utf-8')
# R33 ledger frozen: source/membership/search/backfill/health DB uncertainty could become not-found, empty, or zero/healthy state.
t=t.replace("$message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $message_id));\n        if (!$message)", "$message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $message_id));\n        if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();\n        if (!$message)",1)
t=t.replace("if (!SN_DB::is_member($conversation_id, $viewer_id)) return self::not_found();", "$member=SN_DB::is_member($conversation_id,$viewer_id); if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable(); if (!$member) return self::not_found();",2)
t=t.replace("if (!is_array($rows)) return new WP_Error('search_unavailable', 'Message search is temporarily unavailable.', ['status' => 500]);", "if (!is_array($rows) || ($wpdb->last_error ?? '') !== '') return self::storage_unavailable();",1)
t=t.replace("if (!is_array($rows)) return self::backfill_failure(new WP_Error('search_backfill_query_failed', 'The message search backfill could not read its next batch.'));", "if (!is_array($rows) || ($wpdb->last_error ?? '') !== '') return self::backfill_failure(new WP_Error('search_backfill_query_failed', 'The message search backfill could not read its next batch.'));",1)
old="""    public static function health(): WP_REST_Response {
        global $wpdb;
        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        $rebuilding = (bool) get_option(self::REBUILDING_OPTION, false);
        $error = (string) get_option(self::REBUILD_ERROR_OPTION, '');
        return rest_ensure_response(['ok' => $exists && !$rebuilding && $error === '' && (string) get_option('sn_message_search_schema_version', '') === self::SCHEMA_VERSION, 'table' => $exists, 'schema_version' => (string) get_option('sn_message_search_schema_version', ''), 'tokens' => $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") : 0, 'backfill_after' => (int) get_option('sn_message_search_backfill_after', 0), 'rebuilding' => $rebuilding, 'error' => $error, 'time' => gmdate('c')]);
    }"""
new="""    public static function health(): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = self::table();
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))); if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();
        $exists = $found === $table; $tokens=0;
        if($exists){$count=$wpdb->get_var("SELECT COUNT(*) FROM $table"); if (($wpdb->last_error ?? '') !== '' || $count===null) return self::storage_unavailable(); $tokens=(int)$count;}
        $rebuilding = (bool) get_option(self::REBUILDING_OPTION, false);
        $error = (string) get_option(self::REBUILD_ERROR_OPTION, '');
        return rest_ensure_response(['ok' => $exists && !$rebuilding && $error === '' && (string) get_option('sn_message_search_schema_version', '') === self::SCHEMA_VERSION, 'table' => $exists, 'schema_version' => (string) get_option('sn_message_search_schema_version', ''), 'tokens' => $tokens, 'backfill_after' => (int) get_option('sn_message_search_backfill_after', 0), 'rebuilding' => $rebuilding, 'error' => $error, 'time' => gmdate('c')]);
    }"""
assert old in t; t=t.replace(old,new,1)
anchor="    private static function not_found(): WP_Error"
assert anchor in t; t=t.replace(anchor,"    private static function storage_unavailable(): WP_Error { return new WP_Error('message_search_storage_unavailable','Message search storage truth could not be verified safely.',['status'=>503]); }\n    private static function not_found(): WP_Error",1)
p.write_text(t,encoding='utf-8')
q=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; s=q.read_text(encoding='utf-8'); marker='\nif ($fail) {\n'; assert marker in s and '// Round 33 —' not in s
block=r'''

// Round 33 — message search preserves authorization, source, collection and health database truth.
$search=$read('includes/class-sn-message-search.php');
$check(str_contains($search,'message_search_storage_unavailable') && substr_count($search,'self::storage_unavailable()')>=6, 'Round 33: search source/membership/query DB uncertainty must fail closed instead of not-found/empty.');
$check(str_contains($search,'public static function health(): WP_REST_Response|WP_Error') && str_contains($search,'$count===null'), 'Round 33: message-search health must not cast failed table/count reads into missing/zero state.');
$check(str_contains($search,"!is_array($rows) || ($wpdb->last_error ?? '') !== ''"), 'Round 33: search/backfill collection reads must retain DB-error evidence.');
'''
q.write_text(s.replace(marker,block+marker,1),encoding='utf-8')
