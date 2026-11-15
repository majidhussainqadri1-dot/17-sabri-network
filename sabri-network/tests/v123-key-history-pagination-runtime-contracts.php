<?php
/** V12.3: key-history pagination and explicitly unverified local-only proof. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params){}
    public function get_param(string $key):mixed{return $this->params[$key]??null;}
    public function offsetExists(mixed $k):bool{return isset($this->params[(string)$k]);}
    public function offsetGet(mixed $k):mixed{return $this->params[(string)$k]??null;}
    public function offsetSet(mixed $k,mixed $v):void{$this->params[(string)$k]=$v;}
    public function offsetUnset(mixed $k):void{unset($this->params[(string)$k]);}
}
class WP_REST_Response {public function __construct(private array $data){}public function get_data():array{return $this->data;}}
class WP_Error {public function __construct(private string $code,private string $message,private array $data=[]){}public function get_error_code():string{return $this->code;}}
function absint(mixed $x):int{return abs((int)$x);}
function get_current_user_id():int{return 7;}
function rest_ensure_response(array $value):WP_REST_Response{return new WP_REST_Response($value);}
class SN_DB {
    public static bool $blocked=false;
    public static function is_blocked(int $a,int $b):bool{return self::$blocked;}
    public static function share_active_conversation(int $a,int $b):bool{return true;}
}
class FakeHistoryDB {
    public string $last_error='';
    public bool $failQuery=false;
    public array $history=[];
    public function __construct(){
        for($i=1;$i<=230;$i++)$this->history[]=(object)['id'=>$i,'device_id'=>'mobile','event'=>'rotated','fingerprint'=>'f-'.$i,'previous_fingerprint'=>'f-'.($i-1),'entry_hash'=>'hash-'.$i,'created_at'=>'2026-11-15 00:00:00'];
    }
    public function prepare(string $query,mixed ...$args):string{
        $i=0;return preg_replace_callback('/%d/',static fn($m)=>strval($args[$i++]??0),$query);
    }
    public function get_results(string $sql):array|false{
        if($this->failQuery){$this->last_error='simulated unavailable DB';return false;}
        $this->last_error='';
        if(!preg_match('/WHERE user_id=([0-9]+) AND id>([0-9]+) ORDER BY id ASC LIMIT ([0-9]+)/',$sql,$m))throw new RuntimeException('Unexpected query: '.$sql);
        [$entire,$user,$after,$limit]=$m;
        if((int)$user!==8)throw new RuntimeException('Wrong target account selected');
        return array_slice(array_values(array_filter($this->history,static fn($row)=>$row->id>(int)$after)),0,(int)$limit);
    }
}
$wpdb=new FakeHistoryDB();
require_once dirname(__DIR__).'/includes/class-sn-future-superset-part-1.php';
final class SN_Future_Superset {
    use SN_Future_Superset_Part_1;
    private static function key_log_table():string{return 'wp_sn_future_key_log';}
    private static function not_found():WP_Error{return new WP_Error('not_found','Not authorized');}
    private static function error(string $code,string $message,int $status):WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
}
function check(bool $yes,string $message):void{if(!$yes){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$seen=[];$after=0;
foreach([100,100,30] as $expected){
    $response=SN_Future_Superset::key_transparency(new WP_REST_Request(['user_id'=>8,'after_id'=>$after,'limit'=>100]));
    check($response instanceof WP_REST_Response,'Authorized page must be delivered');
    $data=$response->get_data();
    check(count($data['entries'])===$expected,'Expected page size '.$expected);
    check($data['proof_status']==='local-audit-only' && $data['signed_checkpoint_verified']===false && $data['consistency_proof_verified']===false,'Unsigned history must never claim independent transparency proof.');
    $ids=array_map(static fn($v)=>(int)$v->id,$data['entries']);
    $seen=array_merge($seen,$ids);$after=$data['next_after_id'];
    check($after===end($ids),'Pagination must return the last actual row as its next keyset cursor.');
    check($data['has_more']===($expected!==30),'has_more must track remaining audit entries.');
}
check(count($seen)===230&&count(array_unique($seen))===230&&$seen[0]===1&&end($seen)===230,'Key history must not truncate or duplicate the most recent entries.');
$wpdb->failQuery=true;
$bad=SN_Future_Superset::key_transparency(new WP_REST_Request(['user_id'=>8,'after_id'=>0]));
check($bad instanceof WP_Error && $bad->get_error_code()==='sn_key_history_unavailable','DB error must not be presented as an empty verified history.');
$wpdb->failQuery=false; SN_DB::$blocked=true;
$denied=SN_Future_Superset::key_transparency(new WP_REST_Request(['user_id'=>8]));
check($denied instanceof WP_Error && $denied->get_error_code()==='not_found','Blocked contact must not access another user key history.');
echo "V12.3 key history pagination, privacy and proof-status contracts: PASS\n";
