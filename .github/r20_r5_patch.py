from pathlib import Path

# Final Smail route owner: distinguish DB read/write failure from absence/version conflict.
p=Path('sabri-network/includes/class-sn-fourth-fresh-smail-hardening.php')
s=p.read_text(encoding='utf-8')
s=s.replace("""            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id,version FROM ' . SN_DB::table('smail_drafts') . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',
                $public,
                $owner
            ));
            if (!$row) return self::not_found();
""","""            $wpdb->last_error = '';
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id,version FROM ' . SN_DB::table('smail_drafts') . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',
                $public,
                $owner
            ));
            if ($wpdb->last_error !== '') return self::database_error();
            if (!$row) return self::not_found();
""",1)
s=s.replace("""        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,version FROM ' . SN_DB::table('smail_drafts') . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',
            $public,
            $owner
        ));
        if (!$row) return self::not_found();
""","""        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,version FROM ' . SN_DB::table('smail_drafts') . ' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',
            $public,
            $owner
        ));
        if ($wpdb->last_error !== '') return self::database_error();
        if (!$row) return self::not_found();
""",1)
s=s.replace("""        if ($changed !== 1) return self::conflict();
""","""        if ($changed === false) return self::database_error();
        if ($changed !== 1) return self::conflict();
""",1)
anchor="""    private static function conflict(): WP_Error {
"""
helper="""    private static function database_error(): WP_Error {
        return new WP_Error('smail_database_read_failed', 'Smail state could not be read or written safely. Retry the request.', ['status'=>503]);
    }

"""
if anchor not in s: raise SystemExit('fourth helper anchor missing')
s=s.replace(anchor,helper+anchor,1)
p.write_text(s,encoding='utf-8')

# Runtime Smail owner: checked canonical reads and WP_Error-aware duplicate comparison.
p=Path('sabri-network/includes/class-sn-smail-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
s=s.replace("""            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
            if($existing){
                if(!self::same_send_request($existing,$sender,$recipients,$subject,$body))return self::idempotency_conflict();
""","""            $wpdb->last_error='';
            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
            if($wpdb->last_error!=='')return self::database_error();
            if($existing){
                $same=self::same_send_request($existing,$sender,$recipients,$subject,$body);if(is_wp_error($same))return $same;if(!$same)return self::idempotency_conflict();
""",1)
s=s.replace("""                $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s FOR UPDATE',$client_key));
                if($existing){
                    $same=self::same_send_request($existing,$sender,$recipients,$subject,$body);
                    $wpdb->query('ROLLBACK');
                    if(!$same)return self::idempotency_conflict();
""","""                $wpdb->last_error='';
                $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s FOR UPDATE',$client_key));
                if($wpdb->last_error!=='')throw new RuntimeException('smail_projection_lock_read_failed');
                if($existing){
                    $same=self::same_send_request($existing,$sender,$recipients,$subject,$body);
                    $wpdb->query('ROLLBACK');
                    if(is_wp_error($same))return $same;if(!$same)return self::idempotency_conflict();
""",1)
s=s.replace("""                $race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
                if($race){
                    if(!self::same_send_request($race,$sender,$recipients,$subject,$body))return self::idempotency_conflict();
""","""                $wpdb->last_error='';
                $race=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('smail_messages').' WHERE client_key=%s',$client_key));
                if($wpdb->last_error!=='')return self::database_error();
                if($race){
                    $same=self::same_send_request($race,$sender,$recipients,$subject,$body);if(is_wp_error($same))return $same;if(!$same)return self::idempotency_conflict();
""",1)
old="$id=absint($request['id']);$user=get_current_user_id();\n        return self::with_locks(['sn:f17:smail-state:'.$user.':'.$id],function()use($request,$id,$user){global $wpdb;$table=SN_DB::table('smail_states');$row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE smail_message_id=%d AND user_id=%d\",$id,$user));if(!$row)return new WP_Error('smail_not_found','The Smail item is unavailable.',['status'=>404]);"
new="$id=absint($request['id']);$user=get_current_user_id();\n        return self::with_locks(['sn:f17:smail-state:'.$user.':'.$id],function()use($request,$id,$user){global $wpdb;$table=SN_DB::table('smail_states');$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM $table WHERE smail_message_id=%d AND user_id=%d\",$id,$user));if($wpdb->last_error!=='')return self::database_error();if(!$row)return new WP_Error('smail_not_found','The Smail item is unavailable.',['status'=>404]);"
if old not in s: raise SystemExit('update state target missing')
s=s.replace(old,new,1)
old="return self::with_locks([$lock],function()use($request,$owner,$public,$wpdb){if($public!==''){$row=$wpdb->get_row($wpdb->prepare('SELECT version FROM '.SN_DB::table('smail_drafts').' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',$public,$owner));if(!$row)return new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]);"
new="return self::with_locks([$lock],function()use($request,$owner,$public,$wpdb){if($public!==''){$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT version FROM '.SN_DB::table('smail_drafts').' WHERE public_id=%s AND owner_id=%d AND deleted_at IS NULL',$public,$owner));if($wpdb->last_error!=='')return self::database_error();if(!$row)return new WP_Error('draft_not_found','The Smail draft is unavailable.',['status'=>404]);"
if old not in s: raise SystemExit('save draft target missing')
s=s.replace(old,new,1)
start=s.index('    private static function same_send_request(')
end=s.index('    private static function idempotency_conflict()',start)
replacement="""    private static function same_send_request(object $row,int $sender,array $recipients,string $subject,string $body): bool|WP_Error {
        global $wpdb;
        if((int)$row->sender_id!==$sender||(string)$row->subject!==$subject)return false;
        $wpdb->last_error='';
        $message=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SN_DB::table('messages').' WHERE id=%d',(int)$row->message_id));
        if($wpdb->last_error!=='')return self::database_error();
        if(!$message||(int)$message->conversation_id!==(int)$row->conversation_id||(int)$message->sender_id!==$sender||$message->deleted_at)return false;
        $plain=SN_Message_Body::decrypt_row($message);if(is_wp_error($plain))return $plain;if((string)$plain!==$body)return false;
        $wpdb->last_error='';
        $stored_raw=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.SN_DB::table('smail_states').' WHERE smail_message_id=%d AND user_id<>%d ORDER BY user_id ASC',(int)$row->id,$sender));
        if($wpdb->last_error!==''||!is_array($stored_raw))return self::database_error();
        $stored=array_values(array_map('intval',$stored_raw));
        $requested=array_values(array_unique(array_map('intval',$recipients)));sort($requested,SORT_NUMERIC);
        return $stored===$requested;
    }

"""
s=s[:start]+replacement+s[end:]
anchor="""    private static function idempotency_conflict(): WP_Error {
"""
helper="""    private static function database_error(): WP_Error {
        return new WP_Error('smail_database_read_failed','Smail canonical state could not be read safely. Retry the request.',['status'=>503]);
    }

"""
if anchor not in s: raise SystemExit('runtime helper anchor missing')
s=s.replace(anchor,helper+anchor,1)
p.write_text(s,encoding='utf-8')

# Permanent regression in existing Smail adversarial suite.
p=Path('sabri-network/tests/smail-adversarial-contracts.php')
t=p.read_text(encoding='utf-8')
marker='if($fail){fwrite(STDERR,'
insert="""
$check(str_contains($runtime,'smail_database_read_failed')&&str_contains($runtime,'bool|WP_Error'),'Fresh20 R5: Smail duplicate truth must propagate canonical DB/decryption read failure instead of conflict.');
$check(substr_count($runtime,"last_error=''")>=5&&substr_count($runtime,"last_error!==''")>=5,'Fresh20 R5: runtime Smail authoritative reads must explicitly clear and verify DB error state.');
$check(str_contains($runtime,"if(is_wp_error($same))return $same")&&substr_count($runtime,'if(is_wp_error($same))return $same')>=3,'Fresh20 R5: every send duplicate/race reconciliation path must propagate read failure distinctly.');
"""
idx=t.rfind(marker)
if idx<0: raise SystemExit('smail test tail missing')
t=t[:idx]+insert+t[idx:]
p.write_text(t,encoding='utf-8')

p=Path('sabri-network/tests/smail-static-contracts.php')
t=p.read_text(encoding='utf-8')
# load final hardening for route-owner assertions without changing suite inventory
if "$final = file_get_contents($root.'/includes/class-sn-fourth-fresh-smail-hardening.php');" not in t:
    t=t.replace("$css = file_get_contents($root.'/assets/css/smail.css');", "$css = file_get_contents($root.'/assets/css/smail.css'); $final = file_get_contents($root.'/includes/class-sn-fourth-fresh-smail-hardening.php');",1)
marker='if($fails){fwrite(STDERR,'
insert="""
smc(str_contains($final,'smail_database_read_failed')&&substr_count($final,"last_error")>=4,'Fresh20 R5: final Smail draft owner must distinguish SQL failure from not-found/version conflict.');
"""
idx=t.rfind(marker)
if idx<0: raise SystemExit('smail static tail missing')
t=t[:idx]+insert+t[idx:]
p.write_text(t,encoding='utf-8')
