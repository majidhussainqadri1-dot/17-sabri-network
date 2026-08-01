<?php
/** Static contract review for realtime state and conversation preferences. */
$root = dirname(__DIR__);
$files = [
    'db' => file_get_contents($root . '/includes/class-sn-db.php'),
    'policy' => file_get_contents($root . '/includes/class-sn-policy.php'),
    'rest' => file_get_contents($root . '/includes/class-sn-rest.php'),
    'js' => file_get_contents($root . '/assets/js/network.js'),
    'css' => file_get_contents($root . '/assets/css/network.css'),
    'privacy' => file_get_contents($root . '/includes/class-sn-privacy.php'),
];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($files['db'], "DB_VERSION = '2.0.2'"), 'schema version includes realtime and report-safety tables');
$assert(str_contains($files['db'], "table('presence')"), 'presence table is declared');
$assert(str_contains($files['db'], "PRIMARY KEY (user_id)"), 'one canonical presence row per user');
$assert(str_contains($files['db'], "table('typing')"), 'typing table is declared');
$assert(str_contains($files['db'], 'UNIQUE KEY conversation_user (conversation_id,user_id)'), 'one typing row per member and conversation');
$assert(str_contains($files['db'], "DELETE FROM ' . self::table('typing')"), 'expired typing state is cleaned');
$assert(str_contains($files['db'], "SET status='offline'"), 'expired presence becomes offline');
$assert(str_contains($files['db'], 'member_preferences('), 'member preference helper exists');
$assert(str_contains($files['db'], 'share_active_conversation('), 'presence scope can use a shared active conversation');
$assert(str_contains($files['rest'], "'/presence'"), 'presence REST route exists');
$assert(str_contains($files['rest'], "'/conversations/(?P<id>\\d+)/typing'"), 'typing REST route exists');
$assert(str_contains($files['rest'], "'/conversations/(?P<id>\\d+)/preferences'"), 'conversation preference route exists');
$assert(str_contains($files['rest'], 'heartbeat_presence('), 'presence heartbeat handler exists');
$assert(str_contains($files['rest'], 'get_presence('), 'presence read handler exists');
$assert(str_contains($files['rest'], 'set_typing('), 'typing mutation handler exists');
$assert(str_contains($files['rest'], 'get_typing('), 'typing read handler exists');
$assert(str_contains($files['rest'], 'update_conversation_preferences('), 'preference mutation handler exists');
$assert(str_contains($files['rest'], "'muted' =>"), 'conversation projection includes mute state');
$assert(str_contains($files['rest'], "'archived' =>"), 'conversation projection includes archive state');
$assert(str_contains($files['rest'], "'can_post' =>"), 'conversation projection includes posting authority');
$assert(str_contains($files['rest'], "m.is_muted,m.is_archived"), 'conversation inventory reads native member preferences');
$assert(str_contains($files['policy'], 'can_post_to_conversation('), 'central posting policy exists');
$assert(str_contains($files['policy'], 'can_view_presence('), 'central presence visibility policy exists');
$assert(str_contains($files['policy'], "'last_seen'"), 'last-seen privacy is modeled');
$assert(str_contains($files['js'], 'startPresenceHeartbeat'), 'client starts bounded presence heartbeats');
$assert(str_contains($files['js'], 'loadConversationPresence'), 'client reads scoped presence');
$assert(str_contains($files['js'], 'loadTyping'), 'client reads typing state');
$assert(str_contains($files['js'], 'setTyping'), 'client emits typing state');
$assert(str_contains($files['js'], 'updateConversationPreference'), 'client updates mute/archive state');
$assert(str_contains($files['js'], 'showArchived'), 'client has an archived-conversation view');
$assert(str_contains($files['css'], '.sn-archive-toggle'), 'archive control is styled');
$assert(str_contains($files['css'], '.sn-conversation-preferences'), 'conversation preference controls are styled');
$assert(str_contains($files['css'], '.sn-composer textarea:disabled'), 'read-only channel composer state is visible');
$assert(str_contains($files['privacy'], 'sabri-network-memberships'), 'conversation preferences are included in privacy export');
$assert(str_contains($files['privacy'], "table('presence')"), 'presence is included in privacy export and erasure');
$assert(str_contains($files['privacy'], "table('typing')"), 'typing state is erased with the account');
$assert(str_contains($files['js'], '{...state.activeConversation, ...summary}'), 'poll refresh preserves detailed active-conversation state');

printf("Realtime static contracts: PASS (%d checks)\n", $checks);
