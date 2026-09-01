<?php
defined('ABSPATH') || exit;

trait SN_File_Transfer_Part_8 {
    public static function erase_personal_data(string $email,int $page=1): array {
        global $wpdb;
        $user=get_user_by('email',$email);
        if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
        $uid=(int)$user->ID;
        if((bool)apply_filters('sn_network_retention_prevents_erasure',false,$uid))return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer records are retained under an approved legal or safety hold.'],'done'=>true];
        $sessions=self::sessions_table();$recipients=self::recipients_table();$chunks=self::chunks_table();$now=current_time('mysql',true);$page_size=self::privacy_erase_page_size();

        // Include terminal sender sessions whose prior physical deletion did not
        // finish. Privacy completion must not outrun retryable encrypted-byte
        // destruction, and higher-level anonymization must not sever the user link
        // until those bytes are actually gone.
        $sent_rows=$wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.status FROM $sessions s WHERE s.sender_id=%d AND (s.status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) ORDER BY s.id ASC LIMIT %d",
            $uid,$page_size
        ));
        if($wpdb->last_error!==''||!is_array($sent_rows)){SN_DB::audit('file_transfer_privacy_sent_enumeration_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer erasure enumeration could not be verified and must be retried.'],'done'=>false];}
        $sent=array_map(static fn($row):int=>(int)$row->id,$sent_rows);
        $received_raw=$wpdb->get_col($wpdb->prepare("SELECT id FROM $recipients WHERE user_id=%d AND state<>'erased' ORDER BY id ASC LIMIT %d",$uid,$page_size));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_received_enumeration_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer recipient erasure enumeration could not be verified and must be retried.'],'done'=>false];}$received=array_map('intval',is_array($received_raw)?$received_raw:[]);
        $removed=false;
        if($wpdb->query('START TRANSACTION')===false)return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer erasure could not start and must be retried.'],'done'=>false];
        try{
            foreach($sent_rows as $row){
                $id=(int)$row->id;$status=(string)$row->status;
                if(!in_array($status,['revoked','expired','rejected'],true)){
                    $changed=$wpdb->query($wpdb->prepare("UPDATE $sessions SET status='revoked',revoked_at=COALESCE(revoked_at,%s),version=version+1,updated_at=%s WHERE id=%d AND sender_id=%d AND status NOT IN ('revoked','expired','rejected')",$now,$now,$id,$uid));
                    if($changed!==1)throw new RuntimeException('file_transfer_privacy_revoke_failed');
                    $r=$wpdb->query($wpdb->prepare("UPDATE $recipients SET state='revoked',revoked_at=COALESCE(revoked_at,%s),updated_at=%s WHERE transfer_id=%d AND revoked_at IS NULL",$now,$now,$id));
                    if($r===false)throw new RuntimeException('file_transfer_privacy_recipient_revoke_failed');
                    $removed=true;
                }
            }
            foreach($received as $rid){
                $changed=$wpdb->query($wpdb->prepare("UPDATE $recipients SET state='erased',revoked_at=COALESCE(revoked_at,%s),updated_at=%s WHERE id=%d AND user_id=%d AND state<>'erased'",$now,$now,$rid,$uid));
                if($changed!==1)throw new RuntimeException('file_transfer_privacy_recipient_erase_failed');
                $removed=true;
            }
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('file_transfer_privacy_commit_failed');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            SN_DB::audit('file_transfer_privacy_erase_failed','user',$uid,'failure',['reason'=>$e->getMessage()],$uid);
            return['items_removed'=>false,'items_retained'=>true,'messages'=>['Private transfer erasure could not be committed and must be retried.'],'done'=>false];
        }

        // Bytes are destroyed only after canonical sender access has been revoked.
        // A failed unlink keeps its chunk ledger row, making the next eraser call
        // select the terminal session again instead of falsely declaring completion.
        foreach($sent as $id){
            $had_chunks_raw=$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $chunks WHERE transfer_id=%d LIMIT 1",$id));if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_chunk_probe_failed','file_transfer',$id,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Private transfer byte-erasure state could not be verified and must be retried.'],'done'=>false];}$had_chunks=(bool)$had_chunks_raw;
            if($had_chunks&&self::delete_chunks($id))$removed=true;
        }

        $more_sent=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $sessions s WHERE s.sender_id=%d AND (s.status NOT IN ('revoked','expired','rejected') OR EXISTS (SELECT 1 FROM $chunks c WHERE c.transfer_id=s.id)) LIMIT 1",$uid));
        if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_completion_sent_read_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Private transfer erasure completion could not be verified and must be retried.'],'done'=>false];}
        $more_received=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $recipients WHERE user_id=%d AND state<>'erased' LIMIT 1",$uid));
        if($wpdb->last_error!==''){SN_DB::audit('file_transfer_privacy_completion_received_read_failed','user',$uid,'failure',['reason'=>(string)$wpdb->last_error],$uid);return['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Private transfer recipient erasure completion could not be verified and must be retried.'],'done'=>false];}
        return[
            'items_removed'=>$removed,
            'items_retained'=>true,
            'messages'=>['Minimum integrity, legal-hold and abuse evidence may be retained under the approved retention policy.'],
            'done'=>!$more_sent&&!$more_received,
        ];
    }

    private static function privacy_erase_page_size(): int{return 100;}
    private static function sessions_table(): string{return SN_DB::table('transfer_sessions');}
    private static function chunks_table(): string{return SN_DB::table('transfer_chunks');}
    private static function recipients_table(): string{return SN_DB::table('transfer_recipients');}
}