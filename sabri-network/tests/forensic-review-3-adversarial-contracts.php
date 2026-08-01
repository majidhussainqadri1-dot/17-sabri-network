<?php
/** Fresh negative-path review for third-pass findings. */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
function fr3_adv_check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}
function fr3_adv_content(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $relative);
    }
    return (string) file_get_contents($path);
}

$main = fr3_adv_content('sabri-network.php');
$db = fr3_adv_content('includes/class-sn-db.php');
$rest = fr3_adv_content('includes/class-sn-rest.php');
$safety = fr3_adv_content('includes/class-sn-safety.php');
$rateStart = strpos($db, 'public static function consume_rate_limit');
$rateEnd = strpos($db, 'public static function audit', $rateStart);
$rate = substr($db, $rateStart, $rateEnd - $rateStart);

fr3_adv_check(!str_contains($main, "contacts/{user_id}"), 'The integration contract must not advertise a nonexistent contact endpoint.');
fr3_adv_check(!str_contains($main, "blocks/{user_id}"), 'The integration contract must not advertise a nonexistent plural block endpoint.');
fr3_adv_check(!preg_match('/SELECT\s+\*\s+FROM.+rate_limits/s', $rate), 'Rate limiting must not depend on a stale pre-update counter read.');
fr3_adv_check(!str_contains($rate, '$wpdb->replace'), 'Concurrent first hits or expiry rollover must not reset one another through REPLACE.');
fr3_adv_check(str_contains($rate, 'hits<%d') && str_contains($rate, 'return $updated === 1;'), 'A request must be allowed only when the atomic counter mutation succeeds.');
fr3_adv_check(!str_contains($rest, "['last_message_id' => \$message_id, 'updated_at' => \$now]"), 'A slower message request must not overwrite a newer conversation pointer.');
fr3_adv_check(!str_contains($db, "apply_filters('sn_network_user_is_minor', false, (int) \$update->user_id)"), 'Public-update attachment access must not bypass canonical unknown/minor handling.');
fr3_adv_check(!str_contains($safety, "\$stored !== ''"), 'An empty existing retention-lock value must be treated as malformed rather than active forever.');

if ($failures) {
    fwrite(STDERR, "Forensic review 3 adversarial failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}
printf("Forensic review 3 adversarial contracts: PASS (%d checks)\n", $checks);
