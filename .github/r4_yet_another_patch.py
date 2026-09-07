from pathlib import Path

# R4-D01 governance ledger must fail closed.
p=Path('sabri-network/includes/class-sn-spaces-part-9.php');s=p.read_text(encoding='utf-8')
old="private static function record(int $space,int $actor,string $action,string $target_type,int $target_id,string $reason,array $scope): void {global $wpdb;$json=wp_json_encode($scope);$wpdb->insert(self::audit_table(),['space_id'=>$space,'actor_id'=>$actor,'action'=>$action,'target_type'=>$target_type,'target_id'=>$target_id,'reason'=>self::text($reason,500),'scope_hash'=>hash('sha256',is_string($json)?$json:''),'created_at'=>self::now()]);SN_DB::audit($action,'space',$space,'success',['target_type'=>$target_type,'target_id'=>$target_id,'scope_hash'=>hash('sha256',is_string($json)?$json:'')],$actor);}"
new="private static function record(int $space,int $actor,string $action,string $target_type,int $target_id,string $reason,array $scope): void {global $wpdb;$json=wp_json_encode($scope);$scope_hash=hash('sha256',is_string($json)?$json:'');$ok=$wpdb->insert(self::audit_table(),['space_id'=>$space,'actor_id'=>$actor,'action'=>$action,'target_type'=>$target_type,'target_id'=>$target_id,'reason'=>self::text($reason,500),'scope_hash'=>$scope_hash,'created_at'=>self::now()]);if($ok===false)throw new RuntimeException('space_governance_audit_failed');SN_DB::audit($action,'space',$space,'success',['target_type'=>$target_type,'target_id'=>$target_id,'scope_hash'=>$scope_hash],$actor);}"
if old not in s: raise SystemExit('R4 record target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# R4-D02 settings mutation and governance evidence atomically committed.
p=Path('sabri-network/includes/class-sn-spaces-part-2.php');s=p.read_text(encoding='utf-8')
old="""        $allowed['updated_at']=self::now();$allowed['version']=$expected+1;
        $changed=$wpdb->update(self::spaces_table(),$allowed,['id'=>$id,'version'=>$expected]);
        if($changed!==1)return self::error('sn_space_update_conflict','A concurrent space update was detected.',409);
        self::record($id,$actor,'space_settings_updated','space',$id,'',['fields'=>array_keys($allowed)]);
        return rest_ensure_response(['space'=>self::format_space(self::space($id),$actor)]);
"""
new="""        $allowed['updated_at']=self::now();$allowed['version']=$expected+1;
        if($wpdb->query('START TRANSACTION')===false)return self::error('sn_space_transaction_failed','The space change could not start safely.',500);
        try{
            $locked=self::space($id,true);
            if(!$locked||(int)$locked->version!==$expected){$wpdb->query('ROLLBACK');return self::error('sn_space_update_conflict','A concurrent space update was detected.',409);}
            if(!self::can_manage($id,$actor,'settings')){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Space settings permission is required.',403);}
            $changed=$wpdb->update(self::spaces_table(),$allowed,['id'=>$id,'version'=>$expected]);
            if($changed!==1)throw new RuntimeException('space_update_conflict');
            self::record($id,$actor,'space_settings_updated','space',$id,'',['fields'=>array_keys($allowed)]);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_update_commit_failed');
            return rest_ensure_response(['space'=>self::format_space(self::space($id),$actor)]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_update_failed','The space settings change could not be committed atomically.',500);}
"""
if old not in s: raise SystemExit('R4 update_space target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# R4-D03 expired invitation transition must prove mutation and commit.
p=Path('sabri-network/includes/class-sn-spaces-part-4.php');s=p.read_text(encoding='utf-8')
old="if(strtotime((string)$invite->expires_at.' UTC')<=time()){$wpdb->update(self::invites_table(),['status'=>'expired','active_key'=>null,'updated_at'=>self::now(),'version'=>(int)$invite->version+1],['id'=>$id,'status'=>'pending','version'=>(int)$invite->version]);$wpdb->query('COMMIT');return self::error('sn_invite_expired','The invitation expired.',410);}"
new="if(strtotime((string)$invite->expires_at.' UTC')<=time()){$expired=$wpdb->update(self::invites_table(),['status'=>'expired','active_key'=>null,'updated_at'=>self::now(),'version'=>(int)$invite->version+1],['id'=>$id,'status'=>'pending','version'=>(int)$invite->version]);if($expired!==1)throw new RuntimeException('invite_expiry_conflict');if($wpdb->query('COMMIT')===false)throw new RuntimeException('invite_expiry_commit_failed');return self::error('sn_invite_expired','The invitation expired.',410);}"
if old not in s: raise SystemExit('R4 invite expiry target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# R4-D02 unban + governance evidence under one transaction and current authority.
p=Path('sabri-network/includes/class-sn-spaces-part-5.php');s=p.read_text(encoding='utf-8')
old="""        if($action==='unban'){
            if(!$existing|| (string)$existing->status!=='active')return rest_ensure_response(['status'=>'inactive']);
            $changed=$wpdb->update(self::bans_table(),['status'=>'revoked','updated_at'=>$now,'version'=>(int)$existing->version+1],['id'=>(int)$existing->id,'status'=>'active','version'=>(int)$existing->version]);
            if($changed!==1)return self::error('sn_space_ban_conflict','The ban changed concurrently.',409);
            self::record($space_id,$actor,'member_unbanned','user',$target,self::text((string)$request->get_param('reason'),500),[]);
            return rest_ensure_response(['status'=>'revoked']);
        }
"""
new="""        if($action==='unban'){
            if($wpdb->query('START TRANSACTION')===false)return self::error('sn_space_transaction_failed','The space change could not start safely.',500);
            try{
                $actor_locked=self::member($space_id,$actor,true);
                $ban_locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::bans_table().' WHERE space_id=%d AND user_id=%d FOR UPDATE',$space_id,$target));
                if(!$actor_locked||!self::can_manage($space_id,$actor,'moderation')){$wpdb->query('ROLLBACK');return self::error('sn_space_moderation_forbidden','Moderation permission is required.',403);}
                if(!$ban_locked||(string)$ban_locked->status!=='active'){if($wpdb->query('COMMIT')===false)throw new RuntimeException('unban_noop_commit_failed');return rest_ensure_response(['status'=>'inactive']);}
                $changed=$wpdb->update(self::bans_table(),['status'=>'revoked','updated_at'=>$now,'version'=>(int)$ban_locked->version+1],['id'=>(int)$ban_locked->id,'status'=>'active','version'=>(int)$ban_locked->version]);
                if($changed!==1)throw new RuntimeException('unban_conflict');
                self::record($space_id,$actor,'member_unbanned','user',$target,self::text((string)$request->get_param('reason'),500),[]);
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('unban_commit_failed');
                return rest_ensure_response(['status'=>'revoked']);
            }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_unban_failed','The ban could not be revoked atomically.',500);}
        }
"""
if old not in s: raise SystemExit('R4 unban target missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')

# Permanent regression, reusing inventory-owned suite.
p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php');s=p.read_text(encoding='utf-8')
anchor="$space9=$read('includes/class-sn-spaces-part-9.php');\n"
if "$space2=$read('includes/class-sn-spaces-part-2.php');" not in s:
    if anchor not in s: raise SystemExit('R4 regression anchor missing')
    s=s.replace(anchor,anchor+"$space2=$read('includes/class-sn-spaces-part-2.php');\n$space4=$read('includes/class-sn-spaces-part-4.php');\n",1)
marker='// Yet-another R4 spaces-governance regressions.'
if marker not in s:
    block="""
// Yet-another R4 spaces-governance regressions.
$check(str_contains($space9,'space_governance_audit_failed')&&str_contains($space9,'if($ok===false)throw new RuntimeException'),'Yet R4: File-17 space governance ledger insertion must fail closed.');
$check(str_contains($space2,'space_update_commit_failed')&&str_contains($space2,'START TRANSACTION')&&str_contains($space2,'self::record($id,$actor,\'space_settings_updated\''),'Yet R4: settings mutation and governance evidence must share a checked transaction.');
$check(str_contains($space4,'invite_expiry_conflict')&&str_contains($space4,'invite_expiry_commit_failed'),'Yet R4: expired-invite terminal response must prove both expiry update and commit.');
$check(str_contains($space5,'unban_commit_failed')&&str_contains($space5,'sn_space_unban_failed')&&str_contains($space5,'FOR UPDATE'),'Yet R4: unban must be atomic with locked current authority and governance evidence.');
"""
    tail=s.rfind('if($fail){')
    if tail<0: raise SystemExit('R4 regression tail missing')
    s=s[:tail]+block+s[tail:]
p.write_text(s,encoding='utf-8')
