<?php
/** Behavioral regression for an existing empty retention-lock option. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$sn_options = ['sn_report_retention_lock' => ''];
$sn_cache_deletes = [];
function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }
function apply_filters(string $hook_name, $value, ...$args) { return $value; }
function wp_generate_uuid4(): string { return '55555555-5555-4555-8555-555555555555'; }
function add_option(string $option, $value, string $deprecated = '', $autoload = 'yes'): bool {
    global $sn_options;
    if (array_key_exists($option, $sn_options)) return false;
    $sn_options[$option] = $value;
    return true;
}
function get_option(string $option, $default = false) {
    global $sn_options;
    return array_key_exists($option, $sn_options) ? $sn_options[$option] : $default;
}
function wp_cache_delete(string $key, string $group = ''): bool {
    global $sn_cache_deletes;
    $sn_cache_deletes[] = $group . ':' . $key;
    return true;
}
final class SN_Empty_Lock_DB {
    public string $options = 'wp_options';
    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false {
        global $sn_options;
        $name = (string) ($where['option_name'] ?? '');
        $expected = (string) ($where['option_value'] ?? '');
        if ($table !== $this->options || !array_key_exists($name, $sn_options) || (string) $sn_options[$name] !== $expected) return 0;
        $sn_options[$name] = (string) $data['option_value'];
        return 1;
    }
    public function delete(string $table, array $where, ?array $whereFormat = null): int|false {
        global $sn_options;
        $name = (string) ($where['option_name'] ?? '');
        $expected = (string) ($where['option_value'] ?? '');
        if ($table !== $this->options || !array_key_exists($name, $sn_options) || (string) $sn_options[$name] !== $expected) return 0;
        unset($sn_options[$name]);
        return 1;
    }
}
$wpdb = new SN_Empty_Lock_DB();
require dirname(__DIR__) . '/includes/class-sn-safety.php';

$reflection = new ReflectionClass(SN_Safety::class);
$acquire = $reflection->getMethod('acquire_retention_lock');
$release = $reflection->getMethod('release_retention_lock');
$acquire->setAccessible(true);
$release->setAccessible(true);
$token = (string) $acquire->invoke(null);

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$check($token === '55555555-5555-4555-8555-555555555555', 'An existing empty lock option must be recoverable.');
$check(str_starts_with((string) $sn_options['sn_report_retention_lock'], $token . '|'), 'Empty-lock recovery must install a valid token and expiry.');
$check(in_array('options:sn_report_retention_lock', $sn_cache_deletes, true), 'Direct empty-lock replacement must invalidate the option cache.');
$release->invoke(null, $token);
$check(!array_key_exists('sn_report_retention_lock', $sn_options), 'The recovered lock owner must be able to release the lock.');

if ($failures) {
    fwrite(STDERR, "Retention empty-lock runtime failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) fwrite(STDERR, " - $failure\n");
    exit(1);
}
printf("Retention empty-lock runtime contracts: PASS (%d checks)\n", $checks);
