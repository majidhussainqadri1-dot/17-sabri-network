from pathlib import Path

p=Path('sabri-network/includes/class-sn-relationship-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
repls={
"                $row = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE pair_key=%s FOR UPDATE\", $pair));":"                $row = self::checked_row($wpdb->prepare(\"SELECT * FROM $table WHERE pair_key=%s FOR UPDATE\", $pair), 'contact_lock_read_failed');",
"        $probe = $wpdb->get_row($wpdb->prepare(\"SELECT user_id,contact_user_id FROM $table WHERE id=%d\", $id));\n        if (!$probe) return self::not_found();":"        $wpdb->last_error = '';\n        $probe = $wpdb->get_row($wpdb->prepare(\"SELECT user_id,contact_user_id FROM $table WHERE id=%d\", $id));\n        if ($wpdb->last_error !== '') return self::database_error();\n        if (!$probe) return self::not_found();",
"                $row = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE id=%d FOR UPDATE\", $id));":"                $row = self::checked_row($wpdb->prepare(\"SELECT * FROM $table WHERE id=%d FOR UPDATE\", $id), 'contact_decision_lock_read_failed');",
"                $contact = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $contacts WHERE pair_key=%s FOR UPDATE\", SN_DB::contact_pair_key($actor,$target)));\n                $wpdb->get_results($wpdb->prepare(\"SELECT id FROM $follows WHERE (follower_id=%d AND followed_id=%d) OR (follower_id=%d AND followed_id=%d) FOR UPDATE\", $actor,$target,$target,$actor));":"                $contact = self::checked_row($wpdb->prepare(\"SELECT * FROM $contacts WHERE pair_key=%s FOR UPDATE\", SN_DB::contact_pair_key($actor,$target)), 'block_contact_lock_read_failed');\n                self::checked_results($wpdb->prepare(\"SELECT id FROM $follows WHERE (follower_id=%d AND followed_id=%d) OR (follower_id=%d AND followed_id=%d) FOR UPDATE\", $actor,$target,$target,$actor), 'block_follow_lock_read_failed');",
"                    $direct = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM \".SN_DB::table('conversations').\" WHERE type='direct' AND direct_key=%s FOR UPDATE\", SN_DB::direct_key($actor,$target)));":"                    $direct = self::checked_row($wpdb->prepare(\"SELECT * FROM \".SN_DB::table('conversations').\" WHERE type='direct' AND direct_key=%s FOR UPDATE\", SN_DB::direct_key($actor,$target)), 'block_conversation_lock_read_failed');",
"                $space = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('spaces').' WHERE id=%d', $space_id));\n                if (!$space || !in_array((string)$space->state,['active','restricted','locked'],true) || (int)$space->conversation_id <= 0) return self::not_found();\n                $member = $wpdb->get_row($wpdb->prepare(\"SELECT role FROM \".SN_DB::table('space_members').\" WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1\",$space_id,$actor));\n                if (!$member) return self::not_found();":"                $wpdb->last_error = '';\n                $space = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('spaces').' WHERE id=%d', $space_id));\n                if ($wpdb->last_error !== '') return self::database_error();\n                if (!$space || !in_array((string)$space->state,['active','restricted','locked'],true) || (int)$space->conversation_id <= 0) return self::not_found();\n                $wpdb->last_error = '';\n                $member = $wpdb->get_row($wpdb->prepare(\"SELECT role FROM \".SN_DB::table('space_members').\" WHERE space_id=%d AND user_id=%d AND status='active' LIMIT 1\",$space_id,$actor));\n                if ($wpdb->last_error !== '') return self::database_error();\n                if (!$member) return self::not_found();",
"                $existing = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $conversations WHERE direct_key=%s FOR UPDATE\",$directKey));":"                $existing = self::checked_row($wpdb->prepare(\"SELECT * FROM $conversations WHERE direct_key=%s FOR UPDATE\",$directKey), 'direct_conversation_lock_read_failed');",
"                        $row = $wpdb->get_row($wpdb->prepare(\"SELECT * FROM $memberTable WHERE conversation_id=%d AND user_id=%d FOR UPDATE\",$id,$memberId));":"                        $row = self::checked_row($wpdb->prepare(\"SELECT * FROM $memberTable WHERE conversation_id=%d AND user_id=%d FOR UPDATE\",$id,$memberId), 'direct_member_lock_read_failed');",
}
for old,new in repls.items():
    if old not in s:
        raise SystemExit('missing target: '+old[:80])
    s=s.replace(old,new,1)
anchor="    private static function conversation_lock(int $id): string { return 'sn:f17:conversation:'.substr(hash('sha256',(string)$id),0,32); }\n"
helper="""    private static function checked_row(string $sql, string $failure): ?object {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql);
        if ($wpdb->last_error !== '') throw new RuntimeException($failure);
        return is_object($row) ? $row : null;
    }

    private static function checked_results(string $sql, string $failure): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql);
        if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException($failure);
        return $rows;
    }

"""
if anchor not in s: raise SystemExit('helper anchor missing')
s=s.replace(anchor,helper+anchor,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/another-fresh-r7-relationship-contracts.php')
t=p.read_text(encoding='utf-8')
marker="\nif($fail){fwrite(STDERR,"
insert="""

// Fresh 20-round R1 authoritative relationship lock-read truth.
$check(str_contains($runtime,'private static function checked_row')&&str_contains($runtime,'private static function checked_results'),'Fresh20 R1: final relationship owner must centralize fail-closed checked DB reads.');
foreach(['contact_lock_read_failed','contact_decision_lock_read_failed','block_contact_lock_read_failed','block_follow_lock_read_failed','block_conversation_lock_read_failed','direct_conversation_lock_read_failed','direct_member_lock_read_failed'] as $failure){$check(str_contains($runtime,$failure),"Fresh20 R1: missing checked relationship read failure marker $failure.");}
$check(substr_count($runtime,"if ($wpdb->last_error !== '') return self::database_error();")>=3,'Fresh20 R1: non-transaction relationship/space probes must distinguish DB failure from absence.');
$check(str_contains($runtime,'block_follow_lock_read_failed')&&str_contains($runtime,'checked_results'),'Fresh20 R1: block mutation must prove follow rows were locked before cleanup writes.');
"""
if marker not in t: raise SystemExit('test tail missing')
t=t.replace(marker,insert+marker,1)
p.write_text(t,encoding='utf-8')
