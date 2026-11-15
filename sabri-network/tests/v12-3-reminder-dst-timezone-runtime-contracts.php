<?php
/**
 * V12.3 F17-FUT-07: DST gap and overlap must not become silent scheduling guesses.
 * Dynamic IANA transitions avoid tests expiring after a hard-coded calendar year.
 */
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
require SN_DIR.'includes/class-sn-future24-review-hardening-m.php';
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{
    ++$checks;if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$method=new ReflectionMethod(SN_Future24_Review_Hardening_M::class,'normalize_time');
$normalize=static fn(string $value,string $zone=''):string|WP_Error=>$method->invoke(null,$value,$zone);
$future=gmdate('Y-m-d\TH:i:s\Z',time()+7200);
$accepted=$normalize($future);
$check(is_string($accepted)&&$accepted===gmdate('Y-m-d H:i:s',time()+7200),
    'An explicit ISO UTC instant remains exact');
$check($normalize('tomorrow','Asia/Karachi') instanceof WP_Error,
    'Natural-language timestamps cannot be silently interpreted');
$noZone=$normalize(gmdate('Y-m-d\TH:i:s',time()+7200));
$check($noZone instanceof WP_Error&&$noZone->get_error_code()==='sn_reminder_timezone_required',
    'An offset-free local timestamp requires a timezone');
$validLocal=gmdate('Y-m-d\TH:i:s',time()+2*DAY_IN_SECONDS+3600);
$inKarachi=$normalize($validLocal,'Asia/Karachi');
$check(is_string($inKarachi),'Valid local wall time in Asia/Karachi is supported');

$zone=new DateTimeZone('America/New_York');
$transitions=$zone->getTransitions(time()+DAY_IN_SECONDS,time()+365*DAY_IN_SECONDS-DAY_IN_SECONDS);
$check(is_array($transitions)&&count($transitions)>=2,
    'Current IANA timezone data includes future New York transition metadata');
$previous=(int)$transitions[0]['offset'];
$spring=null;$fall=null;
foreach(array_slice($transitions,1) as $transition){
    $next=(int)$transition['offset'];
    if($next>$previous&&$spring===null)$spring=['ts'=>(int)$transition['ts'],'before'=>$previous,'after'=>$next];
    if($next<$previous&&$fall===null)$fall=['ts'=>(int)$transition['ts'],'before'=>$previous,'after'=>$next];
    $previous=$next;
}
$check(is_array($spring)&&is_array($fall),
    'Both forward and backward transitions are represented in the coming year');
$gapLocal=gmdate('Y-m-d\TH:i:s',$spring['ts']+$spring['before']+1800);
$gap=$normalize($gapLocal,'America/New_York');
$check($gap instanceof WP_Error&&$gap->get_error_code()==='sn_reminder_dst_nonexistent',
    'Spring-forward nonexistent local time is rejected, never silently advanced');

$repeatLocal=gmdate('Y-m-d\TH:i:s',$fall['ts']+$fall['after']+1800);
$repeated=$normalize($repeatLocal,'America/New_York');
$check($repeated instanceof WP_Error&&$repeated->get_error_code()==='sn_reminder_dst_ambiguous',
    'Fall-back repeated local time requires explicit offset');
$formatOffset=static function(int $seconds):string{
    $sign=$seconds>=0?'+':'-';
    $seconds=abs($seconds);
    return sprintf('%s%02d:%02d',$sign,intdiv($seconds,3600),intdiv($seconds%3600,60));
};
$one=$normalize($repeatLocal.$formatOffset($fall['before']),'America/New_York');
$two=$normalize($repeatLocal.$formatOffset($fall['after']),'America/New_York');
$check(is_string($one)&&is_string($two),'Both explicit fall-back offsets are valid instants');
$check(abs(strtotime((string)$one)-strtotime((string)$two))===
    abs($fall['before']-$fall['after']),
    'The two explicit offsets select two genuinely different UTC instants');

$badLocal=$normalize('2027-02-30T13:00:00','Asia/Karachi');
$check($badLocal instanceof WP_Error,
    'Invalid calendar date cannot normalize silently to another date');
echo "V12.3 F17-FUT-07 DST/timezone integrity: $checks PASS, 0 FAIL\n";
