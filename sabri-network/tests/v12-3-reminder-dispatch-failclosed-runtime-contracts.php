<?php
/**
 * V12.3 F17-FUT-07: no File19 listener must not yield fired terminal state.
 * Also protects stable downstream retry identity and failed finalization.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
define('SN_DIR', dirname(__DIR__).'/');
define('MINUTE_IN_SECONDS',60);
final class WP_Error {
    public function __construct(private string $code,private string $message='',private array $data=[]) {}
    public function get_error_code():string{return $this->code;}
    public function get_error_data():array{return $this->data;}
}
final class SN_Communication_Crypto {
    public static function decrypt(string $text,string $context):string|WP_Error{
        $plain=base64_decode($text,true);
        return $plain===false?new WP_Error('invalid_cipher'):$plain;
    }
}
final class SN_DB {
    public static array $audits=[];
    public static bool $member=true;
    public static array $blocked=[];
    public static function is_member(int $conversation,int $owner):bool {
        return self::$member&&$conversation===42&&$owner===7;
    }
    public static function table(string $name):string{return 'wp_sn_'.$name;}
    public static function is_blocked(int $user,int $sender):bool{return !empty(self::$blocked[$sender]);}
    public static function audit(string $event,string $object,int $id,string $state,array $details,int $user=0):void{
        self::$audits[]=['event'=>$event,'object'=>$object,'id'=>$id,'state'=>$state,'details'=>$details,'user'=>$user];
    }
}
final class SN_Message_Operations {
    public static array $hidden=[];
    public static function is_hidden(int $viewer,int $message):bool{return !empty(self::$hidden[$message]);}
}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function current_time(string $type,bool $gmt=false):string{return gmdate('Y-m-d H:i:s');}
function sanitize_text_field(string $text):string{return trim($text);}
$GLOBALS['notification_listener']=false;
$GLOBALS['notifications']=[];
function has_action(string $name):int|false{
    return $name==='sn_network_notification_requested'&&$GLOBALS['notification_listener']?10:false;
}
function do_action(string $name,mixed ...$args):void{
    $GLOBALS['notifications'][]=['name'=>$name,'payload'=>$args[0]??null];
}
final class FakeReminderDB {
    public string $prefix='wp_';
    public ?object $due=null;
    public bool $fail_finalization=false;
    public array $queries=[];
    public int $state_writes=0;
    public function seed(int $id=17):void {
        $this->due=(object)[
            'id'=>$id,'feature_id'=>'F17-FUT-07','owner_id'=>7,
            'scope_type'=>'conversation','scope_id'=>42,'state'=>'active',
            'version'=>1,'updated_at'=>gmdate('Y-m-d H:i:s',time()-900),
            'expires_at'=>gmdate('Y-m-d H:i:s',time()-60),
            'payload_cipher'=>base64_encode(json_encode(['label'=>'Read now','message_id'=>11]))
        ];
    }
    public function prepare(string $sql,mixed ...$args):string{return $sql;}
    public function get_row(string $sql):?object {
        if(str_contains($sql,'wp_sn_messages')){
            return (object)['id'=>11,'conversation_id'=>42,'sender_id'=>8,'deleted_at'=>null];
        }
        return null;
    }
    public function query(string $sql):int{
        $this->queries[]=$sql;return 1;
    }
    public function get_results(string $sql):array{
        if(str_contains($sql,"feature_id='F17-FUT-07'")&&str_contains($sql,"state='active'")){
            return $this->due&&$this->due->state==='active'?[(object)clone $this->due]:[];
        }
        return [];
    }
    public function update(string $table,array $data,array $where):int|false{
        if(!$this->due||(int)($where['id']??0)!==(int)$this->due->id)return 0;
        if(isset($where['state'])&&(string)$where['state']!==(string)$this->due->state)return 0;
        if(isset($where['version'])&&(int)$where['version']!==(int)$this->due->version)return 0;
        if($this->fail_finalization&&($data['state']??'')==='fired')return false;
        foreach($data as $k=>$v)$this->due->{$k}=$v;
        ++$this->state_writes;return 1;
    }
}
require SN_DIR.'includes/class-sn-future-superset.php';
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void {
    ++$checks;if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$wpdb=new FakeReminderDB();$wpdb->seed();
$GLOBALS['notification_listener']=false;$GLOBALS['notifications']=[];
SN_DB::$audits=[];
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='active','Without File19 listener reminder remains retryable, never fired');
$check(count($GLOBALS['notifications'])===0,'Missing File19 consumer cannot receive a ghost notification');
$check(count(SN_DB::$audits)===1&&SN_DB::$audits[0]['event']==='future_reminder_handoff_failed',
    'Unaccepted handoff is privacy-safe audited');
$check($wpdb->state_writes===2,'Failed dispatch releases the firing claim without dropping the reminder');

$GLOBALS['notification_listener']=true;$GLOBALS['notifications']=[];
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='fired','Registered hook receives a request before fired state is set');
$check(count($GLOBALS['notifications'])===1,'One reminder sends one notification-request hook');
$payload=$GLOBALS['notifications'][0]['payload'];
$check(($payload['idempotency_key']??'')==='file17-future-reminder:17',
    'Downstream retry identity remains stable across failed first dispatch');
$check(($payload['recipient_id']??0)===7&&($payload['entity_id']??0)===42,
    'Source owner and scope are preserved without raw private message content');
SN_Future_Superset::cleanup();
$check(count($GLOBALS['notifications'])===1,'Already fired reminder never re-emits on next cleanup');

$wpdb=new FakeReminderDB();$wpdb->seed(18);
$wpdb->fail_finalization=true;$GLOBALS['notifications']=[];SN_DB::$audits=[];
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='active','Finalization write failure leaves reminder available for retry');
$check(count($GLOBALS['notifications'])===1,'A failed finalization does not conceal an attempted handoff');
$check((SN_DB::$audits[0]['details']['reason']??'')==='reminder_finalization_conflict',
    'Failed finalization reason is auditable without exposing message body');
$wpdb->fail_finalization=false;
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='fired','Successful retry can eventually finalize');
$check(count($GLOBALS['notifications'])===2,
    'A recoverable retry may re-emit; downstream File19 must deduplicate by idempotency key');
$check($GLOBALS['notifications'][0]['payload']['idempotency_key']===
       $GLOBALS['notifications'][1]['payload']['idempotency_key'],
    'Retried dispatch retains exact downstream deduplication identity');

echo "V12.3 F17-FUT-07 fail-closed handoff: $checks PASS, 0 FAIL\n";
