<?php
/** File 17 seventh fresh 20-round permanent repository regression contracts. */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$message = $read('includes/class-sn-message-runtime-hardening.php');
$search = $read('includes/class-sn-message-search.php');
$attachment = $read('includes/class-sn-attachment-runtime-hardening.php');
$transferPrivacy = $read('includes/class-sn-file-transfer-part-8.php');
$fifthPrivacy = $read('includes/class-sn-fifth-fresh-privacy-hardening.php');
$callRuntime = $read('includes/class-sn-call-runtime-hardening.php');
$smailRuntime = $read('includes/class-sn-smail-runtime-hardening.php');
$relationshipRuntime = $read('includes/class-sn-relationship-runtime-hardening.php');
$relationships = $read('includes/class-sn-relationships.php');
$spaces2 = $read('includes/class-sn-spaces-part-2.php');
$spaces4 = $read('includes/class-sn-spaces-part-4.php');
$spaces5 = $read('includes/class-sn-spaces-part-5.php');
$spaces9 = $read('includes/class-sn-spaces-part-9.php');
$r13 = $read('includes/class-sn-seventh-fresh-r13-hardening.php');
$r14 = $read('includes/class-sn-seventh-fresh-r14-hardening.php');
$r15 = $read('includes/class-sn-seventh-fresh-r15-privacy-hardening.php');
$loader = $read('includes/class-sn-future24-review-hardening.php');
$admin = $read('includes/class-sn-admin.php');

// R5 — a caller-owned message retry key must also be bound to exact request semantics.
$check(str_contains($message, "'_idempotency_fingerprint'"), 'R5: canonical messages must persist a request-semantic idempotency fingerprint.');
$check(str_contains($message, 'request_semantics(') && str_contains($message, 'idempotency_matches('), 'R5: duplicate reconciliation must compare the retried request with the original message semantics.');
$check(str_contains($message, 'message_idempotency_conflict'), 'R5: a reused key with different request semantics must fail with an explicit conflict.');
$check(str_contains($message, "'attachment_sha256'") && str_contains($message, "'reply_to'"), 'R5: request binding must include attachment identity and reply target, not only the client key.');
$check(!str_contains($message, 'if($existing)return self::reconcile_existing($existing,$user_id,true);'), 'R5: the old payload-blind duplicate path must not remain canonical.');

// R6 — private-search rebuild/index failures must fail closed without skipping rows or erasing the last known-good index first.
$check(str_contains($search, 'public static function backfill(): bool|WP_Error'), 'R6: search backfill must surface failure rather than silently returning void.');
$check(str_contains($search, 'if (is_wp_error($indexed)) return self::backfill_failure($indexed, (int) $row->id);'), 'R6: a failed message index must stop the backfill before its cursor advances past that row.');
$check(str_contains($search, "REBUILDING_OPTION = 'sn_message_search_epoch_rebuilding'") && str_contains($search, 'update_option(self::REBUILDING_OPTION, true, false);'), 'R6: a destructive manual rebuild must enter the same fail-closed rebuilding state used by key-epoch rebuilds.');
$check(str_contains($search, 'search_rebuild_backfill_failed') && str_contains($search, 'update_option(self::REBUILD_ERROR_OPTION, $backfill->get_error_code(), false);'), 'R6: manual rebuild failure must be explicit and persistent until safe retry/completion.');
$decryptPos = strpos($search, '$plain = SN_Message_Body::decrypt_row($message);');
$deletePos = strpos($search, 'token_hash NOT IN', $decryptPos === false ? 0 : $decryptPos);
$check($decryptPos !== false && $deletePos !== false && $decryptPos < $deletePos, 'R6: message plaintext must decrypt and desired tokens must be prepared before stale valid search tokens are reconciled away.');
$check(str_contains($search, "'rebuilding' => \$rebuilding") && str_contains($search, "'error' => \$error"), 'R6: search health must expose rebuilding/error state instead of reporting a partial index healthy.');

// R7 — expensive private-object integrity hashing must occur only after download authorization.
$authPos = strpos($attachment, "if (!is_user_logged_in()) return;");
$noncePos = strpos($attachment, 'wp_verify_nonce', $authPos === false ? 0 : $authPos);
$accessPos = strpos($attachment, 'SN_DB::user_can_access_attachment', $noncePos === false ? 0 : $noncePos);
$hashPos = strpos($attachment, "hash_file('sha256', \$candidate)", $accessPos === false ? 0 : $accessPos);
$check($authPos !== false && $noncePos !== false && $accessPos !== false && $hashPos !== false && $authPos < $noncePos && $noncePos <= $accessPos && $accessPos < $hashPos, 'R7: login, nonce and attachment authorization must precede private-file integrity hashing.');
$check(str_contains($attachment, 'must never become an unauthenticated/unauthorized disk-I/O oracle'), 'R7: the private hashing boundary must document its fail-closed resource-abuse invariant.');

// R8 — privacy erasure must keep terminal transfer sessions attributable until every encrypted chunk is physically gone.
$check(str_contains($transferPrivacy, "s.status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM \$chunks c WHERE c.transfer_id=s.id)"), 'R8: terminal sender transfers with leftover chunk rows must remain in the privacy erasure work queue.');
$check(str_contains($transferPrivacy, 'foreach($sent as $id)') && str_contains($transferPrivacy, 'self::delete_chunks($id)'), 'R8: canonical privacy erasure must retry physical chunk destruction after revoking sender access.');
$check(str_contains($transferPrivacy, '$more_sent=(bool)$wpdb->get_var') && str_contains($transferPrivacy, "EXISTS (SELECT 1 FROM \$chunks c WHERE c.transfer_id=s.id)"), 'R8: erasure completion must remain false while any sender-attributable encrypted chunk ledger remains.');
$baseDonePos = strpos($fifthPrivacy, "if (empty(\$base['done'])) return \$base;");
$anonymizePos = strpos($fifthPrivacy, "sender_id=0", $baseDonePos === false ? 0 : $baseDonePos);
$check($baseDonePos !== false && $anonymizePos !== false && $baseDonePos < $anonymizePos, 'R8: higher-level transfer anonymization must wait for canonical byte-erasure completion.');
$check(str_contains($transferPrivacy, 'higher-level anonymization must not sever the user link'), 'R8: the retryability/linkage invariant must be explicit in the transfer privacy implementation.');

// R9 — call/Meet protected reads, request idempotency and privacy erasure must revalidate current truth.
$check(str_contains($callRuntime, 'validate_meeting_idempotency_reuse') && str_contains($callRuntime, 'sn_meet_idempotency_conflict'), 'R9: meeting idempotency-key reuse must be rejected when the retried meeting semantics differ.');
$check(str_contains($callRuntime, "hash_equals((string)\$row->title, \$title)") && str_contains($callRuntime, "(int)\$row->conversation_id === \$conversation") && str_contains($callRuntime, "(int)\$row->participant_limit === \$limit"), 'R9: meeting idempotency comparison must bind identity to material title/conversation/limit settings.');
$check(str_contains($callRuntime, 'guard_protected_reads') && str_contains($callRuntime, "meetings/([A-Za-z0-9_-]{22,64})/(participants|signals)") && str_contains($callRuntime, "calls/(\\d+)/signals"), 'R9: protected call/Meet GET reads must pass a current authorization boundary.');
$check(str_contains($callRuntime, 'SN_Membership_Assertions::clear_cache($actor)') && str_contains($callRuntime, "SN_DB::is_blocked(\$actor, (int)\$meeting->host_id)"), 'R9: protected Meet reads must revalidate File-00 eligibility and live block state.');
$check(str_contains($callRuntime, 'override_meet_privacy_eraser') && str_contains($callRuntime, 'meet_privacy_erase_retry_safe'), 'R9: the registered Meet privacy eraser must be wrapped by a retry-safe completion boundary.');
$check(str_contains($callRuntime, "\$result['done'] = false") && str_contains($callRuntime, "failed and must be retried"), 'R9: operational Meet erasure failure must keep WordPress privacy retry alive.');

// R10 — Smail retries must require a caller-owned key and bind it to exact mail semantics.
$check(str_contains($smailRuntime, "if(\$client===''||!preg_match") && str_contains($smailRuntime, 'A caller-supplied Smail idempotency key is required.'), 'R10: Smail send must reject a missing caller-owned idempotency key instead of generating a server UUID.');
$check(!str_contains($smailRuntime, "if(\$client==='')\$client=wp_generate_uuid4();"), 'R10: runtime Smail must not silently synthesize an idempotency key.');
$check(str_contains($smailRuntime, 'sort($recipients,SORT_NUMERIC);'), 'R10: recipient order must be canonicalized before idempotency comparison.');
$check(str_contains($smailRuntime, 'idempotency_matches(') && str_contains($smailRuntime, 'smail_idempotency_conflict'), 'R10: duplicate Smail retries must verify request semantics and reject key reuse conflicts.');
$check(str_contains($smailRuntime, "hash_equals((string)\$smail->subject,\$subject)") && str_contains($smailRuntime, 'SN_Message_Body::decrypt_row($message)') && str_contains($smailRuntime, '$stored!==$expected'), 'R10: Smail idempotency binding must cover subject, canonical body and exact recipient set.');

// R11 — relationship/block/direct-conversation transitions must preserve exact directional and retry semantics.
$check(str_contains($relationshipRuntime, 'invalid_block_state') && str_contains($relationshipRuntime, '!is_bool($raw)'), 'R11: malformed block-state input must be rejected rather than coerced into an unblock.');
$check(str_contains($relationshipRuntime, '$reverse = (bool)$wpdb->get_var') && str_contains($relationshipRuntime, "if (!\$reverse && \$contact"), 'R11: removing one directional block must not clear the shared blocked-contact projection while the reverse block remains.');
$check(str_contains($relationshipRuntime, "['blocked'=>\$pairBlocked,'blocked_by_me'=>\$own]") && str_contains($relationships, "'unblock' => \$blocked_by_viewer"), 'R11: API/UI unblock state must distinguish actor-owned block authority from pair-level blocking.');
$check(str_contains($relationshipRuntime, 'ambiguous_direct_target') && str_contains($relationshipRuntime, 'count($members) !== 1'), 'R11: direct conversation creation must reject ambiguous multi-peer input instead of silently truncating it.');
$check(str_contains($relationshipRuntime, 'if (!$existing || $restored)') && str_contains($relationshipRuntime, "conversation_restored':'conversation_created"), 'R11: an unchanged existing direct conversation retry must not re-emit invitation/audit side effects.');
$check(str_contains($relationshipRuntime, '$restored = (string)$existing->status') && str_contains($relationshipRuntime, 'if (!$row || $row->left_at !== null) $restored = true;'), 'R11: true direct-conversation restoration must be distinguished from a no-op retry.');

// R12 — space governance must fail closed and mutation inputs must be exact.
$check(str_contains($spaces9, '$inserted=$wpdb->insert(self::audit_table()') && str_contains($spaces9, "if(\$inserted===false)throw new RuntimeException('space_governance_record_failed')"), 'R12: space governance evidence write failure must abort the governed mutation.');
$check(str_contains($spaces2, "space_settings_commit_failed") && str_contains($spaces2, "self::record(\$id,\$actor,'space_settings_updated'"), 'R12: space settings mutation and governance record must share a commit-checked transaction.');
$check(str_contains($spaces5, 'sn_space_unban_failed') && str_contains($spaces5, "SELECT * FROM '.self::bans_table().' WHERE id=%d FOR UPDATE") && str_contains($spaces5, 'unban_commit_failed'), 'R12: unban and its governance record must be transactional and commit-checked.');
$check(str_contains($spaces2, "in_array(\$action,['join','cancel'],true)") && str_contains($spaces2, 'sn_space_join_action_invalid'), 'R12: malformed join actions must not silently execute the join path.');
$check(str_contains($spaces5, "in_array(\$action,['role','remove'],true)") && str_contains($spaces5, 'sn_space_member_action_invalid'), 'R12: malformed member actions must not silently execute role mutation.');
$check(str_contains($spaces5, "if(!in_array(\$raw_role,self::ROLES,true))") && str_contains($spaces5, 'sn_space_role_invalid'), 'R12: invalid member roles must be rejected rather than defaulting to member.');
$check(str_contains($spaces5, "in_array(\$action,['ban','unban'],true)") && str_contains($spaces5, 'sn_space_ban_action_invalid'), 'R12: malformed moderation actions must not silently become bans.');
$check(str_contains($spaces4, '$expired=$wpdb->update') && str_contains($spaces4, "if(\$expired!==1)throw new RuntimeException('invite_expiry_conflict')") && str_contains($spaces4, 'invite_expiry_commit_failed'), 'R12: expired invitation state must be persisted and commit-confirmed before returning the expired response.');

// R13 — realtime state, receipts, erasure and notification health must revalidate current truth and fail closed.
$check(str_contains($loader, 'class-sn-seventh-fresh-r13-hardening.php') && str_contains($loader, 'SN_Seventh_Fresh_R13_Hardening::register()'), 'R13: the canonical hardening overlay must be loaded and registered.');
$check(str_contains($r13, "add_filter('wp_privacy_personal_data_erasers'") && str_contains($r13, "'done'=>false"), 'R13: privacy eraser operational failures must keep WordPress retry alive.');
$check(str_contains($r13, 'presence_lock($uid)') && str_contains($r13, "SN_DB::table('presence_devices')") && str_contains($r13, '$remaining===null'), 'R13: presence-device erasure must serialize with heartbeat/revoke and verify no rows remain before completion.');
$check(str_contains($r13, 'receipt_user_lock($uid)') && str_contains($r13, "SN_DB::table('message_receipts')") && str_contains($r13, '$remaining===null'), 'R13: receipt erasure must serialize with receipt writes and verify no rows remain before completion.');
$check(substr_count($r13, 'sn_presence_state_invalid') >= 2, 'R13: malformed canonical and legacy presence state must be rejected instead of coercing to online.');
$check(str_contains($r13, 'self::aggregate_presence($forward)') && substr_count($r13, 'SN_Policy::can_view_presence($viewer, $target)') >= 2, 'R13: legacy presence projection must reuse the locked canonical authorization path and post-check current visibility.');
$check(str_contains($r13, 'self::conversation_locks($conversation, $actor)') && str_contains($r13, 'SN_Policy::can_contact($actor, $peer, $context)'), 'R13: typing/receipt direct-conversation metadata must serialize with pair changes and revalidate current contact policy.');
$check(str_contains($r13, 'public static function get_receipts') && str_contains($r13, 'public static function record_receipt') && str_contains($r13, 'self::receipt_user_lock($actor)'), 'R13: receipt reads/writes must share conversation, relationship and privacy-erasure serialization.');
$check(str_contains($r13, "\$data['notification_adapter'] = self::file19_ready();") && str_contains($r13, 'sn_network_file19_notification_adapter_ready'), 'R13: REST health must report a verified File-19 adapter rather than File-17 terminal-filter presence.');
$check(str_contains($admin, 'SN_Seventh_Fresh_R13_Hardening::file19_ready()') && !str_contains($admin, "\$notification_adapter = has_filter('sn_network_notification_handled');"), 'R13: administrator health must not self-certify the File-19 adapter from File-17 own bridge.');

// R14 — safety reports must bind exact semantics/targets, preserve appeal history, use dual control, and scope holds narrowly.
$check(str_contains($loader, 'class-sn-seventh-fresh-r14-hardening.php') && str_contains($loader, 'SN_Seventh_Fresh_R14_Hardening::register()'), 'R14: the canonical safety hardening overlay must be loaded and registered.');
$check(str_contains($r14, "'target_type'") && str_contains($r14, "'target_ref'") && str_contains($r14, "'request_fingerprint'") && str_contains($r14, "'appeal_count'") && str_contains($r14, 'target_ref_created'), 'R14: supplemental report schema must persist canonical target identity, request semantics and appeal count.');
$check(str_contains($r14, "sn_r14_safety_schema_version") && str_contains($r14, 'backfill_reports()'), 'R14: the supplemental report schema must have an explicit resumable migration marker and legacy backfill.');
$check(str_contains($r14, 'request_fingerprint(') && str_contains($r14, 'report_idempotency_conflict') && str_contains($r14, 'request_fingerprint,status,retention_until,version'), 'R14: report idempotency must bind category/details/evidence to the canonical target and reject conflicting key reuse.');
$check(str_contains($r14, "['user','conversation','message','space','call','listing_context']") && str_contains($r14, 'sn_network_listing_report_context_authorized'), 'R14: report targets must cover user, conversation, message, space, call and fail-closed external listing context.');
$check(str_contains($r14, "'medical_harm'") && str_contains($r14, "'copyright'") && str_contains($r14, "'minor_safety'") && str_contains($r14, "'illegal_content'"), 'R14: governing moderation categories must be present without removing legacy categories.');
$check(str_contains($r14, 'appeal_count=appeal_count+1') && str_contains($r14, "'appeal_count'=>\$count"), 'R14: appeal submission must persist and expose a monotonic appeal count.');
$check(str_contains($r14, 'high_risk_action_id') && str_contains($r14, 'SN_High_Risk::claim') && str_contains($r14, "'mass_moderation'") && str_contains($r14, "'operation'=>'report_appeal_decision'") && str_contains($r14, 'SN_High_Risk::complete'), 'R14: high-risk appeal decisions must pass the canonical separated approval/execution high-risk workflow.');
$check(str_contains($r14, "capture_retention_before_native") && str_contains($r14, "scope_native_report_hold") && str_contains($r14, 'has_native_report_hold'), 'R14: native report legal holds must be scoped rather than becoming an account-wide erasure veto.');
$check(str_contains($r14, 'NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=m.id AND r.legal_hold=1)') && str_contains($r14, "r.legal_hold=1 WHERE m.attachment_source='private'"), 'R14: directly held message and private-attachment evidence must remain preserved while unrelated data erases.');
$check(str_contains($r14, 'self::privacy_lock($reporter)') && str_contains($r14, 'self::report_user_lock($reporter)') && str_contains($r14, 'self::report_user_lock($uid)'), 'R14: report creation and report erasure must share the same ordered privacy/report-user serialization domain.');

// R15 — terminal eraser order, Future-24 completeness, held-target evidence and completion signalling must be safe.
$check(str_contains($loader, 'class-sn-seventh-fresh-r15-privacy-hardening.php') && str_contains($loader, 'SN_Seventh_Fresh_R15_Privacy_Hardening::register()'), 'R15: final privacy lifecycle hardening must be loaded after earlier overlays.');
$check(str_contains($r15, "add_filter('wp_privacy_personal_data_erasers', [self::class, 'finalize_erasers'], PHP_INT_MAX)") && str_contains($r15, "\$key !== 'sabri-meet'"), 'R15: a terminal eraser pass must see and guard the final Meet callback rather than being replaced by it.');
$check(str_contains($r15, "\$erasers['sabri-network-future']['callback'] = [self::class, 'erase_future']") && str_contains($r15, "sn_future_device_keys") && str_contains($r15, 'Future device-key erasure'), 'R15: the final Future-24 eraser must erase user-owned device keys instead of losing them to override ordering.');
$check(str_contains($r15, 'erase_message_versions($uid)') && strpos($r15, 'erase_message_versions($uid)') < strpos($r15, 'SN_Seventh_Fresh_R14_Hardening::erase_core'), 'R15: message versions must be processed while sender attribution still exists, before core anonymization severs discovery.');
$check(str_contains($r15, "target_type='call'") && str_contains($r15, "target_type='space'") && str_contains($r15, "target_type='conversation'") && str_contains($r15, 'target_hold_blocks_eraser'), 'R15: target-specific report holds must protect the corresponding core/space evidence class without restoring a global veto.');
$check(str_contains($r15, 'erase_message_organization') && str_contains($r15, 'folder_item_delete_failed') && str_contains($r15, 'star_delete_failed') && str_contains($r15, 'hide_delete_failed') && str_contains($r15, 'organization_commit_failed'), 'R15: message folders/items/stars/hides must erase independently and transactionally without boolean short-circuiting.');
$check(str_contains($r15, 'self::privacy_lock($uid)') && str_contains($r15, 'Another File 17 privacy eraser is running') && str_contains($r15, 'normalize_result'), 'R15: independent erasers must serialize by user and pass a common completion/result boundary.');
$check(str_contains($r15, "case 'sabri-network-smail'") && str_contains($r15, "case 'sabri-network-spaces'") && str_contains($r15, "case 'sabri-network-transfers'") && str_contains($r15, 'Privacy erasure reported completion while erasable user-linked rows still remain'), 'R15: fragile erasers must verify no erasable rows remain before returning done=true.');

if ($fail) {
    fwrite(STDERR, "Seventh fresh 20-round contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Seventh fresh 20-round contracts: PASS ($checks checks)\n";