<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

trait SN_Spaces_Part_9 {
    private static function format_space(?object $space,int $viewer): array {if(!$space)return[];$m=self::member((int)$space->id,$viewer);return['id'=>(int)$space->id,'public_id'=>(string)$space->public_id,'parent_id'=>(int)$space->parent_id,'conversation_id'=>(int)$space->conversation_id,'type'=>(string)$space->type,'subtype'=>(string)$space->subtype,'slug'=>(string)$space->slug,'name'=>(string)$space->name,'description'=>(string)$space->description,'rules'=>(string)$space->rules,'language'=>(string)$space->language,'region'=>(string)$space->region,'categories'=>json_decode((string)$space->categories,true)?:[],'visibility'=>(string)$space->visibility,'state'=>(string)$space->state,'join_policy'=>(string)$space->join_policy,'posting_policy'=>(string)$space->posting_policy,'history_policy'=>(string)$space->history_policy,'member_limit'=>(int)$space->member_limit,'member_count'=>self::member_count((int)$space->id),'slow_mode_seconds'=>(int)$space->slow_mode_seconds,'new_member_delay_seconds'=>(int)$space->new_member_delay_seconds,'invite_pause_until'=>(string)$space->invite_pause_until,'anti_raid_until'=>(string)$space->anti_raid_until,'media_pause_until'=>(string)$space->media_pause_until,'call_pause_until'=>(string)$space->call_pause_until,'version'=>(int)$space->version,'membership'=>$m?['role'=>(string)$m->role,'joined_at'=>(string)$m->joined_at]:null,'created_at'=>(string)$space->created_at,'updated_at'=>(string)$space->updated_at];}

    private static function record(int $space,int $actor,string $action,string $target_type,int $target_id,string $reason,array $scope): void {global $wpdb;$json=wp_json_encode($scope);$wpdb->insert(self::audit_table(),['space_id'=>$space,'actor_id'=>$actor,'action'=>$action,'target_type'=>$target_type,'target_id'=>$target_id,'reason'=>self::text($reason,500),'scope_hash'=>hash('sha256',is_string($json)?$json:''),'created_at'=>self::now()]);SN_DB::audit($action,'space',$space,'success',['target_type'=>$target_type,'target_id'=>$target_id,'scope_hash'=>hash('sha256',is_string($json)?$json:'')],$actor);}

    private static function unique_slug(string $base): string {global $wpdb;$base=$base?:'space';$slug=$base;for($i=2;$i<1000;$i++){if(!(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::spaces_table().' WHERE slug=%s LIMIT 1',$slug)))return $slug;$slug=$base.'-'.$i;}return $base.'-'.wp_generate_password(8,false,false);}

    private static function future_or_null(string $value,int $max): string|WP_Error|null {$value=trim($value);if($value==='')return null;$ts=strtotime($value);if(!$ts||$ts<=time()||$ts>time()+$max)return self::error('sn_space_time_invalid','Use a future time within the permitted range.',400);return gmdate('Y-m-d H:i:s',$ts);}

    private static function active_until(string $value): bool {return $value!==''&&strtotime($value.' UTC')>time();}

    private static function enum(string $value,array $allowed,string $default): string {$value=sanitize_key($value);return in_array($value,$allowed,true)?$value:$default;}

    private static function bounded_int(mixed $value,int $min,int $max,int $default): int {$v=is_numeric($value)?(int)$value:$default;return max($min,min($max,$v));}

    private static function locale(string $value): string {$value=str_replace('_','-',trim($value));return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/',$value)?$value:'en-US';}

    private static function json_list(mixed $value,int $max_items,int $max_chars): string {$items=is_array($value)?$value:[];$out=[];foreach(array_slice($items,0,$max_items) as $item){$v=self::text((string)$item,$max_chars);if($v!==''&&!in_array($v,$out,true))$out[]=$v;}return(string)wp_json_encode($out);}

    private static function text(string $value,int $max): string {return mb_substr(sanitize_textarea_field(wp_unslash($value)),0,$max);}

    private static function now(): string {return current_time('mysql',true);}

    private static function spaces_table(): string {return SN_DB::table('spaces');}

    private static function members_table(): string {return SN_DB::table('space_members');}

    private static function invites_table(): string {return SN_DB::table('space_invites');}

    private static function requests_table(): string {return SN_DB::table('space_join_requests');}

    private static function bans_table(): string {return SN_DB::table('space_bans');}

    private static function audit_table(): string {return SN_DB::table('space_governance');}

    private static function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,['status'=>$status]);}
}
