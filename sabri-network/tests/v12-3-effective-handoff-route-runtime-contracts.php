<?php
/**
 * V12.3 F17-FUT-05/06 effective REST route — settings must not bypass handoff.
 * Isolated WordPress/MySQL fakes; live companion, staging and race tests remain separate.
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
    public function __construct(private array $data, private int $status=200) {}
    public function get_data(): array { return $this->data; }
    public function get_status(): int { return $this->status; }
}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params=[]) {}
    public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
    public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet(mixed $offset,mixed $value): void { $this->params[$offset]=$value; }
    public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
}
final class SN_REST { public static function access(): bool { return true; } }
final class SN_DB {
    public static function is_member(int $conversation,int $user): bool { return $conversation>0&&$user>0; }
    public static function member_role(int $conversation,int $user): string { return 'owner'; }
    public static function audit(string $event,string $type,int $object,string $result,array $metadata,int $actor): void {}
}
final class SN_Communication_Crypto {
    public static function encrypt(string $plain,string $context): string { return base64_encode($plain); }
    public static function decrypt(string $cipher,string $context): string|WP_Error {
        $value=base64_decode($cipher,true);
        return $value===false?new WP_Error('invalid_cipher'): $value;
    }
}
function absint(mixed $x): int { return abs((int)$x); }
function get_current_user_id(): int { return 7; }
function user_can(int $user,string $cap): bool { return false; }
function is_wp_error(mixed $x): bool { return $x instanceof WP_Error; }
function has_filter(string $name): bool { return $name==='sn_network_team_inbox_delegation_allowed'; }
function apply_filters(string $name,mixed $value,mixed ...$args): bool { return true; }
function rest_sanitize_boolean(mixed $v): bool { return (bool)$v; }
function sanitize_key(string $v): string { return strtolower(preg_replace('/[^a-z0-9_-]/','',$v)); }
function wp_json_encode(mixed $v): string|false { return json_encode($v); }
function current_time(string $name,bool $utc=false): string { return '2026-11-14 16:30:00'; }
function rest_ensure_response(mixed $x): WP_REST_Response { return new WP_REST_Response((array)$x); }
$registered=[];
function register_rest_route(string $namespace,string $route,array $args,bool $override=false): void {
    global $registered;
    $registered[$route]=['namespace'=>$namespace,'args'=>$args,'override'=>$override];
}
final class MockWPDB {
    public string $prefix='wp_';
    public ?array $row=null;
    public int $insert_id=0;
    public int $writes=0;
    public array $queries=[];
    public function prepare(string $sql,mixed ...$params): string { return $sql; }
    public function get_var(string $sql): int { $this->queries[]=$sql; return 1; }
    public function get_row(string $sql): ?object { return $this->row?(object)$this->row:null; }
    public function seed(int $assignee,int $version=3): void {
        $this->row=[
            'id'=>55,'feature_id'=>'F17-FUT-05','owner_id'=>0,
            'scope_type'=>'conversation','scope_id'=>42,'state'=>'active',
            'version'=>$version,'payload_cipher'=>base64_encode(json_encode([
                'enabled'=>true,'assignee_id'=>$assignee,'status'=>'open','updated_by'=>7
            ]))
        ];
    }
    public function insert(string $table,array $values): int {
        ++$this->writes;$this->insert_id=55;
        $this->row=['id'=>55] + $values;
        return 1;
    }
    public function update(string $table,array $values,array $where): int {
        if(!$this->row||$where['id']!==$this->row['id']||$where['version']!==$this->row['version'])return 0;
        ++$this->writes;$this->row=array_replace($this->row,$values);
        return 1;
    }
}
require SN_DIR . 'includes/class-sn-future24-review-hardening-k.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {
    ++$checks;if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}
};
SN_Future24_Review_Hardening_K::routes();
$route='/future/team-inbox/(?P<id>\\d+)';
$callback=$registered[$route]['args'][1]['callback']??[];
$check($callback===[SN_Future24_Review_Hardening_K::class,'set_team_inbox'],
    'The current F17-FUT-05 POST route is owned by the governed Team Inbox handler');
$req=static function(array $extra=[]):WP_REST_Request {
    return new WP_REST_Request($extra+['id'=>42,'expected_version'=>3,'enabled'=>true,'status'=>'open']);
};
$wpdb=new MockWPDB();
$wpdb->seed(11,3);
$prior=$wpdb->row;
$change=SN_Future24_Review_Hardening_K::set_team_inbox($req(['assignee_id'=>12]));
$check(is_wp_error($change)&&$change->get_error_code()==='sn_handoff_endpoint_required',
    'Generic settings cannot silently reassign an existing inbox');
$check(($change->get_error_data()['status']??0)===409,'Assignment change has conflict status');
$check($wpdb->writes===0&&$wpdb->row===$prior,'Rejecting reassignment leaves the canonical record unchanged');

$clear=SN_Future24_Review_Hardening_K::set_team_inbox($req(['assignee_id'=>0]));
$check(is_wp_error($clear)&&$clear->get_error_code()==='sn_handoff_endpoint_required',
    'Generic settings cannot silently clear assignment');
$check($wpdb->writes===0,'Clearing assignment cannot perform writes');

$settingsOnly=SN_Future24_Review_Hardening_K::set_team_inbox($req(['status'=>'pending']));
$check($settingsOnly instanceof WP_REST_Response && $settingsOnly->get_status()===200,
    'Settings-only mutation remains usable without an assignee parameter');
$decoded=json_decode(base64_decode($wpdb->row['payload_cipher']),true);
$check(($decoded['assignee_id']??0)===11&&($decoded['status']??'')==='pending',
    'Omitted assignee preserves the current assignment and changes only settings');
$check($wpdb->row['version']===4,'Settings-only update increments the expected version');

$wpdb=new MockWPDB();
$createAssigned=SN_Future24_Review_Hardening_K::set_team_inbox(
    $req(['expected_version'=>0,'assignee_id'=>11]));
$check(is_wp_error($createAssigned)&&$createAssigned->get_error_code()==='sn_handoff_endpoint_required',
    'Initial assignment also requires a dedicated reasoned handoff');
$check($wpdb->writes===0,'Rejected initial assignment cannot create a partial record');
$createUnassigned=SN_Future24_Review_Hardening_K::set_team_inbox(
    $req(['expected_version'=>0,'assignee_id'=>0]));
$check($createUnassigned instanceof WP_REST_Response&&$wpdb->writes===1,
    'Creating an unassigned team inbox remains supported');
$check(($wpdb->row['version']??0)===1,'New unassigned inbox starts at version 1');

$wpdb=new MockWPDB();
$wpdb->seed(11,3);
$stale=SN_Future24_Review_Hardening_K::set_team_inbox($req(['expected_version'=>2]));
$check(is_wp_error($stale)&&$stale->get_error_code()==='sn_team_inbox_stale',
    'CAS rejects stale generic settings');
$check($wpdb->writes===0,'Stale generic settings cannot overwrite the prior state');
echo "V12.3 effective Team Inbox handoff guard: $checks PASS, 0 FAIL\n";
