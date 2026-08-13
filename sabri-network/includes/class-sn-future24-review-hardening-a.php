<?php
/** Review rounds 16–17 — private smart views and governed QR community invitations. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_A {
    public static function register(): void { add_action('rest_api_init', [self::class, 'routes'], 1900); }

    public static function routes(): void {
        register_rest_route('sabri-network/v2', '/future/smart-views/(?P<id>\d+)/results', ['methods'=>'GET','callback'=>[self::class,'smart_view_results'],'permission_callback'=>[SN_REST::class,'access']]);
        register_rest_route('sabri-network/v2', '/future/community-invites', ['methods'=>'POST','callback'=>[self::class,'create_qr_invite'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/future/community-invites/redeem', ['methods'=>'POST','callback'=>[self::class,'redeem_qr_invite'],'permission_callback'=>[SN_REST::class,'access']], true);
        register_rest_route('sabri-network/v2', '/future/community-invites/(?P<id>\d+)/revoke', ['methods'=>'POST','callback'=>[self::class,'revoke_qr_invite'],'permission_callback'=>[SN_REST::class,'access']]);
    }

    public static function smart_view_results(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb; $user_id=get_current_user_id();
        $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE id=%d AND feature_id='F17-FUT-11' AND owner_id=%d AND state='active' LIMIT 1",absint($request['id']),$user_id)); if(!$record)return self::not_found();
        $payload=self::decode($record); if(is_wp_error($payload))return $payload; $criteria=is_array($payload['criteria']??null)?$payload['criteria']:[];
        $where=['m.user_id=%d','m.left_at IS NULL',"c.status='active'"]; $args=[$user_id];
        if(array_key_exists('muted',$criteria)){ $where[]='m.is_muted=%d';$args[]=rest_sanitize_boolean($criteria['muted'])?1:0; }
        if(array_key_exists('archived',$criteria)){ $where[]='m.is_archived=%d';$args[]=rest_sanitize_boolean($criteria['archived'])?1:0; }
        if(!empty($criteria['unread']))$where[]='c.last_message_id>m.last_read_message_id';
        $type=sanitize_key((string)($criteria['conversation_type']??'')); if($type!==''){if(!in_array($type,['direct','group','channel','community'],true))return new WP_Error('sn_smart_view_type_invalid','Saved view contains an unsupported conversation type.',['status'=>409]);$where[]='c.type=%s';$args[]=$type;}
        $days=absint($criteria['days']??0);if($days>0){$days=min(3650,$days);$where[]='c.updated_at>=%s';$args[]=gmdate('Y-m-d H:i:s',time()-$days*DAY_IN_SECONDS);}
        if(!empty($criteria['has_files']))$where[]='EXISTS (SELECT 1 FROM '.SN_DB::table('messages').' mx WHERE mx.conversation_id=c.id AND mx.deleted_at IS NULL AND mx.attachment_id>0)';
        $sql='SELECT c.id,c.type,c.title,c.updated_at,c.last_message_id,m.last_read_message_id,m.is_muted,m.is_archived FROM '.SN_DB::table('members').' m INNER JOIN '.SN_DB::table('conversations').' c ON c.id=m.conversation_id WHERE '.implode(' AND ',$where).' ORDER BY c.updated_at DESC,c.id DESC LIMIT 100';
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$args));$items=[];$need_verified=!empty($criteria['from_verified']);if($need_verified&&!has_filter('sn_network_user_verified'))return new WP_Error('sn_smart_view_verification_provider_unavailable','Verified-account filtering is temporarily unavailable.',['status'=>503]);
        foreach(is_array($rows)?$rows:[] as $row){$c=(int)$row->id;if(!SN_DB::is_member($c,$user_id))continue;if($need_verified){$peers=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL LIMIT 50',$c,$user_id)));$verified=false;foreach($peers as $peer)if((bool)apply_filters('sn_network_user_verified',false,$peer,$user_id,'smart_view')){$verified=true;break;}if(!$verified)continue;}$items[]=['conversation_id'=>$c,'type'=>(string)$row->type,'title'=>(string)$row->title,'updated_at'=>(string)$row->updated_at,'unread'=>(int)$row->last_message_id>(int)$row->last_read_message_id,'muted'=>(bool)$row->is_muted,'archived'=>(bool)$row->is_archived];}
        return rest_ensure_response(['view_id'=>(int)$record->id,'name'=>(string)($payload['name']??''),'items'=>$items,'revalidated'=>true,'global_search_owner'=>'file-26','private_message_corpus_exported'=>false]);
    }

    public static function create_qr_invite(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $space=absint($r->get_param('space_id'));$actor=get_current_user_id();if(!self::space_manager($space,$actor))return self::error('forbidden','Space management permission is required.',403);
        $role=self::enum((string)$r->get_param('role'),['member','observer'],'member');$mode=self::enum((string)$r->get_param('mode'),['one_time','multi_use'],'one_time');$max=max(1,min(50,absint($r->get_param('max_uses'))?:1));if($mode==='one_time')$max=1;$exp=time()+max(1,min(168,absint($r->get_param('expires_in_hours'))?:24))*HOUR_IN_SECONDS;$nonce=wp_generate_uuid4();
        $claims=['typ'=>'f17-space-invite-v2','space_id'=>$space,'role'=>$role,'iss'=>$actor,'nonce'=>$nonce,'exp'=>$exp];$token=SN_Communication_Crypto::sign($claims,'future-space-invite-v2');$hash=hash('sha256',$token);
        $payload=['token_hash'=>$hash,'role'=>$role,'mode'=>$mode,'max_uses'=>$max,'use_count'=>0,'issuer_id'=>$actor,'nonce'=>$nonce];$id=self::create_record('F17-FUT-12',$actor,'space',$space,$payload,'qr-v2:'.$hash,gmdate('Y-m-d H:i:s',$exp));
        return is_wp_error($id)?$id:new WP_REST_Response(['id'=>$id,'token'=>$token,'mode'=>$mode,'max_uses'=>$max,'expires_at'=>gmdate('c',$exp),'qr_payload'=>'sn-network://community-invite?token='.rawurlencode($token)],201);
    }

    public static function redeem_qr_invite(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$user=get_current_user_id();$token=trim((string)$r->get_param('token'));$claims=SN_Communication_Crypto::verify($token,'future-space-invite-v2');if(is_wp_error($claims)||($claims['typ']??'')!=='f17-space-invite-v2')return self::error('sn_invite_invalid','Invitation is invalid or expired.',403);
        $space=absint($claims['space_id']??0);$issuer=absint($claims['iss']??0);$hash=hash('sha256',$token);$client_key=hash('sha256','qr-v2:'.$hash);if($space<=0||$issuer<=0||SN_Policy::is_suspended($user)||SN_DB::is_blocked($user,$issuer))return self::error('sn_invite_ineligible','Invitation cannot be used by this account.',403);
        if(!self::space_manager($space,$issuer))return self::error('sn_inviter_authority_revoked','The inviter no longer has authority to admit members.',410);
        if(SN_Policy::age_state($user)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$user,$issuer,'community_invite'))return self::error('sn_guardian_approval_required','Guardian approval is required.',403);
        if(!(bool)apply_filters('sn_network_space_capacity_allows',true,$space,$user))return self::error('sn_space_full','This community cannot accept another member.',409);
        $banned=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status IN ('banned','blocked') LIMIT 1",$space,$user));if($banned)return self::error('sn_space_membership_blocked','Membership is not permitted.',403);
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE feature_id='F17-FUT-12' AND scope_type='space' AND scope_id=%d AND owner_id=%d AND client_key=%s AND state='active' LIMIT 1 FOR UPDATE",$space,$issuer,$client_key));
            if(!$row)throw new RuntimeException('unavailable');$d=self::decode($row);if(is_wp_error($d)||!hash_equals((string)($d['token_hash']??''),$hash)||!hash_equals((string)($d['nonce']??''),(string)($claims['nonce']??'')))throw new RuntimeException('invalid');$count=(int)($d['use_count']??0);$max=max(1,(int)($d['max_uses']??1));if($count>=$max)throw new RuntimeException('exhausted');
            self::assert_redeem_eligibility($space,$user,$issuer);
            self::activate_member($space,$user,self::enum((string)($claims['role']??'member'),['member','observer'],'member'),$issuer);
            $d['use_count']=$count+1;$state=$d['use_count']>=$max?'redeemed':'active';$cipher=self::encode($row,$d);if(is_wp_error($cipher))throw new RuntimeException($cipher->get_error_code());$ok=$wpdb->update(self::records_table(),['payload_cipher'=>$cipher,'state'=>$state,'updated_at'=>self::now(),'version'=>(int)$row->version+1],['id'=>(int)$row->id,'state'=>'active','version'=>(int)$row->version]);if($ok!==1)throw new RuntimeException('conflict');$wpdb->query('COMMIT');SN_DB::audit('future_qr_invite_redeemed','space',$space,'success',['invite_id'=>(int)$row->id,'remaining'=>max(0,$max-$d['use_count'])],$user);return rest_ensure_response(['space_id'=>$space,'joined'=>true,'remaining_uses'=>max(0,$max-$d['use_count'])]);
        } catch(Throwable $e){$wpdb->query('ROLLBACK');$code=$e->getMessage()==='exhausted'?'sn_invite_exhausted':($e->getMessage()==='invalid'?'sn_invite_invalid':($e->getMessage()==='ineligible'?'sn_invite_ineligible':($e->getMessage()==='capacity'?'sn_space_full':($e->getMessage()==='membership_blocked'?'sn_space_membership_blocked':'sn_invite_unavailable'))));$status=in_array($code,['sn_invite_ineligible','sn_space_membership_blocked'],true)?403:($code==='sn_space_full'?409:410);return self::error($code,'Invitation is no longer available.',$status);}
    }

    public static function revoke_qr_invite(WP_REST_Request $r): WP_REST_Response|WP_Error {
        global $wpdb;$id=absint($r['id']);$actor=get_current_user_id();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::records_table()." WHERE id=%d AND feature_id='F17-FUT-12' LIMIT 1",$id));if(!$row)return self::not_found();if((int)$row->owner_id!==$actor&&!self::space_manager((int)$row->scope_id,$actor))return self::error('forbidden','Invitation management permission is required.',403);if((string)$row->state!=='active')return rest_ensure_response(['id'=>$id,'state'=>(string)$row->state]);$ok=$wpdb->update(self::records_table(),['state'=>'revoked','updated_at'=>self::now(),'version'=>(int)$row->version+1],['id'=>$id,'state'=>'active','version'=>(int)$row->version]);if($ok!==1)return self::error('sn_invite_conflict','Invitation changed before revocation.',409);SN_DB::audit('future_qr_invite_revoked','space',(int)$row->scope_id,'success',['invite_id'=>$id],$actor);return rest_ensure_response(['id'=>$id,'state'=>'revoked']);
    }

    private static function assert_redeem_eligibility(int $space,int $user,int $issuer): void {
        global $wpdb;
        if(SN_Policy::is_suspended($user))throw new RuntimeException('ineligible');
        $blocked=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('blocks').' WHERE (user_id=%d AND blocked_user_id=%d) OR (user_id=%d AND blocked_user_id=%d) LIMIT 1 FOR UPDATE',$user,$issuer,$issuer,$user));
        if($blocked||!self::space_manager($space,$issuer,true))throw new RuntimeException('ineligible');
        if(SN_Policy::age_state($user)!=='adult'&&!(bool)apply_filters('sn_network_guardian_communication_approved',false,$user,$issuer,'community_invite'))throw new RuntimeException('ineligible');
        if(!(bool)apply_filters('sn_network_space_capacity_allows',true,$space,$user))throw new RuntimeException('capacity');
    }

    private static function activate_member(int $space,int $user,string $role,int $by): void {
        global $wpdb;
        $s=$wpdb->get_row($wpdb->prepare('SELECT id,conversation_id,state FROM '.SN_DB::table('spaces').' WHERE id=%d FOR UPDATE',$space));
        if(!$s||!in_array((string)$s->state,['active','restricted'],true))throw new RuntimeException('space_unavailable');
        $at=self::now();
        $m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('space_members').' WHERE space_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',$space,$user));
        if($m&&in_array((string)$m->status,['banned','blocked'],true))throw new RuntimeException('membership_blocked');
        $data=['role'=>$role,'status'=>'active','approved_by'=>$by,'left_at'=>null,'updated_at'=>$at];
        if($m){$ok=$wpdb->update(SN_DB::table('space_members'),$data+['version'=>(int)$m->version+1],['id'=>(int)$m->id,'version'=>(int)$m->version]);if($ok!==1)throw new RuntimeException('member_conflict');}
        else {$ok=$wpdb->insert(SN_DB::table('space_members'),['space_id'=>$space,'user_id'=>$user,'joined_at'=>$at,'created_at'=>$at]+$data);if($ok!==1)throw new RuntimeException('member_write_failed');}
        $c=(int)$s->conversation_id;
        if($c){$cm=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('members').' WHERE conversation_id=%d AND user_id=%d LIMIT 1 FOR UPDATE',$c,$user));$cd=['role'=>'member','left_at'=>null,'joined_at'=>$at];if($cm){$ok=$wpdb->update(SN_DB::table('members'),$cd,['id'=>(int)$cm->id]);if($ok===false)throw new RuntimeException('conversation_member_write_failed');}else{$ok=$wpdb->insert(SN_DB::table('members'),['conversation_id'=>$c,'user_id'=>$user]+$cd);if($ok!==1)throw new RuntimeException('conversation_member_write_failed');}}
    }
    private static function space_manager(int $space,int $user,bool $for_update=false): bool {global $wpdb;$suffix=$for_update?' FOR UPDATE':'';$role=(string)$wpdb->get_var($wpdb->prepare("SELECT role FROM ".SN_DB::table('space_members')." WHERE space_id=%d AND user_id=%d AND status='active' AND left_at IS NULL".$suffix,$space,$user));return in_array($role,['owner','administrator','moderator'],true)||user_can($user,'manage_options');}
    private static function create_record(string $feature,int $owner,string $scope_type,int $scope_id,array $payload,string $client,string $expires): int|WP_Error {global $wpdb;$client_key=hash('sha256',$client);$existing=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::records_table().' WHERE client_key=%s',$client_key));if($existing)return $existing;$at=self::now();$dummy=(object)['feature_id'=>$feature,'owner_id'=>$owner,'scope_type'=>$scope_type,'scope_id'=>$scope_id];$cipher=self::encode($dummy,$payload);if(is_wp_error($cipher))return $cipher;$ok=$wpdb->insert(self::records_table(),['feature_id'=>$feature,'owner_id'=>$owner,'scope_type'=>$scope_type,'scope_id'=>$scope_id,'state'=>'active','payload_cipher'=>$cipher,'client_key'=>$client_key,'expires_at'=>$expires,'created_at'=>$at,'updated_at'=>$at,'version'=>1]);return $ok===false?self::error('database_error','Advanced communication change could not be stored safely.',500):(int)$wpdb->insert_id;}
    private static function records_table(): string {global $wpdb;return $wpdb->prefix.'sn_future_records';}
    private static function decode(object $record): array|WP_Error {$plain=SN_Communication_Crypto::decrypt((string)$record->payload_cipher,'future-record|'.(string)$record->feature_id.'|'.(int)$record->owner_id.'|'.(string)$record->scope_type.'|'.(int)$record->scope_id);if(is_wp_error($plain))return $plain;$data=json_decode($plain,true);return is_array($data)?$data:self::error('sn_future_record_invalid','Advanced communication data is invalid.',500);}
    private static function encode(object $record,array $data): string|WP_Error {$json=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return SN_Communication_Crypto::encrypt((string)$json,'future-record|'.(string)$record->feature_id.'|'.(int)$record->owner_id.'|'.(string)$record->scope_type.'|'.(int)$record->scope_id);}
    private static function enum(string $v,array $allowed,string $default): string {$v=sanitize_key($v);return in_array($v,$allowed,true)?$v:$default;}
    private static function now(): string {return current_time('mysql',true);} private static function not_found(): WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);} private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
