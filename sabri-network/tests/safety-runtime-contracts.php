<?php
/** Behavioral contracts for pure File-17 safety invariants; WordPress is intentionally stubbed. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$sn_test_options = [];
$sn_uuid_queue = [];
$sn_cache_deletes = [];

function wp_json_encode($value, int $flags = 0): string|false {
    return json_encode($value, $flags);
}

function apply_filters(string $hook_name, $value, ...$args) {
    return $value;
}

function wp_generate_uuid4(): string {
    global $sn_uuid_queue;
    if (!$sn_uuid_queue) {
        throw new RuntimeException('The UUID test queue is empty.');
    }
    return (string) array_shift($sn_uuid_queue);
}

function add_option(string $option, $value, string $deprecated = '', $autoload = 'yes'): bool {
    global $sn_test_options;
    if (array_key_exists($option, $sn_test_options)) {
        return false;
    }
    $sn_test_options[$option] = $value;
    return true;
}

function get_option(string $option, $default = false) {
    global $sn_test_options;
    return array_key_exists($option, $sn_test_options) ? $sn_test_options[$option] : $default;
}

function wp_cache_delete(string $key, string $group = ''): bool {
    global $sn_cache_deletes;
    $sn_cache_deletes[] = $group . ':' . $key;
    return true;
}

final class SN_Safety_Test_DB {
    public string $options = 'wp_options';
    public $before_update = null;
    public $before_delete = null;

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false {
        global $sn_test_options;
        if (is_callable($this->before_update)) {
            $callback = $this->before_update;
            $this->before_update = null;
            $callback();
        }
        $option = (string) ($where['option_name'] ?? '');
        $expected = (string) ($where['option_value'] ?? '');
        if ($table !== $this->options || !array_key_exists($option, $sn_test_options) || (string) $sn_test_options[$option] !== $expected) {
            return 0;
        }
        $sn_test_options[$option] = (string) ($data['option_value'] ?? '');
        return 1;
    }

    public function delete(string $table, array $where, ?array $where_format = null): int|false {
        global $sn_test_options;
        if (is_callable($this->before_delete)) {
            $callback = $this->before_delete;
            $this->before_delete = null;
            $callback();
        }
        $option = (string) ($where['option_name'] ?? '');
        $expected = (string) ($where['option_value'] ?? '');
        if ($table !== $this->options || !array_key_exists($option, $sn_test_options) || (string) $sn_test_options[$option] !== $expected) {
            return 0;
        }
        unset($sn_test_options[$option]);
        return 1;
    }
}

$wpdb = new SN_Safety_Test_DB();

require dirname(__DIR__) . '/includes/class-sn-safety.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(SN_Safety::valid_uuid('123e4567-e89b-42d3-a456-426614174000'), 'A valid UUIDv4 must be accepted.');
$check(!SN_Safety::valid_uuid('123e4567-e89b-12d3-a456-426614174000'), 'A non-v4 UUID must be rejected.');
$check(!SN_Safety::valid_uuid('not-a-uuid'), 'Malformed report identifiers must be rejected.');
$check(
    SN_Safety::evidence_hash(['b' => '2', 'a' => '1']) === SN_Safety::evidence_hash(['a' => '1', 'b' => '2']),
    'Evidence hashes must be independent of associative-key insertion order.'
);
$check(
    SN_Safety::evidence_hash(['outer' => ['z' => 2, 'a' => 1]]) === SN_Safety::evidence_hash(['outer' => ['a' => 1, 'z' => 2]]),
    'Evidence hashes must recursively canonicalize nested associative data.'
);
$check(
    SN_Safety::evidence_hash(['items' => ['first', 'second']]) !== SN_Safety::evidence_hash(['items' => ['second', 'first']]),
    'Evidence hashes must preserve list order.'
);
$hash = SN_Safety::evidence_hash(['source' => 'message', 'id' => '42']);
$check(SN_Safety::evidence_is_intact(['id' => '42', 'source' => 'message'], $hash), 'Integrity checks must accept canonically identical evidence.');
$check(!SN_Safety::evidence_is_intact(['source' => 'message', 'id' => '43'], $hash), 'Integrity checks must reject changed evidence.');
$check(SN_Safety::can_transition_status('open', 'reviewing'), 'Open reports must enter review.');
$check(!SN_Safety::can_transition_status('closed', 'actioned'), 'Closed reports must not silently jump back to actioned.');
$check(!SN_Safety::can_transition_status('expired', 'reviewing'), 'Expired reports must not be operationally reopened.');
$check(!SN_Safety::legal_hold_release_authorized(7, (object) ['legal_hold' => 1]), 'Legal-hold release must fail closed without an external authorization decision.');

$reflection = new ReflectionClass(SN_Safety::class);
$acquire = $reflection->getMethod('acquire_retention_lock');
$release = $reflection->getMethod('release_retention_lock');
$acquire->setAccessible(true);
$release->setAccessible(true);
$lock_name = 'sn_report_retention_lock';

$sn_test_options = [];
$sn_cache_deletes = [];
$first_token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$sn_uuid_queue = [$first_token];
$acquired = (string) $acquire->invoke(null);
$check($acquired === $first_token, 'An absent retention lock must be acquired.');
$check(str_starts_with((string) $sn_test_options[$lock_name], $first_token . '|'), 'A new lock must persist its owner token and expiry.');
$release->invoke(null, $first_token);
$check(!array_key_exists($lock_name, $sn_test_options), 'The current owner must be able to release its own unchanged lock.');

$old_token = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$new_token = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$sn_test_options = [$lock_name => $old_token . '|' . (time() - 60)];
$sn_uuid_queue = [$new_token];
$acquired = (string) $acquire->invoke(null);
$check($acquired === $new_token, 'A stale retention lock must be replaced through compare-and-swap.');
$check(str_starts_with((string) $sn_test_options[$lock_name], $new_token . '|'), 'Successful stale takeover must preserve the new owner.');
$release->invoke(null, $old_token);
$check(str_starts_with((string) $sn_test_options[$lock_name], $new_token . '|'), 'A former owner must not release the replacement owner lock.');

$racing_token = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$winner_token = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
$sn_test_options = [$lock_name => $old_token . '|' . (time() - 60)];
$sn_uuid_queue = [$racing_token];
$wpdb->before_update = static function () use (&$sn_test_options, $lock_name, $winner_token): void {
    $sn_test_options[$lock_name] = $winner_token . '|' . (time() + 600);
};
$acquired = (string) $acquire->invoke(null);
$check($acquired === '', 'A stale-lock contender must lose when another worker replaces the observed value first.');
$check(str_starts_with((string) $sn_test_options[$lock_name], $winner_token . '|'), 'A failed compare-and-swap must not overwrite the winning owner lock.');

$releasing_token = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
$renewed_token = '11111111-1111-4111-8111-111111111111';
$sn_test_options = [$lock_name => $releasing_token . '|' . (time() + 600)];
$wpdb->before_delete = static function () use (&$sn_test_options, $lock_name, $renewed_token): void {
    $sn_test_options[$lock_name] = $renewed_token . '|' . (time() + 600);
};
$release->invoke(null, $releasing_token);
$check(str_starts_with((string) $sn_test_options[$lock_name], $renewed_token . '|'), 'Compare-and-delete release must not remove a lock renewed between read and delete.');

$active_token = '22222222-2222-4222-8222-222222222222';
$contender_token = '33333333-3333-4333-8333-333333333333';
$sn_test_options = [$lock_name => $active_token . '|' . (time() + 600)];
$sn_uuid_queue = [$contender_token];
$acquired = (string) $acquire->invoke(null);
$check($acquired === '', 'An unexpired retention lock must reject another worker.');
$check(str_starts_with((string) $sn_test_options[$lock_name], $active_token . '|'), 'An active owner lock must remain unchanged.');

$malformed_token = '44444444-4444-4444-8444-444444444444';
$sn_test_options = [$lock_name => 'malformed-lock-record'];
$sn_uuid_queue = [$malformed_token];
$acquired = (string) $acquire->invoke(null);
$check($acquired === $malformed_token, 'A malformed internal lock record must be recoverable through compare-and-swap.');
$check(str_starts_with((string) $sn_test_options[$lock_name], $malformed_token . '|'), 'Malformed-lock recovery must install a valid new owner record.');

if ($failures) {
    fwrite(STDERR, "Safety runtime contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

printf("Safety runtime contracts: PASS (%d checks)\n", $checks);
