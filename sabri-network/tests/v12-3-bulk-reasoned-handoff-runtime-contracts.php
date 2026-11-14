<?php
/**
 * V12.3 F17-FUT-10/F17-FUT-06: bulk assignment is reasoned, version bound,
 * atomically receipted and idempotent. Isolated fake-DB regression only.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SN_DIR', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
final class WP_Error {
    public function __construct(private string $code,private string $message='',private array $data=[]) {}
    public function get_error_code(): string {return $this->code;}
    public function get_error_data(): array {return $this->data;}
}
final class WP_REST_Response {
    public function __construct(private array $data,private int $status=200) {}
    public function get_data(): array {return $this->data;}
    public function get_status(): int {return $this->status;}
}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params=[]) {}
    public function get_param(string $key): mixed{return $this->params[$key]??null;}
    public function offsetExists(mixed $offset): bool{return isset($this->params[$offset]);}
    public function offsetGet(mixed $offset): mixed{return $this->params[$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value): void{$this->params[$offset]=$value;}
    public function offsetUnset(mixed $offset): void{unset($this->params[$offset]);}
}
final class SN_DB {
    public static function is_member(int $c,int $u):bool{return $c>0&&$u>0;}
    public static function member_role(int $c,int $u):string{return 'owner';}
    public static function table(string $name):string{return 'wp_sn_'.$name;}
    public static function audit(string $action,string $type,int $id,string $result,array $data,int $actor):void{}
}
final class SN_Communication_Crypto {
    public static function encrypt(string $plain,string $context):string{return base64_encode($plain);}
    public static function decrypt(string $cipher,string $context):string|WP_Error{
        $plain=base64_decode($cipher,true);
        return $plain===false?new WP_Error('invalid_cipher'):$plain;
    }
}
function absint(mixed $x):int{return abs((int)$x);}
function get_current_user_id():int{return 7;}
function user_can(int $user,string $cap):bool{return false;}
function is_wp_error(mixed $x):bool{return $x instanceof WP_Error;}
function has_filter(string $key):bool{return $key==='sn_network_team_inbox_delegation_allowed';}
function apply_filters(string $key,mixed $default,mixed ...$args):bool{return true;}
function sanitize_text_field(string $value):string{return trim($value);}
function sanitize_textarea_field(string $value):string{return trim($value);}
function wp_unslash(string $x):string{return $x;}
function rest_sanitize_boolean(mixed $x):bool{return (bool)$x;}
function sanitize_key(string $s):string{return strtolower(preg_replace('/[^a-z0-9_-]/','',$s));}
function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}
function current_time(string $type,bool $utc=false):string{return '2026-11-14 16:50:00';}
function rest_ensure_response(mixed $x):WP_REST_Response{return new WP_REST_Response((array)$x);}

final class FakeBulkDB {
    public string $prefix='wp_';
    public int $insert_id=0;
    public array $queries=[];
    public array $receipts=[];
    public ?object $team=null;
    public ?object $assignment=null;
    public int $writes=0;
    public bool $fail_receipt=false;
    private int $next_id=99;
    private ?array $snapshot=null;
    public function seed():void {
        $this->team=(object)[
            'id'=>55,'feature_id'=>'F17-FUT-05','owner_id'=>0,'scope_type'=>'conversation',
            'scope_id'=>42,'state'=>'active','version'=>3,
            'payload_cipher'=>base64_encode(json_encode(['assignee_id'=>0,'status'=>'open']))
        ];
    }
    public function prepare(string $sql,mixed ...$arguments):string {
        foreach($arguments as $value){
            $sql=preg_replace('/%[ds]/',is_int($value)?(string)$value:"'".str_replace("'","''",(string)$value)."'",(string)$sql,1);
        }
        return $sql;
    }
    public function get_row(string $sql):?object {
        if(str_contains($sql,'wp_sn_members'))return (object)['role'=>'owner','id'=>1001];
        if(str_contains($sql,"feature_id='F17-FUT-04'"))return null;
        if(str_contains($sql,'client_key=')){
            preg_match('/client_key=\'([0-9a-f]{64})\'/',$sql,$matches);
            return $this->receipts[$matches[1]??'']??null;
        }
        if(str_contains($sql,"feature_id='F17-FUT-05'"))return $this->team;
        if(str_contains($sql,"feature_id='F17-FUT-06'")&&str_contains($sql,'owner_id=0'))return $this->assignment;
        return null;
    }
    public function get_var(string $sql):int{return 1001;}
    public function query(string $sql):int|false {
        $this->queries[]=$sql;
        if($sql==='START TRANSACTION'){
            $this->snapshot=unserialize(serialize([$this->team,$this->assignment,$this->receipts]));
            return 1;
        }
        if($sql==='ROLLBACK'){
            if($this->snapshot!==null)[$this->team,$this->assignment,$this->receipts]=$this->snapshot;
            $this->snapshot=null;
            return 1;
        }
        if($sql==='COMMIT'){$this->snapshot=null;return 1;}
        return 1;
    }
    public function insert(string $table,array $data):int|false {
        if($this->fail_receipt&&($data['feature_id']??'')==='F17-FUT-06'&&($data['owner_id']??0)===7)return false;
        $key=$data['client_key']??'';
        if(isset($this->receipts[$key]))return false;
        ++$this->writes;
        $this->insert_id=++$this->next_id;
        $row=(object)(['id'=>$this->insert_id]+$data);
        if(($data['feature_id']??'')==='F17-FUT-06'){
            if(($data['owner_id']??0)===0)$this->assignment=$row;
            else $this->receipts[$key]=$row;
        }
        return 1;
    }
    public function update(string $table,array $data,array $where):int|false {
        $row=$this->team&&$where['id']===$this->team->id?$this->team:null;
        if(!$row&&$this->assignment&&$where['id']===$this->assignment->id)$row=$this->assignment;
        if(!$row||$row->version!==$where['version'])return 0;
        ++$this->writes;
        foreach($data as $k=>$v)$row->{$k}=$v;
        return 1;
    }
}
require SN_DIR . 'includes/class-sn-future-superset.php';
$checks=0;
$check=static function(bool $success,string $why)use(&$checks):void{
    ++$checks;
    if(!$success){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$wpdb=new FakeBulkDB();
$base=['action'=>'assign','conversation_ids'=>[42],'assignee_id'=>11,
    'client_id'=>'bulk-operation-12345','preview'=>false];
$missingReason=SN_Future_Superset::bulk_conversations(new WP_REST_Request($base+['expected_versions'=>[42=>3]]));
$check($missingReason instanceof WP_Error&&$missingReason->get_error_code()==='sn_bulk_handoff_reason_required',
    'Assignment rejects missing reason before creating a job');
$missingVersion=SN_Future_Superset::bulk_conversations(new WP_REST_Request($base+['handoff_reason'=>'Clinic shift']));
$check($missingVersion instanceof WP_Error&&$missingVersion->get_error_code()==='sn_bulk_handoff_versions_required',
    'Assignment rejects missing version map');
$check($wpdb->writes===0,'Invalid handoff requests create no persistent jobs');

$run=new ReflectionMethod(SN_Future_Superset::class,'process_bulk_item');
$wpdb=new FakeBulkDB();$wpdb->seed();
$invalid=$run->invoke(null,42,7,'assign',['assignee_id'=>11],false,301);
$check(($invalid['status']??'')==='handoff_reason_or_version_required',
    'Already-queued legacy assignment without reason/version fails closed');
$check($wpdb->writes===0,'Legacy queue cannot mutate the team');

$params=['assignee_id'=>11,'handoff_reason'=>'Clinic shift','expected_versions'=>['42'=>3]];
$success=$run->invoke(null,42,7,'assign',$params,false,301);
$check(($success['status']??'')==='changed','Versioned reasoned assignment completes as one operation');
$check(in_array('COMMIT',$wpdb->queries,true),'Assignment completes with transaction COMMIT');
$check($wpdb->team->version===4,'Team version increases exactly once');
$decoded=json_decode(base64_decode($wpdb->team->payload_cipher),true);
$check(($decoded['assignee_id']??0)===11,'Canonical team assignee updated');
$receiptKey=hash('sha256','bulk-handoff-receipt:301:42');
$receipt=$wpdb->receipts[$receiptKey]??null;
$check(is_object($receipt),'Handoff receipt is durably stored');
$rd=$receipt?json_decode(base64_decode($receipt->payload_cipher),true):[];
$check(($rd['handoff_reason']??'')==='Clinic shift'&&($rd['team_version']??0)===4,
    'Receipt preserves reason and expected team version');
$count=$wpdb->writes;
$again=$run->invoke(null,42,7,'assign',$params,false,301);
$check(($again['status']??'')==='already_applied','Idempotent retry reports previously committed handoff');
$check($wpdb->writes===$count,'Idempotent retry creates no duplicate receipt or assignment');

$changed=$run->invoke(null,42,7,'assign',$params+['handoff_reason'=>'Altered'],false,301);
$check($wpdb->writes===$count,'Reused job key cannot mutate prior transaction');

$wpdb=new FakeBulkDB();$wpdb->seed();$wpdb->fail_receipt=true;
$failed=$run->invoke(null,42,7,'assign',$params,false,302);
$check(($failed['status']??'')==='failed','Receipt failure cannot produce successful assignment');
$check($wpdb->team->version===3&&!$wpdb->assignment&&!$wpdb->receipts,
    'Receipt failure rolls back canonical assignment state');
echo "V12.3 bulk reasoned handoff: $checks PASS, 0 FAIL\n";
