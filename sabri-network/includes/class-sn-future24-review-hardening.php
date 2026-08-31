<?php
/** Fresh corrective overlays for the Founder-approved Future-24 and current File-17 boundary scope. */
declare(strict_types=1);
defined('ABSPATH') || exit;

require_once SN_DIR . 'includes/class-sn-future24-review-hardening-a.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-b.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-c.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-d.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-e.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-f.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-g.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-h.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-i.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-j.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-k.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-l.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-m.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-n.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-o.php';
require_once SN_DIR . 'includes/class-sn-runtime-boundary-policy.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-review-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-search-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-media-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-lifecycle-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-space-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-realtime-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-call-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-smail-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-transfer-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-privacy-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-safety-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-crypto-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-knowledge-hardening.php';
require_once SN_DIR . 'includes/class-sn-fourth-fresh-interop-hardening.php';
require_once SN_DIR . 'includes/class-sn-round20-correction.php';
require_once SN_DIR . 'includes/class-sn-fifth-fresh-privacy-hardening.php';
require_once SN_DIR . 'includes/class-sn-fifth-fresh-integration-hardening.php';
require_once SN_DIR . 'includes/class-sn-fifth-fresh-feature-hardening.php';
require_once SN_DIR . 'includes/class-sn-fifth-fresh-knowledge-hardening.php';
require_once SN_DIR . 'includes/class-sn-fifth-fresh-migration-hardening.php';
require_once SN_DIR . 'includes/class-sn-fifth-fresh-ui-hardening.php';
require_once SN_DIR . 'includes/class-sn-sixth-fresh-privacy-hardening.php';
require_once SN_DIR . 'includes/class-sn-seventh-fresh-r13-hardening.php';
require_once SN_DIR . 'includes/class-sn-seventh-fresh-r14-hardening.php';
require_once SN_DIR . 'includes/class-sn-seventh-fresh-r15-privacy-hardening.php';

final class SN_Future24_Review_Hardening {
    private const REQUEST_COOLDOWN_DAYS = 30;

    public static function register(): void {
        SN_Runtime_Boundary_Policy::register();
        SN_Fourth_Fresh_Review_Hardening::register();
        SN_Fourth_Fresh_Search_Hardening::register();
        SN_Fourth_Fresh_Media_Hardening::register();
        SN_Fourth_Fresh_Lifecycle_Hardening::register();
        SN_Fourth_Fresh_Space_Hardening::register();
        SN_Fourth_Fresh_Realtime_Hardening::register();
        SN_Fourth_Fresh_Call_Hardening::register();
        SN_Fourth_Fresh_Smail_Hardening::register();
        SN_Fourth_Fresh_Transfer_Hardening::register();
        SN_Fourth_Fresh_Privacy_Hardening::register();
        SN_Fourth_Fresh_Safety_Hardening::register();
        SN_Fourth_Fresh_Crypto_Hardening::register();
        SN_Fourth_Fresh_Knowledge_Hardening::register();
        SN_Fourth_Fresh_Interop_Hardening::register();
        SN_Future24_Review_Hardening_A::register();
        SN_Future24_Review_Hardening_B::register();
        SN_Future24_Review_Hardening_C::register();
        SN_Future24_Review_Hardening_D::register();
        SN_Future24_Review_Hardening_E::register();
        SN_Future24_Review_Hardening_F::register();
        SN_Future24_Review_Hardening_G::register();
        SN_Future24_Review_Hardening_H::register();
        SN_Future24_Review_Hardening_I::register();
        SN_Future24_Review_Hardening_J::register();
        SN_Future24_Review_Hardening_K::register();
        SN_Future24_Review_Hardening_L::register();
        SN_Future24_Review_Hardening_M::register();
        SN_Future24_Review_Hardening_N::register();
        SN_Future24_Review_Hardening_O::register();
        SN_Round20_Correction::register();
        SN_Fifth_Fresh_Privacy_Hardening::register();
        SN_Fifth_Fresh_Integration_Hardening::register();
        SN_Fifth_Fresh_Feature_Hardening::register();
        SN_Fifth_Fresh_Knowledge_Hardening::register();
        SN_Fifth_Fresh_Migration_Hardening::register();
        SN_Fifth_Fresh_UI_Hardening::register();
        SN_Sixth_Fresh_Privacy_Hardening::register();
        SN_Seventh_Fresh_R13_Hardening::register();
        SN_Seventh_Fresh_R14_Hardening::register();
        SN_Seventh_Fresh_R15_Privacy_Hardening::register();
        add_action('rest_api_init', [self::class, 'final_route_composition'], 4000);
    }

    /** Final route composition prevents later single-method hardening from erasing sibling methods. */
    public static function final_route_composition(): void {
        $access = [SN_REST::class, 'access'];
        register_rest_route('sabri-network/v2', '/messages/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [SN_Fourth_Fresh_Review_Hardening::class, 'edit_message'], 'permission_callback' => $access],
            ['methods' => 'DELETE', 'callback' => [SN_Round20_Correction::class, 'delete_message'], 'permission_callback' => $access],
        ], true);
        register_rest_route('sabri-network/v2', '/message-requests/(?P<id>\d+)', [
            'methods' => 'POST', 'callback' => [self::class, 'decide_message_request_atomically'], 'permission_callback' => $access,
        ], true);
        register_rest_route('sabri-network/v2', '/spaces/(?P<id>\d+)/community-artifacts', [
            ['methods'=>'GET','callback'=>[SN_Two_Plan_Completion::class,'list_community_artifacts'],'permission_callback'=>$access],
            ['methods'=>'POST','callback'=>[self::class,'create_community_artifact_atomically'],'permission_callback'=>$access],
        ], true);
        register_rest_route('sabri-network/v2', '/future/device-keys', [
            ['methods' => 'GET', 'callback' => [SN_Future_Superset::class, 'list_device_keys'], 'permission_callback' => $access],
            ['methods' => 'POST', 'callback' => [SN_Future24_Review_Hardening_J::class, 'register_device_key'], 'permission_callback' => $access],
        ], true);
        register_rest_route('sabri-network/v2', '/future/mentorships', [
            ['methods' => 'GET', 'callback' => [SN_Future_Superset::class, 'list_mentorships'], 'permission_callback' => $access],
            ['methods' => 'POST', 'callback' => [SN_Future24_Review_Hardening_B::class, 'create_mentorship'], 'permission_callback' => $access],
        ], true);
        register_rest_route('sabri-network/v2', '/future/reminders', [
            ['methods' => 'GET', 'callback' => [SN_Future_Superset::class, 'list_reminders'], 'permission_callback' => $access],
            ['methods' => 'POST', 'callback' => [SN_Future24_Review_Hardening_M::class, 'create_reminder'], 'permission_callback' => $access],
        ], true);
    }

    /** Accept already has its own canonical transaction; other decisions couple state + event atomically. */
    public static function decide_message_request_atomically(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $actor = get_current_user_id(); $id = absint($request['id']); $action = sanitize_key((string)$request->get_param('action'));
        if ($action === 'accept') return SN_Two_Plan_Completion::decide_message_request($request);
        if (!in_array($action, ['decline','report','cancel'], true)) return self::error('sn_message_request_action_invalid','Choose accept, decline, report, or cancel.',400);
        $table = SN_DB::table('message_requests');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$row || (string)$row->status !== 'pending') return self::not_found();
        $recipient = (int)$row->recipient_id; $requester = (int)$row->requester_id;
        if (($action === 'cancel' && $requester !== $actor) || ($action !== 'cancel' && $recipient !== $actor)) return self::not_found();
        if (SN_DB::is_blocked($requester, $recipient)) return self::error('sn_message_request_blocked','The request is no longer actionable.',403);
        if (!SN_Policy::consume_rate_limit('message_request_decision',(string)$actor,60,HOUR_IN_SECONDS)) return self::error('sn_message_request_decision_rate_limited','Too many request decisions were submitted.',429);

        $report_id = 0;
        if ($action === 'report') {
            $report = new WP_REST_Request('POST','/sabri-network/v2/report');
            $report->set_param('client_id', self::deterministic_uuid('message-request-report|'.$id.'|'.(int)$row->version.'|'.$actor));
            $report->set_param('reported_user_id', $requester);
            $report->set_param('category', sanitize_key((string)$request->get_param('category')) ?: 'spam');
            $report->set_param('details', mb_substr(sanitize_textarea_field((string)$request->get_param('details')),0,4000));
            $report->set_param('evidence', ['message_request_id'=>$id,'request_hash'=>hash('sha256',(string)$row->body_cipher)]);
            $reported = SN_Seventh_Fresh_R14_Hardening::report($report);
            if (is_wp_error($reported)) return $reported;
            $reported_data = $reported->get_data(); $report_id = absint($reported_data['id'] ?? 0);
            if ($report_id <= 0) return self::error('sn_message_request_report_unconfirmed','The safety report could not be confirmed.',503);
        }

        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_message_request_transaction_failed','The request decision could not start safely.',503);
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id));
            if (!$locked || (string)$locked->status !== 'pending' || (int)$locked->version !== (int)$row->version) throw new DomainException('state_changed');
            if (($action === 'cancel' && (int)$locked->requester_id !== $actor) || ($action !== 'cancel' && (int)$locked->recipient_id !== $actor)) throw new DomainException('not_found');
            if (SN_DB::is_blocked((int)$locked->requester_id,(int)$locked->recipient_id)) throw new DomainException('blocked');
            $now = current_time('mysql', true);
            $status = $action === 'report' ? 'reported' : ($action === 'cancel' ? 'cancelled' : 'declined');
            $cooldown = in_array($status,['declined','reported'],true) ? gmdate('Y-m-d H:i:s', time()+self::REQUEST_COOLDOWN_DAYS*DAY_IN_SECONDS) : null;
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status=%s,report_id=%d,cooldown_until=%s,decided_at=%s,updated_at=%s,version=version+1 WHERE id=%d AND status='pending' AND version=%d",
                $status,$report_id,$cooldown,$now,$now,$id,(int)$locked->version
            ));
            if ($changed !== 1) throw new RuntimeException('message_request_state_failed');
            $event = SN_Outbox::enqueue('message_request.'.$status,'message_request',$id,[
                'request_id'=>$id,'requester_id'=>(int)$locked->requester_id,'recipient_id'=>(int)$locked->recipient_id,'report_id'=>$report_id,
            ],'message_request.'.$status.':'.$id.':'.((int)$locked->version+1));
            if (is_wp_error($event)) throw new RuntimeException($event->get_error_code());
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('message_request_commit_failed');
            SN_DB::audit('message_request_'.$status,'message_request',$id,'success',['report_id'=>$report_id],$actor);
            do_action('sn_network_event_queued',$event,'message_request.'.$status);
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
            return rest_ensure_response(['request'=>self::format_message_request($fresh,$actor)]);
        } catch (DomainException $e) {
            $wpdb->query('ROLLBACK');
            if ($e->getMessage() === 'not_found') return self::not_found();
            if ($e->getMessage() === 'blocked') return self::error('sn_message_request_blocked','The request is no longer actionable.',403);
            return self::error('sn_message_request_conflict','The request changed before the decision was saved.',409);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('message_request_atomic_decision_failed','message_request',$id,'failure',['reason'=>$e->getMessage()],$actor);
            return self::error('sn_message_request_failed','The request decision could not be committed with its delivery event.',503);
        }
    }

    /** The community object and its durable event are one commit, never a best-effort pair. */
    public static function create_community_artifact_atomically(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) return self::error('sn_community_artifact_transaction_failed','The community item transaction could not start.',503);
        try {
            $response = SN_Two_Plan_Completion::create_community_artifact($request);
            if (is_wp_error($response)) { $wpdb->query('ROLLBACK'); return $response; }
            $data = $response->get_data(); $id = absint($data['artifact']['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('community_artifact_id_missing');
            $event_key = hash('sha256', 'community_artifact.created|community_artifact.created:'.$id);
            $event_id = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.SN_DB::table('event_outbox').' WHERE event_key=%s LIMIT 1', $event_key));
            if ($event_id <= 0) throw new RuntimeException('community_artifact_event_missing');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('community_artifact_commit_failed');
            return $response;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('community_artifact_atomic_create_failed','space',absint($request['id']),'failure',['reason'=>$e->getMessage()],get_current_user_id());
            return self::error('sn_community_artifact_failed','The community item could not be committed with its delivery event.',503);
        }
    }

    private static function format_message_request(?object $row, int $viewer): array {
        if (!$row) return [];
        $authorized = in_array($viewer,[(int)$row->requester_id,(int)$row->recipient_id],true);
        $plain = $authorized && (string)$row->body_cipher !== ''
            ? SN_Communication_Crypto::decrypt((string)$row->body_cipher,'message-request|'.(int)$row->requester_id.'|'.(int)$row->recipient_id)
            : '';
        return [
            'id'=>(int)$row->id,'requester'=>SN_Auth::public_user((int)$row->requester_id),'recipient'=>SN_Auth::public_user((int)$row->recipient_id),
            'message'=>$authorized?(is_wp_error($plain)?'':(string)$plain):'','message_unavailable'=>is_wp_error($plain),'reason'=>$authorized?(string)$row->reason:'',
            'status'=>(string)$row->status,'version'=>(int)$row->version,'conversation_id'=>(int)$row->conversation_id,
            'report_id'=>$viewer===(int)$row->recipient_id?(int)$row->report_id:0,'cooldown_until'=>(string)$row->cooldown_until,'created_at'=>(string)$row->created_at,'updated_at'=>(string)$row->updated_at,
        ];
    }

    private static function deterministic_uuid(string $seed): string {
        $hex = hash('sha256', $seed);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);
    }

    private static function not_found(): WP_Error { return self::error('sn_not_found','The requested communication object is unavailable.',404); }
    private static function error(string $code,string $message,int $status): WP_Error { return new WP_Error($code,$message,['status'=>$status]); }
}
