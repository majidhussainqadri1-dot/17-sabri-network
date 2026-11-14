<?php
/**
 * V12.3 F17-FUT-05/06: a team-inbox handoff is one atomic user-visible operation.
 * Reproduces first-write success + second-write/commit failure without WordPress or MySQL.
 * This isolated deterministic test does not substitute for staging MySQL race tests.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SN_DIR', dirname(__DIR__) . '/');

final class WP_Error {
    public function __construct(private string $code, private string $message = '', private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}
final class WP_REST_Response {
    public function __construct(private array $data, private int $status = 200) {}
    public function get_data(): array { return $this->data; }
    public function get_status(): int { return $this->status; }
}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params = []) {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    public function set_param(string $key, mixed $value): void { $this->params[$key] = $value; }
    public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->params[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
}
final class SN_DB {
    public static string $role = 'owner';
    public static function member_role(int $conversation, int $user): string { return self::$role; }
    public static function is_member(int $conversation, int $user): bool { return $conversation > 0 && $user > 0; }
}
final class SN_Communication_Crypto {
    public static function encrypt(string $plain, string $context): string {
        return 'sealed:' . hash('sha256', $context) . ':' . base64_encode($plain);
    }
}
function absint(mixed $v): int { return abs((int) $v); }
function get_current_user_id(): int { return 7; }
function current_user_can(string $cap): bool { return false; }
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function rest_sanitize_boolean(mixed $v): bool { return (bool) $v; }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $v)); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function current_time(string $type, bool $gmt = false): string { return '2026-11-14 16:00:00'; }
function rest_ensure_response(mixed $value): WP_REST_Response { return new WP_REST_Response((array) $value); }

final class TeamInboxFakeDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $rows = [];
    public array $queries = [];
    public bool $fail_start = false;
    public bool $fail_assignment = false;
    public bool $fail_commit = false;
    private ?array $snapshot = null;
    private int $next_id = 0;

    public function prepare(string $sql, mixed ...$args): string {
        $i = 0;
        return (string) preg_replace_callback('/%[ds]/', static function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", (string) $value) . "'";
        }, $sql);
    }
    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if ($sql === 'START TRANSACTION') {
            if ($this->fail_start) return false;
            $this->snapshot = unserialize(serialize($this->rows));
            return 1;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot !== null) $this->rows = $this->snapshot;
            $this->snapshot = null;
            return 1;
        }
        if ($sql === 'COMMIT') {
            if ($this->fail_commit) return false;
            $this->snapshot = null;
            return 1;
        }
        return 1;
    }
    public function get_row(string $query): ?object {
        if (!preg_match("/feature_id='(F17-FUT-\\d+)'/", $query, $m)) return null;
        return isset($this->rows[$m[1]]) ? (object) $this->rows[$m[1]] : null;
    }
    public function get_var(string $query): int { return 0; }
    public function insert(string $table, array $data): int|false {
        if ($this->fail_assignment && ($data['feature_id'] ?? '') === 'F17-FUT-06') return false;
        $this->insert_id = ++$this->next_id;
        $this->rows[(string)$data['feature_id']] = ['id' => $this->insert_id, 'version' => 1] + $data;
        return 1;
    }
    public function update(string $table, array $data, array $where): int|false {
        foreach ($this->rows as $feature => $row) {
            if (($row['id'] ?? 0) !== ($where['id'] ?? -1)) continue;
            if (($row['version'] ?? 0) !== ($where['version'] ?? -1)) return 0;
            if ($this->fail_assignment && $feature === 'F17-FUT-06') return false;
            $this->rows[$feature] = array_replace($row, $data);
            return 1;
        }
        return 0;
    }
}
require SN_DIR . 'includes/class-sn-future-superset.php';

$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    ++$checks;
    if (!$ok) { fwrite(STDERR, "FAIL: $why\n"); exit(1); }
};
$request = static fn(int $assignee = 0): WP_REST_Request => new WP_REST_Request([
    'id' => 42, 'enabled' => true, 'assignee_id' => $assignee, 'status' => 'open'
]);

SN_DB::$role = 'member';
$wpdb = new TeamInboxFakeDB();
$denied = SN_Future_Superset::set_team_inbox($request());
$check(is_wp_error($denied), 'A non-manager cannot update team inbox');
$check(!$wpdb->queries && !$wpdb->rows, 'Unauthorized requests cannot begin a write transaction');
SN_DB::$role = 'owner';

$wpdb = new TeamInboxFakeDB();
$wpdb->fail_start = true;
$failed_start = SN_Future_Superset::set_team_inbox($request());
$check(is_wp_error($failed_start), 'Transaction-start failure is not reported as success');
$check(!$wpdb->rows, 'Transaction-start failure makes no writes');

$wpdb = new TeamInboxFakeDB();
$wpdb->fail_assignment = true;
$failed_assignment = SN_Future_Superset::set_team_inbox($request());
$check(is_wp_error($failed_assignment), 'Second-record insert failure propagates');
$check(in_array('ROLLBACK', $wpdb->queries, true), 'Second-record failure rolls back first record');
$check(!$wpdb->rows, 'No partially committed team inbox state after second-record failure');

$wpdb = new TeamInboxFakeDB();
$wpdb->fail_commit = true;
$failed_commit = SN_Future_Superset::set_team_inbox($request());
$check(is_wp_error($failed_commit), 'Commit failure returns an error');
$check(!$wpdb->rows, 'Commit failure rolls back both records');

$wpdb = new TeamInboxFakeDB();
$ok = SN_Future_Superset::set_team_inbox($request());
$check($ok instanceof WP_REST_Response && $ok->get_status() === 200, 'Success follows a committed transaction');
$check(isset($wpdb->rows['F17-FUT-05'], $wpdb->rows['F17-FUT-06']), 'Both canonical records are committed together');
$check(in_array('COMMIT', $wpdb->queries, true), 'Success required a commit');

$before = unserialize(serialize($wpdb->rows));
$wpdb->fail_assignment = true;
$changed = SN_Future_Superset::set_team_inbox($request(8));
$check(is_wp_error($changed), 'An existing assignment update failure propagates');
$check($wpdb->rows === $before, 'Failed update preserves the previous committed team inbox state');

echo "V12.3 F17-FUT-05/06 atomic handoff: $checks PASS, 0 FAIL\n";
