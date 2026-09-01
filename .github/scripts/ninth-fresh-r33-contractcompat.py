from pathlib import Path
p=Path('sabri-network/includes/class-sn-message-search.php')
t=p.read_text(encoding='utf-8')
old="$member=SN_DB::is_member($conversation_id,$viewer_id); if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable(); if (!$member) return self::not_found();"
new="if (!SN_DB::is_member($conversation_id, $viewer_id)) { if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable(); return self::not_found(); } if (($wpdb->last_error ?? '') !== '') return self::storage_unavailable();"
assert t.count(old)==2, f'expected two R33 membership guards, got {t.count(old)}'
t=t.replace(old,new)
p.write_text(t,encoding='utf-8')
