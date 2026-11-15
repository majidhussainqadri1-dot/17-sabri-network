<?php
/**
 * F17-FUT-07 V12.3: an intact member row is not sufficient after a conversation
 * is archived. This checks the canonical DB predicate's generated SQL and
 * failure cases, independent of actual WordPress/MySQL staging.
 */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
define('SN_DIR',dirname(__DIR__).'/');
final class FakeMembershipDB {
    public string $prefix='wp_';
    public array $members=[42=>[7=>true,8=>true]];
    public array $status=[42=>'active'];
    public string $last_query='';
    public int $reads=0;
    public function prepare(string $sql,mixed ...$params):string {
        foreach($params as $param){
            $replacement=is_int($param)?(string)$param:"'".str_replace("'","''",(string)$param)."'";
            $sql=(string)preg_replace('/%[ds]/',$replacement,$sql,1);
        }
        return $sql;
    }
    public function get_var(string $sql):int|false {
        $this->last_query=$sql; $this->reads++;
        preg_match('/m\.conversation_id=(\d+) AND m\.user_id=(\d+)/',$sql,$matches);
        $conversation=(int)($matches[1]??0);
        $user=(int)($matches[2]??0);
        // Not merely a hand-coded join simulation: reject regressions where
        // active status, current membership or both canonical tables disappear.
        if(!str_contains($sql,'JOIN wp_sn_conversations c')
            ||!str_contains($sql,'FROM wp_sn_members m')
            ||!str_contains($sql,"c.status='active'")
            ||!str_contains($sql,'m.left_at IS NULL'))return false;
        return !empty($this->members[$conversation][$user])
            &&($this->status[$conversation]??'')==='active'?101:false;
    }
}
function sanitize_key(string $name):string{
    return strtolower(preg_replace('/[^a-z0-9_-]/','',$name));
}
$wpdb=new FakeMembershipDB();
require SN_DIR.'includes/class-sn-db.php';
$checks=0;
$check=static function(bool $yes,string $why)use(&$checks):void{
    ++$checks;if(!$yes){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$check(SN_DB::is_active_conversation_member(42,7),
    'A current member in an active conversation is authorized');
$check(str_contains($wpdb->last_query,"c.status='active'"),
    'The database query requires canonical active conversation status');
$check(str_contains($wpdb->last_query,'m.left_at IS NULL'),
    'The database query requires an unrevoked member row');
$wpdb->status[42]='archived';
$check(!SN_DB::is_active_conversation_member(42,7),
    'Archiving a conversation revokes due-time reminder authority without deleting the member row');
$wpdb->status[42]='deleted';
$check(!SN_DB::is_active_conversation_member(42,7),
    'A deleted conversation is not a valid reminder source');
$wpdb->status[42]='active';
$wpdb->members[42][7]=false;
$check(!SN_DB::is_active_conversation_member(42,7),
    'Removed member is refused even if the conversation remains active');
$check(SN_DB::is_active_conversation_member(42,8),
    'Another current member may still use active conversation');
$check(!SN_DB::is_active_conversation_member(42,9),
    'Unknown outsider is refused');
$before=$wpdb->reads;
$check(!SN_DB::is_active_conversation_member(0,7)
    &&!SN_DB::is_active_conversation_member(42,0),
    'Empty scope or user is rejected before database access');
$check($wpdb->reads===$before,
    'Invalid identity never queries canonical tables');
echo "V12.3 active-conversation reminder membership: $checks PASS, 0 FAIL\n";
