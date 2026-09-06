from pathlib import Path

p=Path('sabri-network/includes/class-sn-relationship-runtime-hardening.php'); s=p.read_text()
old="""                if ($wpdb->query('COMMIT') === false) {
                    $own = (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $blocks WHERE user_id=%d AND blocked_user_id=%d",$actor,$target));
                    if ($own !== $blocked) throw new RuntimeException('block_commit_failed');
                }
"""
new="""                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->last_error = '';
                    $own_raw = $wpdb->get_var($wpdb->prepare("SELECT id FROM $blocks WHERE user_id=%d AND blocked_user_id=%d",$actor,$target));
                    if ($wpdb->last_error !== '' || ($own_raw !== null && !is_numeric($own_raw))) throw new RuntimeException('block_reconciliation_read_failed');
                    $own = $own_raw !== null;
                    if ($own !== $blocked) throw new RuntimeException('block_commit_failed');
                }
"""
if old not in s: raise SystemExit('block target missing')
s=s.replace(old,new,1)
old="""                if ($wpdb->query('COMMIT') === false) {
                    $fresh = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE id=%d",$id));
                    if (!$fresh || (string)$fresh->status !== 'active') throw new RuntimeException('conversation_commit_failed');
                }
"""
new="""                if ($wpdb->query('COMMIT') === false) {
                    if (!self::direct_conversation_reconciled($id,$directKey,$actor,$target)) throw new RuntimeException('conversation_commit_failed');
                }
"""
if old not in s: raise SystemExit('conversation commit target missing')
s=s.replace(old,new,1)
old="""                $race = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE direct_key=%s",$directKey));
                if ($race && (string)$race->status === 'active') return self::conversation_response((int)$race->id,true,true);
                return self::database_error();
"""
new="""                $wpdb->last_error = '';
                $race = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $conversations WHERE direct_key=%s",$directKey));
                if ($wpdb->last_error === '' && $race && (string)$race->status === 'active' && self::direct_conversation_reconciled((int)$race->id,$directKey,$actor,$target)) return self::conversation_response((int)$race->id,true,true);
                return self::database_error();
"""
if old not in s: raise SystemExit('race target missing')
s=s.replace(old,new,1)
anchor="""    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:'.substr(hash('sha256',(string)$id),0,32); }
"""
helper="""    private static function direct_conversation_reconciled(int $id,string $directKey,int $actor,int $target): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,status,direct_key FROM ".SN_DB::table('conversations')." WHERE id=%d",$id));
        if ($wpdb->last_error !== '' || !$row || (string)$row->status !== 'active' || !hash_equals((string)$row->direct_key,$directKey)) return false;
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM ".SN_DB::table('members')." WHERE conversation_id=%d AND left_at IS NULL ORDER BY user_id ASC",$id));
        if ($wpdb->last_error !== '' || !is_array($ids)) return false;
        $ids = array_values(array_unique(array_map('intval',$ids))); sort($ids,SORT_NUMERIC);
        $expected = [$actor,$target]; sort($expected,SORT_NUMERIC);
        return $ids === $expected;
    }

"""
if anchor not in s: raise SystemExit('helper anchor missing')
s=s.replace(anchor,helper+anchor,1)
p.write_text(s)

p=Path('sabri-network/includes/class-sn-relationships.php'); s=p.read_text()
old="""            $row = SN_DB::follow_record($follower_id, $followed_id);
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) {
                return ['id' => $row ? (int) $row->id : 0, 'status' => 'inactive', 'version' => $row ? (int) $row->version : 0, 'duplicate' => true];
            }
"""
new="""            $wpdb->last_error = '';
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('follows').' WHERE follower_id=%d AND followed_id=%d LIMIT 1',$follower_id,$followed_id));
            if ($wpdb->last_error !== '' || ($row !== null && !is_object($row))) return new WP_Error('follow_database_error','The follow relationship could not be verified.',['status'=>503]);
            if (!$row || !in_array((string) $row->status, ['active', 'pending'], true)) {
                return ['id' => $row ? (int) $row->id : 0, 'status' => 'inactive', 'version' => $row ? (int) $row->version : 0, 'duplicate' => true];
            }
"""
if old not in s: raise SystemExit('unfollow target missing')
p.write_text(s.replace(old,new,1))

p=Path('sabri-network/tests/relationships-adversarial-contracts.php'); s=p.read_text()
mark='// Yet-another R2 relationship reconciliation regressions.'
if mark not in s:
    insert="""
// Yet-another R2 relationship reconciliation regressions.
$rr=file_get_contents($root.'/includes/class-sn-relationship-runtime-hardening.php');
$rels=file_get_contents($root.'/includes/class-sn-relationships.php');
ra_check(str_contains($rr,'block_reconciliation_read_failed')&&str_contains($rr,'$own_raw = $wpdb->get_var'),'Yet R2: block/unblock commit reconciliation is DB-error aware.');
ra_check(str_contains($rr,'direct_conversation_reconciled')&&str_contains($rr,'$ids === $expected'),'Yet R2: direct conversation reconciliation proves exact active membership.');
ra_check(str_contains($rels,"'follow_database_error','The follow relationship could not be verified.")&&str_contains($rels,"$wpdb->last_error !== ''"),'Yet R2: unfollow cannot treat a failed follow-row read as duplicate inactive success.');
"""
    i=s.rfind('if($failures)')
    if i<0: raise SystemExit('test tail missing')
    s=s[:i]+insert+s[i:]
p.write_text(s)
