<?php
/** File 17 eighth review: approved Future-24 caller-owned idempotency regression. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SN_DIR', dirname(__DIR__) . '/');
final class WP_Error {
    public function __construct(private string $code, private string $message = '', private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}
class WP_REST_Request {
    public function __construct(private array $params = []) {}
    public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
    public function get_params(): array { return $this->params; }
}
function wp_json_encode($value, $flags = 0): string|false { return json_encode($value, $flags); }
function wp_generate_uuid4(): never { throw new RuntimeException('Server-generated retry IDs are forbidden for this test'); }
require SN_DIR . 'includes/class-sn-future-superset.php';
$checks = 0;
$check = static function (bool $valid, string $why) use (&$checks): void {
    ++$checks;
    if (!$valid) { fwrite(STDERR, "FAIL: $why\n"); exit(1); }
};
$method = new ReflectionMethod(SN_Future_Superset::class, 'client');
foreach ([
    [], ['client_id'=>''], ['client_id'=>'  '], ['client_id'=>'x'],
    ['client_id'=>'invalid / value'], ['client_id'=>str_repeat('x', 65)],
] as $params) {
    $request = new WP_REST_Request(['title'=>'Repeatable template','body'=>'Same body'] + $params);
    $key = $method->invoke(null, $request, 7, 'template');
    $check($key === '', 'Absent/invalid client_id cannot receive payload-hash or random generated identity');
}
$a = new WP_REST_Request(['client_id'=>'FUT:REQUEST-123456','title'=>'Same','body'=>'Same']);
$b = new WP_REST_Request(['client_id'=>'FUT:REQUEST-123456','title'=>'Same','body'=>'Same']);
$c = new WP_REST_Request(['client_id'=>'FUT:REQUEST-876543','title'=>'Same','body'=>'Same']);
$one = $method->invoke(null,$a,7,'template');
$check($one === '7:template:fut:request-123456', 'Valid caller-supplied ID is scoped and normalized');
$check($one === $method->invoke(null,$b,7,'template'), 'A retry uses the same stable key');
$check($one !== $method->invoke(null,$c,7,'template'), 'Separate identical-payload operations are distinct when client keys differ');
$check($one !== $method->invoke(null,$a,8,'template'), 'Identity is scoped to the user');
$check($one !== $method->invoke(null,$a,7,'reminder'), 'Identity is scoped to the operation');
$create = new ReflectionMethod(SN_Future_Superset::class, 'create_record');
$result = $create->invoke(null,'F17-FUT-08',7,'user',7,['title'=>'Same'],'',null);
$check($result instanceof WP_Error, 'Invalid retry identity is refused before encryption and database access');
$check($result->get_error_code() === 'sn_future_client_id_required', 'A stable fail-closed error code is returned');
$check((int)($result->get_error_data()['status']??0) === 400, 'Absent identity produces HTTP 400');
echo "Future24 caller-owned idempotency: $checks PASS, 0 FAIL\n";
