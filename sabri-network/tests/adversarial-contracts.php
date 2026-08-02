<?php
/**
 * Independent fresh/adversarial static review.
 *
 * This suite deliberately re-checks negative paths, authority boundaries,
 * stale state, race controls, privacy lifecycle, and degraded dependencies.
 * WordPress is intentionally not loaded.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function adv_check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function adv_content(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: $relative");
    }
    return (string) file_get_contents($path);
}

$main = adv_content('sabri-network.php');
$admin = adv_content('includes/class-sn-admin.php');
$shortcode = adv_content('includes/class-sn-shortcode.php');
$db = adv_content('includes/class-sn-db.php');
$policy = adv_content('includes/class-sn-policy.php');
$auth = adv_content('includes/class-sn-auth.php');
$rest = adv_content('includes/class-sn-rest.php');
$files = adv_content('includes/class-sn-private-files.php');
$privacy = adv_content('includes/class-sn-privacy.php');
$safety = adv_content('includes/class-sn-safety.php');
$js = adv_content('assets/js/network.js');
$quality = adv_content('tools/quality-check.sh');
$package = adv_content('tools/package.sh');
$all = implode("\n", [$main, $admin, $shortcode, $db, $policy, $auth, $rest, $files, $privacy, $safety, $js]);
$meSection = substr($rest, strpos($rest, 'public static function get_me'), strpos($rest, 'public static function update_me') - strpos($rest, 'public static function get_me'));
$conversationFormatSection = substr($rest, strpos($rest, 'private static function format_conversation'), strpos($rest, 'private static function format_message') - strpos($rest, 'private static function format_conversation'));

// Authority and exposed-surface review.
adv_check(str_contains($policy, 'identity_authority_unavailable') && str_contains($policy, "['status' => 503]"), 'User access must fail closed when the platform identity authority is unavailable.');
adv_check(str_contains($admin, 'SN_Policy::identity_authority_available()'), 'Administrator health and user policy must use the same identity-authority test.');
adv_check(str_contains($rest, "self::route('/health', 'GET', 'health', '__return_true')"), 'Only the minimal liveness route may be public.');
adv_check(str_contains($rest, "self::route('/admin/health', 'GET', 'admin_health', [self::class, 'admin_access'])"), 'Detailed health evidence must require administrator authorization.');
adv_check(!preg_match('/wp_(?:create_user|insert_user|set_password)|register_new_user|retrieve_password/', $all), 'File 17 must not create accounts, set passwords, or own recovery.');
adv_check(!str_contains($all, 'wp_ajax_nopriv_'), 'No unauthenticated mutation bridge may exist.');

// Minor safety and contact consent.
adv_check(str_contains($policy, "apply_filters('sn_network_minor_contact_allowed', false"), 'Any contact involving a minor must require an explicit allow decision.');
adv_check(str_contains($rest, "apply_filters('sn_network_minor_discoverable', false"), 'Minor directory discovery must default to denied.');
adv_check(str_contains($policy, "return new WP_Error('contact_required'"), 'Direct messages and calls must require an accepted relationship.');
adv_check(str_contains($rest, "WHERE id=%d AND status='pending'"), 'Contact acceptance must be an atomic pending-only transition.');
adv_check(str_contains($db, 'UNIQUE KEY pair_key (pair_key)'), 'Relationship races must be constrained by a unique pair key.');

// Messaging and conversation consistency.
adv_check(str_contains($db, 'UNIQUE KEY direct_key (direct_key)') && str_contains($rest, 'restore_direct_conversation'), 'Direct conversation creation must be unique and safely recoverable.');
adv_check(str_contains($db, 'UNIQUE KEY idempotency_key (idempotency_key)') && str_contains($rest, "'duplicate' => true"), 'Message retries must be database-idempotent.');
adv_check(str_contains($rest, "'attachment_source' => $attachment ? 'private' : 'none'"), 'New text messages must not be mislabeled as legacy public attachments.');
adv_check(str_contains($rest, "SN_Private_Files::delete((int) $attachment['id'], $user_id);"), 'A failed or duplicate message write must not orphan a private upload.');
adv_check(str_contains($conversationFormatSection, "$item['member_ids'] = $member_ids;") && strpos($conversationFormatSection, "$item['member_ids'] = $member_ids;") > strpos($conversationFormatSection, 'if ($include_members)'), 'Conversation-list projections must not expose the full member-ID list by default.');

// Private-file and upload abuse review.
adv_check(str_contains($files, 'dirname(untrailingslashit(ABSPATH))') && str_contains($files, 'self::is_inside_web_root($dir)'), 'Private storage must be outside and explicitly checked against the web root.');
adv_check(!str_contains($files, 'allow_private_storage_inside') && !str_contains($files, 'allow_web_root_storage'), 'There must be no runtime bypass for storage inside the public web root.');
adv_check(!preg_match('/owner_id\s*===\s*$user_id.*return true/s', $db), 'Attachment ownership alone must not grant download access after the message/update reference is gone.');
adv_check(str_contains($files, 'scanner_required') && str_contains($files, 'application/pdf'), 'Documents must fail closed without scanner evidence and PDF signature validation.');
adv_check(str_contains($files, 'Content-Range: bytes */') && str_contains($files, 'status_header(416)'), 'Invalid range requests must fail with HTTP 416.');
adv_check(str_contains($files, "filename*=UTF-8\\'\\''") && str_contains($files, 'Cache-Control: private, no-store'), 'Private delivery must use safe filenames and no-store caching.');

// Call, TURN, signaling, and concurrency review.
adv_check(!str_contains($meSection, 'ice_servers'), 'The general /me response must not disclose TURN credentials.');
adv_check(str_contains($auth, 'SN_DB::is_member($conversation_id, $user_id)') && str_contains($auth, 'sn_network_ephemeral_turn_credentials'), 'ICE credentials must be short-lived and scoped to conversation membership.');
adv_check(strpos($rest, "conversation_contact_check($conversation, $conversation_id, $user_id, 'call')") < strpos($rest, "sn_network_group_call_create_result"), 'Call policy checks must run before delegation to a group-call provider.');
adv_check(str_contains($rest, 'SN_Policy::can_use_group_calls($user_id, $conversation_id)') && str_contains($rest, 'group_call_forbidden'), 'Group-call delegation must be capability and SFU gated.');
adv_check(str_contains($db, 'UNIQUE KEY active_key (active_key)') && str_contains($db, 'backfill_active_call_keys'), 'Only one ringing/active call may exist per conversation.');
adv_check(str_contains($rest, 'if ($current === $status)') && str_contains($rest, "$response['ice_servers'] = SN_Auth::ice_servers"), 'A retried join response must still return scoped ICE configuration.');
adv_check(substr_count($rest, 'START TRANSACTION') >= 4 && str_contains($rest, 'call_cleanup_failed'), 'Call status changes and cleanup must be transactionally guarded.');
adv_check(str_contains($rest, 'active_call_block_cleanup_failed'), 'Blocking a user must end any active direct call and remove signaling state.');
adv_check(str_contains($rest, 'sanitize_signal_payload') && str_contains($rest, 'call_signal_forbidden') && str_contains($rest, '/signals/ack'), 'WebRTC signaling must validate state and payloads and support acknowledgement.');
adv_check(str_contains($db, "status='ringing' AND created_at<%s") && str_contains($db, "status='active' AND COALESCE(started_at,created_at)<%s"), 'Stale ringing and active calls must be bounded and cleaned.');

// Abuse reporting and privacy rights.
adv_check(str_contains($rest, 'does not match the reported message') && str_contains($rest, 'is not a member of this conversation'), 'Reports must bind the reported user to the reported message/conversation.');
foreach (['sabri-network-messages', 'sabri-network-updates', 'sabri-network-contacts', 'sabri-network-calls', 'sabri-network-reports', 'sabri-network-notifications'] as $group) {
    adv_check(str_contains($privacy, $group), "Privacy export must include $group.");
}
adv_check(str_contains($privacy, "['owner_id' => 0, 'status' => 'archived'") , 'Erasure must not leave an active ownerless conversation.');
adv_check(!str_contains($privacy, "self::delete_ids(SN_DB::table('signals'), 'call_id', $call_ids)"), 'Erasing one group-call member must not indiscriminately delete every participant signal by call ID.');
adv_check(str_contains($safety, "details='',evidence='[]'") && str_contains($safety, 'reporter_id=%d AND legal_hold=0'), 'Reporter-authored free text and evidence must be erased when no hold applies.');

// UI, route, and presentation boundaries.
adv_check(str_contains($shortcode, 'SN_Activator::is_owned_page($page_id)'), 'Assets must load only on the File-17-owned Network page or safe route.');
adv_check(!str_contains($main, 'wp_nav_menu_items') && str_contains($main, 'sn_network_route_registered'), 'File 17 must integrate with File 20 rather than creating a second global menu.');
adv_check(str_contains($js, 'const safeUrl') && str_contains($js, "['http:', 'https:'].includes(parsed.protocol)"), 'Dynamic media and link URLs must be protocol allowlisted before entering HTML.');
adv_check(!preg_match('/console\.(?:log|warn|error|debug)\s*\(/', $js), 'Production JavaScript must not retain console diagnostics.');
adv_check(!str_contains($js, 'sendBeacon') && !preg_match('/\beval\s*\(|new Function/', $js), 'Client code must not use unauthenticated beacon writes or dynamic execution.');
adv_check(str_contains($js, 'modalReturnFocus') && str_contains($js, "event.key === 'Tab'"), 'Modal focus must be trapped and restored.');

// Release reproducibility.
adv_check(str_contains($quality, 'tests/static-contracts.php') && str_contains($quality, 'tests/adversarial-contracts.php'), 'Both independent review suites must run in the quality gate.');
adv_check(str_contains($package, "--exclude='.gitignore'") && str_contains($package, "--exclude='tests/'") && str_contains($package, "--exclude='tools/'"), 'The production ZIP must exclude development-only files.');
adv_check(!str_contains($all, 'End-to-End Encrypted') && !str_contains($all, '100% Secure'), 'Unsupported E2EE or absolute-security claims must not appear.');
adv_check(str_contains($rest, 'transfer_conversation_owner') && str_contains($rest, 'Only the current conversation owner may transfer ownership.'), 'Ownership transfer must be restricted to the current owner.');
adv_check(str_contains($rest, '!SN_Policy::has_verified_adult_age($target_id)') && str_contains($rest, 'The new owner must be an active conversation member.'), 'An ineligible, unknown-age, minor, or departed member must not become a conversation owner.');
adv_check(str_contains($rest, 'SELECT user_id,role,left_at FROM $members') && str_contains($rest, 'FOR UPDATE'), 'Ownership transfer must lock and revalidate membership inside the transaction.');
adv_check(strpos($files, '// Revoke authorization before touching bytes') < strpos($files, '$bytes_deleted ='), 'Logical attachment revocation must precede destructive byte removal.');
adv_check(str_contains($files, '$resolved = realpath($dir);') && str_contains($files, "$chunk === false || $chunk === ''"), 'Symlinked storage and zero-length stream reads must be handled safely.');
adv_check(str_contains($db, 'private_attachment_is_referenced') && str_contains($db, 'expired_update_cleanup_failed'), 'Expired-update cleanup must preserve shared attachments and fail safely on database errors.');
adv_check(str_contains($main, 'SN_Activator::ensure_cleanup_schedule()') && str_contains($js, 'Number.isNaN(date.getTime())'), 'Upgrade cron and malformed client timestamps must degrade safely.');
adv_check(str_contains($js, 'api(`conversations/${conversation.id}/owner`') && str_contains($js, "window.confirm('Transfer conversation ownership"), 'The owner-leave dead end must have an explicit, confirmed UI path.');

if ($failures) {
    fwrite(STDERR, "Adversarial contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

echo "Adversarial contracts: PASS ($checks checks)\n";
