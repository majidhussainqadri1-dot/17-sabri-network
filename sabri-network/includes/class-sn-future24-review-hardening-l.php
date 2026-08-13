<?php
/** Review round 34 — CAS-safe assignment/handoff with reason, receipt and SLA metadata. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_L {
    public static function register():void{add_action('rest_api_init',[self::class,'routes'],2000);}
    public static function routes():void{register_rest_route('sabri-network/v2','/future/team-inbox/(?P<id>\d+)/handoff',['methods'=>'POST','callback'=>[self::class,'handoff'],'permission_callback'=>[SN_REST::class,'access']],true);}
    public static function handoff(WP_REST_Request $r):WP_REST_Response|WP_Error{
        global $wpdb;$conversation=absint($r['id']);$actor=get_current_user_id();
        if(!self::manager($conversation,$actor)||!self::delegated($conversation,$actor,'manage'))return self::error('forbidden','Current delegated management authority is required.',403);
        $target=absint($r->get_param('assignee_id'));if($target<=0||!SN_DB::is_member($conversation,$target)||!self::delegated($conversation,$target,'work'))return self::error('sn_handoff_assignee_invalid','Target assignee lacks current delegated work authority.',403);
        $reason=mb_substr(sanitize_textarea_field(wp_unslash((string)$r->get_param('reason'))),0,500);if($reason==='')return self::error('sn_handoff_reason_required','A handoff reason is required.',400);
        $expected=absint($r->get_param('expected_version'));if($expected<=0)return self::error('sn_handoff_version_required','Refresh the team inbox and provide its expected version.',400);
        $sla='';$raw_sla=(string)$r->get_param('sla_due_at');if($raw_sla!==''){$ts=strtotime($raw_sla);if(!$ts||$ts<=time()||$ts>time()+90*DAY_IN_SECONDS)return self::error('sn_handoff_sla_invalid','Choose a future SLA due time within 90 days.',400);$sla=gmdate('Y-m-d H:i:s',$ts);}
        $wpdb->query('START TRANSACTION');
        try{
            $team=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE feature_id='F17-FUT-05' AND owner_id=0 AND scope_type='conversation' AND scope_id=%d AND state='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",$conversation));
            if(!$team||$expected!==(int)$team->version)throw new RuntimeException('stale');
            // Authorization is a transition precondition, not only a request-entry check.
            if(!self::manager_locked($conversation,$actor)||!self::delegated_locked($conversation,$actor,'manage'))throw new RuntimeException('authority_revoked');
            if(!self::delegated_locked($conversation,$target,'work'))throw new RuntimeException('target_revoked');
            $td=self::decode($team);if(is_wp_error($td))throw new RuntimeException('decode');$from=absint($td['assignee_id']??0);
            $td['enabled']=true;$td['assignee_id']=$target;$td['status']='open';$td['updated_by']=$actor;$td['updated_at']=current_time('mysql',true);$td['sla_due_at']=$sla;
            $cipher=self::encode($team,$td);if(is_wp_error($cipher))throw new RuntimeException('crypto');
            $ok=$wpdb->update($wpdb->prefix.'sn_future_records',['payload_cipher'=>$cipher,'updated_at'=>current_time('mysql',true),'version'=>(int)$team->version+1],['id'=>(int)$team->id,'version'=>(int)$team->version]);if($ok!==1)throw new RuntimeException('stale');
            $assignment=self::assignment_row($conversation,true);$receipt=['subtype'=>'assignment','assignee_id'=>$target,'previous_assignee_id'=>$from,'handoff_by'=>$actor,'handoff_reason'=>$reason,'handoff_at'=>current_time('mysql',true),'sla_due_at'=>$sla,'team_version'=>(int)$team->version+1];
            self::save_assignment($conversation,$assignment,$receipt);self::insert_receipt($conversation,$actor,$receipt);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit');
            SN_DB::audit('future_team_handoff','conversation',$conversation,'success',['from'=>$from,'to'=>$target,'team_version'=>(int)$team->version+1,'sla_due_at'=>$sla],$actor);
            return rest_ensure_response(['conversation_id'=>$conversation,'from_assignee_id'=>$from,'assignee_id'=>$target,'reason'=>$reason,'sla_due_at'=>$sla,'version'=>(int)$team->version+1]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');$code=$e->getMessage();if($code==='stale')return self::error('sn_handoff_stale','Team inbox changed before handoff; refresh and retry.',409);if($code==='authority_revoked')return self::error('sn_handoff_authority_revoked','Management or delegation authority changed before handoff.',403);if($code==='target_revoked')return self::error('sn_handoff_assignee_invalid','Target assignee no longer has delegated work authority.',403);return self::error('sn_handoff_failed','Handoff could not be committed safely.',500);}
    }
    private static function assignment_row(int $c,bool $lock=false):?object{global $wpdb;$sql="SELECT * FROM ".$wpdb->prefix."sn_future_records WHERE feature_id='F17-FUT-06' AND owner_id=0 AND scope_type='conversation' AND scope_id=%d AND state='active' ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':'');$row=$wpdb->get_row($wpdb->prepare($sql,$c));return is_object($row)?$row:null;}
    private static function save_assignment(int $c,?object $row,array $data):void{global $wpdb;$dummy=$row?:((object)['feature_id'=>'F17-FUT-06','owner_id'=>0,'scope_type'=>'conversation','scope_id'=>$c,'version'=>0]);$cipher=self::encode($dummy,$data);if(is_wp_error($cipher))throw new RuntimeException('crypto');$now=current_time('mysql',true);if($row){$ok=$wpdb->update($wpdb->prefix.'sn_future_records',['payload_cipher'=>$cipher,'updated_at'=>$now,'version'=>(int)$row->version+1],['id'=>(int)$row->id,'version'=>(int)$row->version]);if($ok!==1)throw new RuntimeException('stale');return;}$ok=$wpdb->insert($wpdb->prefix.'sn_future_records',['feature_id'=>'F17-FUT-06','owner_id'=>0,'scope_type'=>'conversation','scope_id'=>$c,'state'=>'active','payload_cipher'=>$cipher,'client_key'=>hash('sha256','assignment:'.$c),'created_at'=>$now,'updated_at'=>$now,'version'=>1]);if($ok===false)throw new RuntimeException('assignment_insert');}
    private static function insert_receipt(int $c,int $actor,array $receipt):void{global $wpdb;$dummy=(object)['feature_id'=>'F17-FUT-06','owner_id'=>$actor,'scope_type'=>'conversation','scope_id'=>$c];$cipher=self::encode($dummy,['subtype'=>'handoff_receipt']+$receipt);if(is_wp_error($cipher))throw new RuntimeException('crypto');$now=current_time('mysql',true);$ok=$wpdb->insert($wpdb->prefix.'sn_future_records',['feature_id'=>'F17-FUT-06','owner_id'=>$actor,'scope_type'=>'conversation','scope_id'=>$c,'state'=>'active','payload_cipher'=>$cipher,'client_key'=>hash('sha256','handoff-receipt:'.$c.':'.$actor.':'.wp_generate_uuid4()),'created_at'=>$now,'updated_at'=>$now,'version'=>1]);if($ok===false)throw new RuntimeException('receipt_insert');}
    private static function delegated(int $c,int $u,string $p):bool{return SN_DB::is_member($c,$u)&&has_filter('sn_network_team_inbox_delegation_allowed')&&(bool)apply_filters('sn_network_team_inbox_delegation_allowed',false,$c,$u,$p);}
    private static function delegated_locked(int $c,int $u,string $p):bool{global $wpdb;$member=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',$c,$u));return(bool)$member&&has_filter('sn_network_team_inbox_delegation_allowed')&&(bool)apply_filters('sn_network_team_inbox_delegation_allowed',false,$c,$u,$p);}
    private static function manager(int $c,int $u):bool{return $c>0&&(in_array(SN_DB::member_role($c,$u),['owner','moderator'],true)||user_can($u,'manage_options'));}
    private static function manager_locked(int $c,int $u):bool{global $wpdb;if(user_can($u,'manage_options'))return true;$role=(string)$wpdb->get_var($wpdb->prepare('SELECT role FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',$c,$u));return in_array($role,['owner','moderator'],true);}
    private static function decode(object $row):array|WP_Error{$p=SN_Communication_Crypto::decrypt((string)$row->payload_cipher,'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);if(is_wp_error($p))return $p;$d=json_decode($p,true);return is_array($d)?$d:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}
    private static function encode(object $row,array $d):string|WP_Error{return SN_Communication_Crypto::encrypt((string)wp_json_encode($d),'future-record|'.(string)$row->feature_id.'|'.(int)$row->owner_id.'|'.(string)$row->scope_type.'|'.(int)$row->scope_id);}
    private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
