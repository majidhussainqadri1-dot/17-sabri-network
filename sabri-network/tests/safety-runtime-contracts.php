<?php
/** Behavioral contracts for pure File-17 safety invariants; WordPress is intentionally stubbed. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function wp_json_encode($value, int $flags = 0): string|false {
    return json_encode($value, $flags);
}

function apply_filters(string $hook_name, $value, ...$args) {
    return $value;
}

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

if ($failures) {
    fwrite(STDERR, "Safety runtime contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

printf("Safety runtime contracts: PASS (%d checks)\n", $checks);
