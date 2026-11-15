<?php
/** V12.3 FUT-07: a user's stale view cannot change or cancel an updated reminder. */
declare(strict_types=1);
date_default_timezone_set('UTC');
define('ABSPATH',__DIR__.'/');
define('SN_DIR',dirname(__DIR__).'/');
define('DAY_IN_SECONDS',86400);
final class WP_Error {
    public function __construct(private string $code,private string $message='',private array $data=[]) {}
    public function get_error_code():string{return $this->code;}
    public function get_error_data():array{return $this->data;}
}
final class WP_REST_Response {
    public function __construct(private array $data,private int $status=200) {}
    public function get_data():array{return $this->data;}
    public function get_status():int{return $this->status;}
}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params=[]) {}
    public function get_param(string $name):mixed{return $this->params[$name]??null;}
    public function set_param(string $name,mixed $value):void{$this->params[$name]=$value;}
    public function offsetExists(mixed $key):bool{return isset($this->params[$key]);}
    public function offsetGet(mixed $key):mixed{return $this->params[$key]??null;}
    public function offsetSet(mixed $key,mixed $value):void{$this->params[$key]=$value;}
    public function offsetUnset(mixed $key):void{unset($this->params[$key]);}
}
final class SN_DB {
    public static array $audits=[];
    public static bool $is_member=true;
    public static function is_member(int $conversation,int $user):bool {
        return self::$is_member&&$conversation===42&&$user===7;
    }
    public static function audit(string $event,string $object,int $id,string $result,array $meta,int $user):void{
        self::$audits[]=['event'=>$event,'id'=>$id,'result'=>$result];
    }
}
function get_current_user_id():int{return 7;}
function absint(mixed $x):int{return abs((int)$x);}
function is_wp_error(mixed $x):bool{return $x instanceof WP_Error;}
function current_time(string $type,bool $utc=false):string{return gmdate('Y-m-d H:i:s');}
function rest_ensure_response(mixed $value):WP_REST_Response{return new WP_REST_Response((array)$value);}
final class ReminderMutationDB {
    public string $prefix='wp_';
    public ?object $row=null;
    public int $writes=0;
    public bool $force_update_failure=false;
    public function seed(string $state='active',int $version=3):void{
        $this->row=(object)[
            'id'=>31,'feature_id'=>'F17-FUT-07','owner_id'=>7,'scope_id'=>42,
            'state'=>$state,'version'=>$version,
            'expires_at'=>gmdate('Y-m-d H:i:s',time()+3000),
        ];
    }
    public function prepare(string $sql,mixed ...$args):string{return $sql;}
    public function get_row(string $sql):?object{return $this->row?(object)clone $this->row:null;}
    public function update(string $table,array $data,array $where):int|false{
        if($this->force_update_failure)return false;
        if(!$this->row||(int)($where['id']??0)!==(int)$this->row->id
           ||(string)($where['state']??'')!==(string)$this->row->state
           ||(int)($where['version']??0)!==(int)$this->row->version)return 0;
        foreach($data as $k=>$v)$this->row->{$k}=$v;
        ++$this->writes;return 1;
    }
}
require SN_DIR.'includes/class-sn-future24-review-hardening-m.php';
$checkCount=0;
$check=static function(bool $ok,string $why)use(&$checkCount):void{
    ++$checkCount;
    if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$time=gmdate('Y-m-d\TH:i:s\Z',time()+7200);
$request=static function(array $extra=[])use($time):WP_REST_Request{
    return new WP_REST_Request(array_replace(['id'=>31,'remind_at'=>$time],$extra));
};
$wpdb=new ReminderMutationDB();$wpdb->seed();
$none=SN_Future24_Review_Hardening_M::reschedule($request());
$check($none instanceof WP_Error&&$none->get_error_code()==='sn_reminder_version_required',
    'Reschedule rejects missing caller-reviewed expected_version');
$stale=SN_Future24_Review_Hardening_M::reschedule($request(['expected_version'=>2]));
$check($stale instanceof WP_Error&&$stale->get_error_code()==='sn_reminder_stale',
    'Stale client cannot overwrite newer scheduled time');
$check($wpdb->writes===0,'Missing/stale reschedules produce no writes');

$ok=SN_Future24_Review_Hardening_M::reschedule($request(['expected_version'=>3]));
$check($ok instanceof WP_REST_Response&&$ok->get_data()['version']===4,
    'Current version may reschedule and returns the advanced version');
$check($wpdb->row->version===4,'Reschedule persists version CAS');

$cancelNone=SN_Future24_Review_Hardening_M::cancel($request());
$check($cancelNone instanceof WP_Error&&$cancelNone->get_error_code()==='sn_reminder_version_required',
    'Cancellation requires the exact last-seen version');
$cancelStale=SN_Future24_Review_Hardening_M::cancel($request(['expected_version'=>3]));
$check($cancelStale instanceof WP_Error&&$cancelStale->get_error_code()==='sn_reminder_stale',
    'Stale cancellation cannot remove freshly updated reminder');
$check($wpdb->row->state==='active'&&$wpdb->writes===1,
    'Stale mutations preserve active reminder state');
$cancelled=SN_Future24_Review_Hardening_M::cancel($request(['expected_version'=>4]));
$check($cancelled instanceof WP_REST_Response
    &&($cancelled->get_data()['state']??'')==='cancelled'
    &&($cancelled->get_data()['version']??0)===5,
    'Current-version cancellation is explicit, durable and increments version');
$check($wpdb->row->state==='cancelled','Cancellation persists terminal state');
$again=SN_Future24_Review_Hardening_M::cancel($request());
$check($again instanceof WP_REST_Response&&($again->get_data()['state']??'')==='cancelled',
    'Already-terminal cancellation remains idempotent without extra writes');
$check($wpdb->writes===2,'Terminal cancellation retry does not mutate again');
$terminal=SN_Future24_Review_Hardening_M::reschedule($request(['expected_version'=>5]));
$check($terminal instanceof WP_Error&&$terminal->get_error_code()==='sn_reminder_terminal',
    'Terminal reminder cannot be resurrected via reschedule');

$wpdb=new ReminderMutationDB();$wpdb->seed();
SN_DB::$is_member=false;
$denied=SN_Future24_Review_Hardening_M::reschedule($request(['expected_version'=>3]));
$check($denied instanceof WP_Error&&$denied->get_error_code()==='sn_reminder_access_revoked',
    'Removed conversation member cannot create a new due-time acknowledgement');
$check($wpdb->writes===0&&$wpdb->row->version===3,'Revocation produces no mutation');
$ownerCancel=SN_Future24_Review_Hardening_M::cancel($request(['expected_version'=>3]));
$check($ownerCancel instanceof WP_REST_Response&&$wpdb->row->state==='cancelled',
    'Owner can still cancel their own stale reminder after access revocation');
SN_DB::$is_member=true;

$wpdb=new ReminderMutationDB();$wpdb->seed();
$wpdb->force_update_failure=true;
$conflict=SN_Future24_Review_Hardening_M::reschedule($request(['expected_version'=>3]));
$check($conflict instanceof WP_Error&&$conflict->get_error_code()==='sn_reminder_stale',
    'Storage failure cannot return false success');
$check($wpdb->row->version===3&&$wpdb->writes===0,'Failed CAS preserves prior version');
echo "V12.3 reminder versioned mutation: $checkCount PASS, 0 FAIL\n";
