<?php
/** Canonical communities, groups and channels governance for File 17. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Spaces {
    private const SCHEMA = '1.0.0';
    private const TYPES = ['community','group','channel'];
    private const VISIBILITIES = ['public','private','secret'];
    private const ROLES = ['owner','admin','moderator','member'];

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_spaces_schema_version', '') !== self::SCHEMA) self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}sn_spaces (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            parent_id bigint unsigned NULL,
            owner_user_id bigint unsigned NOT NULL,
            type varchar(20) NOT NULL,
            slug varchar(120) NOT NULL,
            name varchar(190) NOT NULL,
            description text NULL,
            visibility varchar(20) NOT NULL DEFAULT 'private',
            state varchar(20) NOT NULL DEFAULT 'active',
            join_policy varchar(20) NOT NULL DEFAULT 'request',
            posting_policy varchar(20) NOT NULL DEFAULT 'members',
            slow_mode_seconds int unsigned NOT NULL DEFAULT 0,
            member_limit int unsigned NOT NULL DEFAULT 5000,
            version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            archived_at datetime NULL,
            closed_at datetime NULL,
            PRIMARY KEY (id), UNIQUE KEY slug (slug),
            KEY parent_state (parent_id,state), KEY owner_state (owner_user_id,state)
        ) {$c};");
        dbDelta("CREATE TABLE {$wpdb->prefix}sn_space_members (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            role varchar(20) NOT NULL DEFAULT 'member',
            status varchar(20) NOT NULL DEFAULT 'active',
            joined_at datetime NULL,
            last_post_at datetime NULL,
            version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY space_user (space_id,user_id),
            KEY user_status (user_id,status), KEY space_role (space_id,role,status)
        ) {$c};");
        dbDelta("CREATE TABLE {$wpdb->prefix}sn_space_invites (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint unsigned NOT NULL,
            inviter_user_id bigint unsigned NOT NULL,
            invitee_user_id bigint unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            decided_at datetime NULL,
            PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash),
            KEY invitee_status (invitee_user_id,status,expires_at)
        ) {$c};");
        dbDelta("CREATE TABLE {$wpdb->prefix}sn_space_bans (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            actor_user_id bigint unsigned NOT NULL,
            reason varchar(500) NOT NULL DEFAULT '',
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            revoked_at datetime NULL,
            PRIMARY KEY (id), KEY active_ban (space_id,user_id,revoked_at), KEY expiry (expires_at,revoked_at)
        ) {$c};");
        update_option('sn_spaces_schema_version', self::SCHEMA, false);
    }

    public static function routes(): void {
        $ns = 'sabri-network/v2';
        register_rest_route($ns, '/spaces', [
            ['methods'=>'GET','callback'=>[self::class,'list_spaces'],'permission_callback'=>'is_user_logged_in'],
            ['methods'=>'POST','callback'=>[self::class,'create_space'],'permission_callback'=>'is_user_logged_in'],
        ]);
        register_rest_route($ns, '/spaces/(?P<id>\d+)', [
            ['methods'=>'GET','callback'=>[self::class,'get_space'],'permission_callback'=>'is_user_logged_in'],
            ['methods'=>'PATCH','callback'=>[self::class,'update_space'],'permission_callback'=>'is_user_logged_in'],
        ]);
        register_rest_route($ns, '/spaces/(?P<id>\d+)/join', ['methods'=>'POST','callback'=>[self::class,'join_space'],'permission_callback'=>'is_user_logged_in']);
        register_rest_route($ns, '/spaces/(?P<id>\d+)/members/(?P<user_id>\d+)', ['methods'=>'PATCH','callback'=>[self::class,'change_member'],'permission_callback'=>'is_user_logged_in']);
        register_rest_route($ns, '/spaces/(?P<id>\d+)/invites', ['methods'=>'POST','callback'=>[self::class,'invite'],'permission_callback'=>'is_user_logged_in']);
        register_rest_route($ns, '/spaces/invites/(?P<invite_id>\d+)/decision', ['methods'=>'POST','callback'=>[self::class,'decide_invite'],'permission_callback'=>'is_user_logged_in']);
        register_rest_route($ns, '/spaces/(?P<id>\d+)/bans', ['methods'=>'POST','callback'=>[self::class,'ban'],'permission_callback'=>'is_user_logged_in']);
        register_rest_route($ns, '/spaces/(?P<id>\d+)/transfer', ['methods'=>'POST','callback'=>[self::class,'transfer'],'permission_callback'=>'is_user_logged_in']);
    }

    private static function now(): string { return current_time('mysql', true); }
    private static function actor(): int { return get_current_user_id(); }
    private static function t(string $n): string { global $wpdb; return $wpdb->prefix . 'sn_' . $n; }
    private static function err(string $c, string $m, int $s): WP_Error { return new WP_Error($c, $m, ['status'=>$s]); }
    private static function space(int $id): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('spaces').' WHERE id=%d',$id)) ?: null; }
    private static function member(int $sid,int $uid): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('space_members').' WHERE space_id=%d AND user_id=%d',$sid,$uid)) ?: null; }
    private static function banned(int $sid,int $uid): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::t('space_bans').' WHERE space_id=%d AND user_id=%d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>%s) LIMIT 1',$sid,$uid,self::now())); }
    private static function manage(int $sid,int $uid): bool { if (user_can($uid,'manage_options')) return true; $m=self::member($sid,$uid); return $m && $m->status==='active' && in_array($m->role,['owner','admin'],true); }
    private static function moderate(int $sid,int $uid): bool { if (self::manage($sid,$uid)) return true; $m=self::member($sid,$uid); return $m && $m->status==='active' && $m->role==='moderator'; }

    public static function create_space(WP_REST_Request $r) {
        global $wpdb; $a=self::actor();
        if (!user_can($a,'sn_create_space') && !user_can($a,'manage_options')) return self::err('sn_space_forbidden','Creation is not allowed.',403);
        $type=sanitize_key((string)$r['type']); $vis=sanitize_key((string)($r['visibility']?:'private')); $name=sanitize_text_field((string)$r['name']);
        if (!in_array($type,self::TYPES,true)||!in_array($vis,self::VISIBILITIES,true)||$name==='') return self::err('sn_space_invalid','Invalid space data.',400);
        $slug=sanitize_title((string)($r['slug']?:$name)); $now=self::now();
        $wpdb->query('START TRANSACTION');
        $ok=$wpdb->insert(self::t('spaces'),['owner_user_id'=>$a,'type'=>$type,'slug'=>$slug,'name'=>$name,'description'=>sanitize_textarea_field((string)$r['description']),'visibility'=>$vis,'state'=>'active','join_policy'=>sanitize_key((string)($r['join_policy']?:'request')),'posting_policy'=>sanitize_key((string)($r['posting_policy']?:'members')),'slow_mode_seconds'=>min(86400,absint($r['slow_mode_seconds'])),'member_limit'=>max(2,min(100000,absint($r['member_limit'])?:5000)),'created_at'=>$now,'updated_at'=>$now]);
        if (!$ok) { $wpdb->query('ROLLBACK'); return self::err('sn_space_create_failed','Space could not be created.',500); }
        $sid=(int)$wpdb->insert_id;
        $ok=$wpdb->insert(self::t('space_members'),['space_id'=>$sid,'user_id'=>$a,'role'=>'owner','status'=>'active','joined_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        if (!$ok) { $wpdb->query('ROLLBACK'); return self::err('sn_space_owner_failed','Owner membership could not be created.',500); }
        $wpdb->query('COMMIT'); do_action('sn_space_created',$sid,$a); return new WP_REST_Response(['id'=>$sid,'version'=>1],201);
    }

    public static function list_spaces(WP_REST_Request $r): WP_REST_Response {
        global $wpdb; $a=self::actor(); $limit=max(1,min(50,absint($r['limit'])?:20));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT s.* FROM '.self::t('spaces').' s LEFT JOIN '.self::t('space_members').' m ON m.space_id=s.id AND m.user_id=%d WHERE s.state<>\'closed\' AND (s.visibility=\'public\' OR m.status=\'active\') ORDER BY s.updated_at DESC LIMIT %d',$a,$limit));
        return new WP_REST_Response(['items'=>$rows],200);
    }

    public static function get_space(WP_REST_Request $r) {
        $s=self::space(absint($r['id'])); if(!$s) return self::err('sn_space_not_found','Space not found.',404);
        $m=self::member((int)$s->id,self::actor()); if($s->visibility!=='public'&&(!$m||$m->status!=='active')) return self::err('sn_space_forbidden','Membership is required.',403);
        return new WP_REST_Response(['space'=>$s,'membership'=>$m],200);
    }

    public static function update_space(WP_REST_Request $r) {
        global $wpdb; $id=absint($r['id']); $a=self::actor(); $s=self::space($id); if(!$s) return self::err('sn_space_not_found','Space not found.',404);
        if(!self::manage($id,$a)) return self::err('sn_space_forbidden','Management permission is required.',403);
        $expected=absint($r['version']); if($expected!== (int)$s->version) return self::err('sn_space_version_conflict','The space changed. Reload and retry.',409);
        $data=['updated_at'=>self::now(),'version'=>(int)$s->version+1];
        foreach(['name','description'] as $k) if($r->has_param($k)) $data[$k]=$k==='name'?sanitize_text_field((string)$r[$k]):sanitize_textarea_field((string)$r[$k]);
        foreach(['visibility','join_policy','posting_policy','state'] as $k) if($r->has_param($k)) $data[$k]=sanitize_key((string)$r[$k]);
        if($r->has_param('slow_mode_seconds')) $data['slow_mode_seconds']=min(86400,absint($r['slow_mode_seconds']));
        $ok=$wpdb->update(self::t('spaces'),$data,['id'=>$id,'version'=>$expected]); if($ok!==1) return self::err('sn_space_update_conflict','Concurrent update detected.',409);
        do_action('sn_space_updated',$id,$a,$data); return new WP_REST_Response(['id'=>$id,'version'=>$data['version']],200);
    }

    public static function join_space(WP_REST_Request $r) {
        global $wpdb; $id=absint($r['id']); $a=self::actor(); $s=self::space($id); if(!$s||$s->state!=='active') return self::err('sn_space_unavailable','Space is unavailable.',404);
        if(self::banned($id,$a)) return self::err('sn_space_banned','You cannot join this space.',403);
        if($s->join_policy==='invite') return self::err('sn_space_invite_required','An invitation is required.',403);
        $status=$s->join_policy==='open'?'active':'pending'; $now=self::now();
        $wpdb->replace(self::t('space_members'),['space_id'=>$id,'user_id'=>$a,'role'=>'member','status'=>$status,'joined_at'=>$status==='active'?$now:null,'created_at'=>$now,'updated_at'=>$now]);
        do_action('sn_space_join_requested',$id,$a,$status); return new WP_REST_Response(['status'=>$status],$status==='active'?200:202);
    }

    public static function change_member(WP_REST_Request $r) {
        global $wpdb; $id=absint($r['id']); $uid=absint($r['user_id']); $a=self::actor(); if(!self::manage($id,$a)) return self::err('sn_space_forbidden','Management permission is required.',403);
        $m=self::member($id,$uid); if(!$m) return self::err('sn_space_member_not_found','Member not found.',404);
        if($m->role==='owner') return self::err('sn_space_owner_protected','Transfer ownership before changing the owner.',409);
        $role=sanitize_key((string)($r['role']?:$m->role)); $status=sanitize_key((string)($r['status']?:$m->status)); if(!in_array($role,self::ROLES,true)||!in_array($status,['active','pending','removed'],true)) return self::err('sn_space_member_invalid','Invalid role or status.',400);
        $wpdb->update(self::t('space_members'),['role'=>$role,'status'=>$status,'version'=>(int)$m->version+1,'updated_at'=>self::now()],['id'=>$m->id,'version'=>$m->version]);
        do_action('sn_space_member_changed',$id,$uid,$a,$role,$status); return new WP_REST_Response(['role'=>$role,'status'=>$status],200);
    }

    public static function invite(WP_REST_Request $r) {
        global $wpdb; $id=absint($r['id']); $a=self::actor(); $uid=absint($r['user_id']); if(!self::moderate($id,$a)) return self::err('sn_space_forbidden','Moderation permission is required.',403);
        if(!$uid||self::banned($id,$uid)) return self::err('sn_space_invitee_invalid','Invitee is not eligible.',400);
        $raw=wp_generate_uuid4().wp_generate_password(32,false); $hash=hash_hmac('sha256',$raw,wp_salt('auth')); $now=self::now(); $exp=gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS*7);
        $ok=$wpdb->insert(self::t('space_invites'),['space_id'=>$id,'inviter_user_id'=>$a,'invitee_user_id'=>$uid,'token_hash'=>$hash,'status'=>'pending','expires_at'=>$exp,'created_at'=>$now]); if(!$ok) return self::err('sn_space_invite_failed','Invitation could not be created.',409);
        do_action('sn_space_invited',$id,$uid,$a,(int)$wpdb->insert_id); return new WP_REST_Response(['invite_id'=>(int)$wpdb->insert_id,'expires_at'=>$exp],201);
    }

    public static function decide_invite(WP_REST_Request $r) {
        global $wpdb; $iid=absint($r['invite_id']); $a=self::actor(); $inv=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('space_invites').' WHERE id=%d AND invitee_user_id=%d',$iid,$a)); if(!$inv||$inv->status!=='pending') return self::err('sn_space_invite_not_found','Invitation is unavailable.',404);
        if(strtotime($inv->expires_at)<=time()) return self::err('sn_space_invite_expired','Invitation expired.',410);
        $decision=sanitize_key((string)$r['decision']); if(!in_array($decision,['accept','decline'],true)) return self::err('sn_space_decision_invalid','Invalid decision.',400);
        $now=self::now(); $wpdb->query('START TRANSACTION'); $wpdb->update(self::t('space_invites'),['status'=>$decision==='accept'?'accepted':'declined','decided_at'=>$now],['id'=>$iid,'status'=>'pending']);
        if($decision==='accept') $wpdb->replace(self::t('space_members'),['space_id'=>$inv->space_id,'user_id'=>$a,'role'=>'member','status'=>'active','joined_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        $wpdb->query('COMMIT'); do_action('sn_space_invite_decided',(int)$inv->space_id,$a,$decision); return new WP_REST_Response(['decision'=>$decision],200);
    }

    public static function ban(WP_REST_Request $r) {
        global $wpdb; $id=absint($r['id']); $a=self::actor(); $uid=absint($r['user_id']); if(!self::moderate($id,$a)) return self::err('sn_space_forbidden','Moderation permission is required.',403);
        $m=self::member($id,$uid); if($m&&in_array($m->role,['owner','admin'],true)&&!self::manage($id,$a)) return self::err('sn_space_role_protected','You cannot ban this role.',403);
        $exp=$r['expires_at']?sanitize_text_field((string)$r['expires_at']):null; $now=self::now(); $wpdb->query('START TRANSACTION');
        $wpdb->insert(self::t('space_bans'),['space_id'=>$id,'user_id'=>$uid,'actor_user_id'=>$a,'reason'=>mb_substr(sanitize_textarea_field((string)$r['reason']),0,500),'expires_at'=>$exp,'created_at'=>$now]);
        $wpdb->update(self::t('space_members'),['status'=>'removed','updated_at'=>$now],['space_id'=>$id,'user_id'=>$uid]); $wpdb->query('COMMIT'); do_action('sn_space_member_banned',$id,$uid,$a); return new WP_REST_Response(['banned'=>true],200);
    }

    public static function transfer(WP_REST_Request $r) {
        global $wpdb; $id=absint($r['id']); $a=self::actor(); $target=absint($r['user_id']); $s=self::space($id); if(!$s||((int)$s->owner_user_id!==$a&&!user_can($a,'manage_options'))) return self::err('sn_space_owner_required','Only the owner can transfer ownership.',403);
        $tm=self::member($id,$target); if(!$tm||$tm->status!=='active'||self::banned($id,$target)) return self::err('sn_space_successor_invalid','Successor must be an active eligible member.',400);
        $expected=absint($r['version']); if($expected!==(int)$s->version) return self::err('sn_space_version_conflict','The space changed. Reload and retry.',409);
        $wpdb->query('START TRANSACTION'); $ok=$wpdb->update(self::t('spaces'),['owner_user_id'=>$target,'version'=>$expected+1,'updated_at'=>self::now()],['id'=>$id,'version'=>$expected]); if($ok!==1){$wpdb->query('ROLLBACK');return self::err('sn_space_transfer_conflict','Concurrent ownership change detected.',409);} $wpdb->update(self::t('space_members'),['role'=>'admin','updated_at'=>self::now()],['space_id'=>$id,'user_id'=>$a]); $wpdb->update(self::t('space_members'),['role'=>'owner','updated_at'=>self::now()],['space_id'=>$id,'user_id'=>$target]); $wpdb->query('COMMIT'); do_action('sn_space_owner_transferred',$id,$a,$target); return new WP_REST_Response(['owner_user_id'=>$target,'version'=>$expected+1],200);
    }

    public static function cleanup(): void {
        global $wpdb; $now=self::now();
        $wpdb->query($wpdb->prepare('UPDATE '.self::t('space_invites').' SET status=\'expired\', decided_at=%s WHERE status=\'pending\' AND expires_at<=%s LIMIT 500',$now,$now));
        $wpdb->query($wpdb->prepare('UPDATE '.self::t('space_bans').' SET revoked_at=%s WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at<=%s LIMIT 500',$now,$now));
    }
}
