#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-smail-runtime-hardening.php'; t=p.read_text(encoding='utf-8')
old="""        $stored=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('smail_states').' WHERE smail_message_id=%d AND user_id<>%d ORDER BY user_id ASC',(int)$smail->id,$sender))?:[]);
        sort($stored,SORT_NUMERIC);$expected=array_values($recipients);sort($expected,SORT_NUMERIC);
        if($stored!==$expected)return self::idempotency_conflict();
        $message=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d',(int)$smail->message_id,(int)$smail->conversation_id));
        if(!$message||$message->deleted_at!==null)return new WP_Error('smail_idempotency_source_unavailable','The original Smail message is unavailable for safe retry reconciliation.',['status'=>409]);"""
new="""        $stored_raw=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('smail_states').' WHERE smail_message_id=%d AND user_id<>%d ORDER BY user_id ASC',(int)$smail->id,$sender));
        if($wpdb->last_error!=='')return new WP_Error('smail_idempotency_state_unavailable','Smail recipient state could not be verified safely.',['status'=>503]);
        $stored=array_map('intval',$stored_raw?:[]);
        sort($stored,SORT_NUMERIC);$expected=array_values($recipients);sort($expected,SORT_NUMERIC);
        if($stored!==$expected)return self::idempotency_conflict();
        $message=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d AND conversation_id=%d',(int)$smail->message_id,(int)$smail->conversation_id));
        if($wpdb->last_error!=='')return new WP_Error('smail_idempotency_source_unavailable','The original Smail message source could not be verified safely.',['status'=>503]);
        if(!$message||$message->deleted_at!==null)return new WP_Error('smail_idempotency_source_unavailable','The original Smail message is unavailable for safe retry reconciliation.',['status'=>409]);"""
if old not in t: raise SystemExit('R28 idempotency anchor missing')
t=t.replace(old,new,1)
old="""    private static function with_locks(array $locks,callable $callback){global $wpdb;$locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];try{foreach($locks as $lock){$ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($ok!==1)return new WP_Error('smail_busy','The Smail item is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return $callback();}finally{foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}"""
new="""    private static function with_locks(array $locks,callable $callback){global $wpdb;$locks=array_values(array_unique(array_filter($locks)));sort($locks,SORT_STRING);$held=[];try{foreach($locks as $lock){$raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($wpdb->last_error!==''||$raw===null)return new WP_Error('smail_lock_unavailable','Smail concurrency control could not be verified safely.',['status'=>503]);$ok=(int)$raw;if($ok!==1)return new WP_Error('smail_busy','The Smail item is changing. Retry the request.',['status'=>409]);$held[]=$lock;}return $callback();}finally{foreach(array_reverse($held) as $lock){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}}}"""
if old not in t: raise SystemExit('R28 lock anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t or '// Round 28 —' in t: raise SystemExit('R28 suite anchor problem')
block=r'''

// Round 28 — Smail idempotency source/recipient snapshots and lock acquisition fail closed.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');
$idemPos=strpos($smailRuntime,'private static function idempotency_matches');$conflictPos=strpos($smailRuntime,'private static function idempotency_conflict');$idemSeg=$idemPos===false?'':substr($smailRuntime,$idemPos,($conflictPos===false?strlen($smailRuntime):$conflictPos)-$idemPos);
$check(str_contains($idemSeg,'smail_idempotency_state_unavailable') && substr_count($idemSeg,'$wpdb->last_error')>=2 && str_contains($idemSeg,'The original Smail message source could not be verified safely.'), 'Round 28: Smail idempotency reconciliation must distinguish DB uncertainty from conflict/missing source.');
$lockPos=strpos($smailRuntime,'private static function with_locks');$lockSeg=$lockPos===false?'':substr($smailRuntime,$lockPos);
$check(str_contains($lockSeg,'smail_lock_unavailable') && str_contains($lockSeg,"$raw===null") && str_contains($lockSeg,'$wpdb->last_error'), 'Round 28: Smail GET_LOCK uncertainty must fail closed as service unavailable, not ordinary contention.');
'''
p.write_text(t.replace(anchor,block+anchor,1),encoding='utf-8')
PY
