<?php
/** Minimal isolated runtime checks of v12.3 reminder lifecycle and dispatch rules. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
define('SN_DIR',dirname(__DIR__).'/');
define('DAY_IN_SECONDS',86400);
define('MINUTE_IN_SECONDS',60);
class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $data){}
    public function get_param(string $key):mixed{return $this->data[$key]??null;}
    public function offsetExists(mixed $offset):bool{return array_key_exists((string)$offset,$this->data);}
    public function offsetGet(mixed $offset):mixed{return $this->data[$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value):void{$this->data[(string)$offset]=$value;}
    public function offsetUnset(mixed $offset):void{unset($this->data[$offset]);}
}
class WP_REST_Response {
    public function __construct(private array $data,private int $status=200){}
    public function get_data():array{return $this->data;}
    public function get_status():int{return $this->status;}
}
class WP_Error {
    public function __construct(private string $code,private string $message,private array $meta=[]){}
    public function get_error_code():string{return $this->code;}
    public function get_error_data():array{return $this->meta;}
}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function rest_ensure_response(mixed $v):WP_REST_Response{return $v instanceof WP_REST_Response?$v:new WP_REST_Response($v);}
function get_current_user_id():int{return 11;}
function absint(mixed $v):int{return abs((int)$v);}
function get_user_by(string $field,int $id):object|false{return $id===11?(object)['ID'=>11]:false;}
function sanitize_text_field(string $s):string{return trim($s);}
function has_action(string $hook):int|false{return TestState::$listener?10:false;}
function do_action(string $hook,mixed $payload):void{TestState::$events[]=['hook'=>$hook,'payload'=>$payload];}
class TestState {
    public static bool $listener=true;public static bool $member=true;public static bool $suspended=false;
    public static bool $blocked=false;public static bool $hidden=false;public static bool $messageDeleted=false;
    public static array $events=[];public static array $audit=[];
}
class SN_DB {
    public static function table(string $type):string{return 'test_sn_'.$type;}
    public static function is_member(int $c,int $u):bool{return TestState::$member&&$c===33&&$u===11;}
    public static function is_blocked(int $u,int $sender):bool{return TestState::$blocked;}
    public static function audit(string $event,string $type,int $id,string $result,array $meta,int $actor):void{TestState::$audit[]=$event;}
}
class SN_Policy {public static function is_suspended(int $u):bool{return TestState::$suspended;}}
class SN_Message_Operations {public static function is_hidden(int $u,int $m):bool{return TestState::$hidden;}}
class SN_Communication_Crypto {
    public static function decrypt(string $cipher,string $context):string|WP_Error{return $cipher==='LOCKED'?'{"enabled":true}':'{"message_id":44,"label":"Private clinical note"}';}
}
class FakeDB {
    public object $row;public array $updates=[];public string $conversationStatus='active';
    public function __construct(){ $this->row=(object)['id'=>29,'feature_id'=>'F17-FUT-07','owner_id'=>11,'scope_type'=>'conversation','scope_id'=>33,'state'=>'active','version'=>3,'expires_at'=>'2026-11-15 00:00:00','updated_at'=>'2026-11-14 00:00:00','payload_cipher'=>'CIPHER'];}
    public function prepare(string $sql,mixed ...$args):string{return $sql.' /* '.json_encode($args).' */';}
    public function get_var(string $sql):mixed {
        if(str_contains($sql,'SELECT status FROM'))return $this->conversationStatus;
        return null;
    }
    public function get_row(string $sql):?object {
        if(str_contains($sql,"feature_id='F17-FUT-07'")) {
            if(str_contains($sql,"state='active'")&&$this->row->state!=='active')return null;
            if(str_contains($sql,'"')||true)return clone $this->row;
        }
        if(str_contains($sql,'FROM test_sn_messages'))return (object)['id'=>44,'conversation_id'=>33,'sender_id'=>22,'deleted_at'=>TestState::$messageDeleted?'2026-11-15':null];
        if(str_contains($sql,'F17-FUT-04'))return (object)['feature_id'=>'F17-FUT-04','owner_id'=>11,'scope_type'=>'conversation','scope_id'=>33,'payload_cipher'=>'LOCKED'];
        return null;
    }
    public function get_results(string $sql):array {
        if(str_contains($sql,"feature_id='F17-FUT-07'")&&$this->row->state==='active')return [clone $this->row];
        return [];
    }
    public function query(string $sql):int{return 1;}
    public function update(string $table,array $data,array $where):int {
        if(($where['id']??0)!==(int)$this->row->id)return 0;
        if(array_key_exists('state',$where)&&$where['state']!==$this->row->state)return 0;
        if(array_key_exists('version',$where)&&$where['version']!==(int)$this->row->version)return 0;
        $this->updates[]=[$data,$where];
        foreach($data as $key=>$value)$this->row->$key=$value;
        return 1;
    }
}
require_once SN_DIR.'includes/class-sn-future-superset.php';
function check(bool $state,string $message):void{if(!$state){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}}
function reset_case():void{global $wpdb;$wpdb=new FakeDB();TestState::$listener=true;TestState::$member=true;TestState::$suspended=false;TestState::$blocked=false;TestState::$hidden=false;TestState::$messageDeleted=false;TestState::$events=[];TestState::$audit=[];}
function req(array $overrides=[]):WP_REST_Request{return new WP_REST_Request(array_merge(['id'=>29,'expected_version'=>3,'remind_at'=>gmdate('c',time()+3600)],$overrides));}
reset_case();
$r=SN_Future_Superset::reschedule_reminder(req());
check($r instanceof WP_REST_Response&&$r->get_data()['version']===4,'Valid reminder reschedule must advance version.');
check(count($wpdb->updates)===1&&$wpdb->row->state==='active','Reschedule uses a single CAS update.');
reset_case();
$r=SN_Future_Superset::reschedule_reminder(req(['expected_version'=>2]));
check($r instanceof WP_Error&&$r->get_error_code()==='sn_reminder_stale_version','Old version must not update a reminder.');
check(!$wpdb->updates,'Stale reminder must have zero writes.');
reset_case();
$r=SN_Future_Superset::cancel_reminder(req());
check($r instanceof WP_REST_Response&&$r->get_data()['state']==='cancelled','Owner can cancel pending reminder.');
check($wpdb->row->state==='cancelled','Cancelled state must be persistent.');
$r=SN_Future_Superset::cancel_reminder(req());
check($r instanceof WP_REST_Response&&$r->get_data()['state']==='cancelled','Repeated cancellation must be idempotent.');
reset_case();$wpdb->row->state='firing';
$r=SN_Future_Superset::cancel_reminder(req());
check($r instanceof WP_Error&&$r->get_error_code()==='sn_reminder_already_processing','A dispatching reminder cannot be falsely cancelled.');
reset_case();TestState::$member=false;
$r=SN_Future_Superset::reschedule_reminder(req());
check($r instanceof WP_Error&&$r->get_error_code()==='sn_reminder_access_revoked','Reschedule must fail after membership revocation.');
reset_case();TestState::$hidden=true;
$r=SN_Future_Superset::reschedule_reminder(req());
check($r instanceof WP_Error&&$r->get_error_code()==='sn_reminder_access_revoked','Hidden message must not be rescheduled.');
reset_case();TestState::$listener=false;
SN_Future_Superset::cleanup();
check(!TestState::$events,'No registered File-19 consumer must not be called/delivered.');
check($wpdb->row->state==='active','Unconsumed reminder must remain eligible for retry.');
reset_case();TestState::$member=false;
SN_Future_Superset::cleanup();
check(!TestState::$events&&$wpdb->row->state==='cancelled','Removed participant must not get a queued private reminder.');
reset_case();
SN_Future_Superset::cleanup();
check(count(TestState::$events)===1,'Authorized reminder handoff must run once.');
$payload=TestState::$events[0]['payload'];
check($payload['body']==='Private reminder'&&(int)$payload['message_id']===0,'Locked conversation must suppress notification text and message preview reference.');
check((string)$wpdb->row->state==='fired','Successful handoff must advance to fired.');
echo "V12.3 reminder lifecycle, privacy and dispatch runtime contracts: PASS\n";
