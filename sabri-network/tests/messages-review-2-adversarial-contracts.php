<?php
/** Messages review round 2: fresh adversarial privacy, concurrency and UI review. */
$root = dirname(__DIR__);
$messages = file_get_contents($root . '/includes/class-sn-messages.php');
$main = file_get_contents($root . '/sabri-network.php');
$js = file_get_contents($root . '/assets/js/messages.js');
$css = file_get_contents($root . '/assets/css/messages.css');
$template = file_get_contents($root . '/templates/messages-app.php');
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($messages, "preg_match('/^[A-Za-z0-9._:-]{8,128}$/'"), 'Device identifiers must be syntactically bounded before use.');
$check(str_contains($messages, "return hash('sha256', \$user_id . ':' . \$device_id)"), 'Raw device identifiers must be replaced by user-scoped cryptographic keys.');
$check(!str_contains($messages, "'device_id' =>") && !str_contains($messages, 'device_id VARCHAR'), 'Raw device identifiers must not be stored or exported.');
$check(str_contains($messages, 'private const MAX_RECEIPT_RANGE = 500'), 'Receipt range writes must be explicitly bounded.');
$check(str_contains($messages, 'private const MAX_RECEIPT_SUMMARIES = 100'), 'Receipt summary reads must be explicitly bounded.');
$check(str_contains($messages, "SN_Policy::consume_rate_limit('message_receipt'"), 'Receipt writes must be rate limited.');
$check(str_contains($messages, "SN_Policy::consume_rate_limit('message_receipt_read'"), 'Receipt summary reads must be rate limited.');
$check(str_contains($messages, "deleted_at IS NULL"), 'Deleted messages must be excluded from new receipts and summaries.');
$check(str_contains($messages, 'START TRANSACTION') && str_contains($messages, 'ROLLBACK') && str_contains($messages, 'COMMIT'), 'Multi-message receipt reconciliation must be transactional.');
$check(str_contains($messages, 'delivered_at=COALESCE(delivered_at,VALUES(delivered_at))'), 'Delivered timestamps must be monotonic and not overwritten by retries.');
$check(str_contains($messages, 'read_at=COALESCE(read_at,VALUES(read_at))'), 'Read timestamps must be monotonic and not overwritten by retries.');
$check(str_contains($messages, 'last_read_message_id=GREATEST(last_read_message_id,%d)'), 'Read pointers must never move backwards.');
$check(str_contains($messages, 'id>%d AND id<=%d') && str_contains($messages, 'ORDER BY id ASC LIMIT %d'), 'Receipt ranges must advance contiguously from device progress instead of jumping to the newest bounded slice.');
$check(str_contains($messages, '$through_message_id,') && !str_contains($messages, "last_read_message_id=GREATEST(last_read_message_id,%d) WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL',\n                    \$message_id"), 'The member read pointer must advance only through the actual reconciled batch.');
$check(str_contains($messages, 'SELECT COALESCE(MAX(message_id),0)') && str_contains($messages, '$state_column IS NOT NULL'), 'Each device and receipt state must resume from durable server-side progress.');
$check(str_contains($messages, "'message_receipt_failed'"), 'Failed receipt transactions must produce native audit evidence.');
$check(str_contains($messages, "'message_receipt_recorded'"), 'Successful receipt transactions must produce native audit evidence.');
$check(str_contains($messages, 'COUNT(DISTINCT CASE WHEN r.delivered_at IS NOT NULL THEN r.user_id END)'), 'Multiple devices must reconcile to one recipient in sender-visible counts.');
$check(str_contains($messages, 'COUNT(DISTINCT CASE WHEN r.read_at IS NOT NULL THEN r.user_id END)'), 'Read summaries must not double-count multiple devices.');
$check(str_contains($messages, 'wp_privacy_personal_data_exporters'), 'Receipt metadata must participate in WordPress privacy export.');
$check(str_contains($messages, 'wp_privacy_personal_data_erasers'), 'Receipt metadata must participate in WordPress privacy erasure.');
$check(str_contains($messages, 'LEFT JOIN $messages m ON m.id=r.message_id'), 'Bounded cleanup must remove orphaned/deleted-message receipt metadata.');
$check(str_contains($messages, "'sn_messages_page_conflict_'"), 'Owned-page creation must fail safely instead of overwriting unrelated pages.');
$check(str_contains($js, 'textContent =') && !str_contains($js, '.innerHTML ='), 'Untrusted message/profile content must be rendered with textContent, not innerHTML.');
$check(str_contains($js, 'url.origin !== window.location.origin'), 'Private attachment links must be constrained to the current origin.');
$check(str_contains($js, "document.visibilityState !== 'visible'"), 'Read receipts must not be emitted from a hidden document.');
$check(str_contains($js, "event.key === 'Escape'") && str_contains($js, 'state.modalReturnFocus.focus()'), 'The contact modal must close by Escape and restore keyboard focus.');
$check(str_contains($js, "event.key !== 'Tab'") && str_contains($js, 'last.focus()') && str_contains($js, 'first.focus()'), 'Keyboard focus must remain inside the open modal.');
$check(str_contains($js, 'window.localStorage.getItem(key)') && str_contains($js, 'window.crypto.randomUUID'), 'Client device identity must be opaque, persistent when possible and cryptographically generated.');
$check(str_contains($js, "credentials: 'same-origin'") && str_contains($js, "'X-WP-Nonce'"), 'Messages requests must use same-origin credentials and WordPress REST nonce protection.');
$check(str_contains($template, 'File 17 does not create accounts or bypass the platform identity authority.'), 'The login surface must preserve truthful identity ownership.');
$check(str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'The Messages experience must honor reduced-motion preferences.');
$check(str_contains($css, 'html[dir="rtl"]'), 'The Messages experience must include explicit RTL behavior.');
$check(!str_contains($main, 'End-to-End Encrypted') && !str_contains($main, '100% Secure'), 'This batch must not introduce unsupported encryption or absolute-security claims.');
$check(!str_contains($messages, 'wp_nav_menu') && !str_contains($messages, 'add_menu_page'), 'Dedicated Messages surfaces must not create a second global shell or navigation authority.');

if ($failures) {
    fwrite(STDERR, "Messages review round 2 failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Messages review round 2 adversarial contracts: PASS ($checks checks)\n";
