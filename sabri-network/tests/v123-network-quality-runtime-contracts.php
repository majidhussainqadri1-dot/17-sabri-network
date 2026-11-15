<?php
/** Isolated executable V12.3 call-quality grading contracts; no WordPress DB or live provider assumed. */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
require_once dirname(__DIR__).'/includes/class-sn-future24-review-hardening-g.php';
$assert=static function(bool $ok,string $message):void {if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}};
$grade=static fn(float $loss,int $rtt,int $jitter,int $kbps):array=>
    SN_Future24_Review_Hardening_G::assess_network_quality($loss,$rtt,$jitter,$kbps);
[$state,$hints]=$grade(0.1,40,5,3000);
$assert($state==='good'&&$hints===[],'Clearly healthy user-supplied metrics should not force degradation.');
[$state,$hints]=$grade(0.1,40,5,100);
$assert($state==='poor'&&!empty($hints['prefer_audio_only'])&&empty($hints['retry_ice']),'Bandwidth-only degradation must prefer audio without claiming a failing ICE connection.');
[$state,$hints]=$grade(0,40,150,3000);
$assert($state==='poor'&&!empty($hints['retry_ice']),'High jitter must affect grade and produce guarded recovery guidance.');
[$state,$hints]=$grade(0,40,50,3000);
$assert($state==='fair'&&!empty($hints['reduce_video_resolution']),'Moderate jitter must affect grade.');
[$state,$hints]=$grade(0,40,5,500);
$assert($state==='fair'&&!empty($hints['reduce_video_resolution']),'Moderate downlink must affect grade.');
[$state,$hints]=$grade(4,40,5,3000);
$assert($state==='fair','Packet loss boundary must remain effective.');
[$state,$hints]=$grade(0,750,5,3000);
$assert($state==='poor'&&!empty($hints['retry_ice']),'Excessive round-trip time remains poor.');
[$state,$hints]=$grade(0,0,0,0);
$assert($state==='poor','Missing effective downlink must never be labelled good.');
$src=file_get_contents(dirname(__DIR__).'/includes/class-sn-future24-review-hardening-g.php');
$fallback=file_get_contents(dirname(__DIR__).'/includes/class-sn-future-superset-part-3.php');
$assert(str_contains($fallback,'SN_Future24_Review_Hardening_G::assess_network_quality'),'Canonical and fallback quality grading must share the same heuristic.');
$assert(str_contains($src,"if(!$consent)return rest_ensure_response")&&str_contains($src,"'stored'=>false"),'Privacy opt-out must remain a non-storing server path.');
echo "V12.3 call-quality grading runtime contracts: PASS\n";
