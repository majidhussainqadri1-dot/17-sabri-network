<?php
/** Round 9 permanent regression: fail-closed cleanup, privacy, governance and outbox truth. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) {
        fwrite(STDERR, "Unable to read $path\n");
        exit(1);
    }
    return $value;
};
$must = static function (bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
};

$db = $read('includes/class-sn-db.php');
$safety = $read('includes/class-sn-safety-runtime-hardening.php');
$high = $read('includes/class-sn-high-risk.php');
$outbox = $read('includes/class-sn-outbox.php');

$cleanup = strstr($db, 'public static function cleanup_expired');
$must(is_string($cleanup), 'R9 cleanup owner remains present');
$must(str_contains($cleanup, "query('START TRANSACTION') === false"), 'R9-D01 cleanup checks transaction start');
$must(str_contains($cleanup, "query('COMMIT') === false"), 'R9-D01 cleanup checks commit result');
$must(str_contains($cleanup, 'expired_update_cleanup_commit_failed'), 'R9-D01 commit failure is explicit');
$must(str_contains($cleanup, "'reason' => 'transaction_start_failed'"), 'R9-D01 start failure is audited');
$must(str_contains($cleanup, "'reason' => $e->getMessage()"), 'R9-D01 cleanup failure reason is audited');

$must(str_contains($safety, "$wpdb->last_error='';"), 'R9-D02 safety retained read clears database error state');
$must(str_contains($safety, '$retained_raw=$wpdb->get_var'), 'R9-D02 safety preserves raw retained count truth');
$must(str_contains($safety, "$wpdb->last_error!==''||$retained_raw===null"), 'R9-D02 safety rejects failed retained count read');
$must(str_contains($safety, "'read_failed'=>true"), 'R9-D02 safety exposes retryable retained-read failure');

$must(str_contains($high, 'public static function list_actions(WP_REST_Request $request): WP_REST_Response|WP_Error'), 'R9-D03 high-risk queue can fail closed');
$must(str_contains($high, "$wpdb->last_error = '';"), 'R9-D03 high-risk queue clears database error state');
$must(str_contains($high, 'sn_high_risk_queue_unavailable'), 'R9-D03 high-risk queue returns explicit unavailable error');
$must(str_contains($high, "!is_array($rows)"), 'R9-D03 invalid high-risk result cannot become empty success');

$must(str_contains($outbox, 'public static function admin_events(WP_REST_Request $request): WP_REST_Response|WP_Error'), 'R9-D04 outbox admin queue can fail closed');
$must(str_contains($outbox, 'outbox_queue_unavailable'), 'R9-D04 outbox admin read has explicit unavailable error');
$must(str_contains($outbox, '$read_error=false'), 'R9-D04 outbox health tracks authoritative read failure');
$must(str_contains($outbox, "'database_read_error'=>$read_error"), 'R9-D04 health exposes database read failure state');
$must(str_contains($outbox, '$outbox_exists&&$inbox_exists&&!$read_error'), 'R9-D04 health cannot be OK after count-read failure');

echo "Another fresh R9 operational-truth contracts: PASS\n";
