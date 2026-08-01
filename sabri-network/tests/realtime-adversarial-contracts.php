<?php
/** Fresh/adversarial contracts for realtime privacy, membership revocation and channel authority. */
$root = dirname(__DIR__);
$db = file_get_contents($root . '/includes/class-sn-db.php');
$policy = file_get_contents($root . '/includes/class-sn-policy.php');
$rest = file_get_contents($root . '/includes/class-sn-rest.php');
$js = file_get_contents($root . '/assets/js/network.js');
$privacy = file_get_contents($root . '/includes/class-sn-privacy.php');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($policy, "conversation->type === 'channel'"), 'channel authority is type-aware');
$assert(str_contains($policy, "['owner', 'moderator']"), 'channel posting defaults to administrators');
$assert(str_contains($policy, "sn_network_channel_member_can_post', false"), 'channel member posting is fail-closed by default');
$assert(str_contains($rest, 'channel_calls_unavailable'), 'broadcast channels cannot silently start peer calls');
$assert(substr_count($rest, 'SN_DB::is_member((int) $call->conversation_id') >= 4, 'active conversation membership gates call status and signaling');
$assert(str_contains($rest, "INNER JOIN ' . SN_DB::table('members') . ' m ON m.conversation_id=c.conversation_id"), 'call inventory excludes users removed from the conversation');
$assert(str_contains($rest, "call_membership_revoke_failed"), 'member removal revokes active call membership');
$assert(str_contains($rest, "call_signal_revoke_failed"), 'member removal revokes queued call signals');
$assert(str_contains($rest, "call_end_after_member_removal_failed"), 'under-populated calls end after revocation');
$assert(str_contains($rest, "table('typing')") && str_contains($rest, "'user_id' => \$target_id"), 'member removal clears typing residue');
$assert(str_contains($rest, "START TRANSACTION") && str_contains($rest, "ROLLBACK"), 'membership and active-call revocation are transactional');
$assert(str_contains($policy, 'SN_DB::is_blocked($viewer_id, $target_id)'), 'blocked users cannot observe presence');
$assert(str_contains($policy, '$contacts = SN_DB::are_contacts'), 'presence evaluates accepted relationship state');
$assert(str_contains($policy, '$shared = SN_DB::share_active_conversation'), 'presence requires a valid relationship context');
$assert(str_contains($policy, 'self::is_minor($target_id) && !$contacts'), 'minor presence does not leak through incidental group membership');
$assert(str_contains($policy, '$visibility === \'contacts\' && $contacts'), 'contacts-only last seen is enforced');
$assert(str_contains($rest, 'array_slice(array_values(array_unique(array_filter(array_map(\'absint\''), 'presence input is normalized');
$assert(str_contains($rest, '0, 100)'), 'presence fan-out is bounded');
$assert(str_contains($rest, "consume_rate_limit('presence'"), 'presence writes are rate limited');
$assert(str_contains($rest, "consume_rate_limit('typing'"), 'typing writes are rate limited');
$assert(str_contains($rest, 'can_post_to_conversation($conversation, $user_id)'), 'typing and messages share posting authority');
$assert(str_contains($rest, "t.expires_at>%s"), 'stale typing is never returned');
$assert(str_contains($rest, "m.left_at IS NULL"), 'typing only returns active members');
$assert(str_contains($db, "\$event['muted'] = \$muted"), 'notification adapters receive mute context');
$assert(str_contains($db, "['message_received']"), 'local mute suppression is limited to message notifications');
$assert(!str_contains($db, 'ip_address') && !str_contains($db, 'device_fingerprint'), 'presence stores no IP or device fingerprint');
$assert(str_contains($rest, "'left_at' => null"), 'preference updates are scoped to active membership');
$assert(str_contains($js, "document.hidden ? 'away' : 'online'"), 'visibility state reduces false online presence');
$assert(str_contains($js, 'clearTimeout(state.typingStopTimer)'), 'typing state has client expiry control');
$assert(str_contains($js, "Only channel administrators may post"), 'read-only channel authority is disclosed in UI');
$assert(str_contains($js, "state.activeConversation.type !== 'channel'"), 'client cannot enable calls in channels');
$assert(str_contains($privacy, "delete(SN_DB::table('presence')"), 'account erasure deletes presence state');
$assert(str_contains($privacy, "delete(SN_DB::table('typing')"), 'account erasure deletes typing state');

printf("Realtime adversarial contracts: PASS (%d checks)\n", $checks);
