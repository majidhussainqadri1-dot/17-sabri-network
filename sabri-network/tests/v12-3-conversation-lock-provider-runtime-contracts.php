<?php
/** V12.3 FUT-04: prevent enabling a lock without verified File-00/02 step-up. */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
define('SN_DIR', dirname(__DIR__).'/');
final class WP_Error {
    public function __construct(private string $code, private string $message='', private array $data=[]) {}
    public function get_error_code(): string {return $this->code;}
}
final class WP_REST_Response {
    public function __construct(private array $data, private int $status=200) {}
    public function get_data(): array {return $this->data;}
    public function get_status(): int {return $this->status;}
}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params=[]) {}
    public function get_param(string $key): mixed {return $this->params[$key]??null;}
    public function get_route(): string {return (string)($this->params['route']??'');}
    public function set_param(string $key,mixed $value): void {$this->params[$key]=$value;}
    public function offsetExists(mixed $offset): bool {return isset($this->params[$offset]);}
    public function offsetGet(mixed $offset): mixed {return $this->params[$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value): void {$this->params[$offset]=$value;}
    public function offsetUnset(mixed $offset): void {unset($this->params[$offset]);}
}
final class SN_DB {
    public static function is_member(int $c,int $u): bool {return $c>0&&$u>0;}
    public static function member_role(int $c,int $u): string {return 'member';}
}
final class SN_Communication_Crypto {
    public static function encrypt(string $plain,string $context): string {return 'sealed:'.base64_encode($plain);}
    public static function decrypt(string $cipher,string $context): string {return base64_decode(substr($cipher,7),true)?:'';}
}
function absint(mixed $v): int {return abs((int)$v);}
function get_current_user_id(): int {return 7;}
function current_user_can(string $cap): bool {return false;}
function is_wp_error(mixed $v): bool {return $v instanceof WP_Error;}
function rest_sanitize_boolean(mixed $v): bool {return (bool)$v;}
function sanitize_key(string $v): string {return strtolower(preg_replace('/[^a-z0-9_\\-]/','',$v));}
function current_time(string $fmt,bool $gmt=false): string {return '2026-11-15 10:00:00';}
function wp_json_encode(mixed $v,int $flags=0): string|false {return json_encode($v,$flags);}
function rest_ensure_response(mixed $v): WP_REST_Response {return new WP_REST_Response((array)$v);}
$stepUp='absent';
function has_filter(string $name): bool {global $stepUp;return $name==='sn_network_step_up_verified'&&$stepUp!=='absent';}
function apply_filters(string $name,mixed $default,mixed ...$args): mixed {global $stepUp;return $name==='sn_network_step_up_verified'?$stepUp==='valid':$default;}
final class FakeDB {
    public string $prefix='wp_';
    public int $insert_id=0;
    public int $writes=0;
    public ?object $row=null;
    public function prepare(string $sql,mixed ...$args): string {return $sql;}
    public function get_row(string $sql): ?object {return $this->row;}
    public function insert(string $table,array $values): int {$this->writes++;$this->insert_id++;$this->row=(object)(['id'=>$this->insert_id,'version'=>1]+$values);return 1;}
    public function update(string $table,array $values,array $where): int|false {if(!$this->row||$this->row->version!==($where['version']??null))return 0;$this->writes++;$this->row=(object)array_replace((array)$this->row,$values);return 1;}
}
require SN_DIR.'includes/class-sn-future-superset.php';
$checks=0;
$check=static function(bool $ok,string $msg) use (&$checks): void {$checks++;if(!$ok){fwrite(STDERR,"FAIL: $msg\\n");exit(1);}};
$request=new WP_REST_Request(['id'=>42,'enabled'=>true,'step_up_ttl'=>900]);
$wpdb=new FakeDB();
$items=SN_Future_Superset::capabilities()->get_data()['items'];
$lock=current(array_values(array_filter($items,static fn(array $i): bool=>$i['id']==='F17-FUT-04')));
$check($lock['status']==='provider-gated','Absent step-up adapter must show provider-gated status');
$denied=SN_Future_Superset::set_conversation_lock($request);
$check(is_wp_error($denied)&&$denied->get_error_code()==='sn_conversation_step_up_provider_unavailable','No provider cannot enable lock');
$check($wpdb->writes===0,'No provider must cause no DB changes');
$stepUp='reject';
$denied=SN_Future_Superset::set_conversation_lock($request);
$check(is_wp_error($denied)&&$denied->get_error_code()==='sn_conversation_step_up_provider_unavailable','Failed step-up cannot enable lock');
$check($wpdb->writes===0,'Failed step-up must cause no DB changes');
$stepUp='valid';
$items=SN_Future_Superset::capabilities()->get_data()['items'];
$lock=current(array_values(array_filter($items,static fn(array $i): bool=>$i['id']==='F17-FUT-04')));
$check($lock['status']==='available','Verified provider can advertise available capability');
$allowed=SN_Future_Superset::set_conversation_lock($request);
$check($allowed instanceof WP_REST_Response,'Valid step-up may enable lock');
$check($wpdb->writes===1,'Successful enable creates exactly one canonical record');
$stepUp='absent';
$privateSearch=new WP_REST_Request(['route'=>'/sabri-network/v2/conversations/42/search']);
$blocked=SN_Future_Superset::pre_dispatch(null,null,$privateSearch);
$check(is_wp_error($blocked)&&$blocked->get_error_code()==='sn_conversation_step_up_required',
    'After provider loss, a previously locked private search must fail closed');
$deniedChange=SN_Future_Superset::set_conversation_lock(new WP_REST_Request(['id'=>42,'enabled'=>false]));
$check(is_wp_error($deniedChange),'Provider loss cannot bypass an existing conversation lock by disabling it');
$check($wpdb->writes===1,'Rejected unlock cannot mutate the saved lock');
$stepUp='valid';
$readable=SN_Future_Superset::pre_dispatch(null,null,$privateSearch);
$check($readable===null,'Valid fresh step-up may continue the authorized private search route');
$unlocked=SN_Future_Superset::set_conversation_lock(new WP_REST_Request(['id'=>42,'enabled'=>false]));
$check($unlocked instanceof WP_REST_Response,'Valid step-up can disable an existing protected lock');
$check($wpdb->writes===2,'Authenticated disable updates the same canonical lock');
$stepUp='absent';
$after=SN_Future_Superset::pre_dispatch(null,null,$privateSearch);
$check($after===null,'After authorized unlock, no stale lock remains to block the route');
echo "V12.3 conversation lock provider: $checks PASS, 0 FAIL\\n";
