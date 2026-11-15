<?php
/** Isolated runtime doubles for atomic F17-FUT-05/06 team assignment. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_REST_Request implements ArrayAccess {
    private array $data;
    public function __construct(array $data){$this->data=$data;}
    public function get_param(string $key){return $this->data[$key]??null;}
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
function get_current_user_id():int{return 7;}
function absint(mixed $v):int{return abs((int)$v);}
function current_user_can(string $cap):bool{return false;}
function rest_sanitize_boolean(mixed $v):bool{return $v===true||in_array((string)$v,['true','1','yes','on'],true);}
class SN_Policy {public static bool $suspended=false;public static function is_suspended(int $u):bool{return self::$suspended;}}
class SN_DB {
    public static array $audits=[];
    public static function table(string $key):string{return 'test_sn_'.$key;}
    public static function audit(string $event,string $type,int $id,string $result,array $meta,int $user):void{self::$audits[]=[$event,$id,$result,$user];}
}
class FakeDB {
    public array $queries=[]; public string $status='active'; public bool $lockAllowed=true;
    public function prepare(string $sql,mixed ...$args):string{return $sql;}
    public function get_var(string $sql):mixed{if(str_contains($sql,'GET_LOCK'))return $this->lockAllowed?1:0;if(str_contains($sql,'RELEASE_LOCK')){$this->queries[]='RELEASE_LOCK';return 1;}return null;}
    public function query(string $sql):int|false {$this->queries[]=$sql;return 1;}
    public function get_row(string $sql):?object {if(str_contains($sql,'SELECT status FROM'))return (object)['status'=>$this->status];return null;}
}
require_once __DIR__.'/../includes/class-sn-future-superset-part-1.php';
class TeamHarness {
    use SN_Future_Superset_Part_1;
    public static int $version=0;
    public static bool $manager=true;
    public static bool $assignee=true;
    public static bool $failAssignment=false;
    public static array $upserts=[];
    private static function conversation_manager(int $c,int $u):bool{return self::$manager&&$c>0&&$u===7;}
    private static function conversation_member(int $c,int $u):bool{return self::$assignee&&$c>0&&$u>0;}
    private static function active_record(string $feature,int $owner,string $type,int $scope):?object{return self::$version?(object)['version'=>self::$version,'payload_cipher'=>'dummy']:null;}
    private static function decode(object $row):array{return ['enabled'=>true,'assignee_id'=>12];}
    private static function enum(string $value,array $values,string $fallback):string{return in_array($value,$values,true)?$value:$fallback;}
    private static function upsert_record(string $feature,int $owner,string $type,int $scope,array $data,string $client,?string $expires):int|WP_Error {
        self::$upserts[]=$feature;
        if(self::$failAssignment&&$feature==='F17-FUT-06')return new WP_Error('forced_assignment_failure','Simulated second write failure',['status'=>500]);
        return count(self::$upserts);
    }
    private static function records_table():string{return 'test_sn_future_records';}
    private static function now():string{return '2026-11-15 00:00:00';}
    private static function db_error():WP_Error{return new WP_Error('sn_db_error','DB error',['status'=>500]);}
    private static function error(string $code,string $message,int $status):WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
    private static function not_found():WP_Error{return new WP_Error('not_found','Not found',['status'=>404]);}
}
function check(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function reset_case():void{global $wpdb;$wpdb=new FakeDB();TeamHarness::$version=0;TeamHarness::$manager=true;TeamHarness::$assignee=true;TeamHarness::$failAssignment=false;TeamHarness::$upserts=[];SN_Policy::$suspended=false;SN_DB::$audits=[];}
function request(array $overrides=[]):WP_REST_Request{return new WP_REST_Request(array_merge(['id'=>20,'expected_version'=>0,'assignee_id'=>12,'enabled'=>true,'status'=>'pending'],$overrides));}
reset_case();
$r=TeamHarness::set_team_inbox(request());
check($r instanceof WP_REST_Response,'Valid assignment should succeed.');
check($r->get_data()['version']===1,'A successful assignment must increment public version.');
check(TeamHarness::$upserts===['F17-FUT-05','F17-FUT-06'],'Both canonical facts must be stored together.');
check(in_array('COMMIT',$wpdb->queries,true),'Two-record write must commit transaction.');
check(!in_array('ROLLBACK',$wpdb->queries,true),'Successful write must not rollback.');
check(in_array('RELEASE_LOCK',$wpdb->queries,true),'Named lock must always be released.');
check(count(SN_DB::$audits)===1,'Successful assignment requires audit.');
reset_case();TeamHarness::$version=5;
$r=TeamHarness::get_team_inbox(request());
check($r instanceof WP_REST_Response&&$r->get_data()['version']===5,'Read DTO must expose current version.');
$r=TeamHarness::set_team_inbox(request(['expected_version'=>4]));
check($r instanceof WP_Error&&$r->get_error_code()==='sn_team_stale_version','Stale version must be rejected.');
check(!TeamHarness::$upserts,'Stale version must not write either record.');
check(in_array('ROLLBACK',$wpdb->queries,true)&&in_array('RELEASE_LOCK',$wpdb->queries,true),'Stale write must rollback and release lock.');
reset_case();TeamHarness::$failAssignment=true;
$r=TeamHarness::set_team_inbox(request());
check($r instanceof WP_Error&&$r->get_error_code()==='forced_assignment_failure','Second record failure must propagate.');
check(in_array('ROLLBACK',$wpdb->queries,true)&&!in_array('COMMIT',$wpdb->queries,true),'Second write failure must rollback the first write.');
check(count(SN_DB::$audits)===0,'Failed assignment must not emit success audit.');
reset_case();$wpdb->status='archived';
$r=TeamHarness::set_team_inbox(request());
check($r instanceof WP_Error&&$r->get_error_code()==='sn_team_conversation_closed','Archived conversation must reject handoff.');
check(!TeamHarness::$upserts&&in_array('ROLLBACK',$wpdb->queries,true),'Closed conversation must not write.');
reset_case();TeamHarness::$assignee=false;
$r=TeamHarness::set_team_inbox(request());
check($r instanceof WP_Error&&$r->get_error_code()==='sn_assignment_member_required','Revoked assignee must be rejected.');
check(!TeamHarness::$upserts,'Revoked assignee must not create assignment.');
reset_case();$wpdb->lockAllowed=false;
$r=TeamHarness::set_team_inbox(request());
check($r instanceof WP_Error&&$r->get_error_code()==='sn_team_lock_unavailable','Named-lock contention must fail closed.');
check(!TeamHarness::$upserts,'Busy lock must prevent writes.');
reset_case();
$r=TeamHarness::set_team_inbox(request(['expected_version'=>null]));
check($r instanceof WP_Error&&$r->get_error_code()==='sn_team_precondition_required','Missing precondition must fail closed.');
check(!TeamHarness::$upserts,'Missing version must not write.');
echo "V12.3 team assignment atomicity and CAS runtime contracts: PASS\n";
