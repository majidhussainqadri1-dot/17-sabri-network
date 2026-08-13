<?php
/** Static regression contracts for Future-24 corrective review cycles through 13-Aug-2026. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) { fwrite(STDERR, "Missing file: {$path}\n"); exit(1); }
    return (string) file_get_contents($full);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$plugin = $read('sabri-network.php');
$activator = $read('includes/class-sn-activator.php');
$runtime = $read('includes/class-sn-two-plan-runtime-hardening.php');
$compat = $read('includes/class-sn-compatibility-hardening.php');
$loader = $read('includes/class-sn-future24-review-hardening.php');
$future = $read('includes/class-sn-future-superset.php');
$part1 = $read('includes/class-sn-future-superset-part-1.php');
$part2 = $read('includes/class-sn-future-superset-part-2.php');
$membershipAdapter = $read('includes/class-sn-membership-assertions.php');
$auth = $read('includes/class-sn-auth.php');
$a = $read('includes/class-sn-future24-review-hardening-a.php');
$b = $read('includes/class-sn-future24-review-hardening-b.php');
$c = $read('includes/class-sn-future24-review-hardening-c.php');
$d = $read('includes/class-sn-future24-review-hardening-d.php');
$e = $read('includes/class-sn-future24-review-hardening-e.php');
$f = $read('includes/class-sn-future24-review-hardening-f.php');
$g = $read('includes/class-sn-future24-review-hardening-g.php');
$h = $read('includes/class-sn-future24-review-hardening-h.php');
$i = $read('includes/class-sn-future24-review-hardening-i.php');
$j = $read('includes/class-sn-future24-review-hardening-j.php');
$k = $read('includes/class-sn-future24-review-hardening-k.php');
$l = $read('includes/class-sn-future24-review-hardening-l.php');
$m = $read('includes/class-sn-future24-review-hardening-m.php');
$n = $read('includes/class-sn-future24-review-hardening-n.php');
$o = $read('includes/class-sn-future24-review-hardening-o.php');

// Additional fresh cycle — Round 1: bootstrap, canonical routing and global REST isolation.
$assert(!str_contains($plugin, "\$_GET['sn-network-safe']"), 'Safe standalone rendering must be reachable only through the canonical rewrite query var.');
$assert(str_contains($plugin, 'SN_Future_Superset::maybe_upgrade();') && str_contains($plugin, 'SN_Future_Superset::install();'), 'Current Future-24 schema must participate in normal upgrade/version reconciliation.');
$twoPlanInstall = strpos($activator, 'SN_Two_Plan_Completion::install();');
$futureInstall = strpos($activator, 'SN_Future_Superset::install();');
$versionPublish = strpos($activator, "update_option('sn_plugin_version', SN_VERSION, false);");
$assert($twoPlanInstall !== false && $futureInstall !== false && $versionPublish !== false && $twoPlanInstall < $versionPublish && $futureInstall < $versionPublish, 'Activation must install all current schemas before publishing the current plugin version.');
$assert(str_contains($runtime, "if (!str_starts_with(\$route, '/sabri-network/v2/')) return null;"), 'Global rest_pre_dispatch hardening must ignore non-File-17 namespaces.');
$assert(str_contains($runtime, '$file_params = $request->get_file_params();') && str_contains($runtime, '$access = SN_Policy::access();'), 'File hashing must revalidate canonical File-17 access before touching uploaded bytes.');

// Additional fresh cycle — Round 2: File 00 authority, age/guardian and phone ownership.
$assert(str_contains($auth, "class-sn-membership-assertions.php"), 'File 17 must load its fail-closed File 00 communication assertion adapter.');
$assert(str_contains($membershipAdapter, "function_exists('smc_communication_assertions')") && str_contains($membershipAdapter, "function_exists('smc_membership_assertions')"), 'Protected communication must require both canonical File 00 assertion functions.');
$assert(str_contains($membershipAdapter, "MIN_CONTRACT_VERSION = '1.1.1'") && str_contains($membershipAdapter, 'identity_contract_unsupported'), 'Unknown/legacy Membership contract versions must fail closed.');
foreach (['contract_version', 'user_id', 'status', 'eligible', 'phone_verified', 'can_message', 'can_call', 'suspended'] as $field) {
    $assert(str_contains($membershipAdapter, "'{$field}'"), "File 00 communication assertion validation is missing {$field}.");
}
$assert(str_contains($membershipAdapter, 'identity_assertion_subject_mismatch') && str_contains($membershipAdapter, 'identity_assertion_type_invalid'), 'Malformed or cross-subject File 00 assertions must fail closed.');
$assert(str_contains($membershipAdapter, "add_filter('sn_network_user_can_access'") && str_contains($membershipAdapter, "add_filter('sn_network_user_is_suspended'") && str_contains($membershipAdapter, "add_filter('sn_network_user_age_state'") && str_contains($membershipAdapter, "add_filter('sn_network_guardian_consent_valid'"), 'Canonical Membership assertions must drive File-17 policy decisions.');
$assert(!str_contains($auth, "'meta_key' => 'sn_phone_e164'") && !str_contains($auth, "get_user_meta(\$user_id, 'sn_phone_e164'"), 'File 17 must not maintain or query a duplicate raw-phone identity registry.');
$assert(str_contains($auth, 'SN_Membership_Assertions::phone_projection') && str_contains($auth, 'SN_Membership_Assertions::resolve_user_by_phone') && str_contains($membershipAdapter, 'sn_network_file00_phone_projection') && str_contains($membershipAdapter, 'sn_network_file00_user_by_phone'), 'Phone display/resolution must cross the File 00 assertion adapter and its explicit owner projection boundary.');
$assert(!str_contains($auth, "get_user_meta(\$user_id, 'sn_phone_verified'") && str_contains($auth, 'sn_network_public_verification_badge'), 'A public verification badge must not be inferred from a local phone-verification meta flag.');
$assert(str_contains($auth, "\$assertion['can_call'] !== true"), 'TURN/STUN credentials must require current File 00 call eligibility.');

$assert(substr_count($runtime, 'SN_Future_Superset::register();') === 1, 'Runtime hardening must be the single Future-24 registration owner.');
$assert(substr_count($compat, 'SN_Future_Superset::register();') === 0, 'Compatibility hardening must not register Future-24 a second time.');
foreach (range('a', 'o') as $suffix) {
    $path = "includes/class-sn-future24-review-hardening-{$suffix}.php";
    $assert(is_file($root . '/' . $path), "Missing corrective hardening {$suffix}.");
    $assert(str_contains($loader, "class-sn-future24-review-hardening-{$suffix}.php"), "Loader does not require corrective hardening {$suffix}.");
    $assert(str_contains($loader, 'SN_Future24_Review_Hardening_' . strtoupper($suffix) . '::register()'), "Loader does not register corrective hardening {$suffix}.");
}

$assert(str_contains($part1, 'sn_network_device_public_key_valid'), 'Device public-key validation must fail closed.');
$assert(str_contains($part1, 'sn_conversation_step_up_required'), 'Sensitive conversation lock must require step-up.');
$assert(str_contains($part1, 'SN_Message_Operations::is_hidden($u,(int)$m->id)'), 'Message-version capture must respect hidden-message state.');
$assert(str_contains($future, "state='firing'"), 'Reminder handoff must claim due work before File 19 notification.');
$assert(str_contains($future, 'file17-future-reminder:'), 'Reminder handoff must retain a stable downstream idempotency key.');
$assert(str_contains($part2, "'mark_unread','label','assign','export_request'"), 'Bulk operations must retain the approved action superset.');
$assert(str_contains($part2, "['state'=>'processing'") && str_contains($part2, "['id'=>(int)\$job->id,'state'=>'queued'") && str_contains($part2, "['id'=>(int)\$job->id,'state'=>'processing'"), 'Bulk jobs must keep resumable queued/processing CAS transitions.');

$assert(str_contains($a, 'assert_redeem_eligibility($space,$user,$issuer)'), 'QR redemption must revalidate eligibility inside its locked transition.');
$assert(str_contains($a, "SN_DB::table('blocks')") && str_contains($a, 'FOR UPDATE'), 'QR redemption must lock authoritative block/membership state.');
$assert(str_contains($a, "in_array((string)\$m->status,['banned','blocked'],true)"), 'QR admission must refuse banned/blocked membership rows.');
$assert(str_contains($a, "['id'=>(int)\$m->id,'version'=>(int)\$m->version]"), 'QR membership activation must use version CAS.');
$assert(str_contains($a, "user_can(\$user,'manage_options')") && !str_contains($a, "current_user_can('manage_options')"), 'QR manager authority must evaluate the asserted manager.');

$assert(str_contains($b, "add_action('sn_cleanup_hourly',[self::class,'expire_temporary_memberships'],5)"), 'Temporary expiry must claim due rows before legacy cleanup.');
$assert(str_contains($b, "SET state='expiry_pending'") && str_contains($b, "'membership_version'=>\$new_member_version"), 'Temporary membership expiry must be version-bound and crash-safe.');
$assert(str_contains($b, '(int)$member->version===$bound_version'), 'Temporary expiry must not revoke an independently changed membership.');
$assert(str_contains($b, 'generation_after_id') && str_contains($b, 'mentorship-v2:'), 'Mentorship idempotency must be lifecycle-generation aware.');
$assert(str_contains($b, "'idempotent'=>true"), 'A same-generation pending mentorship retry must be idempotent.');
$assert(!str_contains($b, 'SN_Future_Superset::create_mentorship($r)'), 'Mentorship creation must not fall back to the permanently unique legacy pair key.');

$assert(str_contains($o, "serialize_message_version_edit") && str_contains($o, "sn:f17:msg-edit:") && str_contains($o, 'SELECT GET_LOCK') && str_contains($o, 'SELECT RELEASE_LOCK'), 'Message edit snapshots must be serialized by a per-message advisory lock.');
$assert(str_contains($o, "add_filter('rest_pre_dispatch',[self::class,'serialize_message_version_edit'],7,3)"), 'Message edit lock must run before Future-24 version capture.');
$assert(str_contains($j, 'GET_LOCK') && str_contains($j, 'revoke_device_key') && str_contains($j, 'signed_checkpoint'), 'Device key transparency must retain serialized ledger and revocation controls.');
$assert(str_contains($i, 'sn_future_device_keys') && str_contains($i, 'sn_future_key_log'), 'Device-key storage and key-log contracts must remain present.');
$assert(str_contains($l, 'manager_locked($conversation,$actor)') && str_contains($l, "delegated_locked(\$conversation,\$target,'work')"), 'Team handoff must revalidate actor and target authority inside the transition.');
$assert(str_contains($l, 'LIMIT 1 FOR UPDATE'), 'Team handoff authority must be backed by locked membership rows.');
$assert(str_contains($m, 'sn_reminder_terminal') && str_contains($m, 'sn_reminder_busy'), 'Reminder lifecycle must reject terminal resurrection and firing-state mutation.');
$assert(str_contains($m, "['id'=>(int)\$row->id,'state'=>'active','version'=>(int)\$row->version]"), 'Reminder cancel/reschedule mutation must use active-state version CAS.');
$assert(str_contains($n, 'may_edit_locked($row,$actor)') && str_contains($n, "'template_manage'"), 'Team template edit/delete must revalidate delegated authority while locked.');
$assert(str_contains($n, 'START TRANSACTION') && str_contains($n, 'template_revision'), 'Template revision and mutation must remain atomic.');
$assert(str_contains($part2, 'bulk_assign_delegation_changed') && str_contains($part2, "sn_network_team_inbox_delegation_allowed"), 'Bulk assignment must enforce Team Inbox delegation.');
$assert(str_contains($part2, "START TRANSACTION") && str_contains($part2, "F17-FUT-05") && str_contains($part2, "F17-FUT-06"), 'Bulk team/assignment writes must share an atomic transaction.');
$assert(str_contains($a, 'smart-views/(?P<id>\\d+)/results') && str_contains($a, 'sn_smart_view_verification_provider_unavailable'), 'Smart private views must retain fail-closed verified filtering.');
$assert(str_contains($c, 'sn_network_citation_resolve') && str_contains($c, "empty(\$resolved['current'])") && str_contains($c, "empty(\$resolved['allowed'])"), 'Citation cards must resolve current canonical owner access.');
$assert(str_contains($c, 'sn_case_retention_failed') && str_contains($c, 'START TRANSACTION'), 'Case discussions must fail closed if retention cannot commit atomically.');
$assert(str_contains($c, "'expires_at'=>null") && str_contains($c, 'Idempotent retry must not extend'), 'Case retention must bind once and not extend on idempotent retry.');
$assert(str_contains($d, 'sn:f17:speaker-queue:') && str_contains($d, 'SELECT GET_LOCK') && str_contains($d, 'START TRANSACTION'), 'Speaker queue must use a per-call lock plus DB transaction.');
$assert(str_contains($d, "feature_rows('F17-FUT-18',\$call,true)") && str_contains($d, 'no partial update was committed'), 'Speaker reorder/next must lock rows and fail without partial updates.');
$assert(str_contains($e, "'provisioning'") && str_contains($e, "'moving'") && str_contains($e, "'closing'") && str_contains($e, "'reconcile_required'"), 'Breakouts must expose recoverable provider lifecycle states.');
$assert(str_contains($e, "SET state='expiry_pending'") && str_contains($e, 'sn_network_breakout_move_rollback_result'), 'Breakout expiry/move must be provider-aware and compensatable.');
$assert(str_contains($e, 'sn:f17:breakout:') && str_contains($e, "user_can(\$u,'manage_options')"), 'Breakout lifecycle must be per-call serialized and asserted-user authorized.');
$assert(str_contains($f, 'transfer_preparing') && str_contains($f, 'transfer_committing') && str_contains($f, 'takeover_committing') && str_contains($f, 'reconcile_required'), 'Host lifecycle must use explicit transition/reconciliation states.');
$assert(str_contains($f, 'sn_network_call_host_transfer_rollback') && str_contains($f, 'sn_network_call_host_takeover_rollback'), 'Provider host changes must have compensation contracts.');
$assert(str_contains($f, 'sn:f17:host:') && str_contains($f, "user_can(\$u,'manage_options')") && !str_contains($f, "current_user_can('manage_options')"), 'Host lifecycle must be serialized and asserted-user authorized.');
$assert(str_contains($g, 'telemetry_consent') && str_contains($g, 'packet_loss_bucket') && str_contains($g, 'sn_network_quality_retention_hours'), 'Network-quality telemetry must stay consented, bucketed and short-lived.');
$assert(str_contains($g, 'sn_ai_task_invalid') && str_contains($g, 'File 16 AI governance has not authorized this exact task'), 'AI governance must authorize the exact executed task.');
$assert(str_contains($g, 'sn_ai_context_stale') && substr_count($g, 'SN_DB::is_member($conversation,$user)') >= 2 && str_contains($g, 'SN_Message_Operations::is_hidden($user,$id)'), 'AI private context must revalidate membership and selected-message visibility at provider handoff.');
$assert(str_contains($g, 'sn_network_private_semantic_index_event_allowed') && str_contains($g, 'require_current_consent'), 'Semantic index changes must fail closed behind an explicit consent-aware gate.');
$assert(str_contains($g, 'purge_pending') && str_contains($g, 'retry_semantic_purges') && str_contains($g, 'sn_network_private_semantic_purge_result'), 'Consent withdrawal must track and retry semantic projection purge.');
$assert(str_contains($g, 'sn_semantic_context_stale') && !str_contains($g, "'query'=>"), 'Semantic search must recheck consent/access and never log raw query text.');
$assert(str_contains($h, 'sn_interop_inbound_authorized') && str_contains($h, 'sn_network_interop_inbound_filter') && str_contains($h, 'sn_network_interop_outbound_filter'), 'Interoperability inbound/outbound must pass explicit adapter authorization/filter gates.');
$assert(str_contains($h, 'kill_switch') && str_contains($h, 'sn_interop_reconcile_required'), 'Interop kill switch must fail closed and preserve reconciliation state.');
$assert(str_contains($h, 'idempotency_key') && str_contains($h, 'receipt'), 'Interop side effects must use idempotency/receipt evidence.');

echo "Future-24 forty-round corrective static contracts: PASS\n";
