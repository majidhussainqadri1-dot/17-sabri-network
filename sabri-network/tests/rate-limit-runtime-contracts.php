<?php
/** Behavioral model for the database-atomic File-17 rate limiter. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function apply_filters(string $hook_name, $value, ...$args) { return $value; }
function sanitize_key(string $key): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $key)); }
function wp_salt(string $scheme = 'auth'): string { return 'rate-limit-test-salt-' . $scheme; }

final class SN_Rate_Limit_Test_DB {
    public string $prefix = 'wp_';
    public array $rows = [];
    public bool $fail_insert = false;
    public bool $fail_update = false;
    public array $queries = [];

    public function prepare(string $query, ...$args): array {
        return ['query' => $query, 'args' => $args];
    }

    public function query($prepared): int|false {
        $query = (string) ($prepared['query'] ?? '');
        $args = (array) ($prepared['args'] ?? []);
        $this->queries[] = $query;
        if (str_starts_with(ltrim($query), 'INSERT IGNORE')) {
            if ($this->fail_insert) {
                return false;
            }
            [$bucket, $hash, $started, $expires] = $args;
            $key = $bucket . ':' . $hash;
            if (isset($this->rows[$key])) {
                return 0;
            }
            $this->rows[$key] = ['hits' => 0, 'window_started_at' => $started, 'expires_at' => $expires];
            return 1;
        }
        if (str_starts_with(ltrim($query), 'UPDATE')) {
            if ($this->fail_update) {
                return false;
            }
            [$now1, $now2, $newStarted, $now3, $newExpires, $bucket, $hash, $conditionNow, $limit] = $args;
            $key = $bucket . ':' . $hash;
            if (!isset($this->rows[$key])) {
                return 0;
            }
            $row = $this->rows[$key];
            $expired = (string) $row['expires_at'] <= (string) $conditionNow;
            if (!$expired && (int) $row['hits'] >= (int) $limit) {
                return 0;
            }
            $row['hits'] = $expired ? 1 : (int) $row['hits'] + 1;
            if ($expired) {
                $row['window_started_at'] = $newStarted;
                $row['expires_at'] = $newExpires;
            }
            $this->rows[$key] = $row;
            return 1;
        }
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}

$wpdb = new SN_Rate_Limit_Test_DB();
require dirname(__DIR__) . '/includes/class-sn-db.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$hash = hash_hmac('sha256', 'user-7', wp_salt('nonce'));
$key = 'message_send:' . $hash;
$check(SN_DB::consume_rate_limit('message_send', 'user-7', 2, 60), 'The first hit in a new window must succeed.');
$check(SN_DB::consume_rate_limit('message_send', 'user-7', 2, 60), 'The second hit below the ceiling must succeed.');
$check(!SN_DB::consume_rate_limit('message_send', 'user-7', 2, 60), 'A hit at the configured ceiling must fail closed.');
$check((int) $wpdb->rows[$key]['hits'] === 2, 'Denied hits must not increment or reset the active counter.');

$wpdb->rows[$key] = ['hits' => 99, 'window_started_at' => '1999-01-01 00:00:00', 'expires_at' => '2000-01-01 00:00:00'];
$check(SN_DB::consume_rate_limit('message_send', 'user-7', 3, 60), 'The first hit after expiry must atomically open a new window.');
$check((int) $wpdb->rows[$key]['hits'] === 1, 'Expired counters must restart at exactly one rather than preserve or reset concurrent hits.');
$check(SN_DB::consume_rate_limit('message_send', 'user-7', 3, 60), 'A second hit in the renewed window must increment rather than reset it.');
$check((int) $wpdb->rows[$key]['hits'] === 2, 'Renewed-window hits must be monotonic.');

$wpdb = new SN_Rate_Limit_Test_DB();
$wpdb->fail_insert = true;
$check(!SN_DB::consume_rate_limit('report_global', 'user-9', 10, 60), 'Counter initialization errors must fail closed.');
$wpdb = new SN_Rate_Limit_Test_DB();
$wpdb->fail_update = true;
$check(!SN_DB::consume_rate_limit('report_global', 'user-9', 10, 60), 'Atomic counter mutation errors must fail closed.');
$check(
    str_contains(implode("\n", $wpdb->queries), 'INSERT IGNORE') && str_contains(implode("\n", $wpdb->queries), 'hits=IF'),
    'The behavioral path must execute the initialize-once and conditional-update SQL contracts.'
);

if ($failures) {
    fwrite(STDERR, "Rate-limit runtime failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}
printf("Rate-limit runtime contracts: PASS (%d checks)\n", $checks);
