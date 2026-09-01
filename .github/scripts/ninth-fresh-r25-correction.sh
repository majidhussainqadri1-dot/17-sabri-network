#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
# Smail send must fail closed when idempotency authority cannot be read.
p=root/'includes/class-sn-smail-runtime-hardening.php'; t=p.read_text(encoding='utf-8')
old="""            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
            if($existing){$match=self::idempotency_matches($existing,$sender,$recipients,$subject,$body);if(is_wp_error($match))return $match;return rest_ensure_response(['smail'=>self::format($existing),'duplicate'=>true]);}
"""
new="""            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
            if($wpdb->last_error!==''){SN_DB::audit('smail_idempotency_lookup_failed','smail',0,'failure',['reason'=>(string)$wpdb->last_error],$sender);return new WP_Error('smail_idempotency_unavailable','Smail idempotency state could not be verified safely.',['status'=>503]);}
            if($existing){$match=self::idempotency_matches($existing,$sender,$recipients,$subject,$body);if(is_wp_error($match))return $match;return rest_ensure_response(['smail'=>self::format($existing),'duplicate'=>true]);}
"""
if old not in t: raise SystemExit('R25 send idempotency anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')
# Mailbox read must distinguish database failure from a legitimate empty mailbox.
p=root/'includes/class-sn-smail-part-1.php'; t=p.read_text(encoding='utf-8')
old="""        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        $items = array_map(static function ($row) use ($user_id): array {
"""
new="""        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        if ($wpdb->last_error !== '') {
            SN_DB::audit('smail_mailbox_read_failed', 'smail', 0, 'failure', ['box'=>$box,'reason'=>(string)$wpdb->last_error], $user_id);
            return new WP_Error('smail_mailbox_unavailable', 'The Smail mailbox could not be read safely.', ['status'=>503]);
        }
        $items = array_map(static function ($row) use ($user_id): array {
"""
if old not in t: raise SystemExit('R25 mailbox anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')
# Permanent R25 contracts.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('R25 suite anchor missing')
block=r'''
// Round 25 — Smail idempotency and mailbox availability truth fail closed.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');$smail1=$read('includes/class-sn-smail-part-1.php');
$sendPos=strpos($smailRuntime,'public static function send');$idemPos=strpos($smailRuntime,'private static function idempotency_matches');$sendSeg=$sendPos===false?'':substr($smailRuntime,$sendPos,($idemPos===false?strlen($smailRuntime):$idemPos)-$sendPos);
$check(str_contains($sendSeg,'smail_idempotency_lookup_failed') && str_contains($sendSeg,'smail_idempotency_unavailable') && str_contains($sendSeg,'$wpdb->last_error'), 'Round 25: Smail send must fail closed when client-key idempotency state cannot be read.');
$check(str_contains($smail1,'smail_mailbox_read_failed') && str_contains($smail1,'smail_mailbox_unavailable') && str_contains($smail1,'$wpdb->last_error'), 'Round 25: Smail mailbox DB failure must not become a legitimate empty mailbox.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-smail-runtime-hardening.php
php -l sabri-network/includes/class-sn-smail-part-1.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
