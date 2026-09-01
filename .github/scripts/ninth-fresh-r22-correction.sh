#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-db.php'; t=p.read_text(encoding='utf-8')
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
new="""    public static function is_blocked(int $a, int $b): bool {
        global $wpdb;
        $blocked = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('blocks') . ' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1',
            $a,
            $b,
            $b,
            $a
        ));
        if ($wpdb->last_error !== '') {
            // Block state is a privacy/authorization boundary. Unknown must deny.
            self::audit('block_state_read_failed', 'block', 0, 'failure', [
                'actor_hash' => hash('sha256', (string) $a),
                'target_hash' => hash('sha256', (string) $b),
                'reason' => (string) $wpdb->last_error,
            ], 0);
            return true;
        }
        return $blocked !== null;
    }
"""
if old not in t: raise SystemExit('R22 is_blocked anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('R22 suite anchor missing')
block=r'''
// Round 22 — block-state uncertainty fails closed for directory/profile/phone privacy.
$db=$read('includes/class-sn-db.php');$auth=$read('includes/class-sn-auth.php');$rest=$read('includes/class-sn-rest.php');
$blockedPos=strpos($db,'public static function is_blocked');$blockedEnd=$blockedPos===false?false:strpos($db,'public static function add_notification',$blockedPos);$blockedSeg=$blockedPos===false?'':substr($db,$blockedPos,($blockedEnd===false?strlen($db):$blockedEnd)-$blockedPos);
$check(str_contains($blockedSeg,'block_state_read_failed') && str_contains($blockedSeg,'$wpdb->last_error') && str_contains($blockedSeg,'return true;'), 'Round 22: unknown block state must fail privacy/authorization closed.');
$check(str_contains($auth,'SN_DB::is_blocked($viewer_id, $user_id)') && str_contains($auth,'can_view_phone'), 'Round 22: public profile/phone disclosure must remain behind canonical block state.');
$check(str_contains($rest,'if (SN_DB::is_blocked($viewer_id, $id))') && str_contains($rest,'sn_network_allow_phone_directory_lookup'), 'Round 22: directory and phone lookup results must remain behind canonical block suppression.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-db.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
