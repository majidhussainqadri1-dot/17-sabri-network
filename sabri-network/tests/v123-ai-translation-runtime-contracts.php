<?php
/** F17-FUT-22 source-level runtime refusal tests: no unauthorized translation provider calls. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_REST_Request {
    public function __construct(private array $params){}
    public function get_param(string $key):mixed{return $this->params[$key]??null;}
}
class WP_REST_Response {}
class WP_Error {
    public function __construct(private string $code,private string $msg,private array $info=[]){}
    public function get_error_code():string{return $this->code;}
}
class TestPolicy {public static bool $localeAllowed=false;public static int $calls=0;}
class SN_DB {public static function is_member(int $c,int $u):bool{return $c===33&&$u===7;}}
function get_current_user_id():int{return 7;}
function absint(mixed $v):int{return abs((int)$v);}
function rest_sanitize_boolean(mixed $v):bool{return $v===true||$v==='true'||$v==='1';}
function sanitize_key(string $v):string{return strtolower(preg_replace('/[^a-z0-9_-]/i','',$v));}
function sanitize_text_field(string $v):string{return trim(strip_tags($v));}
function has_filter(string $tag):int|false{return $tag==='sn_network_ai_translation_locale_allowed'&&TestPolicy::$localeAllowed?10:false;}
function apply_filters(string $tag,mixed $value,mixed ...$args):mixed {
    TestPolicy::$calls++;
    return $tag==='sn_network_ai_translation_locale_allowed'?TestPolicy::$localeAllowed:$value;
}
require_once __DIR__.'/../includes/class-sn-future24-review-hardening-g.php';
function must(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function req(array $override=[]):WP_REST_Request{return new WP_REST_Request(array_merge(['conversation_id'=>33,'task'=>'translate','target_locale'=>'ur','explicit_consent'=>true],$override));}
$r=SN_Future24_Review_Hardening_G::ai_assistant(req(['explicit_consent'=>false]));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_ai_consent_required','Selected private content must require opt-in on every request.');
$r=SN_Future24_Review_Hardening_G::ai_assistant(req(['target_locale'=>'../../secret']));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_ai_target_locale_invalid','Invalid language tag must not reach an external provider.');
$r=SN_Future24_Review_Hardening_G::ai_assistant(req(['target_locale'=>'zz']));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_ai_translation_locale_not_approved','Unsupported language must be explicitly approved.');
TestPolicy::$localeAllowed=true;
$r=SN_Future24_Review_Hardening_G::ai_assistant(req(['target_locale'=>'ur']));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_ai_governance_unavailable','Locale approval alone must not bypass File-16 task/context authorization.');
$r=SN_Future24_Review_Hardening_G::ai_assistant(req(['task'=>'unapproved']));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_ai_task_invalid','Unknown task must be rejected before any message is read.');
$r=SN_Future24_Review_Hardening_G::ai_assistant(req(['task'=>'summary']));
must($r instanceof WP_Error&&$r->get_error_code()==='sn_ai_governance_unavailable','Nontranslation tasks also need current File-16 governance.');
echo "V12.3 provider-gated AI translation refusal runtime contracts: PASS\n";
