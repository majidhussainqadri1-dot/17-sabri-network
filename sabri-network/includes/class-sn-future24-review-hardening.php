<?php
/** Fresh 40-round corrective overlays for the Founder-approved Future-24 scope. */
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

final class SN_Future24_Review_Hardening {
    public static function register(): void {
        // Register the common File-17 boundary first so every later Future-24
        // pre-dispatch reservation/lock/provider hook executes behind current
        // File-00 access, object membership and storage/search epoch controls.
        SN_Runtime_Boundary_Policy::register();
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
    }
}