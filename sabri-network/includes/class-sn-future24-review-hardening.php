<?php
/** Fresh 40-round corrective overlays for the Founder-approved Future-24 scope. */
declare(strict_types=1);
defined('ABSPATH') || exit;

require_once SN_DIR . 'includes/class-sn-future24-review-hardening-a.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-b.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-c.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-d.php';
require_once SN_DIR . 'includes/class-sn-future24-review-hardening-e.php';

final class SN_Future24_Review_Hardening {
    public static function register(): void {
        SN_Future24_Review_Hardening_A::register();
        SN_Future24_Review_Hardening_B::register();
        SN_Future24_Review_Hardening_C::register();
        SN_Future24_Review_Hardening_D::register();
        SN_Future24_Review_Hardening_E::register();
    }
}
