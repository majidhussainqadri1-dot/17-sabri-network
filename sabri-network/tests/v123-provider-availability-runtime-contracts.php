<?php
/** V12.3: fail-closed feature availability when approval/provider evidence is absent. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_REST_Response {public function __construct(private array $data){}public function get_data():array{return $this->data;}}
class WP_Error {}
function rest_ensure_response(array $d):WP_REST_Response{return new WP_REST_Response($d);}
function get_current_user_id():int{return 7;}
function has_filter(string $name):bool{return isset($GLOBALS['readyHooks'][$name]);}
function has_action(string $name):int|false{return has_filter($name)?1:false;}
function apply_filters(string $name,mixed $value,...$args):mixed{return $GLOBALS['providerState'][$name]??$value;}
require_once dirname(__DIR__).'/includes/class-sn-future-superset-part-1.php';
class SN_Future_Superset {
    use SN_Future_Superset_Part_1;
    public const SCHEMA_VERSION='1.0.0';
    public const FEATURE_COUNT=11;
    public static function features():array{
        $ids=['01','02','03','04','07','15','16','17','22','23','24'];
        $result=[];foreach($ids as $id)$result['F17-FUT-'.$id]=['slug'=>'feature-'.$id,'phase'=>'advanced','label'=>'Feature '.$id,'provider'=>''];
        return $result;
    }
}
function check(bool $v,string $message):void{if(!$v){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function states():array{
    $rows=SN_Future_Superset::capabilities()->get_data()['items'];
    return array_column($rows,'status','id');
}
$GLOBALS['readyHooks']=[];
$GLOBALS['providerState']=[];
$states=states();
foreach($states as $id=>$state)check($state==='provider-gated',"$id must be gated when no approved provider is connected.");
$GLOBALS['readyHooks']=array_fill_keys([
    'sn_network_device_public_key_valid','sn_network_step_up_verified',
    'sn_network_notification_requested','sn_network_citation_resolve',
    'sn_network_case_discussion_professional_allowed',
    'sn_network_ai_assistant_result','sn_network_ai_context_authorized',
    'sn_network_ai_context_redact','sn_network_private_semantic_search_result',
    'sn_network_private_semantic_purge_result','sn_network_interop_provider_ready',
    'sn_network_interop_remote_allowed'
],true);
$GLOBALS['providerState']=[
 'sn_network_e2ee_provider_status'=>['ready'=>true,'audited'=>true,'protocol'=>'test-audited','audit_ref'=>'independent-review'],
 'sn_network_device_verification_provider_status'=>['ready'=>true,'revocation_propagation'=>true,'key_change_warning'=>true],
 'sn_network_key_transparency_provider_status'=>['ready'=>true,'signed_checkpoint'=>true,'consistency_proof'=>true,'audited'=>true],
 'sn_network_sfu_available'=>true
];
$states=states();
foreach($states as $id=>$state)check($state==='available',"$id should be advertised only after complete fixture approval.");
unset($GLOBALS['providerState']['sn_network_e2ee_provider_status']['audit_ref']);
unset($GLOBALS['providerState']['sn_network_key_transparency_provider_status']['consistency_proof']);
unset($GLOBALS['providerState']['sn_network_device_verification_provider_status']['key_change_warning']);
$states=states();
foreach(['01','02','03'] as $n)check($states['F17-FUT-'.$n]==='provider-gated',"Missing advanced trust proof $n must fail closed.");
echo "V12.3 provider availability truth contracts: PASS\n";
