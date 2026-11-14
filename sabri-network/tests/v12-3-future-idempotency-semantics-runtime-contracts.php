<?php
/**
 * V12.3 Future-24: client ID reuse cannot change operation, subject, payload
 * or an exact requested reminder time. Deterministic mocked database test.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SN_DIR', dirname(__DIR__) . '/');
final class WP_Error {
    public function __construct(private string $code,private string $message='',private array $data=[]) {}
    public function get_error_code(): string {return $this->code;}
    public function get_error_data(): array {return $this->data;}
}
final class SN_Communication_Crypto {
    public static function encrypt(string $plain,string $context): string {return base64_encode($plain);}
    public static function decrypt(string $cipher,string $context): string|WP_Error {
        $plain=base64_decode($cipher,true);
        return $plain===false?new WP_Error('bad_cipher'): $plain;
    }
}
function wp_json_encode(mixed $value,int $flags=0): string|false{return json_encode($value,$flags);}
function current_time(string $kind,bool $utc=false): string{return '2026-11-14 16:50:00';}
function is_wp_error(mixed $value): bool{return $value instanceof WP_Error;}

final class FutureIdempotencyFakeDB {
    public string $prefix='wp_';
    public int $insert_id=0;
    public int $successful_inserts=0;
    public array $rows=[];
    public ?string $lookup=null;
    private int $next_id=0;
    public function insert(string $table,array $data): int|false {
        $key=$data['client_key']??null;
        if($key===null||isset($this->rows[$key]))return false;
        ++$this->successful_inserts;
        $this->insert_id=++$this->next_id;
        $this->rows[$key]=(object)(['id'=>$this->insert_id]+$data);
        return 1;
    }
    public function prepare(string $sql,mixed ...$args): string {
        $this->lookup=(string)($args[0]??'');
        return $sql;
    }
    public function get_row(string $sql): ?object {
        return $this->lookup!==null?($this->rows[$this->lookup]??null):null;
    }
}
require SN_DIR . 'includes/class-sn-future-superset.php';
$checks=0;
$check=static function(bool $passed,string $why)use(&$checks):void{
    ++$checks;
    if(!$passed){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$method=new ReflectionMethod(SN_Future_Superset::class,'create_record');
$write=static function(
    string $feature,int $owner,string $scope_type,int $scope,
    array $data,string $client,?string $expiry=null,string $state='active'
)use($method):int|WP_Error{
    return $method->invoke(null,$feature,$owner,$scope_type,$scope,$data,$client,$expiry,$state);
};
$wpdb=new FutureIdempotencyFakeDB();
$one=$write('F17-FUT-08',7,'user',7,['title'=>'Intro','body'=>'Text'],'7:template:unique-1');
$check(is_int($one)&&$one===1,'First caller-owned creation stores exactly one record');
$same=$write('F17-FUT-08',7,'user',7,['body'=>'Text','title'=>'Intro'],'7:template:unique-1');
$check($same===$one,'Matching retry with reordered associative keys returns original ID');
$changed=$write('F17-FUT-08',7,'user',7,['title'=>'Intro','body'=>'DIFFERENT'],'7:template:unique-1');
$check($changed instanceof WP_Error && $changed->get_error_code()==='sn_future_idempotency_conflict','Different content with reused key is a conflict');
$check(($changed->get_error_data()['status']??0)===409,'Semantic key mismatch is HTTP 409');
$crossFeature=$write('F17-FUT-11',7,'user',7,['title'=>'Intro','body'=>'Text'],'7:template:unique-1');
$check($crossFeature instanceof WP_Error,'Cross-feature client key reuse cannot return a foreign record');
$crossOwner=$write('F17-FUT-08',8,'user',8,['title'=>'Intro','body'=>'Text'],'7:template:unique-1');
$check($crossOwner instanceof WP_Error,'Cross-owner key reuse is refused');
$crossScope=$write('F17-FUT-08',7,'conversation',42,['title'=>'Intro','body'=>'Text'],'7:template:unique-1');
$check($crossScope instanceof WP_Error,'Cross-scope key reuse is refused');
$missing=$write('F17-FUT-08',7,'user',7,['title'=>'Intro','body'=>'Text'],'');
$check($missing instanceof WP_Error && $missing->get_error_code()==='sn_future_client_id_required',
    'Missing client key remains rejected before storage');

$data=['message_id'=>99,'label'=>'Read later'];
$reminder=$write('F17-FUT-07',7,'conversation',42,$data,'7:reminder:unique-2','2026-11-15 10:00:00');
$check(is_int($reminder)&&$reminder===2,'First reminder creates one record');
$check($write('F17-FUT-07',7,'conversation',42,$data,'7:reminder:unique-2','2026-11-15 10:00:00')===$reminder,
    'Exact reminder retry returns original ID');
$changedTime=$write('F17-FUT-07',7,'conversation',42,$data,'7:reminder:unique-2','2026-11-15 11:00:00');
$check($changedTime instanceof WP_Error && $changedTime->get_error_code()==='sn_future_idempotency_conflict',
    'Same reminder key cannot silently accept a different requested time');
$changedMessage=$write('F17-FUT-07',7,'conversation',42,['message_id'=>100,'label'=>'Read later'],'7:reminder:unique-2','2026-11-15 10:00:00');
$check($changedMessage instanceof WP_Error,'Reminder key cannot silently select another message');

$bulk=['action'=>'archive','conversation_ids'=>[42,43],'params'=>[],'preview'=>false,
    'cursor'=>0,'results'=>[],'requested_at'=>'2026-11-14 16:50:00'];
$job=$write('F17-FUT-10',7,'user',7,$bulk,'7:bulk:unique-3','2026-11-21 16:50:00','queued');
$check(is_int($job)&&$job===3,'Bulk job creation stores an original job');
$stored=$wpdb->rows[hash('sha256','7:bulk:unique-3')];
$after=$bulk;
$after['cursor']=2;
$after['results']=[['conversation_id'=>42,'status'=>'success'],['conversation_id'=>43,'status'=>'success']];
$stored->payload_cipher=base64_encode(json_encode($after));
$stored->state='completed';
$retry=$bulk;
$retry['requested_at']='2026-11-14 16:51:00';
$check($write('F17-FUT-10',7,'user',7,$retry,'7:bulk:unique-3','2026-11-21 16:51:00','queued')===$job,
    'Completed bulk job retry tolerates generated time and progress metadata');
$retry['conversation_ids']=[42,44];
$changedBulk=$write('F17-FUT-10',7,'user',7,$retry,'7:bulk:unique-3','2026-11-21 16:51:00','queued');
$check($changedBulk instanceof WP_Error,'Bulk client key cannot be reused for changed conversation IDs');
$check($wpdb->successful_inserts===3,'All duplicate/mismatched requests made zero additional records');

echo "V12.3 Future-24 semantic retry binding: $checks PASS, 0 FAIL\n";
