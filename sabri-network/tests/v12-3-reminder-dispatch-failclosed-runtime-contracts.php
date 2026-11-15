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
    public static bool $active=true;
    public static array $blocked=[];
    public static function is_member(int $conversation,int $owner):bool {
        return self::$member&&$conversation===42&&$owner===7;
    }
    public static function is_active_conversation_member(int $c,int $u):bool{return self::$active&&self::is_member($c,$u);}
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
function absint(mixed $value):int{return abs((int)$value);}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function current_time(string $type,bool $gmt=false):string{return gmdate('Y-m-d H:i:s');}
function sanitize_text_field(string $text):string{return trim($text);}
function sanitize_key(string $text):string{return strtolower(preg_replace('/[^a-z0-9_-]/','',$text));}
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
    public bool $fail_cancellation=false;
    public bool $message_exists=true;
    public bool $message_deleted=false;
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
            if(!$this->message_exists)return null;
            return (object)['id'=>11,'conversation_id'=>42,'sender_id'=>8,
                'deleted_at'=>$this->message_deleted?'2026-11-14 10:00:00':null];
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
        if($this->fail_cancellation&&($data['state']??'')==='cancelled')return false;
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
$GLOBALS['notification_listener']=true;$GLOBALS['notifications']=[];
SN_DB::$audits=[];
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='active','File19 API absence cannot mark a reminder fired');
$check(count($GLOBALS['notifications'])===0,'Legacy action listener is not a File19 persistence receipt');
$check((SN_DB::$audits[0]['details']['reason']??'')==='file19_notification_contract_unavailable',
    'Missing File19 canonical interface is recorded without private content');
$check($wpdb->state_writes===2,'Missing provider releases firing claim for a retry');

$GLOBALS['file19_mode']='processed';
$GLOBALS['file19_events']=[];
$GLOBALS['file19_contracts']=[];
if(!function_exists('sun_register_notification_producer')){
    function sun_register_notification_producer(string $name,array $contract):bool {
        $GLOBALS['file19_contracts'][]=['name'=>$name,'contract'=>$contract];
        return $GLOBALS['file19_mode']!=='registration_failed';
    }
    function sun_ingest_domain_event(array $event):array|WP_Error {
        $GLOBALS['file19_events'][]=$event;
        $mode=(string)$GLOBALS['file19_mode'];
        if($mode==='exception')throw new RuntimeException('provider_failure');
        if($mode==='error')return new WP_Error('temporary_unavailable');
        if($mode==='invalid')return ['status'=>'queued','event_public_id'=>'sun-event-1'];
        if($mode==='missing_id')return ['status'=>'processed'];
        return ['status'=>$mode==='duplicate'?'duplicate':'processed',
            'event_public_id'=>'sun-event-1'];
    }
}
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='fired','Processed File19 intake finalizes the due reminder');
$check(count($GLOBALS['file19_events'])===1,'Exactly one canonical File19 event was ingested');
$check(count($GLOBALS['notifications'])===0,'Old unacknowledged hook is never emitted as a parallel transport');
$event=$GLOBALS['file19_events'][0];
$check(($event['producer']??'')==='sabri-file17'
    &&($event['owner']??'')==='File 17'
    &&($event['event_type']??'')==='Communication.ReminderDue'
    &&($event['schema_version']??'')==='1.0.0',
    'Exact owner, producer, event and schema match File19 source contracts');
$check(($event['event_id']??'')==='f17-reminder-17'
    &&($event['idempotency_key']??'')==='f17-reminder-17',
    'Stable source identity permits cross-plugin idempotent retries');
$check(($event['recipients'][0]['user_id']??0)===7
    &&($event['sensitivity']??'')==='sensitive',
    'Only the canonical recipient ID and sensitivity are shared');
$check(!str_contains(json_encode($event),'Read now')
    &&!str_contains(json_encode($event),'message_id')
    &&!str_contains(json_encode($event),'sender_id'),
    'No private reminder label or chat contents leave File17');
$check(($GLOBALS['file19_contracts'][0]['contract']['event_types'][0]??'')
    ==='Communication.ReminderDue'
    &&($GLOBALS['file19_contracts'][0]['contract']['owner']??'')==='File 17',
    'Producer registry authorizes only File17-specific reminder facts');
SN_Future_Superset::cleanup();
$check(count($GLOBALS['file19_events'])===1,'Already fired reminder cannot duplicate File19 intake');

$wpdb=new FakeReminderDB();$wpdb->seed(18);
$wpdb->fail_finalization=true;$GLOBALS['file19_events']=[];SN_DB::$audits=[];
$GLOBALS['file19_mode']='processed';
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='active','Finalization write failure keeps canonical reminder retryable');
$check(count($GLOBALS['file19_events'])===1,'File19 may have durably accepted before File17 finalization fails');
$check((SN_DB::$audits[0]['details']['reason']??'')==='reminder_finalization_conflict',
    'File17 failed finalization stays auditable');
$wpdb->fail_finalization=false;$GLOBALS['file19_mode']='duplicate';
SN_Future_Superset::cleanup();
$check($wpdb->due->state==='fired','Idempotent File19 duplicate receipt allows finalization after prior acceptance');
$check(count($GLOBALS['file19_events'])===2
    &&$GLOBALS['file19_events'][0]['event_id']===$GLOBALS['file19_events'][1]['event_id'],
    'Retry never changes producer-scoped event identity');

foreach(['registration_failed','error','invalid','missing_id','exception'] as $i=>$mode){
    $wpdb=new FakeReminderDB();$wpdb->seed(30+$i);
    $GLOBALS['file19_events']=[];SN_DB::$audits=[];$GLOBALS['file19_mode']=$mode;
    SN_Future_Superset::cleanup();
    $check($wpdb->due->state==='active',"Provider failure ($mode) cannot return terminal success");
    $check(count(SN_DB::$audits)===1
        &&SN_DB::$audits[0]['event']==='future_reminder_handoff_failed',
        "Provider failure ($mode) is recorded for retry");
}
$GLOBALS['file19_mode']='processed';

// Simulate a changed authorization AFTER the earlier due-preflight hook.
foreach(['revoked','archived','hidden','blocked','deleted','missing','scope','failed_cancel'] as $i=>$mode){
    $wpdb=new FakeReminderDB();$wpdb->seed(50+$i);
    SN_DB::$member=true;SN_DB::$active=true;
    SN_DB::$blocked=[];SN_Message_Operations::$hidden=[];
    if($mode==='revoked'||$mode==='failed_cancel')SN_DB::$member=false;
    if($mode==='archived')SN_DB::$active=false;
    if($mode==='hidden')SN_Message_Operations::$hidden[11]=true;
    if($mode==='blocked')SN_DB::$blocked[8]=true;
    if($mode==='deleted')$wpdb->message_deleted=true;
    if($mode==='missing')$wpdb->message_exists=false;
    if($mode==='scope')$wpdb->due->scope_id=0;
    if($mode==='failed_cancel')$wpdb->fail_cancellation=true;
    SN_DB::$audits=[];$GLOBALS['file19_events']=[];
    SN_Future_Superset::cleanup();
    $check(count($GLOBALS['file19_events'])===0,
        "Changed authorization ($mode) cannot reach File19 intake");
    $expected=$mode==='failed_cancel'?'firing':'cancelled';
    $check($wpdb->due->state===$expected,
        "Revoked due reminder ($mode) is never marked fired");
    $check((SN_DB::$audits[0]['event']??'')==='future_reminder_authorization_revoked',
        "Denied reminder ($mode) is audited with no message body");
}
SN_DB::$member=true;SN_DB::$active=true;SN_DB::$blocked=[];
SN_Message_Operations::$hidden=[];
echo "V12.3 F17-FUT-07 File19 durable intake and ACL: $checks PASS, 0 FAIL\n";
