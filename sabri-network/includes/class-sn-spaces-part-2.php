<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_2 {
    public static function update_space(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id=absint($request['id']);$actor=get_current_user_id();$expected=absint($request->get_param('version'));
        if($wpdb->query('START TRANSACTION')===false)return self::error('sn_space_update_failed','The space settings transaction could not start safely.',500);
        try{
            $space=self::space($id,true);
            if(!$space){$wpdb->query('ROLLBACK');return self::error('sn_space_not_found','The space is unavailable.',404);}
            $access=self::assert_manage_locked($id,$actor,'settings');
            if(is_wp_error($access)){$wpdb->query('ROLLBACK');return self::error('sn_space_manage_forbidden','Current space settings permission is required.',403);}
            if($expected!==(int)$space->version){$wpdb->query('ROLLBACK');return self::error('sn_space_version_conflict','The space changed. Reload and retry.',409);}
            $allowed=[];
            foreach(['name'=>191,'description'=>4000,'rules'=>8000,'region'=>80,'subtype'=>40] as $key=>$max){if($request->has_param($key))$allowed[$key]=self::text((string)$request->get_param($key),$max);}
            if($request->has_param('language'))$allowed['language']=self::locale((string)$request->get_param('language'));
            if($request->has_param('categories'))$allowed['categories']=self::json_list($request->get_param('categories'),20,60);
            if($request->has_param('visibility'))$allowed['visibility']=self::enum((string)$request->get_param('visibility'),self::VISIBILITIES,(string)$space->visibility);
            if($request->has_param('join_policy'))$allowed['join_policy']=self::enum((string)$request->get_param('join_policy'),self::JOIN_POLICIES,(string)$space->join_policy);
            if(isset($allowed['visibility'])&&$allowed['visibility']==='hidden')$allowed['join_policy']='invite';
            if($request->has_param('posting_policy'))$allowed['posting_policy']=self::enum((string)$request->get_param('posting_policy'),self::POSTING_POLICIES,(string)$space->posting_policy);
            if($request->has_param('history_policy'))$allowed['history_policy']=self::enum((string)$request->get_param('history_policy'),['full','from_join','limited','none'],(string)$space->history_policy);
            if($request->has_param('member_limit'))$allowed['member_limit']=self::bounded_int($request->get_param('member_limit'),2,100000,(int)$space->member_limit);
            if($request->has_param('slow_mode_seconds'))$allowed['slow_mode_seconds']=self::bounded_int($request->get_param('slow_mode_seconds'),0,86400,(int)$space->slow_mode_seconds);
            if($request->has_param('new_member_delay_seconds'))$allowed['new_member_delay_seconds']=self::bounded_int($request->get_param('new_member_delay_seconds'),0,604800,(int)$space->new_member_delay_seconds);
            foreach(['invite_pause_until','anti_raid_until','media_pause_until','call_pause_until'] as $key){if($request->has_param($key)){$dt=self::future_or_null((string)$request->get_param($key),30*DAY_IN_SECONDS);if(is_wp_error($dt)){$wpdb->query('ROLLBACK');return $dt;}$allowed[$key]=$dt;}}
            if(!$allowed){if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_read_commit_failed');return rest_ensure_response(['space'=>self::format_space($space,$actor)]);}
            $allowed['updated_at']=self::now();$allowed['version']=$expected+1;
            $changed=$wpdb->update(self::spaces_table(),$allowed,['id'=>$id,'version'=>$expected]);
            if($changed!==1)throw new RuntimeException('space_update_conflict');
            self::record($id,$actor,'space_settings_updated','space',$id,'',['fields'=>array_keys($allowed)]);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('space_update_commit_failed');
            return rest_ensure_response(['space'=>self::format_space(self::space($id),$actor)]);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_update_conflict','The space settings could not be committed safely.',409);}
    }

    public static function join_space(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $id=absint($request['id']);$user=get_current_user_id();$action=sanitize_key((string)$request->get_param('action'))?:'join';
        if(!in_array($action,['join','cancel'],true))return self::error('sn_space_join_action_invalid','Choose join or cancel.',400);
        $space=self::space($id);if(!$space||!self::can_see_existence($space,$user))return self::error('sn_space_not_found','The space is unavailable.',404);
        if($action==='cancel'){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::requests_table()." WHERE space_id=%d AND requester_id=%d AND status='pending' ORDER BY id DESC LIMIT 1",$id,$user));
            if(!$row)return self::error('sn_join_request_missing','No pending join request exists.',404);
            $changed=$wpdb->update(self::requests_table(),['status'=>'cancelled','active_key'=>null,'updated_at'=>self::now(),'version'=>(int)$row->version+1],['id'=>(int)$row->id,'status'=>'pending','version'=>(int)$row->version]);
            return $changed===1?rest_ensure_response(['status'=>'cancelled']):self::error('sn_join_request_conflict','The join request changed concurrently.',409);
        }
        if(!SN_Policy::consume_rate_limit('space_join',(string)$user,30,HOUR_IN_SECONDS))return self::error('sn_space_join_rate_limited','Too many join attempts.',429);
        if($wpdb->query('START TRANSACTION')===false)return self::error('sn_space_join_failed','The join transaction could not start safely.',500);
        try{
            $space=self::space($id,true);if(!$space)throw new RuntimeException('space_missing');
            $elig=self::join_eligibility($space,$user);if(is_wp_error($elig)){$wpdb->query('ROLLBACK');return $elig;}
            if((string)$space->join_policy==='invite'){$wpdb->query('ROLLBACK');return self::error('sn_space_invite_required','An invitation is required.',403);}
            if((string)$space->join_policy==='open'){
                self::activate_member($id,$user,'member',$user);
                $event=SN_Outbox::enqueue('space.member_joined','space',$id,['space_id'=>$id,'user_id'=>$user,'role'=>'member'],'space.member_joined:'.$id.':'.$user.':'.self::now());
                if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
                self::record($id,$user,'member_joined','user',$user,'',[]);
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('join_commit_failed');
                return rest_ensure_response(['status'=>'active']);
            }
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::requests_table()." WHERE space_id=%d AND requester_id=%d AND status='pending' LIMIT 1",$id,$user));
            if($existing){if($wpdb->query('COMMIT')===false)throw new RuntimeException('join_request_read_commit_failed');return rest_ensure_response(['status'=>'pending','request_id'=>(int)$existing->id]);}
            $now=self::now();
            if($wpdb->insert(self::requests_table(),['request_uuid'=>wp_generate_uuid4(),'space_id'=>$id,'requester_id'=>$user,'active_key'=>hash('sha256',$id.':'.$user),'status'=>'pending','reason'=>self::text((string)$request->get_param('reason'),500),'created_at'=>$now,'updated_at'=>$now])===false)throw new RuntimeException('join_request_insert_failed');
            $request_id=(int)$wpdb->insert_id;self::record($id,$user,'join_requested','join_request',$request_id,'',[]);
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('join_request_commit_failed');
            return new WP_REST_Response(['status'=>'pending','request_id'=>$request_id],202);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('sn_space_join_failed','The join action could not be completed.',500);}
    }
}
