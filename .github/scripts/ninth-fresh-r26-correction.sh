#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-smail-runtime-hardening.php'; t=p.read_text(encoding='utf-8')
old='''        $states=SN_DB::table('smail_states');$drafts=SN_DB::table('smail_drafts');$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $states WHERE user_id=%d ORDER BY id ASC LIMIT %d",$uid,self::ERASE_BATCH))?:[]);$draft_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d",$uid,self::ERASE_BATCH))?:[]);$removed=false;$now=current_time('mysql',true);$empty=hash_hmac('sha256','',wp_salt('auth').'|sn-sm-draft-blind-v1');'''
new='''        $states=SN_DB::table('smail_states');$drafts=SN_DB::table('smail_drafts');
        $ids_raw=$wpdb->get_col($wpdb->prepare("SELECT id FROM $states WHERE user_id=%d ORDER BY id ASC LIMIT %d",$uid,self::ERASE_BATCH));
        if($wpdb->last_error!=='')return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail erasure state enumeration failed; retry is required.'],'done'=>false];
        $ids=array_map('intval',$ids_raw?:[]);
        $draft_ids_raw=$wpdb->get_col($wpdb->prepare("SELECT id FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d",$uid,self::ERASE_BATCH));
        if($wpdb->last_error!=='')return['items_removed'=>false,'items_retained'=>true,'messages'=>['Smail erasure draft enumeration failed; retry is required.'],'done'=>false];
        $draft_ids=array_map('intval',$draft_ids_raw?:[]);$removed=false;$now=current_time('mysql',true);$empty=hash_hmac('sha256','',wp_salt('auth').'|sn-sm-draft-blind-v1');'''
if old not in t: raise SystemExit('R26 enumeration anchor missing')
t=t.replace(old,new,1)
old='''        $more=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $states WHERE user_id=%d LIMIT 1",$uid))||(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1",$uid));return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Canonical messages remain subject to File-17 conversation retention, legal hold and participant rights.'],'done'=>!$more];'''
new='''        $more_states=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $states WHERE user_id=%d LIMIT 1",$uid));
        if($wpdb->last_error!=='')return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Smail erasure completion could not verify remaining state rows; retry is required.'],'done'=>false];
        $more_drafts=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $drafts WHERE owner_id=%d AND deleted_at IS NULL LIMIT 1",$uid));
        if($wpdb->last_error!=='')return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Smail erasure completion could not verify remaining draft rows; retry is required.'],'done'=>false];
        $more=(bool)$more_states||(bool)$more_drafts;return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Canonical messages remain subject to File-17 conversation retention, legal hold and participant rights.'],'done'=>!$more];'''
if old not in t: raise SystemExit('R26 completion anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t or '// Round 26 —' in t: raise SystemExit('R26 suite anchor problem')
block=r'''

// Round 26 — Smail privacy erasure enumeration/completion remains retryable on DB uncertainty.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');
$erasePos=strpos($smailRuntime,'public static function erase_personal_data');$trashPos=strpos($smailRuntime,'private static function trash_draft');$eraseSeg=$erasePos===false?'':substr($smailRuntime,$erasePos,($trashPos===false?strlen($smailRuntime):$trashPos)-$erasePos);
$check(str_contains($eraseSeg,'Smail erasure state enumeration failed; retry is required.') && str_contains($eraseSeg,'Smail erasure draft enumeration failed; retry is required.') && substr_count($eraseSeg,'$wpdb->last_error')>=4, 'Round 26: Smail erasure must not convert enumeration DB failure into an empty workset.');
$check(str_contains($eraseSeg,'Smail erasure completion could not verify remaining state rows; retry is required.') && str_contains($eraseSeg,'Smail erasure completion could not verify remaining draft rows; retry is required.') && str_contains($eraseSeg,"'done'=>false"), 'Round 26: Smail erasure completion must not claim done when remaining-row truth is unavailable.');
'''
p.write_text(t.replace(anchor,block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-smail-runtime-hardening.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
