<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('SN_VERSION', '2.0.1');

final class WP_Error {
    public function __construct(private string $code, private string $message = '', private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function absint($value): int { return abs((int) $value); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function wp_unslash($value) { return $value; }
function wp_generate_uuid4(): string {
    static $n = 0;
    $n++;
    return sprintf('123e4567-e89b-42d3-a456-%012d', $n);
}
function wp_salt($scheme = 'auth'): string { return 'unit-test-' . $scheme; }
function current_time($type, $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_parse_url($url): array|false { return parse_url($url); }
function home_url($path = '/'): string { return 'https://example.test' . $path; }
function add_query_arg($key, $value, $url): string { return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode((string) $key) . '=' . rawurlencode((string) $value); }
function __($value, $domain = ''): string { return (string) $value; }
function add_action(...$args): void {}
function add_filter(...$args): void {}
function update_option(...$args): bool { return true; }
function get_option($key, $default = '') { return $default; }
function get_user_by($field, $value) { return false; }

$GLOBALS['sn_cf01_filters'] = [
    'issuer' => false,
    'consent' => false,
    'read' => false,
    'revoke' => false,
];
function apply_filters($hook, $value, ...$args) {
    $map = [
        'sn_cf01_clinical_context_issuer_authorized' => 'issuer',
        'sn_cf01_clinical_context_consent_authorized' => 'consent',
        'sn_cf01_clinical_context_read_authorized' => 'read',
        'sn_cf01_clinical_context_revoke_authorized' => 'revoke',
    ];
    return isset($map[$hook]) ? $GLOBALS['sn_cf01_filters'][$map[$hook]] : $value;
}

final class FakeWpdb {
    public string $prefix = 'wp_';
    public array $conversations = [];
    public array $members = [];
    public array $references = [];
    public int $insert_id = 0;

    public function get_charset_collate(): string { return ''; }
    public function prepare(string $query, ...$args): array { return ['query' => $query, 'args' => $args]; }

    public function get_row($prepared): ?object {
        [$query, $args] = $this->parts($prepared);
        if (str_contains($query, 'FROM wp_sn_conversations')) {
            return $this->conversations[(int) $args[0]] ?? null;
        }
        if (str_contains($query, 'FROM wp_sn_members')) {
            $conversation = (int) $args[0];
            $user = (int) $args[1];
            foreach ($this->members as $row) {
                if ((int) $row->conversation_id === $conversation && (int) $row->user_id === $user && $row->left_at === null) {
                    return (object) ['id' => (int) $row->id];
                }
            }
            return null;
        }
        if (str_contains($query, 'FROM wp_sn_cf01_context_refs')) {
            foreach ($this->references as $row) {
                if (str_contains($query, 'issued_by=%d') && (int) $row->issued_by === (int) $args[0] && (string) $row->idempotency_key === (string) $args[1]) {
                    return clone $row;
                }
                if (str_contains($query, 'reference_uuid=%s') && (string) $row->reference_uuid === (string) $args[0]) {
                    if (str_contains($query, 'status=%s') && (string) $row->status !== (string) $args[1]) {
                        continue;
                    }
                    return clone $row;
                }
            }
        }
        return null;
    }

    public function get_var($prepared) {
        [$query, $args] = $this->parts($prepared);
        if (str_contains($query, 'SELECT COUNT(*) FROM wp_sn_members')) {
            $conversation = (int) $args[0];
            return count(array_filter($this->members, fn($row) => (int) $row->conversation_id === $conversation && $row->left_at === null));
        }
        if (str_contains($query, 'SELECT user_id FROM wp_sn_members')) {
            $conversation = (int) $args[0];
            $actor = (int) $args[1];
            foreach ($this->members as $row) {
                if ((int) $row->conversation_id === $conversation && (int) $row->user_id !== $actor && $row->left_at === null) {
                    return (int) $row->user_id;
                }
            }
            return 0;
        }
        return null;
    }

    public function insert(string $table, array $data) {
        if ($table !== 'wp_sn_cf01_context_refs') {
            return false;
        }
        foreach ($this->references as $row) {
            if ((int) $row->issued_by === (int) $data['issued_by'] && (string) $row->idempotency_key === (string) $data['idempotency_key']) {
                return false;
            }
        }
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        $data['revoked_at'] = null;
        $this->references[$this->insert_id] = (object) $data;
        return 1;
    }

    public function update(string $table, array $data, array $where) {
        if ($table !== 'wp_sn_cf01_context_refs') {
            return false;
        }
        foreach ($this->references as $id => $row) {
            $matches = true;
            foreach ($where as $key => $expected) {
                if ((string) $row->{$key} !== (string) $expected) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                foreach ($data as $key => $value) {
                    $row->{$key} = $value;
                }
                $this->references[$id] = $row;
                return 1;
            }
        }
        return 0;
    }

    public function query($prepared) {
        [$query, $args] = $this->parts($prepared);
        if (in_array($query, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
            return 1;
        }
        if (str_starts_with($query, 'UPDATE wp_sn_cf01_context_refs SET status=')) {
            return 0;
        }
        return 1;
    }

    private function parts($prepared): array {
        return is_array($prepared) ? [$prepared['query'], $prepared['args']] : [(string) $prepared, []];
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['wpdb']->conversations[10] = (object) [
    'id' => 10,
    'type' => 'direct',
    'owner_id' => 7,
    'privacy' => 'private',
    'status' => 'active',
    'updated_at' => gmdate('Y-m-d H:i:s'),
];
$GLOBALS['wpdb']->members = [
    (object) ['id' => 1, 'conversation_id' => 10, 'user_id' => 7, 'role' => 'owner', 'left_at' => null],
    (object) ['id' => 2, 'conversation_id' => 10, 'user_id' => 8, 'role' => 'member', 'left_at' => null],
];

final class SN_DB {
    public static array $blocked = [];
    public static array $audits = [];
    public static function table(string $name): string { return 'wp_sn_' . $name; }
    public static function is_member(int $conversation, int $user): bool {
        global $wpdb;
        foreach ($wpdb->members as $row) {
            if ((int) $row->conversation_id === $conversation && (int) $row->user_id === $user && $row->left_at === null) {
                return true;
            }
        }
        return false;
    }
    public static function member_role(int $conversation, int $user): string {
        global $wpdb;
        foreach ($wpdb->members as $row) {
            if ((int) $row->conversation_id === $conversation && (int) $row->user_id === $user && $row->left_at === null) {
                return (string) $row->role;
            }
        }
        return '';
    }
    public static function is_blocked(int $a, int $b): bool { return !empty(self::$blocked[min($a, $b) . ':' . max($a, $b)]); }
    public static function audit(...$args): void { self::$audits[] = $args; }
}
final class SN_Membership_Assertions {
    public static function clear_cache(int $user_id = 0): void {}
}
final class SN_Policy {
    public static function access(): bool|WP_Error { return true; }
}
final class SN_Outbox {
    public static array $events = [];
    public static function enqueue(...$args) { self::$events[] = $args; return true; }
}
final class SN_Messages {
    public static function messages_url(): string { return 'https://example.test/messages/'; }
}

require dirname(__DIR__) . '/includes/class-sn-cf01-clinical-context.php';

$tests = 0;
function sn_cf01_runtime_assert(bool $condition, string $message): void {
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$context = [
    'purpose' => 'care_coordination',
    'consent_reference' => 'consent:clinical:12345678',
    'idempotency_key' => 'issue:clinical:12345678',
    'ttl_seconds' => 3600,
];

$result = SN_CF01_Clinical_Context::issue_reference(10, 7, $context);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_issuer_not_authorized', 'issuer authorization fails closed');

$GLOBALS['sn_cf01_filters']['issuer'] = true;
$result = SN_CF01_Clinical_Context::issue_reference(10, 7, $context);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_consent_not_authorized', 'consent authorization fails closed');

$GLOBALS['sn_cf01_filters']['consent'] = true;
$GLOBALS['sn_cf01_filters']['read'] = true;
$result = SN_CF01_Clinical_Context::issue_reference(10, 7, $context);
sn_cf01_runtime_assert(is_array($result) && $result['result'] === 'valid', 'authorized opaque reference is issued');
sn_cf01_runtime_assert(count($GLOBALS['wpdb']->references) === 1, 'one reference row is created');
sn_cf01_runtime_assert($result['content_boundary']['message_body_included'] === false, 'assertion contains no message body');
sn_cf01_runtime_assert($result['content_boundary']['attachment_included'] === false, 'assertion contains no attachment');
sn_cf01_runtime_assert($result['authorization_boundary']['treating_relationship'] === false, 'chat membership is not a treating relationship');
sn_cf01_runtime_assert($result['authorization_boundary']['clinical_write_authority'] === false, 'reference grants no clinical write authority');
$reference = $result['reference']['reference_uuid'];

$repeat = SN_CF01_Clinical_Context::issue_reference(10, 7, $context);
sn_cf01_runtime_assert(is_array($repeat) && $repeat['reference']['reference_uuid'] === $reference, 'idempotent replay returns the original reference');
sn_cf01_runtime_assert(count($GLOBALS['wpdb']->references) === 1, 'idempotent replay creates no duplicate row');

$mismatch = $context;
$mismatch['purpose'] = 'follow_up_reference';
$result = SN_CF01_Clinical_Context::issue_reference(10, 7, $mismatch);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_idempotency_scope_mismatch', 'idempotency key cannot cross purpose scope');

$GLOBALS['sn_cf01_filters']['read'] = false;
$result = SN_CF01_Clinical_Context::assertion($reference, 7, ['purpose' => 'care_coordination']);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_reference_read_not_authorized', 'every assertion read fails closed without fresh authorization');

$GLOBALS['sn_cf01_filters']['read'] = true;
$result = SN_CF01_Clinical_Context::assertion($reference, 7, ['purpose' => 'follow_up_reference']);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_reference_purpose_mismatch', 'reference purpose cannot be changed at read time');

$result = SN_CF01_Clinical_Context::assertion($reference, 7, ['purpose' => 'care_coordination']);
sn_cf01_runtime_assert(is_array($result) && $result['communication_context']['participant_count'] === 2, 'current participant count is projected without identities');
sn_cf01_runtime_assert(str_starts_with($result['communication_context']['owner_reference'], 'sn-subject-'), 'owner is emitted only as a File 17 opaque reference');
sn_cf01_runtime_assert($result['destination_intent']['contains_bearer_authorization'] === false, 'destination intent is not bearer authorization');

$destination = SN_CF01_Clinical_Context::resolve_destination($reference, 7, ['purpose' => 'care_coordination']);
sn_cf01_runtime_assert(is_array($destination) && str_starts_with($destination['url'], 'https://example.test/messages/'), 'destination resolves only to same-origin HTTPS');
sn_cf01_runtime_assert($destination['authorization_rechecked'] === true && $destination['bearer_authorization'] === false, 'destination resolution rechecks authorization and carries no bearer grant');

SN_DB::$blocked['7:8'] = true;
$result = SN_CF01_Clinical_Context::assertion($reference, 7, ['purpose' => 'care_coordination']);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_reference_not_found', 'direct-conversation block fails without relationship disclosure');
SN_DB::$blocked = [];

$GLOBALS['wpdb']->members[0]->left_at = gmdate('Y-m-d H:i:s');
$result = SN_CF01_Clinical_Context::assertion($reference, 7, ['purpose' => 'care_coordination']);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_reference_not_found', 'lost membership invalidates the reference');
$GLOBALS['wpdb']->members[0]->left_at = null;

$result = SN_CF01_Clinical_Context::revoke_reference($reference, 8, 'not_owner');
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_reference_not_found', 'unauthorized revocation reveals no reference existence');

$result = SN_CF01_Clinical_Context::revoke_reference($reference, 7, 'consent_withdrawn');
sn_cf01_runtime_assert(is_array($result) && $result['status'] === 'revoked' && $result['version'] === 2, 'issuer can revoke with optimistic version increment');
$result = SN_CF01_Clinical_Context::assertion($reference, 7, ['purpose' => 'care_coordination']);
sn_cf01_runtime_assert(is_wp_error($result) && $result->get_error_code() === 'sn_cf01_reference_not_found', 'revoked reference is immediately unusable');

$contract = SN_CF01_Clinical_Context::contract();
sn_cf01_runtime_assert($contract['writes_clinical_data'] === false, 'contract writes no clinical data');
sn_cf01_runtime_assert($contract['copies_message_content'] === false && $contract['copies_attachments'] === false, 'contract copies no message or attachment content');
sn_cf01_runtime_assert($contract['chat_membership_is_treating_relationship'] === false, 'contract explicitly separates chat membership from care relationship');

echo "File 17 CF-01 runtime contracts: $tests PASS, 0 FAIL\n";
