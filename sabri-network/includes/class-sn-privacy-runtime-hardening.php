<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Corrective privacy layer for encrypted communication content and erasure ordering. */
final class SN_Privacy_Runtime_Hardening {
    private const BATCH = 100;
    private const LOCK_TIMEOUT = 5;

    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'override_exporter'], 1400);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_eraser'], 1400);
    }

    public static function override_exporter(array $exporters): array {
        if (isset($exporters['sabri-network'])) $exporters['sabri-network']['callback'] = [self::class, 'export'];
        return $exporters;
    }

    public static function override_eraser(array $erasers): array {
        if (isset($erasers['sabri-network'])) $erasers['sabri-network']['callback'] = [self::class, 'erase'];
        return $erasers;
    }

    public static function export(string $email, int $page=1): array {
        global $wpdb;
        $result=SN_Compatibility_Hardening::privacy_export($email,$page);
        $user=get_user_by('email',$email);if(!$user||!isset($result['data'])||!is_array($result['data']))return$result;$uid=(int)$user->ID;
        foreach($result['data'] as &$item){
            if(($item['group_id']??'')!=='sabri-network-updates'||!preg_match('/^update-(\d+)$/',(string)($item['item_id']??''),$m))continue;
            $row=$wpdb->get_row($wpdb->prepare('SELECT id,user_id,body FROM '.SN_DB::table('updates').' WHERE id=%d',(int)$m[1]));if(!$row||(int)$row->user_id!==$uid)continue;
            $plain=SN_Communication_Crypto::decrypt((string)$row->body,'temporary-update|'.$uid);
            foreach((array)($item['data']??[]) as &$field)if(($field['name']??'')===__('Update','sabri-network'))$field['value']=is_wp_error($plain)?__('[encrypted update unavailable]','sabri-network'):(string)$plain;unset($field);
        }
        unset($item);return$result;
    }

    public static function erase(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;
        if((bool)apply_filters('sn_network_retention_prevents_erasure',false,$uid))return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Some Network data is retained under an approved legal or safety hold.','sabri-network')],'done'=>true];
        $lock='sn:f17:privacy:'.$uid;$got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));if($got!==1)return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Privacy erasure is already running. Retry this page.','sabri-network')],'done'=>false];
        try{
            $message_result=self::erase_message_batch($uid);if($message_result!==null)return$message_result;
            $update_result=self::erase_update_batch($uid);if($update_result!==null)return$update_result;
            // Core binary-bearing records are now revoked/deleted in safe order. Let the canonical eraser minimize relational metadata.
            $legacy=SN_Privacy::erase($email,1);
            $remaining=self::remaining_core_rows($uid);
            if($remaining>0){$legacy['done']=false;$legacy['items_retained']=true;$legacy['messages'][]=__('Some File-17 relational privacy rows remain and require another erasure pass.','sabri-network');}
            return$legacy;
        } finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    private static function erase_message_batch(int $uid): ?array {
        global $wpdb;$table=SN_DB::table('messages');$rows=$wpdb->get_results($wpdb->prepare("SELECT id,attachment_id,attachment_source FROM $table WHERE sender_id=%d ORDER BY id ASC LIMIT %d",$uid,self::BATCH));if(!$rows)return null;$now=current_time('mysql',true);$attachments=[];
        if($wpdb->query('START TRANSACTION')===false)return self::retry('The message-erasure transaction could not start.');
        try{
            foreach($rows as $row){$id=(int)$row->id;$locked=$wpdb->get_row($wpdb->prepare("SELECT id,sender_id,attachment_id,attachment_source FROM $table WHERE id=%d FOR UPDATE",$id));if(!$locked||(int)$locked->sender_id!==$uid)continue;if((string)$locked->attachment_source==='private'&&(int)$locked->attachment_id>0)$attachments[]=(int)$locked->attachment_id;
                $updated=$wpdb->query($wpdb->prepare("UPDATE $table SET sender_id=0,body='',attachment_id=0,attachment_source='erased',metadata=%s,deleted_at=COALESCE(deleted_at,%s) WHERE id=%d AND sender_id=%d",(string)wp_json_encode(['erased'=>true]),$now,$id,$uid));if($updated!==1)throw new RuntimeException('privacy_message_update_failed');
                $removed=SN_Message_Search::remove_message($id);if(is_wp_error($removed))throw new RuntimeException($removed->get_error_code());
            }
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('privacy_message_commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('privacy_message_batch_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);return self::retry('A message-erasure batch could not be committed.');}
        foreach(array_values(array_unique($attachments)) as $attachment)SN_Private_Files::delete($attachment,$uid);
        SN_DB::audit('privacy_message_batch_erased','user',$uid,'success',['count'=>count($rows)],0);
        return['items_removed'=>true,'items_retained'=>true,'messages'=>[__('Message bodies were anonymized before private attachment bytes were removed; shared conversation/audit evidence may remain under policy.','sabri-network')],'done'=>false];
    }

    private static function erase_update_batch(int $uid): ?array {
        global $wpdb;$updates=SN_DB::table('updates');$views=SN_DB::table('update_views');$rows=$wpdb->get_results($wpdb->prepare("SELECT id,media_id,media_source FROM $updates WHERE user_id=%d ORDER BY id ASC LIMIT %d",$uid,self::BATCH));if(!$rows)return null;$ids=[];$media=[];foreach($rows as $row){$ids[]=(int)$row->id;if((string)$row->media_source==='private'&&(int)$row->media_id>0)$media[]=(int)$row->media_id;}
        if($wpdb->query('START TRANSACTION')===false)return self::retry('The update-erasure transaction could not start.');
        try{$ph=implode(',',array_fill(0,count($ids),'%d'));if($wpdb->query($wpdb->prepare("DELETE FROM $views WHERE update_id IN ($ph)",...$ids))===false)throw new RuntimeException('privacy_update_views_failed');$args=array_merge([$uid],$ids);$idph=implode(',',array_fill(0,count($ids),'%d'));if($wpdb->query($wpdb->prepare("DELETE FROM $updates WHERE user_id=%d AND id IN ($idph)",...$args))===false)throw new RuntimeException('privacy_updates_failed');if($wpdb->query('COMMIT')===false)throw new RuntimeException('privacy_update_commit_failed');}catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('privacy_update_batch_failed','user',$uid,'failure',['reason'=>$e->getMessage()],0);return self::retry('A temporary-update erasure batch could not be committed.');}
        foreach(array_values(array_unique($media)) as $attachment)SN_Private_Files::delete($attachment,$uid);return['items_removed'=>true,'items_retained'=>true,'messages'=>[__('Temporary update records were removed before their private media bytes were purged.','sabri-network')],'done'=>false];
    }

    private static function remaining_core_rows(int $uid): int {
        global $wpdb;$count=0;$count+=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.SN_DB::table('messages').' WHERE sender_id=%d',$uid));$count+=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.SN_DB::table('updates').' WHERE user_id=%d',$uid));$count+=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.SN_DB::table('members').' WHERE user_id=%d',$uid));$count+=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.SN_DB::table('notifications').' WHERE user_id=%d',$uid));return$count;
    }

    private static function retry(string $message): array{return['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];}
}
