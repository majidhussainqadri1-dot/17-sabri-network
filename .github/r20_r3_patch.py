from pathlib import Path
p=Path('sabri-network/includes/class-sn-fourth-fresh-search-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        $tokens = SN_DB::table('message_search_tokens');
        if ($wpdb->query('TRUNCATE TABLE ' . $tokens) === false) {
            update_option(self::ERROR_OPTION, 'truncate_failed', false);
            update_option(self::REBUILD_OPTION, true, false);
            return new WP_Error('search_rebuild_failed', 'The search index could not be reset.', ['status'=>500]);
        }
        update_option('sn_message_search_backfill_after', 0, false);
        update_option(self::EPOCH_OPTION, self::epoch(), false);
        update_option(self::REBUILD_OPTION, true, false);
        delete_option(self::ERROR_OPTION);
"""
new="""        $tokens = SN_DB::table('message_search_tokens');
        if (!self::prepare_rebuild_state()) {
            update_option(self::ERROR_OPTION, 'rebuild_state_publish_failed', false);
            return new WP_Error('search_rebuild_state_failed', 'The search rebuild state could not be published durably.', ['status'=>503]);
        }
        if ($wpdb->query('TRUNCATE TABLE ' . $tokens) === false) {
            update_option(self::ERROR_OPTION, 'truncate_failed', false);
            return new WP_Error('search_rebuild_failed', 'The search index could not be reset.', ['status'=>500]);
        }
        $epoch = self::epoch();
        update_option(self::EPOCH_OPTION, $epoch, false);
        if ((string)get_option(self::EPOCH_OPTION, '') !== $epoch) {
            update_option(self::ERROR_OPTION, 'epoch_publish_failed', false);
            return new WP_Error('search_rebuild_state_failed', 'The search rebuild epoch could not be published durably.', ['status'=>503]);
        }
        delete_option(self::ERROR_OPTION);
"""
if old not in s: raise SystemExit('manual reset target missing')
s=s.replace(old,new,1)
old="""        if ($stored !== $current) {
            if ($wpdb->query('TRUNCATE TABLE ' . $tokens) === false) {
                update_option(self::ERROR_OPTION, 'truncate_failed', false);
                update_option(self::REBUILD_OPTION, true, false);
                return;
            }
            update_option('sn_message_search_backfill_after', 0, false);
            update_option(self::EPOCH_OPTION, $current, false);
            update_option(self::REBUILD_OPTION, true, false);
            delete_option(self::ERROR_OPTION);
"""
new="""        if ($stored !== $current) {
            if (!self::prepare_rebuild_state()) {
                self::record_error('rebuild_state_publish_failed', 0, 0);
                return;
            }
            if ($wpdb->query('TRUNCATE TABLE ' . $tokens) === false) {
                self::record_error('truncate_failed', 0, 0);
                return;
            }
            update_option(self::EPOCH_OPTION, $current, false);
            if ((string)get_option(self::EPOCH_OPTION, '') !== $current) {
                self::record_error('epoch_publish_failed', 0, 0);
                return;
            }
            delete_option(self::ERROR_OPTION);
"""
if old not in s: raise SystemExit('epoch reset target missing')
s=s.replace(old,new,1)
anchor="""    private static function record_error(string $code, int $after, int $message_id): void {
"""
helper="""    private static function prepare_rebuild_state(): bool {
        update_option('sn_message_search_backfill_after', 0, false);
        update_option(self::REBUILD_OPTION, true, false);
        return (int)get_option('sn_message_search_backfill_after', -1) === 0
            && (bool)get_option(self::REBUILD_OPTION, false) === true;
    }

"""
if anchor not in s: raise SystemExit('helper anchor missing')
s=s.replace(anchor,helper+anchor,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
t=p.read_text(encoding='utf-8')
marker='if($fail){fwrite(STDERR,'
insert="""
$check(str_contains($searchHardening,'private static function prepare_rebuild_state')&&substr_count($searchHardening,'self::prepare_rebuild_state()')>=2,'Fresh20 R3: both destructive search reset paths must prepare durable rebuild state before truncation.');
$check(str_contains($searchHardening,"get_option('sn_message_search_backfill_after', -1)")&&str_contains($searchHardening,'get_option(self::REBUILD_OPTION, false)'),'Fresh20 R3: search reset must verify cursor-zero and rebuild-pending state after publication.');
$check(str_contains($searchHardening,"'search_rebuild_state_failed'")&&str_contains($searchHardening,"'epoch_publish_failed'"),'Fresh20 R3: manual/epoch state publication failures must fail closed explicitly.');
"""
idx=t.rfind(marker)
if idx<0: raise SystemExit('seventh test tail missing')
t=t[:idx]+insert+t[idx:]
p.write_text(t,encoding='utf-8')
