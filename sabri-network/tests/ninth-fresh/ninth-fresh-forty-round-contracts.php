<?php
/** File 17 ninth fresh 40-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

// Round 2 — action-time File-00 truth and idempotent replay disclosure boundary.
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$firewall = $read('includes/class-sn-two-plan-contract-firewall.php');
$check(str_contains($boundary, "add_filter('rest_pre_dispatch', [self::class, 'final_identity_gate'], PHP_INT_MAX, 3)"), 'Round 2: final identity gate must execute after every File-17 pre-dispatch lock/cache layer.');
$finalPos = strpos($boundary, 'public static function final_identity_gate');
$nextPos = $finalPos === false ? false : strpos($boundary, 'public static function reconcile_search_epoch', $finalPos);
$segment = $finalPos === false ? '' : substr($boundary, $finalPos, ($nextPos === false ? strlen($boundary) : $nextPos) - $finalPos);
$check(str_contains($segment, 'SN_Policy::access()') && !str_contains($segment, 'if ($result !== null) return $result;'), 'Round 2: final identity gate must revalidate even when an earlier pre-dispatch layer produced a cached result.');
$check(str_contains($segment, 'SN_REST::admin_access()'), 'Round 2: high-risk admin mutations must also revalidate administrator authority at the final action-time gate.');
$check(substr_count($firewall, 'self::existing_result(') >= 3 && substr_count($firewall, '$scope_key, $request)') >= 3, 'Round 2: every idempotency replay path must carry the current request into the replay authorization boundary.');
$check(str_contains($firewall, "apply_filters('sn_network_idempotency_replay_authorized', false") && str_contains($firewall, "'refetch_required' => true"), 'Round 2: completed idempotency records must default to dedupe-only/refetch-required rather than stale private-response disclosure.');
$authPos = strpos($firewall, "apply_filters('sn_network_idempotency_replay_authorized'");
$decryptPos = strpos($firewall, 'SN_Communication_Crypto::decrypt', $authPos === false ? 0 : $authPos);
$check($authPos !== false && $decryptPos !== false && $authPos < $decryptPos && str_contains($firewall, '$authorized !== true'), 'Round 2: cached response decryption must be unreachable without strict current replay authorization.');


// Round 8 — retryable message-create surfaces require caller-owned idempotency keys.
$compat = $read('includes/class-sn-compatibility-hardening.php');
$integrity = $read('includes/class-sn-message-integrity.php');
$voice = $read('includes/class-sn-fifth-fresh-feature-hardening.php');
$check(!str_contains($compat, "get_param('client_id'))) ?: wp_generate_uuid4()") && str_contains($compat, '$client = strtolower(trim((string) $request->get_param(\'client_id\')));'), 'Round 8: forwarding must never invent a client idempotency key.');
$check(!str_contains($integrity, "get_param('client_id'))) ?: wp_generate_uuid4()") && str_contains($integrity, '$client_id = strtolower(trim((string) $request->get_param(\'client_id\')));'), 'Round 8: the internal message sender must fail closed when caller idempotency is absent.');
$voicePos = strpos($voice, 'public static function send_voice_note');
$voiceEnd = $voicePos === false ? false : strpos($voice, 'public static function structured_message', $voicePos);
$voiceSeg = $voicePos === false ? '' : substr($voice, $voicePos, ($voiceEnd === false ? strlen($voice) : $voiceEnd) - $voicePos);
$check(str_contains($voiceSeg, 'A caller-supplied voice-note idempotency key is required.') && str_contains($voiceSeg, '$forward->set_param(\'client_id\', $client_id);'), 'Round 8: final voice-note creation must validate and preserve the caller idempotency key before upload/message creation.');


// Round 9 — message organization/read/reaction mutations are serialized and fail closed.
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$ops = $read('includes/class-sn-message-operations.php');
$rest = $read('includes/class-sn-rest.php');
$check(str_contains($boundary, "lock_message_metadata_mutation'], 2200") && str_contains($boundary, "release_message_metadata_mutation'], 2200") && str_contains($boundary, "sn:f17:conversation:"), 'Round 9: message metadata mutations must hold the same conversation lock used by membership changes.');
foreach (['reaction|mentions|pin|star|hide','message-folders','/read$'] as $needle) $check(str_contains($boundary,$needle), 'Round 9: central metadata lock routing lost coverage for '.$needle.'.');
$check(str_contains($ops,"sn_pin_action_invalid") && str_contains($ops,"sn_unpin_failed") && str_contains($ops,"sn_star_action_invalid") && str_contains($ops,"sn_unstar_failed"), 'Round 9: pin/star actions must reject unknown verbs and surface delete failures.');
$check(str_contains($ops,"sn_folder_item_action_invalid") && str_contains($ops,"sn_folder_item_remove_failed"), 'Round 9: folder-item actions must reject unknown verbs and surface removal failures.');
$check(str_contains($ops,"sn_folder_count_failed") && str_contains($ops,"sn_folder_version_required") && str_contains($ops,"FOR UPDATE") && str_contains($ops,"folder_version_conflict"), 'Round 9: folder capacity and deletion must use fail-closed count truth plus exact-version CAS.');
$check(str_contains($rest,'$raw_reaction = trim') && str_contains($rest,"invalid_reaction") && str_contains($rest,"message_read_lookup_failed"), 'Round 9: invalid reactions must not become removals and latest-read lookup failures must not become zero pointers.');


// Round 10 — critical cron/retry scheduling is fail-closed and observable.
$activator = $read('includes/class-sn-activator.php');
$outbox = $read('includes/class-sn-outbox.php');
$private = $read('includes/class-sn-private-files.php');
$check(str_contains($activator, "wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sn_cleanup_hourly', [], true)") && str_contains($activator, "sn_cleanup_schedule_error") && str_contains($activator, 'is_wp_error($schedule)'), 'Round 10: hourly cleanup scheduling must expose failure and fail activation closed.');
$check(str_contains($outbox, "wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sn_every_minute', 'sn_network_outbox_tick', [], true)") && str_contains($outbox, "sn_outbox_schedule_error") && str_contains($outbox, "'ok'=>\$outbox_exists&&\$inbox_exists&&\$next_run>0"), 'Round 10: outbox health must require a successfully scheduled delivery tick.');
$check(substr_count($private, "attachment_delete_retry_schedule_failed") >= 2 && substr_count($private, "wp_schedule_single_event") >= 2 && str_contains($private, '[$attachment_id], true'), 'Round 10: private-byte retry scheduling failures must be audited rather than silently discarded.');


// Round 12 — transfer quota and scanner readiness truth fail closed.
$transfer2 = $read('includes/class-sn-file-transfer-part-2.php');
$transfer7 = $read('includes/class-sn-file-transfer-part-7.php');
$check(str_contains($transfer2, 'transfer_quota_unavailable') && str_contains($transfer2, 'file_transfer_quota_read_failed') && str_contains($transfer2, '$wpdb->last_error'), 'Round 12: daily transfer quota DB failure must not become zero usage.');
$check(str_contains($transfer7, "apply_filters('sn_network_transfer_scanner_ready',false)===true") && !str_contains($transfer7, "scanner_connected'=>has_filter") && str_contains($transfer7, "'ok'=>!\$missing&&!\$storage") === false && str_contains($transfer7, "'ok'=>!\$missing&&\$storage&&\$scanner_ready"), 'Round 12: scanner health requires an explicit readiness declaration, not hook presence.');


// Round 16 — presence device budget fails closed on DB read failure.
$presence = $read('includes/class-sn-presence-devices.php');
$check(str_contains($presence, 'presence_device_limit_unavailable') && str_contains($presence, 'presence_device_limit_read_failed') && str_contains($presence, '$count_raw=$wpdb->get_var') && str_contains($presence, "$wpdb->last_error!==''"), 'Round 16: active-device limit DB failure must not become zero active devices.');

if ($fail) {
    fwrite(STDERR, "Ninth fresh 40-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Ninth fresh 40-round contracts: PASS ($checks checks)\n";
