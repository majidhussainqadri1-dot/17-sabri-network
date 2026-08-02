<?php
/** Third-pass static contracts for newly discovered integration and concurrency defects. */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$content = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $relative);
    }
    return (string) file_get_contents($path);
};

$main = $content('sabri-network.php');
$db = $content('includes/class-sn-db.php');
$rest = $content('includes/class-sn-rest.php');
$safety = $content('includes/class-sn-safety.php');
$rateStart = strpos($db, 'public static function consume_rate_limit');
$rateEnd = strpos($db, 'public static function audit', $rateStart);
$rate = substr($db, $rateStart, $rateEnd - $rateStart);

$check(str_contains($main, "'contact_route' => rest_url('sabri-network/v2/contacts')"), 'The published contact route must point to the actual contact-request collection endpoint.');
$check(str_contains($main, "'contact_decision_route' => rest_url('sabri-network/v2/contacts/{request_id}')"), 'The integration contract must expose the actual request-ID decision route separately.');
$check(str_contains($main, "'block_route' => rest_url('sabri-network/v2/block')"), 'The published block route must point to the actual singular /block endpoint.');
$check(str_contains($main, "'contact_request_method' => 'POST'") && str_contains($main, "'block_method' => 'POST'"), 'Mutating integration routes must publish their HTTP methods.');
$check(str_contains($rate, 'INSERT IGNORE INTO $table') && str_contains($rate, 'hits=IF(expires_at<=%s,1,hits+1)'), 'Rate limits must initialize once and mutate through an atomic conditional update.');
$check(str_contains($rate, 'AND (expires_at<=%s OR hits<%d)'), 'The atomic rate update must enforce expiry reset or a below-limit ceiling in SQL.');
$check(!str_contains($rate, 'get_row(') && !str_contains($rate, '->replace('), 'Rate limiting must not use the former read-then-replace counter reset path.');
$check(str_contains($rest, 'last_message_id=GREATEST(last_message_id,%d)') && str_contains($rest, 'updated_at=GREATEST(updated_at,%s)'), 'Conversation message pointers and timestamps must be monotonic under concurrent sends.');
$check(str_contains($db, "privacy === 'public' && SN_Policy::has_verified_adult_age"), 'Private attachment authorization must require an explicit verified-adult age state for public updates.');
$check(!str_contains($safety, "\$stored !== ''\n            &&"), 'An existing empty malformed retention lock must not become permanently unrecoverable.');

if ($failures) {
    fwrite(STDERR, "Forensic review 3 static failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}
printf("Forensic review 3 static contracts: PASS (%d checks)\n", $checks);
