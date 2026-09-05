<?php
/** Next fresh 10-round cycle: Round-9 permanent correction contracts. */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = [];
$checks = 0;
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};

$r9 = $read('includes/class-sn-r9-runtime-hardening.php');
$loader = $read('includes/class-sn-future24-review-hardening.php');

$check(str_contains($loader, "class-sn-r9-runtime-hardening.php") && str_contains($loader, 'SN_R9_Runtime_Hardening::register()'), 'R9 correction must be loaded and registered.');
$check(str_contains($r9, "add_action('rest_api_init', [self::class, 'override_routes'], 3500)"), 'R9 transaction routes must be final after earlier Future24 owners.');
$check(str_contains($r9, "'/calls/(?P<id>\\d+)/hand-raise'") && str_contains($r9, 'SN_Future24_Review_Hardening_D::hand_raise($request)'), 'R9 must guard final hand-raise transaction entry.');
$check(str_contains($r9, "'/calls/(?P<id>\\d+)/speaker-queue'") && str_contains($r9, 'SN_Future24_Review_Hardening_D::manage_speaker_queue($request)'), 'R9 must guard final speaker-queue mutation entry.');
$check(str_contains($r9, "'/future/templates/(?P<id>\\d+)'") && str_contains($r9, 'SN_Future24_Review_Hardening_N::update_template($request)') && str_contains($r9, 'SN_Future24_Review_Hardening_N::delete_template($request)'), 'R9 must guard final template update/delete transaction entries.');
$check(str_contains($r9, 'new SN_R6_WPDB_Guard($original)') && str_contains($r9, 'finally') && str_contains($r9, '$wpdb = $original;'), 'R9 transaction failure promotion must be request-scoped and restore wpdb.');
$check(str_contains($r9, "add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_future_eraser'], 9700)"), 'R9 Future eraser must replace the sixth-cycle callback before the global wrapper.');
$check(str_contains($r9, "sn_future_device_keys") && str_contains($r9, 'DEVICE_KEY_BATCH') && str_contains($r9, "device_key_delete_failed"), 'R9 final Future eraser must perform bounded checked device-key deletion.');
$check(str_contains($r9, "'done'=>!$more_keys") && str_contains($r9, 'Append-only key-transparency integrity entries were retained'), 'R9 privacy receipt must not finish while device keys remain and must disclose retained transparency history.');
$check(str_contains($r9, "remove_action('sn_cleanup_hourly', [SN_Future24_Review_Hardening_O::class, 'bulk_job_preflight'], 0)") && str_contains($r9, "add_action('sn_cleanup_hourly', [self::class, 'bulk_job_preflight'], 0)"), 'R9 must replace the unchecked bulk scheduler recovery owner.');
$check(substr_count($r9, '$wpdb->query($query) === false') >= 1 && str_contains($r9, 'future_bulk_recovery_failed'), 'R9 bulk scheduler recovery must detect and audit DB failure.');

if ($fail) {
    fwrite(STDERR, "Next R9 contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "Next R9 contracts: PASS ($checks checks)\n";
