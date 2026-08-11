<?php
/** Standalone static contract checks; WordPress is intentionally not loaded. */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function content(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: $relative");
    }
    return (string) file_get_contents($path);
}

$main = content('sabri-network.php');
$db = content('includes/class-sn-db.php');
$policy = content('includes/class-sn-policy.php');
$rest = content('includes/class-sn-rest.php');
$auth = content('includes/class-sn-auth.php');
$files = content('includes/class-sn-private-files.php');
$privacy = content('includes/class-sn-privacy.php');
$activator = content('includes/class-sn-activator.php');
$ajax = content('includes/class-sn-ajax.php');
$template = content('templates/network-app.php');
$js = content('assets/js/network.js');
$css = content('assets/css/network.css');
$allRuntime = implode("\n", [$main, $db, $policy, $rest, $auth, $files, $privacy, $activator, $ajax, $template, $js]);

check(str_contains($main, 'Version: 2.1.0'), 'Plugin header must declare version 2.1.0.');
check(str_contains($main, "define('SN_VERSION', '2.1.0')"), 'SN_VERSION must be 2.1.0.');
check(str_contains($rest, "private const NS = 'sabri-network/v2'"), 'REST API must use the v2 namespace.');
check(!str_contains($allRuntime, 'wp_ajax_nopriv_'), 'Authenticated Network actions must not register nopriv AJAX routes.');
check(!preg_match('/register_rest_route\s*\([^\n]+otp/i', $rest), 'File 17 must not expose OTP REST routes.');
check(!preg_match('/<[^>]+(?:otp|verification[-_ ]?code)/i', $template), 'File 17 UI must not contain OTP/account-verification forms.');
check(str_contains($activator, 'sn_sms_webhook_url') && str_contains($activator, 'delete_option'), 'Legacy SMS secrets must be retired.');
check(str_contains($db, 'DROP TABLE IF EXISTS') && str_contains($db, 'sn_phone_otps'), 'Legacy OTP table must be removed during controlled upgrade.');
check(!str_contains($auth, "get_option('sn_turn_credential'"), 'Long-lived TURN credentials must not be read from options.');
check(str_contains($auth, 'sn_network_ephemeral_turn_credentials'), 'TURN credentials must come from the ephemeral adapter.');
check(str_contains($policy, 'identity_authority_unavailable'), 'Missing identity authority must fail closed.');
check(str_contains($policy, 'guardian_consent_required'), 'Minor contact must enforce guardian consent.');
check(str_contains($policy, 'contact_required'), 'Messages and calls must require accepted contact consent.');
check(str_contains($rest, "'permission_callback'"), 'REST routes must declare permission callbacks.');
check(str_contains($rest, 'idempotency_key'), 'Message submission must use idempotency.');
check(str_contains($db, 'UNIQUE KEY idempotency_key'), 'Message idempotency must be database-enforced.');
check(str_contains($db, 'UNIQUE KEY direct_key'), 'Direct conversation uniqueness must be database-enforced.');
check(str_contains($db, 'UNIQUE KEY pair_key'), 'Contact-pair uniqueness must be database-enforced.');
check(str_contains($db, 'UNIQUE KEY active_key'), 'Active-call uniqueness must be database-enforced.');
check(str_contains($rest, "'active_key' => hash('sha256', 'conversation:'"), 'Call creation must set the active-call key.');
check(str_contains($rest, "'active_key' => null"), 'Call end must release the active-call key.');
check(str_contains($rest, '/signals/ack'), 'WebRTC signals must support acknowledgement.');
check(str_contains($rest, 'call_signal_forbidden'), 'WebRTC signal state must be validated.');
check(str_contains($rest, 'invalid_call_transition'), 'Call member state transitions must be validated.');
check(str_contains($rest, 'invalid_report_target'), 'Reports must validate their target.');
check(str_contains($rest, 'Deleted messages cannot receive new reactions.'), 'Deleted messages must reject new reactions.');
check(str_contains($files, 'dirname(untrailingslashit(ABSPATH))'), 'Private storage must default outside the WordPress root.');
check(str_contains($files, 'is_inside_web_root'), 'Private storage must reject unsafe web-root placement.');
check(str_contains($files, 'sn_network_attachment_scan_result'), 'Attachment malware-scanner contract must exist.');
check(str_contains($files, 'scanner_required'), 'Documents must fail closed when scanner evidence is unavailable.');
check(str_contains($files, "header('Cache-Control: private, no-store"), 'Private files must use no-store delivery.');
check(str_contains($files, 'wp_verify_nonce') && str_contains($files, 'user_can_access_attachment'), 'Private file delivery must require nonce and object authorization.');
check(!str_contains($allRuntime, 'media_handle_upload') && !str_contains($allRuntime, 'wp_insert_attachment'), 'New private messages must not store files in the public Media Library.');
check(str_contains($rest, 'Legacy attachment requires controlled migration'), 'Legacy public attachments must be withheld pending migration.');
check(str_contains($privacy, 'wp_privacy_personal_data_exporters'), 'Privacy exporter must be registered.');
check(str_contains($privacy, 'wp_privacy_personal_data_erasers'), 'Privacy eraser must be registered.');
check(str_contains($main, 'sn_network_route_registered'), 'File 17 must publish a File-20 route contract.');
check(!str_contains($main, 'wp_nav_menu_items'), 'File 17 must not inject duplicate global navigation.');
check(str_contains($activator, 'is_owned_page'), 'Network page repair must be ownership-gated.');
check(str_contains($rest, "(string) \$row->status !== 'pending'"), 'Contact decisions must be pending-only.');
check(str_contains($rest, 'owner_removal_forbidden'), 'Conversation owner must not leave without ownership transfer.');
check(!str_contains($js, 'sendBeacon'), 'Call state must not use unauthenticated sendBeacon requests.');
check(str_contains($js, "endLocalCall(false)"), 'Remote bye must still close and update the local call state.');
check(str_contains($js, 'modalReturnFocus') && str_contains($js, "event.key === 'Tab'"), 'Modal focus must be contained and restored.');
check(str_contains($js, "item.classList.add('is-visible')"), 'Toast visibility state must be applied.');
check(str_contains($css, '#ff8a1f'), 'Sabri Orange secondary design token remains present for compatibility/accent use.');
check(str_contains($css, ':focus-visible'), 'Keyboard focus indicator must be present.');
check(str_contains($css, 'prefers-reduced-motion'), 'Reduced-motion support must be present.');
check(str_contains($css, '[dir="rtl"]'), 'RTL support must be present.');
check(!preg_match('/\b(?:var_dump|print_r|error_log)\s*\(/', $allRuntime), 'Debug output/logging must not remain in production runtime.');
check(!preg_match('/\b(?:eval|shell_exec|passthru|proc_open|popen)\s*\(/', $allRuntime), 'Dangerous dynamic execution functions must not be used.');
check(!str_contains($allRuntime, '100% Secure') && !str_contains($allRuntime, 'End-to-End Encrypted'), 'Unsupported security claims must not appear.');
check(str_contains($rest, "/conversations/(?P<id>\d+)/owner") && str_contains($rest, 'transfer_conversation_owner'), 'Group ownership transfer must have an authenticated REST contract.');
check(str_contains($rest, 'conversation_owner_transferred') && str_contains($rest, 'SELECT * FROM $conversations WHERE id=%d FOR UPDATE'), 'Ownership transfer must be audited and lock the canonical conversation row.');
check(str_contains($main, 'SN_Activator::ensure_cleanup_schedule()'), 'Plugin upgrades must repair the cleanup schedule without requiring reactivation.');
check(strpos($files, '// Revoke authorization before touching bytes') < strpos($files, '$bytes_deleted ='), 'Attachment authorization must be revoked before file bytes are deleted.');
check(str_contains($files, "\$chunk === false || \$chunk === ''"), 'Private-file streaming must stop on a zero-length read.');
check(str_contains($files, '$resolved = realpath($dir);'), 'Private storage validation must resolve an existing directory symlink itself.');
check(str_contains($db, 'private_attachment_is_referenced') && strpos($db, "DELETE FROM ' . self::table('updates')") < strpos($db, 'SN_Private_Files::delete($attachment_id'), 'Expired updates must be deleted before unreferenced private bytes.');
check(str_contains($js, 'Number.isNaN(date.getTime())') && str_contains($js, 'sn-owner-transfer-form'), 'Client date formatting and ownership-transfer UI must handle failure paths safely.');

if ($failures) {
    fwrite(STDERR, "Static contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) fwrite(STDERR, " - $failure\n");
    exit(1);
}

echo "Static contracts: PASS ($checks checks)\n";