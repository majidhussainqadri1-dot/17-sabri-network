<?php
/** F17-FUT-02/03: simulated device-key transaction / audit-log rollback. */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
class WP_REST_Request {
    public function __construct(private array $params){}
    public function get_param(string $name):mixed{return $this->params[$name]??null;}
}
class WP_REST_Response {
    public function __construct(private array $data,private int $status=200){}
    public function get_data():array{return $this->data;}
}
class WP_Error {
    public function __construct(private string $code,private string $message,private array $data=[]){}
    public function get_error_code():string{return $this->code;}
}
function rest_ensure_response(array $v):WP_REST_Response{return new WP_REST_Response($v);}
function is_wp_error(mixed $x):bool{return $x instanceof WP_Error;}
function get_current_user_id():int{return 7;}
function sanitize_text_field(string $s):string{return trim($s);}
function sanitize_key(string $s):string{return strtolower(preg_replace('/[^a-z0-9_-]/','',$s));}
function has_filter(string $key):bool{return $key==='sn_network_device_public_key_valid';}
function apply_filters(string $key,mixed $default,...$args):mixed{return $key==='sn_network_device_public_key_valid';}
function current_time(string $format,bool $utc):string{return '2026-11-15 20:00:00';}
class FakeDB {
    public string $prefix='wp_';
    public string $last_error='';
    public array $keys=[];
    public array $logs=[];
    public bool $failLog=false;
    public bool $locked=false;
    public array $queries=[];
    private ?array $saved=null;
    public function prepare(string $sql,mixed ...$args):string {
        $values=array_map(static fn($v)=>is_int($v)?(string)$v:"'".str_replace("'","''",(string)$v)."'",$args);
        $i=0;return preg_replace_callback('/%[ds]/',static function($m)use(&$i,$values){return $values[$i++]??'NULL';},$sql);
    }
    public function get_var(string $sql):mixed {
        $this->last_error='';
        if(str_contains($sql,'GET_LOCK(')){$this->locked=true;return 1;}
        if(str_contains($sql,'RELEASE_LOCK(')){$this->locked=false;return 1;}
        if(str_contains($sql,'SELECT entry_hash FROM'))return $this->logs?end($this->logs)['entry_hash']:null;
        throw new RuntimeException('Unexpected get_var: '.$sql);
    }
    public function query(string $sql):int|false {
        $this->queries[]=$sql;
        if($sql==='START TRANSACTION'){$this->saved=[$this->keys,$this->logs];return 1;}
        if($sql==='ROLLBACK'){if($this->saved){[$this->keys,$this->logs]=$this->saved;}$this->saved=null;return 1;}
        if($sql==='COMMIT'){$this->saved=null;return 1;}
        throw new RuntimeException('Unexpected query: '.$sql);
    }
    public function get_row(string $sql):?object {
        if(!str_contains($sql,'wp_sn_future_device_keys')||!str_contains($sql,'FOR UPDATE'))throw new RuntimeException('Unprotected device key read');
        $v=$this->keys['7:mobile']??null;
        return $v?(object)$v:null;
    }
    public function update(string $table,array $data,array $where):int|false {
        if($table!=='wp_sn_future_device_keys')throw new RuntimeException('Unexpected update table');
        $this->keys['7:mobile']=array_merge($this->keys['7:mobile'],$data);
        return 1;
    }
    public function insert(string $table,array $data):int|false {
        if($table==='wp_sn_future_device_keys'){$this->keys['7:mobile']=$data+['id'=>1];return 1;}
        if($table==='wp_sn_future_key_log'){
            if($this->failLog){$this->last_error='simulated key log write failure';return false;}
            $this->logs[]=$data;$this->last_error='';return 1;
        }
        throw new RuntimeException('Unexpected insert table: '.$table);
    }
}
$wpdb=new FakeDB();
require_once dirname(__DIR__).'/includes/class-sn-future-superset-part-1.php';
require_once dirname(__DIR__).'/includes/class-sn-future-superset-core.php';
final class SN_Future_Superset {use SN_Future_Superset_Part_1,SN_Future_Superset_Core;}
function check(bool $v,string $label):void{if(!$v){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
$first=SN_Future_Superset::register_device_key(new WP_REST_Request(['device_id'=>'mobile','algorithm'=>'x25519','public_key'=>'valid-public-key-a']));
check($first instanceof WP_REST_Response,'Initial registration must succeed.');
check(count($wpdb->logs)===1&&count($wpdb->keys)===1,'First key needs exactly one audit commitment.');
check($wpdb->locked===false,'Key global lock must release on success.');
$retry=SN_Future_Superset::register_device_key(new WP_REST_Request(['device_id'=>'mobile','algorithm'=>'x25519','public_key'=>'valid-public-key-a']));
check($retry instanceof WP_REST_Response && !empty($retry->get_data()['unchanged']),'Idempotent repeat must return unchanged.');
check(count($wpdb->logs)===1,'Unchanged key must not append fake rotation.');
$previous=$wpdb->keys['7:mobile']['fingerprint'];$hash=$wpdb->logs[0]['entry_hash'];
$wpdb->failLog=true;
$failed=SN_Future_Superset::register_device_key(new WP_REST_Request(['device_id'=>'mobile','algorithm'=>'x25519','public_key'=>'valid-public-key-b']));
check($failed instanceof WP_Error&&$failed->get_error_code()==='sn_device_key_log_failed','Audit persistence failure must be explicitly rejected.');
check($wpdb->keys['7:mobile']['fingerprint']===$previous,'Failed key log must roll back rotated public key.');
check(count($wpdb->logs)===1,'Failed rotation must not leave a fake log entry.');
check($wpdb->locked===false,'Global key lock must release on rollback.');
$wpdb->failLog=false;
$second=SN_Future_Superset::register_device_key(new WP_REST_Request(['device_id'=>'mobile','algorithm'=>'x25519','public_key'=>'valid-public-key-b']));
check($second instanceof WP_REST_Response,'Key rotation must succeed with healthy audit storage.');
check(count($wpdb->logs)===2 && $wpdb->logs[1]['event']==='rotated','Rotation must append exactly one audit event.');
check($wpdb->logs[1]['previous_fingerprint']===$previous,'Rotation must link previous key fingerprint.');
check($wpdb->logs[1]['entry_hash']!==$hash,'New key event must advance hash commitment.');
check($wpdb->locked===false,'Global lock must release on rotation.');
echo "V12.3 atomic device key and audit-log rollback contracts: PASS\n";
