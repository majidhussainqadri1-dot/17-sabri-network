<?php
/** Review rounds 20+ — canonical citation and clinical-discussion owner-boundary enforcement. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Future24_Review_Hardening_C {
    public static function register(): void { add_action('rest_api_init',[self::class,'routes'],1975); }
    public static function routes(): void {
        register_rest_route('sabri-network/v2','/future/citations',['methods'=>'POST','callback'=>[self::class,'create_citation_card'],'permission_callback'=>[SN_REST::class,'access']],true);
    }
    public static function create_citation_card(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $actor=get_current_user_id();$conversation=absint($r->get_param('conversation_id'));if($conversation<=0||!SN_DB::is_member($conversation,$actor))return self::not_found();
        $owner=sanitize_key((string)$r->get_param('source_owner'));$canonical_id=mb_substr(sanitize_text_field((string)$r->get_param('canonical_id')),0,191);if(!in_array($owner,['file-06','file-12'],true)||$canonical_id==='')return self::error('sn_citation_source_invalid','Select a canonical File 06 or File 12 source.',400);
        if(!has_filter('sn_network_citation_resolve'))return self::error('sn_citation_provider_unavailable','The canonical source owner is temporarily unavailable.',503);
        $resolved=apply_filters('sn_network_citation_resolve',null,$owner,$canonical_id,$actor,$conversation);
        if(!is_array($resolved)||empty($resolved['exists'])||empty($resolved['current'])||empty($resolved['allowed']))return self::error('sn_citation_not_available','The canonical source is unavailable or not currently accessible.',404);
        $url=esc_url_raw((string)($resolved['canonical_url']??''));if($url===''||!self::same_site($url))return self::error('sn_citation_contract_invalid','The canonical owner returned an invalid citation URL.',503);
        $r->set_param('canonical_id',mb_substr(sanitize_text_field((string)($resolved['canonical_id']??$canonical_id)),0,191));$r->set_param('canonical_url',$url);$r->set_param('title',mb_substr(sanitize_text_field((string)($resolved['title']??'')),0,300));$r->set_param('locator',mb_substr(sanitize_text_field((string)($resolved['locator']??$r->get_param('locator'))),0,120));
        $response=SN_Future_Superset::create_citation_card($r);if(!is_wp_error($response))SN_DB::audit('future_citation_owner_resolved','conversation',$conversation,'success',['source_owner'=>$owner,'canonical_id'=>$canonical_id],$actor);return $response;
    }
    private static function same_site(string $url):bool{$home=wp_parse_url(home_url('/'));$target=wp_parse_url($url);return is_array($home)&&is_array($target)&&strtolower((string)($home['host']??''))===strtolower((string)($target['host']??''));}
    private static function not_found():WP_Error{return self::error('not_found','Requested communication object is unavailable.',404);}private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error($c,$m,['status'=>$s]);}
}
