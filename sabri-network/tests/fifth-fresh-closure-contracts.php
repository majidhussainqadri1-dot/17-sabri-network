<?php
/** Fifth fresh closure-status contract plus later release-closure guards. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;$check=static function(bool $ok,string $m)use(&$fail,&$checks){$checks++;if(!$ok)$fail[]=$m;};
$note=(string)file_get_contents($root.'/FIFTH-FRESH-CLOSURE.txt');
$check(str_contains($note,'Defect rounds: R3,R4,R5,R8,R9,R11,R12,R14,R15,R17,R18,R19,R20.'),'Closure defect-round ledger mismatch.');
$check(str_contains($note,'Clean rounds: R1,R2,R6,R7,R10,R13,R16.'),'Closure clean-round ledger mismatch.');
$check(str_contains($note,'Exact-head QA/package, staging, live, DB/migration and operational statuses remain separately evidenced.'),'Closure status boundary missing.');

// Next fresh Round 10 — standalone package and repository-current truth must fail closed.
$package=(string)file_get_contents($root.'/tools/package.sh');
foreach([
    'includes/class-sn-next-message-operations-hardening.php',
    'includes/class-sn-r6-transaction-hardening.php',
    'includes/class-sn-r7-privacy-hardening.php',
    'includes/class-sn-r8-interop-finalization-hardening.php',
    'includes/class-sn-r9-runtime-hardening.php',
] as $surface){
    $check(str_contains($package,$surface),'Next R10: standalone package required-surface inventory is missing '.$surface.'.');
}
$readme=(string)file_get_contents($repo.'/README.md');
$status=(string)file_get_contents($repo.'/STATUS.md');
$coding=(string)file_get_contents($repo.'/CODING-COMPLETENESS.md');
$boundary=(string)file_get_contents($root.'/CURRENT-CANDIDATE-BOUNDARY.txt');
foreach(['README'=>$readme,'STATUS'=>$status,'CODING-COMPLETENESS'=>$coding,'CURRENT-CANDIDATE-BOUNDARY'=>$boundary] as $name=>$text){
    $check(str_contains($text,'review/file17-next-10-round-2026-09-04'),'Next R10: '.$name.' must identify the current next-fresh branch.');
    $check(str_contains($text,'R5'),'Next R10: '.$name.' must retain the current cycle clean-round truth.');
    $check(!str_contains($text,'Current repository state: fresh 10-round corrective cycle completed on `review/file17-fresh-10-round-2026-09-04`'),'Next R10: '.$name.' must not present the prior fresh branch as current repository truth.');
}

// Another fresh Round 1 — crash-abandoned idempotency reservations must become terminal fail-closed evidence.
$firewall=(string)file_get_contents($root.'/includes/class-sn-two-plan-contract-firewall.php');
$staleSelect=strpos($firewall,"WHERE state='processing' AND updated_at<%s");
$staleTerminal=strpos($firewall,"'state' => 'unreplayable'",$staleSelect===false?0:$staleSelect);
$terminalCleanup=strpos($firewall,"WHERE state IN ('complete','unreplayable') AND updated_at<%s");
$check($staleSelect!==false&&$staleTerminal!==false&&$terminalCleanup!==false&&$staleSelect<$terminalCleanup,'Another R1: stale processing reservations must be terminalized before ordinary terminal-cache cleanup.');
$check(str_contains($firewall,"idempotency_processing_stale")&&str_contains($firewall,"'reconciliation_required' => true"),'Another R1: stale processing recovery must emit reconciliation-required audit evidence.');
$check(str_contains($firewall,"time() - 2 * HOUR_IN_SECONDS")&&!str_contains($firewall,"DELETE FROM ".'".self::table()."'." WHERE state='processing'"),'Another R1: uncertain processing reservations need a bounded stale threshold and must never be deleted for automatic re-execution.');

// Another fresh Round 2 — CF-01 issuance must prove transaction start before any lock/write.
$cf01=(string)file_get_contents($root.'/includes/class-sn-cf01-clinical-context.php');
$cfTx=strpos($cf01,"if (\$wpdb->query('START TRANSACTION') === false)");
$cfLock=strpos($cf01,'FOR UPDATE');
$check($cfTx!==false&&$cfLock!==false&&$cfTx<$cfLock,'Another R2: CF-01 issue_reference must fail closed if transaction start fails before its first locking read.');
$check(str_contains($cf01,"sn_cf01_transaction_failed")&&str_contains($cf01,'could not start safely'),'Another R2: CF-01 transaction-start failure needs an explicit fail-closed error contract.');

// Another fresh Round 3 — privacy reads/completion must never collapse DB failure into empty/done truth.
$privacyRuntime=(string)file_get_contents($root.'/includes/class-sn-privacy-runtime-hardening.php');
$privacySixth=(string)file_get_contents($root.'/includes/class-sn-sixth-fresh-privacy-hardening.php');
$privacyR9=(string)file_get_contents($root.'/includes/class-sn-r9-runtime-hardening.php');
$check(substr_count($privacyRuntime,'if(!is_array($rows))return self::retry(')>=2,'Another R3: canonical message/update erasure reads must fail closed instead of treating DB errors as empty batches.');
$check(str_contains($privacyRuntime,'$owned_raw=')&&str_contains($privacyRuntime,'$attachment_raw=')&&str_contains($privacyRuntime,'privacy_membership_lock_read_failed')&&str_contains($privacyRuntime,'privacy_call_membership_read_failed'),'Another R3: relational privacy decisions must validate owner/attachment/membership/call reads before dependent destructive mutations.');
$check(str_contains($privacyRuntime,'private static function verify_erasure_completion')&&str_contains($privacyRuntime,"case 'sabri-network-smail'")&&str_contains($privacyRuntime,"case 'sabri-network-two-plan'")&&str_contains($privacyRuntime,"case 'sabri-network-future'"),'Another R3: the final privacy guard must independently verify extension-domain completion before allowing done=true.');
$check(str_contains($privacyRuntime,"sn_privacy_completion_read_failed")&&str_contains($privacyRuntime,"\$wpdb->last_error!==''"),'Another R3: failed final privacy completion reads must become retryable rather than false success.');
$check(str_contains($privacySixth,'if (!is_array($rows)) return self::retry(')&&str_contains($privacySixth,'if (!is_array($scan)) return self::retry(')&&str_contains($privacySixth,'$remaining_versions_raw'),'Another R3: Future record/version erasure must reject invalid scans and verify retained-version truth.');
$check(str_contains($privacyR9,'$more_keys_raw')&&str_contains($privacyR9,'$key_log_raw')&&str_contains($privacyR9,'Key-transparency retained-data truth could not be verified safely.'),'Another R3: final device-key erasure must verify both pending-key and retained transparency reads.');


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

if($fail){fwrite(STDERR,"Fifth fresh closure failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Fifth fresh closure contracts: PASS ($checks checks)\n";
