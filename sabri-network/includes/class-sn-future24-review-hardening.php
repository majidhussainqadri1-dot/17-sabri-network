<?php
/** Fresh 40-round corrective overlays for the Founder-approved Future-24 scope. */
declare(strict_types=1);
defined('ABSPATH') || exit;

require_once SN_DIR . 'includes/class-sn-future24-review-hardening-a.php';

final class SN_Future24_Review_Hardening {
    public static function register(): void {
        SN_Future24_Review_Hardening_A::register();
    }
}
