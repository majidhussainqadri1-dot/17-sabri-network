#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-presence-devices.php'; t=p.read_text(encoding='utf-8')
old="$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s',$user,$now));\n            if($count>=self::MAX_DEVICES)return self::error('sn_presence_device_limit','Revoke an active device before adding another.',409);"
new="$count_raw=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s',$user,$now));\n            if($wpdb->last_error!==''){SN_DB::audit('presence_device_limit_read_failed','presence_device',0,'failure',['reason'=>(string)$wpdb->last_error],$user);return self::error('sn_presence_device_limit_unavailable','The active-device limit could not be verified safely. Retry later.',503);}\n            $count=(int)$count_raw;\n            if($count>=self::MAX_DEVICES)return self::error('sn_presence_device_limit','Revoke an active device before adding another.',409);"
if old not in t: raise SystemExit('presence count anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('suite anchor missing')
block=r'''
// Round 16 — presence device budget fails closed on DB read failure.
$presence = $read('includes/class-sn-presence-devices.php');
$check(str_contains($presence, 'presence_device_limit_unavailable') && str_contains($presence, 'presence_device_limit_read_failed') && str_contains($presence, '$count_raw=$wpdb->get_var') && str_contains($presence, "$wpdb->last_error!==''"), 'Round 16: active-device limit DB failure must not become zero active devices.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-presence-devices.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
