<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_1 {
    public static function create_space(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id();
        if (!self::can_create($actor)) return self::error('sn_space_create_forbidden', 'This account cannot create a collaborative space.', 403);
        $type = sanitize_key((string) $request->get_param('type'));
        $name = self::text((string) $request->get_param('name'), 191);
        if (!in_array($type, self::TYPES, true) || $name === '') return self::error('sn_space_invalid', 'A valid space type and name are required.', 400);
        if (!SN_Policy::consume_rate_limit('space_create', (string) $actor, 10, DAY_IN_SECONDS)) return self::error('sn_space_create_rate_limited', 'Too many spaces were created recently.', 429);
        $visibility = self::enum((string) $request->get_param('visibility'), self::VISIBILITIES, $type === 'community' ? 'discoverable_private' : 'invite_only');
        $join = self::enum((string) $request->get_param('join_policy'), self::JOIN_POLICIES, $visibility === 'public' ? 'open' : 'request');
        if ($visibility === 'hidden') $join = 'invite';
        $parent_id = absint($request->get_param('parent_id'));
        if ($parent_id > 0) {
            $parent = self::space($parent_id);
            if (!$parent || (string) $parent->type !== 'community' || !in_array((string) $parent->state, ['active','restricted'], true)) {
                return self::error('sn_space_parent_invalid', 'The parent community is unavailable.', 409);
            }
            if (!self::can_manage($parent_id, $actor, 'settings')) {
                return self::error('sn_space_parent_forbidden', 'Parent-community management permission is required.', 403);
            }
        } elseif ($type !== 'community') {
            $parent_id = 0;
        }
        $slug_base = sanitize_title((string) $request->get_param('slug')) ?: sanitize_title($name);
        $slug = self::unique_slug($slug_base);
        $now = self::now();
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::error('sn_space_create_failed', 'The space transaction could not start safely.', 500);
        }
        try {
            if ($parent_id > 0) {
                $parent_locked = self::space($parent_id, true);
                if (!$parent_locked || (string) $parent_locked->type !== 'community' || !in_array((string) $parent_locked->state, ['active','restricted'], true)) {
                    $wpdb->query('ROLLBACK');
                    return self::error('sn_space_parent_invalid', 'The parent community is unavailable.', 409);
                }
                $parent_access = self::assert_manage_locked($parent_id, $actor, 'settings');
                if (is_wp_error($parent_access)) {
                    $wpdb->query('ROLLBACK');
                    return self::error('sn_space_parent_forbidden', 'Current parent-community management permission is required.', 403);
                }
            }
            $ok = $wpdb->insert(self::spaces_table(), [
                'public_id'=>wp_generate_uuid4(),'parent_id'=>$parent_id,'owner_user_id'=>$actor,
                'type'=>$type,'subtype'=>self::text((string)$request->get_param('subtype'),40),'slug'=>$slug,'name'=>$name,
                'description'=>self::text((string)$request->get_param('description'),4000),'rules'=>self::text((string)$request->get_param('rules'),8000),
                'language'=>self::locale((string)$request->get_param('language')),'region'=>self::text((string)$request->get_param('region'),80),
                'categories'=>self::json_list($request->get_param('categories'),20,60),'visibility'=>$visibility,'state'=>'active','join_policy'=>$join,
                'posting_policy'=>self::enum((string)$request->get_param('posting_policy'),self::POSTING_POLICIES,$type==='channel'?'roles':'members'),
                'history_policy'=>self::enum((string)$request->get_param('history_policy'),['full','from_join','limited','none'],'from_join'),
                'member_limit'=>self::bounded_int($request->get_param('member_limit'),2,100000,500),
                'slow_mode_seconds'=>self::bounded_int($request->get_param('slow_mode_seconds'),0,86400,0),
                'new_member_delay_seconds'=>self::bounded_int($request->get_param('new_member_delay_seconds'),0,604800,0),
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            if ($ok === false) throw new RuntimeException('space_insert_failed');
            $id = (int) $wpdb->insert_id;
            if ($wpdb->insert(self::members_table(), ['space_id'=>$id,'user_id'=>$actor,'role'=>'owner','status'=>'active','approved_by'=>$actor,'joined_at'=>$now,'created_at'=>$now,'updated_at'=>$now]) === false) throw new RuntimeException('space_owner_insert_failed');
            $conversation_id = self::create_space_conversation($id, $type, $name, $slug, $actor, $now);
            if ($conversation_id > 0 && $wpdb->update(self::spaces_table(), ['conversation_id'=>$conversation_id], ['id'=>$id]) !== 1) throw new RuntimeException('space_conversation_link_failed');
            $event = SN_Outbox::enqueue('space.created','space',$id,['space_id'=>$id,'conversation_id'=>$conversation_id,'type'=>$type,'visibility'=>$visibility,'owner_id'=>$actor,'created_at'=>$now],'space.created:'.$id);
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            self::record($id,$actor,'space_created','space',$id,'',['type'=>$type,'visibility'=>$visibility]);
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('space_commit_failed');
            do_action('sn_network_event_queued',$event,'space.created');
            return new WP_REST_Response(['space'=>self::format_space(self::space($id),$actor)],201);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('space_create_failed','space',0,'failure',['reason'=>$e->getMessage()],$actor);
            return self::error('sn_space_create_failed','The space could not be created atomically.',500);
        }
    }

    public static function list_spaces(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $viewer = get_current_user_id();
        $limit = max(1,min(self::MAX_LIST,absint($request->get_param('limit'))?:30));
        $after = absint($request->get_param('after'));
        $type = sanitize_key((string)$request->get_param('type'));
        $type_sql = in_array($type,self::TYPES,true) ? $wpdb->prepare(' AND s.type=%s',$type) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.* FROM ".self::spaces_table()." s LEFT JOIN ".self::members_table()." m ON m.space_id=s.id AND m.user_id=%d AND m.status='active' WHERE s.id>%d AND s.state<>'deletion_requested' AND (m.id IS NOT NULL OR (s.state<>'closed' AND s.visibility IN ('public','discoverable_private'))) $type_sql ORDER BY s.id ASC LIMIT %d",
            $viewer,$after,$limit+1
        ));
        $accessible=[];
        foreach (is_array($rows)?$rows:[] as $row) {
            if (self::can_view($row,$viewer)) $accessible[]=$row;
        }
        $has_more=count($accessible)>$limit;
        if($has_more)$accessible=array_slice($accessible,0,$limit);
        $items=array_map(static fn($row)=>self::format_space($row,$viewer),$accessible);
        $next=$has_more&&$accessible?(int)end($accessible)->id:null;
        return rest_ensure_response(['items'=>$items,'next_after'=>$next]);
    }

    public static function get_space(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $row=self::space(absint($request['id']));$viewer=get_current_user_id();
        if(!$row||!self::can_view($row,$viewer))return self::error('sn_space_not_found','The space is unavailable.',404);
        return rest_ensure_response(['space'=>self::format_space($row,$viewer)]);
    }
}
