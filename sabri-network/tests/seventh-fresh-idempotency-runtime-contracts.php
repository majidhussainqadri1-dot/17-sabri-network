<?php
/** Seventh fresh File 17: caller-owned idempotency identity runtime contracts. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
final class WP_Error {
    public function __construct(private string $code, private string $message = '', private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}
function wp_generate_uuid4(): string { throw new RuntimeException('Server must not fabricate retry identities.'); }
require dirname(__DIR__) . '/includes/class-sn-two-plan-completion.php';
$method = new ReflectionMethod(SN_Two_Plan_Completion::class, 'client_id');
$checks = 0;
$assert = static function (bool $pass, string $description) use (&$checks): void {
    ++$checks;
    if (!$pass) { fwrite(STDERR, "FAIL: $description\n"); exit(1); }
};
foreach (['', ' ', "\t\n", 'short', 'a@invalid', str_repeat('x', 65)] as $input) {
    $result = $method->invoke(null, $input);
    $assert($result instanceof WP_Error, 'Missing/malformed client ID must fail closed');
    $assert($result->get_error_code() === 'sn_client_id_invalid', 'Stable error code');
    $assert((int) $result->get_error_data()['status'] === 400, 'HTTP 400 for missing or malformed ID');
}
foreach (['request-12345678', 'SCHEDULE:RETRY-123456', 'poll.12345678', 'checklist_12345678'] as $input) {
    $one = $method->invoke(null, $input);
    $two = $method->invoke(null, $input);
    $assert(is_string($one) && $one === strtolower($input), 'Valid client ID normalizes correctly');
    $assert($one === $two, 'Identical caller retry ID is stable');
}
echo "Seventh fresh idempotency runtime contracts: $checks PASS, 0 FAIL\n";
