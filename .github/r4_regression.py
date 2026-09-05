from pathlib import Path

# Finish R4 source correction: never treat a legal-hold DB read error as a held=true boolean.
p=Path('sabri-network/includes/class-sn-two-plan-completion.php')
s=p.read_text(encoding='utf-8')
old="if(self::message_has_legal_hold($id))return self::error('sn_expiry_legal_hold','This message is preserved by a safety/legal hold.',409);"
new="$hold=self::message_has_legal_hold($id);if(is_wp_error($hold))return $hold;if($hold)return self::error('sn_expiry_legal_hold','This message is preserved by a safety/legal hold.',409);"
if new not in s:
    if s.count(old)!=1: raise SystemExit('R4 expiry setter legal-hold target mismatch')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Permanent R4 regression guards are appended to the existing exhaustive closure suite.
p=Path('sabri-network/tests/fifth-fresh-closure-contracts.php')
s=p.read_text(encoding='utf-8')
marker="if($fail){fwrite(STDERR,\"Fifth fresh closure failures"
if 'Another fresh Round 4 — lifecycle/retention' not in s:
    pos=s.find(marker)
    if pos<0: raise SystemExit('R4 regression insertion marker missing')
    block=r'''
// Another fresh Round 4 — lifecycle/retention mutations must fail closed and recover deterministically.
$twoPlan=(string)file_get_contents($root.'/includes/class-sn-two-plan-completion.php');
$safetyRuntime=(string)file_get_contents($root.'/includes/class-sn-safety-runtime-hardening.php');
$canonicalTx=strpos($twoPlan,"sn_two_plan_transaction_failed");
$canonicalInsert=strpos($twoPlan,"$wpdb->insert(SN_DB::table('messages')");
$check($canonicalTx!==false&&$canonicalInsert!==false&&$canonicalTx<$canonicalInsert,'Another R4: canonical structured/scheduled message helper must prove transaction start before its first message insert.');
$check(str_contains($twoPlan,"sn_checklist_transaction_failed")&&str_contains($twoPlan,"if($wpdb->query('START TRANSACTION')===false)return self::error('sn_checklist_transaction_failed'"),'Another R4: checklist mutation must fail closed when its transaction cannot start.');
$check(str_contains($twoPlan,"stale_processing_max_attempts")&&str_contains($twoPlan,"schedule_finalize_failed")&&str_contains($twoPlan,"status='processing' AND updated_at<=%s"),'Another R4: scheduled delivery must reclaim stale processing claims and verify terminal sent-state publication.');
$check(str_contains($twoPlan,"sn_legal_hold_read_failed")&&str_contains($twoPlan,"$wpdb->last_error!==''")&&str_contains($twoPlan,"sn:f17:message-retention:"),'Another R4: disappearing-message erasure must fail closed on legal-hold read failure under the canonical retention lock.');
$holdPos=strpos($twoPlan,'$held=self::message_has_legal_hold($id)');
$erasePos=strpos($twoPlan,"'attachment_source'=>'expired'",$holdPos===false?0:$holdPos);
$check($holdPos!==false&&$erasePos!==false&&$holdPos<$erasePos&&str_contains($twoPlan,'SELECT * FROM $messages WHERE id=%d FOR UPDATE'),'Another R4: expiry worker must re-read locked message/hold truth before destructive expiry.');
$check(str_contains($safetyRuntime,"sn:f17:message-retention:")&&str_contains($safetyRuntime,"_sn_safety_retention_lock")&&str_contains($safetyRuntime,"SELECT RELEASE_LOCK(%s)"),'Another R4: report/legal-hold mutation guard must share and release the message-retention lock namespace.');

'''
    s=s[:pos]+block+s[pos:]
p.write_text(s,encoding='utf-8')
