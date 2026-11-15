<?php
/**
 * V12.3 F17-FUT-22/23: providers and projections may outlive authorization.
 * This isolated runtime test verifies no AI/search output after mid-request revoke.
 */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
define('SN_DIR',dirname(__DIR__).'/');
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
final class WP_REST_Request {
    public function __construct(private array $params=[]) {}
    public function get_param(string $name):mixed{return $this->params[$name]??null;}
}
final class SN_Policy {
    public static function age_state(int $user):string{return $GLOBALS['age_state'];}
}
final class SN_DB {
    public static array $audits=[];
    public static function is_member(int $c,int $u):bool{return $c===42&&$u===7&&$GLOBALS['member'];}
    public static function table(string $name):string{return 'wp_sn_'.$name;}
    public static function audit(string $event,string $type,int $id,string $outcome,array $metadata,int $user):void{
        self::$audits[]=['event'=>$event,'outcome'=>$outcome];
    }
}
final class SN_Communication_Crypto {
    public static function decrypt(string $cipher,string $context):string|WP_Error{
        $text=base64_decode($cipher,true);
        return $text===false?new WP_Error('invalid_cipher'):$text;
    }
}
final class SN_Message_Operations {
    public static array $hidden=[];
    public static function is_hidden(int $viewer,int $id):bool{return !empty(self::$hidden[$id]);}
}
final class SN_Message_Body {
    public static mixed $after_decrypt=null;
    public static function decrypt_row(object $row):string{
        if(is_callable(self::$after_decrypt))(self::$after_decrypt)();
        return (string)$row->body;
    }
}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function get_current_user_id():int{return 7;}
function absint(mixed $v):int{return abs((int)$v);}
function rest_sanitize_boolean(mixed $v):bool{return (bool)$v;}
function sanitize_key(string $v):string{return strtolower(preg_replace('/[^a-z0-9_-]/','',$v));}
function sanitize_text_field(string $v):string{return trim($v);}
function rest_ensure_response(mixed $value):WP_REST_Response{return new WP_REST_Response((array)$value);}
function has_filter(string $name):int|false{
    return in_array($name,['sn_network_ai_context_authorized','sn_network_ai_context_redact',
         'sn_network_ai_assistant_result','sn_network_private_semantic_search_result'],true)?10:false;
}
function apply_filters(string $name,mixed $value,mixed ...$args):mixed{
    if($name==='sn_network_ai_context_authorized')return $GLOBALS['ai_authorized'];
    if($name==='sn_network_ai_context_redact')return 'Redacted context';
    if($name==='sn_network_ai_minor_allowed')return false;
    if($name==='sn_network_ai_assistant_result'){
        if(is_callable($GLOBALS['on_provider']))($GLOBALS['on_provider'])();
        return ['draft'=>'PRIVATE AI OUTPUT'];
    }
    if($name==='sn_network_private_semantic_search_result'){
        if(is_callable($GLOBALS['on_provider']))($GLOBALS['on_provider'])();
        return ['message_ids'=>[101]];
    }
    return $value;
}
final class FakePrivateDB {
    public string $prefix='wp_';
    public array $messages=[];
    public function __construct(){
        $this->messages=[
            101=>(object)[
                'id'=>101,'conversation_id'=>42,'deleted_at'=>null,
                'sender_id'=>8,'created_at'=>'2026-11-15 01:00:00',
                'body'=>'PRIVATE MESSAGE CONTENT',
            ]
        ];
    }
    public function prepare(string $sql,mixed ...$params):string{
        foreach($params as $value){
            $replacement=is_int($value)?(string)$value:"'".str_replace("'","''",(string)$value)."'";
            $sql=(string)preg_replace('/%[ds]/',$replacement,$sql,1);
        }
        return $sql;
    }
    public function get_row(string $sql):?object{
        if(str_contains($sql,'wp_sn_future_records')){
            return (object)[
                'feature_id'=>'F17-FUT-23','owner_id'=>7,
                'scope_type'=>'user','scope_id'=>7,'state'=>'active',
                'payload_cipher'=>base64_encode(json_encode([
                    'subtype'=>'consent','enabled'=>$GLOBALS['semantic_consent'],
                ])),
            ];
        }
        if(str_contains($sql,'wp_sn_messages')){
            preg_match('/WHERE id=(\d+)/',$sql,$m);
            return $this->messages[(int)($m[1]??0)]??null;
        }
        return null;
    }
}
$wpdb=new FakePrivateDB();
require SN_DIR.'includes/class-sn-future24-review-hardening-g.php';

$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{
    ++$checks;if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$reset=static function()use(&$wpdb):void{
    $wpdb=new FakePrivateDB();
    $GLOBALS['member']=true;
    $GLOBALS['ai_authorized']=true;
    $GLOBALS['semantic_consent']=true;
    $GLOBALS['age_state']='adult';
    $GLOBALS['on_provider']=null;
    SN_Message_Body::$after_decrypt=null;
    SN_Message_Operations::$hidden=[];
    SN_DB::$audits=[];
};
$ai=static function():WP_REST_Response|WP_Error{
    return SN_Future24_Review_Hardening_G::ai_assistant(
        new WP_REST_Request([
            'conversation_id'=>42,'task'=>'summary','explicit_consent'=>true,
            'message_ids'=>[101]
        ])
    );
};
$semantic=static function():WP_REST_Response|WP_Error{
    return SN_Future24_Review_Hardening_G::semantic_search(
        new WP_REST_Request(['conversation_id'=>42,'query'=>'private notes'])
    );
};
$reset();
$ok=$ai();
$check($ok instanceof WP_REST_Response&&isset($ok->get_data()['result']),
    'Current consent, membership and File16 authorization allow a bounded AI response');
$check(count(SN_DB::$audits)===1,'Successful AI request is audit-safe');

$reset();
$GLOBALS['on_provider']=static function():void{$GLOBALS['member']=false;};
$out=$ai();
$check($out instanceof WP_Error&&$out->get_error_code()==='sn_ai_context_stale',
    'Mid-provider membership revoke prevents private AI result display');
$check(!SN_DB::$audits,'Denied AI result cannot be logged as successful');

$reset();
$GLOBALS['on_provider']=static function():void{$GLOBALS['ai_authorized']=false;};
$out=$ai();
$check($out instanceof WP_Error&&$out->get_error_code()==='sn_ai_context_stale',
    'Mid-provider File16 governance revoke prevents private result display');

$reset();
$GLOBALS['on_provider']=static function()use(&$wpdb):void{$wpdb->messages[101]->deleted_at='2026-11-15 02:00:00';};
$out=$ai();
$check($out instanceof WP_Error,'Message deletion during AI provider run blocks response');

$reset();
$GLOBALS['on_provider']=static function():void{SN_Message_Operations::$hidden[101]=true;};
$out=$ai();
$check($out instanceof WP_Error,'Message concealment during AI processing blocks response');

$reset();
$GLOBALS['on_provider']=static function():void{$GLOBALS['age_state']='minor';};
$out=$ai();
$check($out instanceof WP_Error&&$out->get_error_code()==='sn_ai_minor_restricted',
    'Changed guardian/minor policy is checked again before AI output');

$reset();
$ok=$semantic();
$check($ok instanceof WP_REST_Response&&count($ok->get_data()['items'])===1,
    'Private semantic search returns authorized currently visible snippet');
$check(count(SN_DB::$audits)===1,'Authorized semantic result is audited');

$reset();
SN_Message_Body::$after_decrypt=static function():void{$GLOBALS['member']=false;};
$out=$semantic();
$check($out instanceof WP_Error&&$out->get_error_code()==='sn_semantic_context_stale',
    'Membership revoked during snippet projection prevents returning accumulated content');
$check(!SN_DB::$audits,'Stale semantic result is not marked successful');

$reset();
SN_Message_Body::$after_decrypt=static function():void{$GLOBALS['semantic_consent']=false;};
$out=$semantic();
$check($out instanceof WP_Error&&$out->get_error_code()==='sn_semantic_context_stale',
    'Consent revoked during snippet projection prevents private result display');

$reset();
SN_Message_Body::$after_decrypt=static function():void{SN_Message_Operations::$hidden[101]=true;};
$out=$semantic();
$check($out instanceof WP_Error&&$out->get_error_code()==='sn_semantic_context_stale',
    'Message hidden after projection check is not returned');

$effective=file_get_contents(SN_DIR.'includes/class-sn-fifth-fresh-knowledge-hardening.php');
$check(is_string($effective)&&str_contains($effective,'SN_Future24_Review_Hardening_G::ai_assistant')
    &&str_contains($effective,'SN_Future24_Review_Hardening_G::semantic_search')
    &&str_contains($effective,"'override_routes'], 3100"),
    'Priority-3100 effective REST override delegates to the audited Future24-G handler');

echo "V12.3 post-provider private output revocation: $checks PASS, 0 FAIL\n";
