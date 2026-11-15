<?php
/**
 * V12.3 F17-FUT-07: effective POST route creates only authorized reminders.
 * Deterministic WordPress/DB doubles; not a staging/production test.
 */
declare(strict_types=1);
date_default_timezone_set('UTC');
define('ABSPATH', __DIR__ . '/');
define('SN_DIR', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);

final class WP_Error {
    public function __construct(private string $code,private string $message='',private array $data=[]) {}
    public function get_error_code():string{return $this->code;}
    public function get_error_data():array{return $this->data;}
}
final class WP_REST_Response {
    public function __construct(private array $data,private int $status=200) {}
    public function get_status():int{return $this->status;}
    public function get_data():array{return $this->data;}
}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params=[]) {}
    public function get_param(string $key):mixed{return $this->params[$key]??null;}
    public function set_param(string $key,mixed $value):void{$this->params[$key]=$value;}
    public function offsetExists(mixed $key):bool{return isset($this->params[$key]);}
    public function offsetGet(mixed $key):mixed{return $this->params[$key]??null;}
    public function offsetSet(mixed $key,mixed $value):void{$this->params[$key]=$value;}
    public function offsetUnset(mixed $key):void{unset($this->params[$key]);}
}
final class SN_REST {public static function access():bool{return true;}}
final class SN_DB {
    public static array $membership=[42=>true,43=>true];
    public static array $blocked=[];
    public static function is_member(int $c,int $u):bool{return $u===7&&($c>0)&&!empty(self::$membership[$c]);}
    public static function is_blocked(int $viewer,int $sender):bool{return !empty(self::$blocked[$sender]);}
    public static function table(string $name):string{return 'wp_sn_'.$name;}
}
final class SN_Message_Operations {
    public static array $hidden=[];
    public static function is_hidden(int $viewer,int $id):bool{return !empty(self::$hidden[$id]);}
}
final class SN_Communication_Crypto {
    public static function encrypt(string $text,string $context):string{return base64_encode($text);}
    public static function decrypt(string $text,string $context):string|WP_Error{
        $result=base64_decode($text,true);
        return $result===false?new WP_Error('invalid_cipher'):$result;
    }
}
function get_current_user_id():int{return 7;}
function absint(mixed $x):int{return abs((int)$x);}
function is_wp_error(mixed $x):bool{return $x instanceof WP_Error;}
function current_time(string $type,bool $utc=false):string{return gmdate('Y-m-d H:i:s');}
function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
function sanitize_text_field(string $v):string{return trim($v);}
function rest_ensure_response(mixed $value):WP_REST_Response{return new WP_REST_Response((array)$value);}
function register_rest_route(string $namespace,string $route,array $args,bool $override=false):void{
    global $routes;$routes[$route]=['callback'=>$args['callback']??null,'override'=>$override];
}

final class FakeReminderDB {
    public string $prefix='wp_';
    public int $insert_id=0;
    public array $messages=[];
    public array $records=[];
    public int $successful_inserts=0;
    private int $next_id=0;
    public function __construct(){
        $this->messages=[
            11=>(object)['id'=>11,'conversation_id'=>42,'deleted_at'=>null,'sender_id'=>4],
            12=>(object)['id'=>12,'conversation_id'=>43,'deleted_at'=>null,'sender_id'=>5],
            13=>(object)['id'=>13,'conversation_id'=>42,'deleted_at'=>'2026-10-01 11:00:00','sender_id'=>4],
            14=>(object)['id'=>14,'conversation_id'=>42,'deleted_at'=>null,'sender_id'=>6],
            15=>(object)['id'=>15,'conversation_id'=>42,'deleted_at'=>null,'sender_id'=>8],
        ];
    }
    public function prepare(string $sql,mixed ...$args):string {
        foreach($args as $arg){
            $quoted=is_int($arg)?(string)$arg:"'".str_replace("'","''",(string)$arg)."'";
            $sql=(string)preg_replace('/%[ds]/',$quoted,$sql,1);
        }
        return $sql;
    }
    public function get_row(string $query):?object{
        if(str_contains($query,'wp_sn_messages')){
            preg_match('/WHERE id=(\d+)/',$query,$m);
            return $this->messages[(int)($m[1]??0)]??null;
        }
        if(str_contains($query,'wp_sn_future_records')){
            preg_match("/client_key='([0-9a-f]{64})'/",$query,$m);
            return $this->records[$m[1]??'']??null;
        }
        return null;
    }
    public function get_results(string $query):array{
        return str_contains($query,'wp_sn_future_records')
            ?array_values($this->records):[];
    }
    public function insert(string $table,array $row):int|false{
        $key=(string)($row['client_key']??'');
        if(isset($this->records[$key]))return false;
        ++$this->successful_inserts;
        $this->insert_id=++$this->next_id;
        $this->records[$key]=(object)(['id'=>$this->insert_id,'version'=>1]+$row);
        return 1;
    }
}
$wpdb=new FakeReminderDB();
require SN_DIR.'includes/class-sn-future-superset.php';
require SN_DIR.'includes/class-sn-future24-review-hardening-m.php';

SN_Future24_Review_Hardening_M::routes();
$checks=0;
$check=static function(bool $ok,string $what)use(&$checks):void{
    ++$checks;if(!$ok){fwrite(STDERR,"FAIL: $what\n");exit(1);}
};
$check(($routes['/future/reminders']['callback']??null)===[SN_Future24_Review_Hardening_M::class,'create_reminder'],
    'The effective creation route delegates through timezone-aware Future24-M');
$future=gmdate('Y-m-d\TH:i:s\Z',time()+3600);
$req=static function(array $change=[])use($future):WP_REST_Request{
    return new WP_REST_Request(array_replace([
        'client_id'=>'reminder-unique-123','conversation_id'=>42,'message_id'=>11,
        'label'=>'Read note','remind_at'=>$future
    ],$change));
};
$rejected=[
    ['conversation_id'=>0],
    ['conversation_id'=>1000],
    ['message_id'=>12],
    ['message_id'=>13],
    ['message_id'=>'not-a-message'],
    ['message_id'=>'-10'],
];
foreach($rejected as $changes){
    $result=SN_Future24_Review_Hardening_M::create_reminder($req($changes));
    $check($result instanceof WP_Error,'Orphan, nonmember, foreign, deleted or malformed target rejected');
}
SN_Message_Operations::$hidden[14]=true;
$check(SN_Future24_Review_Hardening_M::create_reminder($req(['message_id'=>14])) instanceof WP_Error,
    'Hidden message cannot become a reminder source');
SN_DB::$blocked[8]=true;
$check(SN_Future24_Review_Hardening_M::create_reminder($req(['message_id'=>15])) instanceof WP_Error,
    'Blocked sender cannot become a reminder source');
$check($wpdb->successful_inserts===0,'Every rejected create made zero persistent writes');

$valid=SN_Future24_Review_Hardening_M::create_reminder($req());
$check($valid instanceof WP_REST_Response&&$valid->get_status()===201,
    'Authorized visible message creates a reminder with HTTP 201');
$check($wpdb->successful_inserts===1,'Exactly one authorized reminder was stored');
$retry=SN_Future24_Review_Hardening_M::create_reminder($req());
$check($retry instanceof WP_REST_Response&&$retry->get_data()['id']===$valid->get_data()['id'],
    'Exact retry reuses the authorized record');
$check($wpdb->successful_inserts===1,'Exact retry adds no duplicate records');

$changed=SN_Future24_Review_Hardening_M::create_reminder($req(['message_id'=>0]));
$check($changed instanceof WP_Error&&$changed->get_error_code()==='sn_future_idempotency_conflict',
    'Same client ID cannot silently discard a previous message target');
$conversationOnly=SN_Future24_Review_Hardening_M::create_reminder($req([
    'message_id'=>0,'client_id'=>'conversation-only-unique-123'
]));
$check($conversationOnly instanceof WP_REST_Response&&$conversationOnly->get_status()===201,
    'A member may set a conversation-level reminder with no selected message');
$check($wpdb->successful_inserts===2,'Authorized conversation-only operation creates one separate row');

$listed=SN_Future_Superset::list_reminders();
$items=$listed->get_data()['items']??[];
$check(count($items)===2,'Reminder list projects both active canonical records');
$check(isset($items[0]['version'])&&$items[0]['version']===1,
    'Reminder list exposes authoritative expected_version for CAS mutations');
$check(isset($items[1]['version'])&&$items[1]['version']===1,
    'Every returned reminder includes its version, not just the latest item');

echo "V12.3 F17-FUT-07 authorized create: $checks PASS, 0 FAIL\n";
