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
}
