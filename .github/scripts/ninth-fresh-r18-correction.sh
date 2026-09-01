#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

# R18A: rebuild completion and continuation scheduling must fail closed.
p=root/'includes/class-sn-runtime-boundary-policy.php'
t=p.read_text(encoding='utf-8')
old="""        $table = SN_DB::table('message_search_tokens');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
"""
new="""        $table = SN_DB::table('message_search_tokens');
        $table_probe = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error !== '') {
            update_option(self::SEARCH_ERROR_OPTION, 'table_probe_failed', false);
            update_option(self::SEARCH_REBUILD_OPTION, true, false);
            SN_DB::audit('message_search_table_probe_failed', 'message_search', 0, 'failure', ['reason'=>(string)$wpdb->last_error], 0);
            return;
        }
        if ($table_probe !== $table) return;
"""
if old not in t: raise SystemExit('R18 runtime table probe anchor missing')
t=t.replace(old,new,1)
old="""        $after = max(0, (int) get_option('sn_message_search_backfill_after', 0));
        $remaining = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SN_DB::table('messages') . ' WHERE id>%d LIMIT 1', $after));
        if ($remaining === 0) {
"""
new="""        $after = max(0, (int) get_option('sn_message_search_backfill_after', 0));
        $remaining_raw = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SN_DB::table('messages') . ' WHERE id>%d LIMIT 1', $after));
        if ($wpdb->last_error !== '') {
            update_option(self::SEARCH_ERROR_OPTION, 'remaining_count_failed', false);
            SN_DB::audit('message_search_rebuild_count_failed', 'message_search', 0, 'failure', ['reason'=>(string)$wpdb->last_error,'cursor'=>$after], 0);
            return;
        }
        $remaining = (int) $remaining_raw;
        if ($remaining === 0) {
"""
if old not in t: raise SystemExit('R18 runtime count anchor missing')
t=t.replace(old,new,1)
old="""        if (!wp_next_scheduled(self::SEARCH_CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_CONTINUE_HOOK);
        }
"""
new="""        if (!wp_next_scheduled(self::SEARCH_CONTINUE_HOOK)) {
            $scheduled = wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_CONTINUE_HOOK, [], true);
            if (is_wp_error($scheduled) || $scheduled === false) {
                update_option(self::SEARCH_ERROR_OPTION, 'continuation_schedule_failed', false);
                SN_DB::audit('message_search_rebuild_schedule_failed', 'message_search', 0, 'failure', [
                    'reason'=>is_wp_error($scheduled)?$scheduled->get_error_code():'schedule_returned_false','cursor'=>$after,
                ], 0);
            }
        }
"""
if old not in t: raise SystemExit('R18 runtime schedule anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# R18B: private search reads must not turn DB errors into valid empty/not-found responses.
p=root/'includes/class-sn-message-search.php'
t=p.read_text(encoding='utf-8')
old="""        } else {
            $snapshot = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id),0) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));
        }
"""
new="""        } else {
            $snapshot_raw = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id),0) FROM ' . SN_DB::table('messages') . ' WHERE conversation_id=%d', $conversation_id));
            if ($wpdb->last_error !== '') return new WP_Error('search_snapshot_unavailable', 'Message search snapshot could not be read safely.', ['status'=>503]);
            $snapshot = (int) $snapshot_raw;
        }
"""
if old not in t: raise SystemExit('R18 search snapshot anchor missing')
t=t.replace(old,new,1)
old="""        $target = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $messages WHERE id=%d AND conversation_id=%d AND id<=%d AND deleted_at IS NULL\", $target_id, $conversation_id, $snapshot));
        if (!$target || !self::indexable($target) || SN_Message_Operations::is_hidden($viewer_id, $target_id)) return self::not_found();
        $before = array_reverse($wpdb->get_results($wpdb->prepare(\"SELECT * FROM $messages WHERE conversation_id=%d AND id<%d AND id<=%d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d\", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT)) ?: []);
        $after = $wpdb->get_results($wpdb->prepare(\"SELECT * FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d\", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT)) ?: [];
"""
new="""        $target = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $messages WHERE id=%d AND conversation_id=%d AND id<=%d AND deleted_at IS NULL\", $target_id, $conversation_id, $snapshot));
        if ($wpdb->last_error !== '') return new WP_Error('search_context_unavailable', 'Message search context could not be read safely.', ['status'=>503]);
        if (!$target || !self::indexable($target) || SN_Message_Operations::is_hidden($viewer_id, $target_id)) return self::not_found();
        $before_rows = $wpdb->get_results($wpdb->prepare(\"SELECT * FROM $messages WHERE conversation_id=%d AND id<%d AND id<=%d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d\", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT));
        if (!is_array($before_rows) || $wpdb->last_error !== '') return new WP_Error('search_context_unavailable', 'Message search context could not be read safely.', ['status'=>503]);
        $before = array_reverse($before_rows);
        $after = $wpdb->get_results($wpdb->prepare(\"SELECT * FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d\", $conversation_id, $target_id, $snapshot, self::MAX_CONTEXT));
        if (!is_array($after) || $wpdb->last_error !== '') return new WP_Error('search_context_unavailable', 'Message search context could not be read safely.', ['status'=>503]);
"""
if old not in t: raise SystemExit('R18 search context anchor missing')
t=t.replace(old,new,1)
old="""        $ids = $wpdb->get_col(\"SELECT t.id FROM $tokens t LEFT JOIN $messages m ON m.id=t.message_id WHERE m.id IS NULL OR m.deleted_at IS NOT NULL ORDER BY t.id ASC LIMIT 1000\");
        if ($ids) $wpdb->query('DELETE FROM ' . $tokens . ' WHERE id IN (' . implode(',', array_map('absint', $ids)) . ')');
"""
new="""        $ids = $wpdb->get_col(\"SELECT t.id FROM $tokens t LEFT JOIN $messages m ON m.id=t.message_id WHERE m.id IS NULL OR m.deleted_at IS NOT NULL ORDER BY t.id ASC LIMIT 1000\");
        if (!is_array($ids) || $wpdb->last_error !== '') {
            if (class_exists('SN_DB')) SN_DB::audit('message_search_cleanup_read_failed', 'message_search', 0, 'failure', ['reason'=>(string)$wpdb->last_error], 0);
            return;
        }
        if ($ids && $wpdb->query('DELETE FROM ' . $tokens . ' WHERE id IN (' . implode(',', array_map('absint', $ids)) . ')') === false) {
            if (class_exists('SN_DB')) SN_DB::audit('message_search_cleanup_delete_failed', 'message_search', 0, 'failure', ['reason'=>(string)$wpdb->last_error], 0);
        }
"""
if old not in t: raise SystemExit('R18 search cleanup anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Permanent R18 contracts.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('R18 suite anchor missing')
block=r'''
// Round 18 — private-search DB/rebuild truth fails closed.
$search = $read('includes/class-sn-message-search.php');
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$check(str_contains($search, 'search_snapshot_unavailable') && str_contains($search, 'search_context_unavailable') && str_contains($search, 'message_search_cleanup_read_failed'), 'Round 18: search DB read failures must not become valid empty/not-found responses.');
$check(str_contains($boundary, 'remaining_count_failed') && str_contains($boundary, 'message_search_rebuild_count_failed') && str_contains($boundary, '$remaining_raw'), 'Round 18: rebuild completion count failure must never become zero remaining rows.');
$check(str_contains($boundary, 'continuation_schedule_failed') && str_contains($boundary, 'message_search_rebuild_schedule_failed') && str_contains($boundary, 'wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_CONTINUE_HOOK, [], true)'), 'Round 18: search rebuild continuation scheduling failure must be observable and fail closed.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-runtime-boundary-policy.php
php -l sabri-network/includes/class-sn-message-search.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
