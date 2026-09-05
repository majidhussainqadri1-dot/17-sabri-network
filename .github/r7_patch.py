from pathlib import Path

# 1) SN_DB block truth: error-aware authoritative API plus conservative bool wrapper.
p=Path('sabri-network/includes/class-sn-db.php'); s=p.read_text(encoding='utf-8')
old="""    public static function is_blocked(int $a, int $b): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('blocks') . ' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1',
            $a,
            $b,
            $b,
            $a
        ));
    }
"""
new="""    public static function blocked_state(int $a, int $b): bool|WP_Error {
        global $wpdb;
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }
        $wpdb->last_error = '';
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('blocks') . ' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1',
            $a,
            $b,
            $b,
            $a
        ));
        if ($wpdb->last_error !== '' || ($value !== null && !is_numeric($value))) {
            return new WP_Error('relationship_block_state_unavailable', 'The relationship block state could not be verified.', ['status' => 503]);
        }
        return $value !== null;
    }

    /** Boolean privacy wrapper: unavailable block truth is treated as blocked. */
    public static function is_blocked(int $a, int $b): bool {
        $state = self::blocked_state($a, $b);
        return is_wp_error($state) ? true : $state;
    }
"""
if old not in s: raise SystemExit('R7 DB block target mismatch')
s=s.replace(old,new,1); p.write_text(s,encoding='utf-8')

# 2) Policy mutation authorization propagates block-read failure.
p=Path('sabri-network/includes/class-sn-policy.php'); s=p.read_text(encoding='utf-8')
needle="""        if (SN_DB::is_blocked($actor_id, $target_id)) {
            return new WP_Error('blocked', 'This Network connection is unavailable.', ['status' => 403]);
        }
"""
repl="""        $blocked = SN_DB::blocked_state($actor_id, $target_id);
        if (is_wp_error($blocked)) {
            return $blocked;
        }
        if ($blocked) {
            return new WP_Error('blocked', 'This Network connection is unavailable.', ['status' => 403]);
        }
"""
if s.count(needle) < 2: raise SystemExit('R7 policy block targets mismatch')
s=s.replace(needle,repl,2); p.write_text(s,encoding='utf-8')

# 3) Relationship state propagates block truth failure; block cleanup rejects failed call ledger read.
p=Path('sabri-network/includes/class-sn-relationships.php'); s=p.read_text(encoding='utf-8')
old='$blocked = SN_DB::is_blocked($viewer_id, $target_id);'
new='$blocked = SN_DB::blocked_state($viewer_id, $target_id);\n        if (is_wp_error($blocked)) return $blocked;'
if old not in s: raise SystemExit('R7 relationship state target mismatch')
s=s.replace(old,new,1); p.write_text(s,encoding='utf-8')

p=Path('sabri-network/includes/class-sn-relationship-runtime-hardening.php'); s=p.read_text(encoding='utf-8')
old="""        $ids = array_map('intval',$wpdb->get_col($wpdb->prepare(\"SELECT id FROM $calls WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE\",$conversation)));
        if (!$ids) return;
"""
new="""        $wpdb->last_error = '';
        $raw_ids = $wpdb->get_col($wpdb->prepare(\"SELECT id FROM $calls WHERE conversation_id=%d AND status IN ('ringing','active') FOR UPDATE\",$conversation));
        if ($wpdb->last_error !== '' || !is_array($raw_ids)) throw new RuntimeException('active_call_block_ledger_read_failed');
        $ids = array_map('intval',$raw_ids);
        if (!$ids) return;
"""
if old not in s: raise SystemExit('R7 active-call cleanup target mismatch')
s=s.replace(old,new,1); p.write_text(s,encoding='utf-8')

# 4) Presence device limit and erasure fail closed on DB failure.
p=Path('sabri-network/includes/class-sn-presence-devices.php'); s=p.read_text(encoding='utf-8')
old="""            $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s',$user,$now));
            if($count>=self::MAX_DEVICES)return self::error('sn_presence_device_limit','Revoke an active device before adding another.',409);
"""
new="""            $wpdb->last_error='';$count_raw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s',$user,$now));
            if($wpdb->last_error!==''||$count_raw===null||!is_numeric($count_raw))return self::error('sn_presence_device_count_unavailable','Active device capacity could not be verified. Retry the heartbeat.',503);
            $count=(int)$count_raw;if($count>=self::MAX_DEVICES)return self::error('sn_presence_device_limit','Revoke an active device before adding another.',409);
"""
if old not in s: raise SystemExit('R7 presence count target mismatch')
s=s.replace(old,new,1)
old="""    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$deleted=$wpdb->delete(self::table(),['user_id'=>(int)$user->ID]);return['items_removed'=>$deleted>0,'items_retained'=>false,'messages'=>[],'done'=>true];}
"""
new="""    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$deleted=$wpdb->delete(self::table(),['user_id'=>(int)$user->ID]);if($deleted===false)return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Presence-device erasure could not be committed and must be retried.','sabri-network')],'done'=>false];return['items_removed'=>$deleted>0,'items_retained'=>false,'messages'=>[],'done'=>true];}
"""
if old not in s: raise SystemExit('R7 presence eraser target mismatch')
s=s.replace(old,new,1); p.write_text(s,encoding='utf-8')
