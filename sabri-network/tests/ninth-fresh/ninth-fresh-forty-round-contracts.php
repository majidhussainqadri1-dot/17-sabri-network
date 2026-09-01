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
$check(str_contains($presence, 'presence_device_limit_unavailable') && str_contains($presence, 'presence_device_limit_read_failed') && str_contains($presence, '$count_raw=$wpdb->get_var') && str_contains($presence, '$wpdb->last_error'), 'Round 16: active-device limit DB failure must not become zero active devices.');


// Round 17 — generic File-19 notification handoff is truthful and explicitly acknowledged.
$central = $read('includes/class-sn-central-plan-hardening.php');
$check(str_contains($central, 'SN_Seventh_Fresh_R13_Hardening::file19_ready()') && str_contains($central, 'notification_file19_unavailable'), 'Round 17: generic notifications must verify File 19 readiness before claiming a handoff.');
$check(str_contains($central, 'sn_network_notification_delivery_result') && str_contains($central, 'notification_file19_handoff_unacknowledged') && str_contains($central, 'missing_explicit_ack'), 'Round 17: generic notification handoff success requires explicit File 19 acknowledgement.');
$check(str_contains($central, 'file17-notification:') && str_contains($central, 'idempotency_key_hash') && str_contains($central, 'notification_deferred_to_file19') && str_contains($central, "'success'"), 'Round 17: File 19 handoffs carry stable evidence and success is recorded only on the acknowledged path.');


// Round 18 — private-search DB/rebuild truth fails closed.
$search = $read('includes/class-sn-message-search.php');
$boundary = $read('includes/class-sn-runtime-boundary-policy.php');
$check(str_contains($search, 'search_snapshot_unavailable') && str_contains($search, 'search_context_unavailable') && str_contains($search, 'message_search_cleanup_read_failed'), 'Round 18: search DB read failures must not become valid empty/not-found responses.');
$check(str_contains($boundary, 'remaining_count_failed') && str_contains($boundary, 'message_search_rebuild_count_failed') && str_contains($boundary, '$remaining_raw'), 'Round 18: rebuild completion count failure must never become zero remaining rows.');
$check(str_contains($boundary, 'continuation_schedule_failed') && str_contains($boundary, 'message_search_rebuild_schedule_failed') && str_contains($boundary, 'wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_CONTINUE_HOOK, [], true)'), 'Round 18: search rebuild continuation scheduling failure must be observable and fail closed.');


// Round 19 — terminal privacy completion independently verifies CF01, Two-Plan and Meet.
$privacy = $read('includes/class-sn-seventh-fresh-r15-privacy-hardening.php');
$integration = $read('includes/class-sn-fifth-fresh-integration-hardening.php');
$twoPlanPrivacy = $read('includes/class-sn-fourth-fresh-privacy-hardening.php');
$twoPlan = $read('includes/class-sn-two-plan-completion.php');
foreach (['sabri-network-cf01-references','sabri-network-two-plan','sabri-meet'] as $key) $check(str_contains($privacy, "case '$key':"), 'Round 19: terminal verifier lost coverage for '.$key.'.');
$check(str_contains($integration, 'Clinical-context reference erasure completion could not be verified.') && str_contains($integration, '$wpdb->last_error'), 'Round 19: CF01 child completion query must fail closed.');
$check(str_contains($twoPlanPrivacy, 'Poll-vote erasure could not enumerate its work.') && str_contains($twoPlanPrivacy, 'Poll-vote legal-hold verification must be retried.'), 'Round 19: poll-vote erasure DB uncertainty must remain retryable.');
$check(str_contains($twoPlan, 'Scheduled-message privacy erasure must be retried.') && str_contains($twoPlan, 'Message-request privacy erasure must be retried.'), 'Round 19: Two-Plan base eraser write failures must remain retryable.');


// Round 20 — moderation retention and blocking durability fail closed.
$safetyPrivacy=$read('includes/class-sn-fourth-fresh-privacy-hardening.php');
$rest=$read('includes/class-sn-rest.php');
$check(str_contains($safetyPrivacy,'native_legal_hold_verification_failed') && str_contains($safetyPrivacy,'$wpdb->last_error') && str_contains($safetyPrivacy,'return true;'), 'Round 20: native legal-hold DB uncertainty must retain rather than fail open.');
$blockPos=strpos($rest,'public static function block_user'); $blockEnd=$blockPos===false?false:strpos($rest,'public static function admin_reports',$blockPos); $blockSeg=$blockPos===false?'':substr($rest,$blockPos,($blockEnd===false?strlen($rest):$blockEnd)-$blockPos);
$check(str_contains($blockSeg,"block_commit_failed") && str_contains($blockSeg,"query('COMMIT') === false"), 'Round 20: block/unblock success requires a confirmed transaction commit.');


// Round 21 — space membership/governance is fail-closed and action-time locked.
$spaces7=$read('includes/class-sn-spaces-part-7.php');$spaces8=$read('includes/class-sn-spaces-part-8.php');
$spaces1=$read('includes/class-sn-spaces-part-1.php');$spaces2=$read('includes/class-sn-spaces-part-2.php');$spaces3=$read('includes/class-sn-spaces-part-3.php');$spaces4=$read('includes/class-sn-spaces-part-4.php');$spaces5=$read('includes/class-sn-spaces-part-5.php');$spaces6=$read('includes/class-sn-spaces-part-6.php');
$check(str_contains($spaces7,'sn_space_membership_state_unavailable') && str_contains($spaces7,'sn_space_capacity_unavailable') && substr_count($spaces7,'$wpdb->last_error')>=3, 'Round 21: ban/member/capacity DB uncertainty must fail join eligibility closed.');
$check(str_contains($spaces8,'can_manage_locked') && str_contains($spaces8,'role_can_manage'), 'Round 21: canonical space manager authorization must support locked action-time checks.');
foreach ([$spaces1,$spaces2,$spaces3,$spaces4,$spaces6] as $i=>$src) $check(str_contains($src,'can_manage_locked'), 'Round 21: a space mutation path lost locked manager revalidation #'.$i.'.');
$check(substr_count($spaces5,'role_can_manage')>=3 && str_contains($spaces5,'$actor_locked=self::member') && str_contains($spaces5,'$target_locked=self::member'), 'Round 21: member/ban mutations must recompute authority/hierarchy from locked memberships.');


// Round 22 — block-state uncertainty fails closed for directory/profile/phone privacy.
$db=$read('includes/class-sn-db.php');$auth=$read('includes/class-sn-auth.php');$rest=$read('includes/class-sn-rest.php');
$blockedPos=strpos($db,'public static function is_blocked');$blockedEnd=$blockedPos===false?false:strpos($db,'public static function add_notification',$blockedPos);$blockedSeg=$blockedPos===false?'':substr($db,$blockedPos,($blockedEnd===false?strlen($db):$blockedEnd)-$blockedPos);
$check(str_contains($blockedSeg,'block_state_read_failed') && str_contains($blockedSeg,'$wpdb->last_error') && str_contains($blockedSeg,'return true;'), 'Round 22: unknown block state must fail privacy/authorization closed.');
$check(str_contains($auth,'SN_DB::is_blocked($viewer_id, $user_id)') && str_contains($auth,'can_view_phone'), 'Round 22: public profile/phone disclosure must remain behind canonical block state.');
$check(str_contains($rest,'if (SN_DB::is_blocked($viewer_id, $id))') && str_contains($rest,'sn_network_allow_phone_directory_lookup'), 'Round 22: directory and phone lookup results must remain behind canonical block suppression.');


// Round 23 — private attachment bytes are retained on reference/commit uncertainty.
$db=$read('includes/class-sn-db.php');
$refPos=strpos($db,'public static function private_attachment_is_referenced');$refEnd=$refPos===false?false:strpos($db,'public static function cleanup_expired',$refPos);$refSeg=$refPos===false?'':substr($db,$refPos,($refEnd===false?strlen($db):$refEnd)-$refPos);
$check(substr_count($refSeg,'$wpdb->last_error')>=2 && substr_count($refSeg,'attachment_reference_check_failed')>=2 && substr_count($refSeg,'return true;')>=3, 'Round 23: private attachment reference DB uncertainty must retain bytes.');
$cleanupPos=strpos($db,'public static function cleanup_expired');$cleanupEnd=$cleanupPos===false?false:strpos($db,'private static function migrate_contacts',$cleanupPos);$cleanupSeg=$cleanupPos===false?'':substr($db,$cleanupPos,($cleanupEnd===false?strlen($db):$cleanupEnd)-$cleanupPos);
$check(str_contains($cleanupSeg,"query('COMMIT') === false") && str_contains($cleanupSeg,'expired_update_commit_failed') && strpos($cleanupSeg,"query('COMMIT') === false") < strpos($cleanupSeg,'SN_Private_Files::delete'), 'Round 23: expired update attachment bytes require a confirmed commit before deletion.');


// Round 24 — File Transfer snapshots, scanning, reconciliation and cleanup fail closed.
$ft5=$read('includes/class-sn-file-transfer-part-5.php');$ft6=$read('includes/class-sn-file-transfer-part-6.php');$ft7=$read('includes/class-sn-file-transfer-part-7.php');$ft4=$read('includes/class-sn-file-transfer-part-4.php');
$check(str_contains($ft6,'recipient_ids((int)$row->id,true)') && str_contains($ft6,'transfer_recipient_state_unavailable') && str_contains($ft6,'file_transfer_recipient_snapshot_failed'), 'Round 24: protected transfer revalidation must fail closed on recipient snapshot DB uncertainty.');
$check(str_contains($ft5,'file_transfer_scan_snapshot_failed') && str_contains($ft5,'count($chunks)!==(int)$row->total_chunks') && str_contains($ft5,'$materialized_bytes!==(int)$row->total_bytes'), 'Round 24: scanner materialization must prove a complete chunk and byte snapshot.');
$check(str_contains($ft4,'file_transfer_finalize_reconciliation_read_failed') && str_contains($ft4,'$wpdb->last_error'), 'Round 24: finalize commit reconciliation must not convert DB uncertainty into success.');
$check(str_contains($ft5,'file_transfer_revoke_reconciliation_read_failed') && str_contains($ft5,'$wpdb->last_error') , 'Round 24: revoke commit reconciliation must not convert DB uncertainty into success.');
$check(str_contains($ft7,'file_transfer_chunk_ledger_read_failed') && str_contains($ft7,'return false;'), 'Round 24: chunk-ledger DB uncertainty must keep cleanup retryable.');


// Round 25 — Smail idempotency and mailbox availability truth fail closed.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');$smail1=$read('includes/class-sn-smail-part-1.php');
$sendPos=strpos($smailRuntime,'public static function send');$idemPos=strpos($smailRuntime,'private static function idempotency_matches');$sendSeg=$sendPos===false?'':substr($smailRuntime,$sendPos,($idemPos===false?strlen($smailRuntime):$idemPos)-$sendPos);
$check(str_contains($sendSeg,'smail_idempotency_lookup_failed') && str_contains($sendSeg,'smail_idempotency_unavailable') && str_contains($sendSeg,'$wpdb->last_error'), 'Round 25: Smail send must fail closed when client-key idempotency state cannot be read.');
$check(str_contains($smail1,'smail_mailbox_read_failed') && str_contains($smail1,'smail_mailbox_unavailable') && str_contains($smail1,'$wpdb->last_error'), 'Round 25: Smail mailbox DB failure must not become a legitimate empty mailbox.');


// Round 26 — Smail privacy erasure enumeration/completion remains retryable on DB uncertainty.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');
$erasePos=strpos($smailRuntime,'public static function erase_personal_data');$trashPos=strpos($smailRuntime,'private static function trash_draft');$eraseSeg=$erasePos===false?'':substr($smailRuntime,$erasePos,($trashPos===false?strlen($smailRuntime):$trashPos)-$erasePos);
$check(str_contains($eraseSeg,'Smail erasure state enumeration failed; retry is required.') && str_contains($eraseSeg,'Smail erasure draft enumeration failed; retry is required.') && substr_count($eraseSeg,'$wpdb->last_error')>=4, 'Round 26: Smail erasure must not convert enumeration DB failure into an empty workset.');
$check(str_contains($eraseSeg,'Smail erasure completion could not verify remaining state rows; retry is required.') && str_contains($eraseSeg,'Smail erasure completion could not verify remaining draft rows; retry is required.') && str_contains($eraseSeg,"'done'=>false"), 'Round 26: Smail erasure completion must not claim done when remaining-row truth is unavailable.');


// Round 27 — Smail state/draft reads distinguish database failure from absence.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');$smail2=$read('includes/class-sn-smail-part-2.php');
$check(str_contains($smailRuntime,'smail_state_unavailable') && str_contains($smailRuntime,'draft_state_unavailable') && str_contains($smailRuntime,'draft_delete_unavailable') && substr_count($smailRuntime,'$wpdb->last_error')>=7, 'Round 27: Smail state and draft mutations must not convert DB uncertainty into not-found.');
$check(str_contains($smail2,'WP_REST_Response|WP_Error') && str_contains($smail2,'smail_drafts_unavailable') && str_contains($smail2,'$wpdb->last_error'), 'Round 27: draft-list DB failure must not become a legitimate empty list.');


// Round 28 — Smail idempotency source/recipient snapshots and lock acquisition fail closed.
$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');
$idemPos=strpos($smailRuntime,'private static function idempotency_matches');$conflictPos=strpos($smailRuntime,'private static function idempotency_conflict');$idemSeg=$idemPos===false?'':substr($smailRuntime,$idemPos,($conflictPos===false?strlen($smailRuntime):$conflictPos)-$idemPos);
$check(str_contains($idemSeg,'smail_idempotency_state_unavailable') && substr_count($idemSeg,'$wpdb->last_error')>=2 && str_contains($idemSeg,'The original Smail message source could not be verified safely.'), 'Round 28: Smail idempotency reconciliation must distinguish DB uncertainty from conflict/missing source.');
$lockPos=strpos($smailRuntime,'private static function with_locks');$lockSeg=$lockPos===false?'':substr($smailRuntime,$lockPos);
$check(str_contains($lockSeg,'smail_lock_unavailable') && str_contains($lockSeg,'$raw===null') && str_contains($lockSeg,'$wpdb->last_error'), 'Round 28: Smail GET_LOCK uncertainty must fail closed as service unavailable, not ordinary contention.');


// Round 29 — Sabri Meet transactional, capacity and moderation side effects fail closed.
$meet=$read('includes/class-sn-meet.php');
$check(substr_count($meet,"query('COMMIT') === false")>=6 && str_contains($meet,'transaction_commit_failed'), 'Round 29: Meet transactions must confirm COMMIT before success/audit/notification.');
$check(substr_count($meet,'meeting_active_count_unavailable')>=2 && str_contains($meet,'meeting_user_session_count_unavailable') && str_contains($meet,'meeting_room_session_count_unavailable') && str_contains($meet,'meeting_other_session_count_unavailable'), 'Round 29: Meet participant/session capacity reads must not cast DB uncertainty to zero.');
$check(str_contains($meet,'meeting_end_sessions_write_failed') && str_contains($meet,'meeting_end_participants_write_failed') && str_contains($meet,'meeting_admit_sessions_write_failed') && str_contains($meet,'meeting_deny_sessions_write_failed') && str_contains($meet,'meeting_remove_sessions_write_failed'), 'Round 29: moderation must not commit when session/participant side effects fail.');
$check(substr_count($meet,'meeting_active_sessions_read_failed')>=2, 'Round 29: mute/lower-hand must fail closed when active-session snapshots cannot be read.');


// Round 30 — Sabri Meet collection/health reads fail closed on database uncertainty.
$meet=$read('includes/class-sn-meet.php');
$check(str_contains($meet,'meet_health_unavailable') && str_contains($meet,'Sabri Meet storage health could not be verified safely.'), 'Round 30: health DB uncertainty must not be reported as missing tables/healthy state.');
$check(str_contains($meet,'meetings_unavailable') && str_contains($meet,'meet_participants_unavailable') && str_contains($meet,'meet_sessions_unavailable'), 'Round 30: meeting list and roster DB uncertainty must not become successful empty collections.');
$check(str_contains($meet,'meet_signals_unavailable') && substr_count($meet,'$wpdb->last_error')>=10, 'Round 30: signaling read DB uncertainty must not become a successful empty signal list.');


// Round 31 — CF-01 clinical-context assertions and privacy workflows preserve database truth.
$cf01=$read('includes/class-sn-cf01-clinical-context.php');
$check(str_contains($cf01,'sn_cf01_storage_unavailable') && substr_count($cf01,'return self::storage_unavailable();')>=8, 'Round 31: CF-01 membership/conversation/reference DB uncertainty must fail closed as unavailable, not not-found/conflict.');
$check(str_contains($cf01,'private static function participant_count(int $conversation_id): int|WP_Error') && str_contains($cf01,'$count === null') && str_contains($cf01,'private static function participant_class(int $conversation_id, int $actor_id): string|WP_Error'), 'Round 31: CF-01 participant count/class must not fabricate clinical-context state under DB uncertainty.');
$check(str_contains($cf01,'private static function direct_conversation_blocked(object $conversation, int $actor_id): bool|WP_Error') && str_contains($cf01,'$other_raw') && substr_count($cf01,'is_wp_error($blocked)')>=2, 'Round 31: direct-conversation block checks must preserve DB uncertainty.');
$check(str_contains($cf01,"'done' => false") && str_contains($cf01,'Communication-context erasure could not be verified; retry is required.') && str_contains($cf01,"return ['data' => [], 'done' => false];"), 'Round 31: CF-01 privacy export/erasure must remain retryable when DB truth is unavailable.');
$check(str_contains($cf01,"do_action('sn_cf01_cleanup_failed'") && str_contains($cf01,"throw new RuntimeException('reference_lookup_failed')"), 'Round 31: CF-01 cleanup/idempotency read failures must be observable and fail closed.');


// Round 32 — durable event delivery preserves database truth across enqueue, dispatch, inbox and administration.
$outbox=$read('includes/class-sn-outbox.php');
$check(str_contains($outbox,'event_storage_unavailable') && substr_count($outbox,'self::storage_unavailable()')>=7, 'Round 32: outbox DB uncertainty must not become absence, contention, stale version, or empty administration state.');
$check(str_contains($outbox,'incoming_event_storage_unavailable') && str_contains($outbox,'Incoming event storage truth could not be verified.'), 'Round 32: incoming idempotency source DB uncertainty must fail closed before transactional handling.');
$check(str_contains($outbox,'public static function admin_events(WP_REST_Request $request): WP_REST_Response|WP_Error') && str_contains($outbox,'public static function health(): WP_REST_Response|WP_Error'), 'Round 32: admin list and health endpoints must surface storage unavailability rather than valid empty/zero state.');
$check(str_contains($outbox,'sn_network_outbox_cleanup_failed') && str_contains($outbox,'$outbox_cleanup===false||$inbox_cleanup===false'), 'Round 32: outbox/inbox retention cleanup failure must be observable.');


// Round 33 — message search preserves authorization, source, collection and health database truth.
$search=$read('includes/class-sn-message-search.php');
$check(str_contains($search,'message_search_storage_unavailable') && substr_count($search,'self::storage_unavailable()')>=6, 'Round 33: search source/membership/query DB uncertainty must fail closed instead of not-found/empty.');
$check(str_contains($search,'public static function health(): WP_REST_Response|WP_Error') && str_contains($search,'$count===null'), 'Round 33: message-search health must not cast failed table/count reads into missing/zero state.');
$check(str_contains($search,'message search backfill could not read its next batch') && substr_count($search,'storage_unavailable()')>=6, 'Round 33: search/backfill collection reads must retain DB-error evidence.');


// Round 34 — realtime concurrency locks distinguish infrastructure uncertainty from contention.
$rt=$read('includes/class-sn-realtime-runtime-hardening.php');
$check(str_contains($rt,'sn_realtime_lock_unavailable') && str_contains($rt,'$raw===null') && str_contains($rt,'sn_realtime_lock_unavailable') && str_contains($rt,'$raw===null'), 'Round 34: realtime GET_LOCK DB uncertainty must fail closed as 503, not ordinary 409 contention.');
$check(str_contains($rt,'sn_realtime_lock_release_failed') && str_contains($rt,'RELEASE_LOCK(%s)'), 'Round 34: realtime lock-release failure must be observable.');


// Round 35 — privacy export/erasure preserves lock and destructive-snapshot database truth.
$privacy=$read('includes/class-sn-privacy-runtime-hardening.php');
$check(str_contains($privacy,'Privacy erasure concurrency control could not be verified') && str_contains($privacy,'$raw_lock===null') && str_contains($privacy,'sn_network_privacy_lock_release_failed'), 'Round 35: privacy lock acquisition/release DB uncertainty must be distinguished from ordinary contention.');
$check(str_contains($privacy,'Message-erasure enumeration could not be verified') && str_contains($privacy,'privacy_message_lock_read_failed') && str_contains($privacy,'Temporary-update erasure enumeration could not be verified'), 'Round 35: message/update erasure must not interpret failed enumeration or locked rereads as no work.');
$check(str_contains($privacy,'Owned-conversation privacy snapshot could not be verified') && str_contains($privacy,'Attachment privacy snapshot could not be verified') && str_contains($privacy,'privacy_membership_enumeration_failed') && str_contains($privacy,'privacy_call_membership_enumeration_failed') && str_contains($privacy,'privacy_direct_call_enumeration_failed'), 'Round 35: relational privacy deletion must not proceed from incomplete conversation/member/call snapshots.');
$check(str_contains($privacy,'sn_network_privacy_lock_release_failed') && substr_count($privacy,"['done']=false")>=1, 'Round 35: privacy export enrichment DB failure must remain retryable instead of returning an apparently complete export.');


// Round 36 — private 1 GiB transfer workflow preserves database truth across initiation, chunks, finalize, download, cleanup and privacy erasure.
$t2=$read('includes/class-sn-file-transfer-part-2.php');$t3=$read('includes/class-sn-file-transfer-part-3.php');$t4=$read('includes/class-sn-file-transfer-part-4.php');$t5=$read('includes/class-sn-file-transfer-part-5.php');$t6=$read('includes/class-sn-file-transfer-part-6.php');$t7=$read('includes/class-sn-file-transfer-part-7.php');$t8=$read('includes/class-sn-file-transfer-part-8.php');
$check(str_contains($t2,'file_transfer_idempotency_lookup_failed') && str_contains($t2,'file_transfer_idempotency_recipient_read_failed') && str_contains($t2,'file_transfer_post_commit_read_failed'), 'Round 36: transfer initiation/idempotency DB uncertainty must fail closed.');
$check(str_contains($t3,'file_transfer_chunk_lookup_failed') && str_contains($t3,'chunk_session_read_failed') && str_contains($t3,'file_transfer_chunk_reconciliation_read_failed') && str_contains($t3,'file_transfer_chunk_post_commit_read_failed'), 'Round 36: chunk lookup/lock/reconciliation/post-commit reads must preserve DB truth.');
$check(str_contains($t4,'file_transfer_finalize_chunk_read_failed') && str_contains($t4,'transfer_finalize_read_failed') && str_contains($t4,'file_transfer_finalize_reconciliation_session_read_failed') && str_contains($t4,'file_transfer_list_read_failed'), 'Round 36: finalization and transfer listing must not convert failed reads into ordinary state.');
$check(str_contains($t5,'file_transfer_download_snapshot_failed') && str_contains($t5,'file_transfer_scan_session_read_failed') && strpos($t5,'file_transfer_download_snapshot_failed') < strpos($t5,"header('Content-Type:"), 'Round 36: download/scan must verify canonical DB snapshot before emitting private bytes.');
$check(str_contains($t6,'file_transfer_recipient_enumeration_failed') && str_contains($t7,'file_transfer_cleanup_enumeration_failed') && str_contains($t7,'file_transfer_privacy_export_read_failed'), 'Round 36: recipient enumeration, cleanup and privacy export DB failures must be observable and retryable.');
$check(str_contains($t8,'file_transfer_privacy_sent_enumeration_failed') && str_contains($t8,'file_transfer_privacy_received_enumeration_failed') && str_contains($t8,'file_transfer_privacy_chunk_probe_failed') && str_contains($t8,'file_transfer_privacy_completion_received_read_failed'), 'Round 36: transfer privacy erasure must never claim completion from failed enumeration/probe/completion reads.');


// Round 37 — transfer access, archive inspection, post-finalize truth and download integrity fail closed.
$t4=$read('includes/class-sn-file-transfer-part-4.php');$t5=$read('includes/class-sn-file-transfer-part-5.php');$t6=$read('includes/class-sn-file-transfer-part-6.php');$t7=$read('includes/class-sn-file-transfer-part-7.php');
$check(str_contains($t6,'file_transfer_access_read_failed') && str_contains($t6,'bool|WP_Error') && str_contains($t6,'archive_entry_unreadable'), 'Round 37: authorization DB uncertainty and unreadable archive entries must fail closed.');
$check(substr_count($t4,'$access=self::can_access')>=2 && substr_count($t4,'transfer_state_unavailable')>=4, 'Round 37: transfer status/grant must distinguish authorization/state DB uncertainty from ordinary not-found.');
$check(str_contains($t4,'file_transfer_quarantine_reread_failed') && str_contains($t4,'file_transfer_ready_recipient_read_failed') && str_contains($t4,'file_transfer_ready_reread_failed'), 'Round 37: quarantine and post-finalize committed state must be re-read truthfully.');
$check(str_contains($t5,'Private transfer state temporarily unavailable.') && str_contains($t5,'file_transfer_download_preflight_failed') && strpos($t5,'file_transfer_download_preflight_failed') < strpos($t5,'status_header($status)'), 'Round 37: downloads must authenticate the complete encrypted snapshot before success headers and expose state DB failure as unavailable.');
$check(str_contains($t7,'file_transfer_health_db_probe_failed') && str_contains($t7,"'database_ready'=>\$database_ready") && str_contains($t7,"'ok'=>!\$missing&&\$storage&&\$scanner_ready&&\$database_ready"), 'Round 37: transfer health must distinguish DB probe uncertainty from an ordinary missing table.');


// Round 38 — outbox queue truth, inbox concurrency and File-19 notification delivery are durable/retryable.
$outbox=$read('includes/class-sn-outbox.php');$central=$read('includes/class-sn-central-plan-hardening.php');
$dispatchPos=strpos($outbox,'public static function dispatch_batch');$dispatchEnd=$dispatchPos===false?false:strpos($outbox,'public static function dispatch_one',$dispatchPos);$dispatchSeg=$dispatchPos===false?'':substr($outbox,$dispatchPos,($dispatchEnd===false?strlen($outbox):$dispatchEnd)-$dispatchPos);
$check(str_contains($dispatchSeg,'$wpdb->last_error') && str_contains($dispatchSeg,'event_dispatch_queue_read_failed'), 'Round 38: an outbox queue-read DB failure must never become an ordinary empty queue.');
$incomingPos=strpos($outbox,'public static function consume_incoming');$incomingEnd=$incomingPos===false?false:strpos($outbox,'public static function admin_events',$incomingPos);$incomingSeg=$incomingPos===false?'':substr($outbox,$incomingPos,($incomingEnd===false?strlen($outbox):$incomingEnd)-$incomingPos);
$check(str_contains($incomingSeg,'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)') && str_contains($incomingSeg,'FOR UPDATE') && strpos($incomingSeg,'FOR UPDATE') < strpos($incomingSeg,'$handler($clean)'), 'Round 38: concurrent incoming-event consumers must serialize on the canonical inbox row before handler execution.');
$check(str_contains($central,"add_filter('sn_network_outbox_delivery_result', [self::class, 'deliver_notification_outbox'], PHP_INT_MAX, 2)") && str_contains($central,"SN_Outbox::enqueue(") && str_contains($central,"'notification.requested'"), 'Round 38: File-19 notification facts must enter the durable File-17 outbox instead of relying on best-effort synchronous handoff.');
$check(str_contains($central,'notification_outbox_enqueue_failed') && str_contains($central,'notification_outbox_queued') && str_contains($central,'notification_file19_handoff_unacknowledged'), 'Round 38: notification durability and explicit File-19 acknowledgement failures must remain observable.');
$check(str_contains($central,"return new WP_Error('notification_file19_unavailable'") && str_contains($central,"return new WP_Error('notification_file19_handoff_unacknowledged'") && str_contains($central,'notification_deferred_to_file19'), 'Round 38: File-19 outage/unacknowledged delivery must remain retryable through the outbox and only explicit acknowledgement may succeed.');

if ($fail) {
    fwrite(STDERR, "Ninth fresh 40-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Ninth fresh 40-round contracts: PASS ($checks checks)\n";
