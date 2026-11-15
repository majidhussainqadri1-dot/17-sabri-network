<?php
/** F17-FUT-11: saved private view must not leak locked titles/activity. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $data){}
    public function offsetExists(mixed $k):bool{return isset($this->data[(string)$k]);}
    public function offsetGet(mixed $k):mixed{return $this->data[(string)$k]??null;}
    public function offsetSet(mixed $k,mixed $v):void{$this->data[(string)$k]=$v;}
    public function offsetUnset(mixed $k):void{unset($this->data[(string)$k]);}
    public function get_param(string $k):mixed{return $this->data[$k]??null;}
}
class WP_REST_Response {
    public function __construct(private array $data){}
    public function get_data():array{return $this->data;}
}
class WP_Error {
    public function __construct(private string $code,private string $text,private array $extra=[]){}
    public function get_error_code():string{return $this->code;}
}
function rest_ensure_response(array $x):WP_REST_Response{return new WP_REST_Response($x);}
function is_wp_error(mixed $x):bool{return $x instanceof WP_Error;}
function get_current_user_id():int{return 7;}
function absint(mixed $v):int{return abs((int)$v);}
function sanitize_key(string $x):string{return strtolower(preg_replace('/[^a-z0-9_-]/','',$x));}
function rest_sanitize_boolean(mixed $v):bool{return (bool)$v;}
function has_filter(string $hook):bool{return false;}
class SN_DB {
    public static bool $member=true;
    public static function is_member(int $c,int $u):bool{return self::$member&&in_array($c,[17,18],true)&&$u===7;}
    public static function table(string $name):string{return 'wp_sn_'.$name;}
}
class SN_Communication_Crypto {
    public static function decrypt(string $encrypted,string $context):string{return '{"name":"Saved view","criteria":{}}';}
}
class SN_Future_Superset {
    public static string $locked='deny';
    public static function sensitive_preview_access(int $user,int $c):bool|WP_Error{
        if($user!==7||!SN_DB::is_member($c,$user))return new WP_Error('not_found','No membership');
        if($c!==17)return true;
        if(self::$locked==='error')return new WP_Error('sn_conversation_lock_state_unavailable','Encrypted lock invalid');
        return self::$locked==='allow';
    }
}
class FakeDB {
    public string $prefix='wp_';
    public string $last_error='';
    public bool $failedQuery=false;
    public function prepare(string $sql,mixed ...$args):string{return $sql;}
    public function get_row(string $sql):?object{
        if(!str_contains($sql,"feature_id='F17-FUT-11'"))throw new RuntimeException('Unexpected saved view query.');
        return (object)['id'=>55,'feature_id'=>'F17-FUT-11','owner_id'=>7,'scope_type'=>'user','scope_id'=>7,'payload_cipher'=>'cipher'];
    }
    public function get_results(string $sql):array|false{
        if($this->failedQuery){$this->last_error='DB connection lost';return false;}
        $this->last_error='';
        if(!str_contains($sql,'wp_sn_members')||!str_contains($sql,'wp_sn_conversations'))throw new RuntimeException('Unexpected conversation query');
        return [
          (object)['id'=>17,'type'=>'direct','title'=>'Secret patient conversation','updated_at'=>'private timestamp','last_message_id'=>50,'last_read_message_id'=>10,'is_muted'=>0,'is_archived'=>0],
          (object)['id'=>18,'type'=>'group','title'=>'Public to this member','updated_at'=>'nonsecret timestamp','last_message_id'=>30,'last_read_message_id'=>20,'is_muted'=>0,'is_archived'=>0]
        ];
    }
}
$wpdb=new FakeDB();
require_once dirname(__DIR__).'/includes/class-sn-future24-review-hardening-a.php';
function check(bool $condition,string $reason):void{if(!$condition){fwrite(STDERR,"FAIL: $reason\n");exit(1);}}
$req=new WP_REST_Request(['id'=>55]);
$denied=SN_Future24_Review_Hardening_A::smart_view_results($req);
check($denied instanceof WP_REST_Response,'Saved view should return authorized nonlocked items.');
$data=$denied->get_data();
check(count($data['items'])===1&&$data['items'][0]['conversation_id']===18,'Locked conversation metadata must be excluded, not merely visually hidden.');
check(!str_contains(json_encode($data),'Secret patient conversation')&&!str_contains(json_encode($data),'private timestamp'),'Locked title and activity must not leak into API output.');
SN_Future_Superset::$locked='allow';
$allowed=SN_Future24_Review_Hardening_A::smart_view_results($req);
check($allowed instanceof WP_REST_Response&&count($allowed->get_data()['items'])===2,'Approved step-up may include sensitive conversation metadata.');
SN_Future_Superset::$locked='error';
$bad=SN_Future24_Review_Hardening_A::smart_view_results($req);
check($bad instanceof WP_Error&&$bad->get_error_code()==='sn_conversation_lock_state_unavailable','Corrupt lock must abort saved view instead of leaking or silently skipping it.');
$wpdb->failedQuery=true;
$queryFailed=SN_Future24_Review_Hardening_A::smart_view_results($req);
check($queryFailed instanceof WP_Error&&$queryFailed->get_error_code()==='sn_smart_view_query_unavailable','Failed private-view DB query must not be reported as an empty successful result.');
$wpdb->failedQuery=false;
SN_DB::$member=false;
$revoked=SN_Future24_Review_Hardening_A::smart_view_results($req);
check($revoked instanceof WP_REST_Response && count($revoked->get_data()['items'])===0,'Revoked member cannot see private smart view results.');
echo "V12.3 smart-view sensitive lock / revocation runtime contracts: PASS\n";
