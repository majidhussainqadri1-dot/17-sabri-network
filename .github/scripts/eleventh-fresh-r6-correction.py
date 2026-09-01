from pathlib import Path

def patch(path,repls):
 p=Path(path);s=p.read_text(encoding='utf-8')
 for old,new in repls:
  if old not in s: raise SystemExit('missing anchor in '+path+': '+old[:160])
  s=s.replace(old,new,1)
 p.write_text(s,encoding='utf-8')

patch('sabri-network/includes/class-sn-attachment-runtime-hardening.php',[
("""        $row = $wpdb->get_row($wpdb->prepare('SELECT id,storage_key,sha256,deleted_at FROM ' . SN_DB::table('attachments') . ' WHERE id=%d', $attachment_id));
        if (!$row || $row->deleted_at !== null) return;
""","""        $row = $wpdb->get_row($wpdb->prepare('SELECT id,storage_key,sha256,deleted_at FROM ' . SN_DB::table('attachments') . ' WHERE id=%d', $attachment_id));
        if (($wpdb->last_error ?? '') !== '') { SN_DB::audit('attachment_integrity_state_read_failed','attachment',$attachment_id,'failure',['reason'=>(string)$wpdb->last_error],$user_id); status_header(503); exit; }
        if (!$row || $row->deleted_at !== null) return;
"""),
("""        $row = $wpdb->get_row($wpdb->prepare('SELECT id,metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        if (!$row || $row->deleted_at !== null) return new WP_Error('sn_voice_note_state_changed', 'The voice note changed before metadata finalization.', ['status' => 409]);
""","""        $row = $wpdb->get_row($wpdb->prepare('SELECT id,metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
        if (($wpdb->last_error ?? '') !== '') return new WP_Error('sn_voice_note_state_unavailable','The committed voice-note state could not be verified safely. Retry later.',['status'=>503,'message_id'=>$id]);
        if (!$row || $row->deleted_at !== null) return new WP_Error('sn_voice_note_state_changed', 'The voice note changed before metadata finalization.', ['status' => 409]);
"""),
("""        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('messages') . ' SET metadata=%s WHERE id=%d AND metadata=%s AND deleted_at IS NULL', (string) wp_json_encode($meta), $id, (string) $row->metadata));
        if ($changed !== 1) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
            $fresh_meta = $fresh ? json_decode((string) $fresh->metadata, true) : null;
""","""        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . SN_DB::table('messages') . ' SET metadata=%s WHERE id=%d AND metadata=%s AND deleted_at IS NULL', (string) wp_json_encode($meta), $id, (string) $row->metadata));
        if ($changed === false) { SN_DB::audit('voice_note_metadata_write_failed','message',$id,'failure',['reason'=>(string)($wpdb->last_error??'')],get_current_user_id()); return new WP_Error('sn_voice_note_metadata_finalize_failed','The audio message was committed but its voice-note metadata needs a safe retry.',['status'=>503,'message_id'=>$id]); }
        if ($changed !== 1) {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT metadata,deleted_at FROM ' . SN_DB::table('messages') . ' WHERE id=%d', $id));
            if (($wpdb->last_error ?? '') !== '') return new WP_Error('sn_voice_note_state_unavailable','The committed voice-note state could not be reconciled safely. Retry later.',['status'=>503,'message_id'=>$id]);
            $fresh_meta = $fresh ? json_decode((string) $fresh->metadata, true) : null;
""")
])

patch('sabri-network/includes/class-sn-private-files.php',[
("""        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (!$row) {
            status_header(404);
            exit;
        }
""","""        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (($wpdb->last_error ?? '') !== '') { SN_DB::audit('attachment_delivery_state_read_failed','attachment',$attachment_id,'failure',['reason'=>(string)$wpdb->last_error],$user_id); status_header(503); exit; }
        if (!$row) {
            status_header(404);
            exit;
        }
"""),
("""        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (!$row || (!$force && (int) $row->owner_id !== $actor_id)) {
            return false;
        }
""","""        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NULL', $attachment_id));
        if (($wpdb->last_error ?? '') !== '') { SN_DB::audit('attachment_delete_state_read_failed','attachment',$attachment_id,'failure',['reason'=>(string)$wpdb->last_error],$actor_id); return false; }
        if (!$row || (!$force && (int) $row->owner_id !== $actor_id)) {
            return false;
        }
"""),
("""        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,storage_key FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NOT NULL',
            $attachment_id
        ));
        if (!$row) {
            return;
        }
""","""        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,storage_key FROM ' . SN_DB::table('attachments') . ' WHERE id=%d AND deleted_at IS NOT NULL',
            $attachment_id
        ));
        if (($wpdb->last_error ?? '') !== '') {
            SN_DB::audit('attachment_delete_retry_state_read_failed','attachment',$attachment_id,'failure',['reason'=>(string)$wpdb->last_error],0);
            if (!wp_next_scheduled('sn_network_retry_private_delete', [$attachment_id])) {
                $scheduled=wp_schedule_single_event(time()+5*MINUTE_IN_SECONDS,'sn_network_retry_private_delete',[$attachment_id],true);
                if(is_wp_error($scheduled)||$scheduled===false)SN_DB::audit('attachment_delete_retry_schedule_failed','attachment',$attachment_id,'failure',['reason'=>is_wp_error($scheduled)?$scheduled->get_error_code():'schedule_failed'],0);
            }
            return;
        }
        if (!$row) {
            return;
        }
""")
])

t=Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php');s=t.read_text(encoding='utf-8');anchor='if($fail){fwrite(STDERR,'
block="""// R6 — private attachment delivery/deletion and voice-note postcommit truth.\n$attachment=$read($root.'/includes/class-sn-attachment-runtime-hardening.php');\n$private=$read($root.'/includes/class-sn-private-files.php');\n$check(str_contains($attachment,'sn_voice_note_state_unavailable'),'R6 committed voice-note DB uncertainty must be retryable.');\n$check(str_contains($attachment,'attachment_integrity_state_read_failed'),'R6 integrity preflight DB failures must fail closed.');\n$check(str_contains($private,'attachment_delivery_state_read_failed'),'R6 private delivery DB failures must not masquerade as 404.');\n$check(str_contains($private,'attachment_delete_retry_state_read_failed'),'R6 private-byte deletion retries must survive DB-read outages.');\n"""
if anchor not in s: raise SystemExit('missing test footer')
t.write_text(s.replace(anchor,block+anchor,1),encoding='utf-8')
