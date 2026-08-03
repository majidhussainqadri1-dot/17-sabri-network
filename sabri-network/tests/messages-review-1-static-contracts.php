<?php
/** Messages review round 1: dedicated surfaces, routing and receipt-domain contracts. */
$root = dirname(__DIR__);
$messages = file_get_contents($root . '/includes/class-sn-messages.php');
$main = file_get_contents($root . '/sabri-network.php');
$activator = file_get_contents($root . '/includes/class-sn-activator.php');
$template = file_get_contents($root . '/templates/messages-app.php');
$settings = file_get_contents($root . '/templates/communication-settings.php');
$standalone = file_get_contents($root . '/templates/messages-standalone.php');
$js = file_get_contents($root . '/assets/js/messages.js');
$css = file_get_contents($root . '/assets/css/messages.css');
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($main, "require_once SN_DIR . 'includes/class-sn-messages.php'"), 'The dedicated Messages runtime must be loaded by File 17.');
$check(str_contains($main, 'SN_Messages::register()'), 'Messages hooks must be registered by the canonical File-17 plugin.');
$check(str_contains($activator, 'SN_Messages::install()'), 'Activation must install the receipt domain.');
$check(str_contains($activator, 'SN_Messages::register_rewrites()'), 'Activation must materialize Messages rewrites before flushing.');
$check(str_contains($activator, 'SN_Messages::ensure_pages()'), 'Activation must create only File-17-owned Messages/settings pages.');
$check(str_contains($messages, "add_shortcode('sabri_messages'"), 'A dedicated Messages shortcode must exist.');
$check(str_contains($messages, "add_shortcode('sabri_communication_settings'"), 'A dedicated communication-settings shortcode must exist.');
$check(str_contains($messages, "add_rewrite_rule('^messages/([1-9][0-9]*)/?$'"), 'Conversation deep links must use the canonical /messages/{id}/ surface.');
$check(str_contains($messages, "add_rewrite_rule('^messages-safe/?$'"), 'Messages must have a repair-safe route.');
$check(str_contains($messages, "add_rewrite_rule('^communication-settings-safe/?$'"), 'Communication settings must have a repair-safe route.');
$check(str_contains($messages, "private const PAGE_OWNER_META = '_sn_messages_owned'"), 'Owned pages must carry a File-17-specific ownership marker.');
$check(str_contains($messages, "'sn_messages_page_id'"), 'Messages page identity must be persisted.');
$check(str_contains($messages, "'sn_communication_settings_page_id'"), 'Communication-settings page identity must be persisted.');
$check(str_contains($messages, "header('X-Robots-Tag: noindex, noarchive'"), 'Private Messages surfaces must be noindex/noarchive.');
$check(str_contains($messages, "define('DONOTCACHEPAGE', true)"), 'Private Messages surfaces must disable page caching.');
$check(str_contains($messages, 'CREATE TABLE $table'), 'The native message-receipt table must be installable.');
$check(str_contains($messages, 'UNIQUE KEY message_user_device (message_id,user_id,device_key)'), 'Receipt writes must be idempotent per message, recipient and device.');
$check(str_contains($messages, "'/conversations/(?P<id>\\d+)/receipts'"), 'The versioned conversation receipt route must exist.');
$check(str_contains($messages, 'SN_DB::is_member($conversation_id, $user_id)'), 'Receipt access must revalidate active conversation membership server-side.');
$check(str_contains($messages, 'if ((int) $target->sender_id === $user_id)'), 'A sender must not forge a recipient receipt for their own message.');
$check(str_contains($messages, 'WHERE m.conversation_id=%d AND m.sender_id=%d'), 'Receipt summaries must be restricted to messages sent by the requesting user.');
$check(str_contains($template, 'id="snm-conversation-list"') && str_contains($template, 'id="snm-message-list"'), 'The Messages surface must provide separate conversation and message regions.');
$check(str_contains($settings, 'id="snm-settings-form"') && str_contains($settings, 'name="messages"') && str_contains($settings, 'name="calls"'), 'Communication settings must expose canonical privacy controls.');
$check(str_contains($standalone, "SN_Messages::render_settings()") && str_contains($standalone, "SN_Messages::render_messages()"), 'The standalone route must render only the requested Messages-owned mode.');
$check(str_contains($js, "api('conversations')") && str_contains($js, "'/messages?limit=100'"), 'The UI must consume the existing canonical conversation/message APIs rather than create a parallel backend.');
$check(str_contains($js, "'/receipts'"), 'The UI must reconcile native receipt state.');
$check(str_contains($messages, 'requested_message_id') && str_contains($messages, 'through_message_id') && str_contains($messages, "'more' => \$more"), 'Receipt batching must expose requested, actual-through and continuation state.');
$check(str_contains($js, 'for (let batch = 0; batch < 20; batch += 1)') && str_contains($js, 'Boolean(result.more)'), 'The client must continue bounded receipt batches without assuming one request covers the full history.');
$check(str_contains($css, '@media (max-width: 900px)') && str_contains($css, 'min-height: 44px'), 'Messages UI must provide responsive layouts and minimum touch targets.');

if ($failures) {
    fwrite(STDERR, "Messages review round 1 failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Messages review round 1 static contracts: PASS ($checks checks)\n";
