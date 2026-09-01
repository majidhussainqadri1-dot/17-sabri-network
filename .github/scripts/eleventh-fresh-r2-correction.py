from pathlib import Path
p=Path('sabri-network/includes/class-sn-presence-devices.php')
s=p.read_text(encoding='utf-8')
def rep(old,new):
    global s
    if old not in s: raise SystemExit('missing anchor: '+old[:160])
    s=s.replace(old,new,1)
rep("""        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id=%d AND device_key=%s',$user,$device_key));
        if(!$existing){
""","""        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id=%d AND device_key=%s',$user,$device_key));
        if(($wpdb->last_error??'')!==''){SN_DB::audit('presence_device_state_read_failed','presence_device',0,'failure',['reason'=>(string)$wpdb->last_error],$user);return self::error('sn_presence_state_unavailable','Presence device state could not be verified safely.',503);}
        if(!$existing){
""")
rep("""            if($changed!==1)return self::error('sn_presence_conflict','A concurrent heartbeat was detected.',409);
""","""            if($changed===false)return self::error('sn_presence_write_failed','The presence heartbeat could not be stored safely.',503);
            if($changed!==1)return self::error('sn_presence_conflict','A concurrent heartbeat was detected.',409);
""")
rep("""    public static function list_own(): WP_REST_Response {
        global $wpdb;$user=get_current_user_id();$now=self::now();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT device_key,device_label,state,last_seen_at,expires_at,revoked_at,version,created_at FROM '.self::table().' WHERE user_id=%d ORDER BY updated_at DESC LIMIT %d',$user,self::MAX_DEVICES));$items=[];
        foreach(is_array($rows)?$rows:[] as $row)$items[]=['device_ref'=>self::sign_ref($user,(string)$row->device_key),'label'=>(string)$row->device_label,'state'=>self::effective_state($row,$now),'last_seen_at'=>(string)$row->last_seen_at,'expires_at'=>(string)$row->expires_at,'revoked'=>(bool)$row->revoked_at,'version'=>(int)$row->version,'created_at'=>(string)$row->created_at];
        return rest_ensure_response(['items'=>$items]);
    }
""","""    public static function list_own(): WP_REST_Response|WP_Error {
        global $wpdb;$user=get_current_user_id();$now=self::now();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT device_key,device_label,state,last_seen_at,expires_at,revoked_at,version,created_at FROM '.self::table().' WHERE user_id=%d ORDER BY updated_at DESC LIMIT %d',$user,self::MAX_DEVICES));
        if(($wpdb->last_error??'')!==''||!is_array($rows)){SN_DB::audit('presence_device_list_read_failed','user',$user,'failure',['reason'=>(string)($wpdb->last_error??'')],$user);return self::error('sn_presence_state_unavailable','Presence device state could not be verified safely.',503);}
        $items=[];foreach($rows as $row)$items[]=['device_ref'=>self::sign_ref($user,(string)$row->device_key),'label'=>(string)$row->device_label,'state'=>self::effective_state($row,$now),'last_seen_at'=>(string)$row->last_seen_at,'expires_at'=>(string)$row->expires_at,'revoked'=>(bool)$row->revoked_at,'version'=>(int)$row->version,'created_at'=>(string)$row->created_at];
        return rest_ensure_response(['items'=>$items]);
    }
""")
rep("""        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id=%d AND device_key=%s',$user,(string)$ref['device_key']));if(!$row)return self::error('sn_presence_device_missing','The device is unavailable.',404);
""","""        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id=%d AND device_key=%s',$user,(string)$ref['device_key']));if(($wpdb->last_error??'')!=='')return self::error('sn_presence_state_unavailable','Presence device state could not be verified safely.',503);if(!$row)return self::error('sn_presence_device_missing','The device is unavailable.',404);
""")
rep("""        if($changed!==1)return self::error('sn_presence_revoke_conflict','The device changed concurrently.',409);
""","""        if($changed===false)return self::error('sn_presence_revoke_failed','The device revocation could not be stored safely.',503);
        if($changed!==1)return self::error('sn_presence_revoke_conflict','The device changed concurrently.',409);
""")
rep("""        $now=self::now();$rows=$wpdb->get_results($wpdb->prepare('SELECT state,last_seen_at,expires_at,revoked_at FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s ORDER BY last_seen_at DESC LIMIT %d',$target,$now,self::MAX_DEVICES));
        $state='offline';$last=null;$rank=['offline'=>0,'away'=>1,'online'=>2,'dnd'=>3];
""","""        $now=self::now();$rows=$wpdb->get_results($wpdb->prepare('SELECT state,last_seen_at,expires_at,revoked_at FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s ORDER BY last_seen_at DESC LIMIT %d',$target,$now,self::MAX_DEVICES));
        if(($wpdb->last_error??'')!==''||!is_array($rows)){SN_DB::audit('presence_aggregate_read_failed','user',$target,'failure',['reason'=>(string)($wpdb->last_error??'')],$viewer);return self::error('sn_presence_state_unavailable','Presence state could not be verified safely.',503);}
        $state='offline';$last=null;$rank=['offline'=>0,'away'=>1,'online'=>2,'dnd'=>3];
""")
rep("""    public static function cleanup(): void {
        global $wpdb;$cutoff=gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS);$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE (revoked_at IS NOT NULL AND revoked_at<%s) OR (expires_at<%s AND updated_at<%s) LIMIT 500',$cutoff,$cutoff,$cutoff));
    }
""","""    public static function cleanup(): void {
        global $wpdb;$cutoff=gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS);$deleted=$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE (revoked_at IS NOT NULL AND revoked_at<%s) OR (expires_at<%s AND updated_at<%s) LIMIT 500',$cutoff,$cutoff,$cutoff));
        if($deleted===false)SN_DB::audit('presence_cleanup_failed','system',0,'failure',['reason'=>(string)($wpdb->last_error??'')],0);
    }
""")
rep("""        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,device_label,state,last_seen_at,expires_at,revoked_at,created_at FROM '.self::table().' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',(int)$user->ID,$limit,$offset));$data=[];
        foreach(is_array($rows)?$rows:[] as $row)$data[]=['group_id'=>'sabri-network-presence-devices','group_label'=>__('Network presence devices','sabri-network'),'item_id'=>'presence-device-'.(int)$row->id,'data'=>[['name'=>__('Device label','sabri-network'),'value'=>(string)$row->device_label],['name'=>__('State','sabri-network'),'value'=>(string)$row->state],['name'=>__('Last seen','sabri-network'),'value'=>(string)$row->last_seen_at],['name'=>__('Expires','sabri-network'),'value'=>(string)$row->expires_at],['name'=>__('Revoked','sabri-network'),'value'=>(string)$row->revoked_at],['name'=>__('Created','sabri-network'),'value'=>(string)$row->created_at]]];
""","""        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,device_label,state,last_seen_at,expires_at,revoked_at,created_at FROM '.self::table().' WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d',(int)$user->ID,$limit,$offset));$data=[];
        if(($wpdb->last_error??'')!==''||!is_array($rows)){SN_DB::audit('presence_export_read_failed','user',(int)$user->ID,'failure',['reason'=>(string)($wpdb->last_error??'')],0);return['data'=>[],'done'=>false];}
        foreach($rows as $row)$data[]=['group_id'=>'sabri-network-presence-devices','group_label'=>__('Network presence devices','sabri-network'),'item_id'=>'presence-device-'.(int)$row->id,'data'=>[['name'=>__('Device label','sabri-network'),'value'=>(string)$row->device_label],['name'=>__('State','sabri-network'),'value'=>(string)$row->state],['name'=>__('Last seen','sabri-network'),'value'=>(string)$row->last_seen_at],['name'=>__('Expires','sabri-network'),'value'=>(string)$row->expires_at],['name'=>__('Revoked','sabri-network'),'value'=>(string)$row->revoked_at],['name'=>__('Created','sabri-network'),'value'=>(string)$row->created_at]]];
""")
rep("""    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$deleted=$wpdb->delete(self::table(),['user_id'=>(int)$user->ID]);return['items_removed'=>$deleted>0,'items_retained'=>false,'messages'=>[],'done'=>true];}
""","""    public static function erase_data(string $email,int $page=1): array {global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$deleted=$wpdb->delete(self::table(),['user_id'=>(int)$user->ID]);if($deleted===false){SN_DB::audit('presence_erase_failed','user',(int)$user->ID,'failure',['reason'=>(string)($wpdb->last_error??'')],0);return['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Presence device erasure could not be completed safely. Retry later.','sabri-network')],'done'=>false];}return['items_removed'=>$deleted>0,'items_retained'=>false,'messages'=>[],'done'=>true];}
""")
p.write_text(s,encoding='utf-8')

t=Path('sabri-network/tests/eleventh-fresh/eleventh-fresh-ten-round-contracts.php')
ts=t.read_text(encoding='utf-8')
anchor='if($fail){fwrite(STDERR,'
block="""// R2 — presence reads, mutations and privacy callbacks must preserve storage uncertainty.\n$presence=$read($root.'/includes/class-sn-presence-devices.php');\n$check(str_contains($presence,'sn_presence_state_unavailable'),'R2 presence state reads must fail closed on DB uncertainty.');\n$check(str_contains($presence,'presence_cleanup_failed'),'R2 presence cleanup failure must remain observable.');\n$check(str_contains($presence,'presence_export_read_failed'),'R2 privacy export must not report completion on failed reads.');\n$check(str_contains($presence,'presence_erase_failed'),'R2 privacy erasure must not report completion on failed deletes.');\n"""
if anchor not in ts: raise SystemExit('missing test footer')
ts=ts.replace(anchor,block+anchor,1)
t.write_text(ts,encoding='utf-8')
