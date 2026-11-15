<?php
/** F17-FUT-08 owner-scoped saved-reply preview: no automatic send. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params){}
    public function get_param(string $name):mixed{return $this->params[$name]??null;}
    public function offsetExists(mixed $offset):bool{return array_key_exists((string)$offset,$this->params);}
    public function offsetGet(mixed $offset):mixed{return $this->params[$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value):void{$this->params[(string)$offset]=$value;}
    public function offsetUnset(mixed $offset):void{unset($this->params[$offset]);}
}
class WP_REST_Response {
    public function __construct(private array $data,private int $status=200){}
    public function get_data():array{return $this->data;}
}
class WP_Error {
    public function __construct(private string $code,private string $msg,private array $data=[]){}
    public function get_error_code():string{return $this->code;}
}
class SN_Policy {public static bool $suspended=false;public static function is_suspended(int $id):bool{return self::$suspended;}}
class SN_DB {public static array $audits=[];public static function audit(string $e,string $t,int $id,string $state,array $meta,int $u):void{self::$audits[]=[$e,$id,$state,$meta,$u];}}
class WPDBFake {
    public array $args=[];
    public function prepare(string $sql,mixed ...$args):string{$this->args=$args;return $sql;}
    public function get_row(string $sql):?object {
        if(!str_contains($sql,"feature_id='F17-FUT-08'")||!str_contains($sql,"owner_id=%d AND state='active'"))return null;
        if((int)($this->args[0]??0)!==77||(int)($this->args[1]??0)!==3)return null;
        return (object)['id'=>77,'owner_id'=>3,'body'=>Harness::$template];
    }
}
class TestApp {public static bool $clinicalAllowed=false;public static int $sent=0;}
function get_current_user_id():int{return 3;}
function absint(mixed $v):int{return abs((int)$v);}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function rest_ensure_response(mixed $v):WP_REST_Response{return $v instanceof WP_REST_Response?$v:new WP_REST_Response($v);}
function sanitize_text_field(string $v):string{return trim(strip_tags($v));}
function apply_filters(string $tag,mixed $default,mixed ...$args):mixed{return $tag==='sn_network_professional_template_allowed'?TestApp::$clinicalAllowed:$default;}
function do_action(string $tag,mixed ...$args):void{TestApp::$sent++;}
require_once __DIR__.'/../includes/class-sn-future-superset-part-1.php';
class Harness {
    use SN_Future_Superset_Part_1;
    public static string $template='Hello {{recipient_name}}, visit {{clinic_name}} on {{appointment_date}}.';
    private static function records_table():string{return 'test_sn_future_records';}
    private static function decode(object $row):array{return ['body'=>$row->body];}
    private static function looks_clinical_directive(string $body):bool{return (bool)preg_match('/\\b(prescribe|dose|diagnose)\\b/i',$body);}
    private static function not_found():WP_Error{return new WP_Error('not_found','Missing',['status'=>404]);}
    private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
function must(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function request(array $overrides=[]):WP_REST_Request{return new WP_REST_Request(array_merge(['id'=>77,'variables'=>['recipient_name'=>'Dr <b>Ahmad</b>','clinic_name'=>'Sabri Clinic','appointment_date'=>'Monday']],$overrides));}
$wpdb=new WPDBFake();
$r=Harness::preview_template(request());
must($r instanceof WP_REST_Response,'Authorized preview should succeed.');
must($r->get_data()['preview']==='Hello Dr Ahmad, visit Sabri Clinic on Monday.','Allowlisted variables should be rendered as sanitized plain text.');
must($r->get_data()['not_sent']===true&&$r->get_data()['requires_manual_review']===true,'The response must affirm no message was sent.');
must(TestApp::$sent===0,'Preview must not trigger any outbound actions.');
must(count(SN_DB::$audits)===1&&!str_contains(json_encode(SN_DB::$audits),'Dr Ahmad'),'Audit must not contain raw personalized reply.');
$r=Harness::preview_template(request(['id'=>78]));
must($r instanceof WP_Error&&$r->get_error_code()==='not_found','Non-owned / nonexistent template must be hidden.');
$r=Harness::preview_template(request(['variables'=>['unsafe_role'=>'admin']]));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_template_variable_forbidden','Unapproved variables must be refused.');
$r=Harness::preview_template(request(['variables'=>['recipient_name'=>'A']]));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_template_variables_unresolved','Incomplete substitutions must be refused.');
Harness::$template='Please prescribe a dose, {{recipient_name}}.';
$r=Harness::preview_template(request());
must($r instanceof WP_Error&&$r->get_error_code()==='sn_template_clinical_guard','Clinical directives must require approved owner policy.');
TestApp::$clinicalAllowed=true;
$r=Harness::preview_template(request());
must($r instanceof WP_REST_Response&&$r->get_data()['not_sent'],'Policy-approved content remains a preview, not an automatic message.');
SN_Policy::$suspended=true;
$r=Harness::preview_template(request());
must($r instanceof WP_Error&&$r->get_error_code()==='not_found','Suspended account must not preview private templates.');
must(TestApp::$sent===0,'No scenario may automatically send a message.');
echo "V12.3 private template preview runtime contracts: PASS\n";
