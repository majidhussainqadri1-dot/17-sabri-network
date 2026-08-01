<?php
/** Minimal runtime verification of signed, URL-safe follow cursors. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
class WP_Error {public function __construct(public string $code,public string $message='',public array $data=[]){} }
function is_wp_error($v):bool{return $v instanceof WP_Error;}
function wp_json_encode($v):string{return json_encode($v,JSON_THROW_ON_ERROR);}
function wp_salt($scheme='auth'):string{return 'file17-runtime-test-salt-'.$scheme;}
require dirname(__DIR__).'/includes/class-sn-relationships.php';
$failures=[];$checks=0;function rr(bool $c,string $m):void{global $failures,$checks;$checks++;if(!$c)$failures[]=$m;}
$ref=new ReflectionClass('SN_Relationships');$enc=$ref->getMethod('encode_cursor');$enc->setAccessible(true);$dec=$ref->getMethod('decode_cursor');$dec->setAccessible(true);
$cursor=$enc->invoke(null,987,41,'following');
rr(is_string($cursor)&&str_contains($cursor,'.'),'Cursor must contain payload and signature.');
rr(!str_contains($cursor,'=')&&!str_contains($cursor,'+')&&!str_contains($cursor,'/'),'Cursor body must be URL-safe and unpadded.');
rr($dec->invoke(null,$cursor,41,'following')===987,'Valid cursor must round-trip.');
rr(is_wp_error($dec->invoke(null,$cursor,42,'following')),'Cursor must be user-bound.');
rr(is_wp_error($dec->invoke(null,$cursor,41,'followers')),'Cursor must be scope-bound.');
$tampered='A'.substr($cursor,1);rr(is_wp_error($dec->invoke(null,$tampered,41,'following')),'Tampered cursor must fail.');
rr(is_wp_error($dec->invoke(null,'invalid',41,'following')),'Malformed cursor must fail.');
[$body,$sig]=explode('.',$cursor,2);$bad=$body.'.'.str_repeat('0',strlen($sig));rr(is_wp_error($dec->invoke(null,$bad,41,'following')),'Forged signature must fail.');
if($failures){fwrite(STDERR,"Relationship runtime failures (".count($failures)."/$checks):\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "Relationship runtime contracts: PASS ($checks checks)\n";
