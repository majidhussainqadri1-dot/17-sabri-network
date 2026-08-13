<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_8 {
    public static function erase_personal_data(string $email,int $page=1): array {
        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;
        if((bool)apply_filters('sn_network_retention_prevents_erasure',false,$uid))return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer records are retained under an approved legal or safety hold.'],'done'=>true];
        $sessions=self::sessions_table();$recipients=self::recipients_table();$now=current_time('mysql',true);$page_size=self::privacy_erase_page_size();
        $sent=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $sessions WHERE sender_id=%d AND status NOT IN ('revoked','expired','rejected') ORDER BY id ASC LIMIT %d",$uid,$page_size))?:[]);
        $received=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM $recipients WHERE user_id=%d AND state<>'erased' ORDER BY id ASC LIMIT %d",$uid,$page_size))?:[]);
        $removed=false;
        if($wpdb->query('START TRANSACTION')===false)return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer erasure could not start and must be retried.'],'done'=>false];
        try{
            foreach($sent as $id){$changed=$wpdb->query($wpdb->prepare("UPDATE $sessions SET status='revoked',revoked_at=COALESCE(revoked_at,%s),version=version+1,updated_at=%s WHERE id=%d AND sender_id=%d AND status NOT IN ('revoked','expired','rejected')",$now,$now,$id,$uid));if($changed!==1)throw new RuntimeException('file_transfer_privacy_revoke_failed');$r=$wpdb->query($wpdb->prepare("UPDATE $recipients SET state='revoked',revoked_at=COALESCE(revoked_at,%s),updated_at=%s WHERE transfer_id=%d AND revoked_at IS NULL",$now,$now,$id));if($r===false)throw new RuntimeException('file_transfer_privacy_recipient_revoke_failed');$removed=true;}
            foreach($received as $rid){$changed=$wpdb->query($wpdb->prepare("UPDATE $recipients SET state='erased',revoked_at=COALESCE(revoked_at,%s),updated_at=%s WHERE id=%d AND user_id=%d AND state<>'erased'",$now,$now,$rid,$uid));if($changed!==1)throw new RuntimeException('file_transfer_privacy_recipient_erase_failed');$removed=true;}
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('file_transfer_privacy_commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');SN_DB::audit('file_transfer_privacy_erase_failed','user',$uid,'failure',['reason'=>$e->getMessage()],$uid);return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer erasure could not be committed and must be retried.'],'done'=>false];}
        // Bytes are destroyed only after canonical sender access has been revoked.
        foreach($sent as $id)self::delete_chunks($id);
        $more_sent=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $sessions WHERE sender_id=%d AND status NOT IN ('revoked','expired','rejected') LIMIT 1",$uid));
        $more_received=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $recipients WHERE user_id=%d AND state<>'erased' LIMIT 1",$uid));
        $leftover_chunks=false;foreach($sent as $id){if((bool)$wpdb->get_var($wpdb->prepare('SELECT 1 FROM '.self::chunks_table().' WHERE transfer_id=%d LIMIT 1',$id))){$leftover_chunks=true;break;}}
        return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Minimum integrity, legal-hold and abuse evidence may be retained under the approved retention policy.'],'done'=>!$more_sent&&!$more_received&&!$leftover_chunks];
    }

    private static function privacy_erase_page_size(): int{return 100;}
    private static function sessions_table(): string{return SN_DB::table('transfer_sessions');}
    private static function chunks_table(): string{return SN_DB::table('transfer_chunks');}
    private static function recipients_table(): string{return SN_DB::table('transfer_recipients');}
}
