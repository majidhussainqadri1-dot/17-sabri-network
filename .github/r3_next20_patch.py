from pathlib import Path
p=Path('sabri-network/includes/class-sn-message-integrity.php')
s=p.read_text(encoding='utf-8')
old="""        $table = SN_DB::table('message_receipts');
        $column = $state === 'read' ? 'read_at' : 'delivered_at';
        $through = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(message_id),0) FROM $table WHERE conversation_id=%d AND user_id=%d AND device_key=%s AND $column IS NOT NULL", $conversation_id, $user_id, $device_key));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $conversation_id, $through, $requested_id, $user_id, self::MAX_RECEIPT_RANGE));
"""
new="""        $table = SN_DB::table('message_receipts');
        $column = $state === 'read' ? 'read_at' : 'delivered_at';
        $wpdb->last_error = '';
        $through_raw = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(message_id),0) FROM $table WHERE conversation_id=%d AND user_id=%d AND device_key=%s AND $column IS NOT NULL", $conversation_id, $user_id, $device_key));
        if ($wpdb->last_error !== '' || $through_raw === null) return new WP_Error('receipt_progress_unavailable', 'The receipt progress state could not be verified.', ['status' => 503]);
        $through = (int) $through_raw;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $conversation_id, $through, $requested_id, $user_id, self::MAX_RECEIPT_RANGE));
"""
if new not in s:
    if old not in s: raise SystemExit('R3 progress-read target mismatch')
    s=s.replace(old,new,1)
old2="""        $recorded = 0; $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        try {
"""
new2="""        $recorded = 0; $now = current_time('mysql', true);
        if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('database_error', 'The receipt transaction could not be started.', ['status' => 503]);
        try {
"""
if new2 not in s:
    if old2 not in s: raise SystemExit('R3 transaction-start target mismatch')
    s=s.replace(old2,new2,1)
old3="""        $more = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $conversation_id, $through, $requested_id, $user_id));
        do_action('sn_network_message_receipt_recorded', $conversation_id, $through, $user_id, $state, $requested_id, $more);
"""
new3="""        $wpdb->last_error = '';
        $more_raw = $wpdb->get_var($wpdb->prepare("SELECT id FROM $messages WHERE conversation_id=%d AND id>%d AND id<=%d AND sender_id<>%d AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $conversation_id, $through, $requested_id, $user_id));
        if ($wpdb->last_error !== '') {
            SN_DB::audit('message_receipt_progress_failed', 'conversation', $conversation_id, 'failure', ['requested_message_id' => $requested_id, 'through_message_id' => $through, 'state' => $state], $user_id);
            return new WP_Error('receipt_progress_unavailable', 'The committed receipt range could not be checked for remaining work. Retry safely.', ['status' => 503]);
        }
        $more = $more_raw !== null && (int) $more_raw > 0;
        do_action('sn_network_message_receipt_recorded', $conversation_id, $through, $user_id, $state, $requested_id, $more);
"""
if new3 not in s:
    if old3 not in s: raise SystemExit('R3 completion-read target mismatch')
    s=s.replace(old3,new3,1)
p.write_text(s,encoding='utf-8')

t=Path('sabri-network/tests/next20-contracts.php')
ts=t.read_text(encoding='utf-8')
if "$integrity=$read('includes/class-sn-message-integrity.php');" not in ts:
    ts=ts.replace("$activator=$read('includes/class-sn-activator.php');", "$integrity=$read('includes/class-sn-message-integrity.php');$activator=$read('includes/class-sn-activator.php');",1)
marker="if($fail){fwrite(STDERR,\"Next20 contracts failed (\".count($fail).\"/$checks):\\n - \".implode(\"\\n - \",$fail).\"\\n\");exit(1);}"
checks="""$check(str_contains($integrity,'START TRANSACTION')&&str_contains($integrity,'receipt transaction could not be started'),'R3 receipt owner fails closed on transaction-start failure');
$check(str_contains($integrity,'receipt_progress_unavailable')&&str_contains($integrity,'$through_raw')&&str_contains($integrity,'$more_raw'),'R3 receipt progress reads are error-aware and retryable');
$check(str_contains($integrity,'message_receipt_progress_failed'),'R3 committed receipt completion-probe failure is audited');
"""
if 'R3 receipt owner fails closed' not in ts:
    if marker not in ts: raise SystemExit('R3 test insertion marker missing')
    ts=ts.replace(marker,checks+marker,1)
t.write_text(ts,encoding='utf-8')
