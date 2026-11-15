<?php
/** File 17 V12.3 F17-FUT-04: lock preview and corrupted-policy fail-closed runtime. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
class WP_REST_Request {
    public function __construct(private string $route, private string $method='GET',private array $params=[]){}
    public function get_route():string{return $this->route;}
    public function get_method():string{return $this->method;}
    public function get_param(string $key):mixed{return $this->params[$key]??null;}
}
class WP_REST_Response {
    public function __construct(private array $data,private int $status=200){}
    public function get_data():array{return $this->data;}
    public function set_data(array $data):void{$this->data=$data;}
    public function get_status():int{return $this->status;}
}
class WP_Error {
    public function __construct(private string $code,private string $message,private array $data=[]){}
    public function get_error_code():string{return $this->code;}
    public function get_error_data():array{return $this->data;}
}
function absint(mixed $v):int{return abs((int)$v);}
function get_current_user_id():int{return 7;}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function apply_filters(string $name,mixed $default,...$args):mixed{
    return $name==='sn_network_step_up_verified' ? (bool)($GLOBALS['stepup_verified']??false) : $default;
}
require_once dirname(__DIR__).'/includes/class-sn-future-superset-part-1.php';
final class SN_Future_Superset {
    use SN_Future_Superset_Part_1;
    public static array $locks=[];
    private static function active_record(string $feature,int $user,string $scope,int $id):?object {
        if($feature!=='F17-FUT-04'||$user!==7||$scope!=='conversation')return null;
        return array_key_exists($id,self::$locks)?(object)['id'=>$id]:null;
    }
    private static function decode(object $row):array|WP_Error{
        $d=self::$locks[$row->id]??null;
        return $d==='corrupt'?new WP_Error('cannot_decrypt','decryption unavailable'):($d?:[]);
    }
    private static function error(string $code,string $message,int $status):WP_Error{
        return new WP_Error($code,$message,['status'=>$status]);
    }
}
function check(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function rows():WP_REST_Response{return new WP_REST_Response(['conversations'=>[
    ['id'=>17,'title'=>'Private peer','description'=>'Sensitive notes','last_message'=>['body'=>'secret message'],'avatar'=>'private-avatar','unread_count'=>8,'can_post'=>true],
    ['id'=>18,'title'=>'Public chat','description'=>'Open','last_message'=>['body'=>'visible message'],'unread_count'=>2,'can_post'=>true],
]]);}
$list=new WP_REST_Request('/sabri-network/v2/conversations');
SN_Future_Superset::$locks=[17=>['enabled'=>true,'step_up_ttl'=>120]];
$GLOBALS['stepup_verified']=false;
$blocked=SN_Future_Superset::post_dispatch(rows(),null,$list);
check($blocked->get_status()===200,'Listing remains usable when individual lock is active.');
$data=$blocked->get_data();
check($data['conversations'][0]['last_message']===null,'Locked preview text must not be returned.');
check($data['conversations'][0]['title']==='Locked conversation','Locked title must be neutral.');
check($data['conversations'][0]['description']===''&&$data['conversations'][0]['unread_count']===0,'Locked metadata must be suppressed.');
check(($data['conversations'][0]['sensitive_locked']??false)===true,'Client must receive an explicit lock badge state.');
check($data['conversations'][1]['last_message']['body']==='visible message','Unlocked conversation must remain usable.');
$pre=SN_Future_Superset::pre_dispatch(null,null,new WP_REST_Request('/sabri-network/v2/conversations/17'));
check($pre instanceof WP_Error&&$pre->get_error_code()==='sn_conversation_step_up_required','Direct locked conversation must require step-up.');
$GLOBALS['stepup_verified']=true;
$unlocked=SN_Future_Superset::post_dispatch(rows(),null,$list)->get_data();
check($unlocked['conversations'][0]['last_message']['body']==='secret message','Fresh approved step-up may reveal content.');
SN_Future_Superset::$locks=[17=>['enabled'=>false]];
$GLOBALS['stepup_verified']=false;
$disabled=SN_Future_Superset::post_dispatch(rows(),null,$list)->get_data();
check($disabled['conversations'][0]['last_message']['body']==='secret message','Disabled lock must not redact.');
SN_Future_Superset::$locks=[17=>'corrupt'];
$failed=SN_Future_Superset::post_dispatch(rows(),null,$list);
check($failed instanceof WP_REST_Response && $failed->get_status()===503,'Corrupt stored lock state must fail the entire preview listing closed.');
check(!str_contains(json_encode($failed->get_data()),'secret message'),'Corrupt-policy failure must not leak message previews.');
$preFailed=SN_Future_Superset::pre_dispatch(null,null,new WP_REST_Request('/sabri-network/v2/conversations/17'));
check($preFailed instanceof WP_Error&&$preFailed->get_error_code()==='sn_conversation_lock_state_unavailable','Direct access with corrupt lock must fail closed.');
check(SN_Future_Superset::pre_dispatch(null,null,new WP_REST_Request('/other-plugin/v1/example'))===null,'Other REST namespaces remain unaffected.');
echo "V12.3 sensitive lock preview and corruption fail-closed: PASS\n";
