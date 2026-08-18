<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$must = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$relationships = file_get_contents($root . '/includes/class-sn-relationships.php');
$relationshipRuntime = file_get_contents($root . '/includes/class-sn-relationship-runtime-hardening.php');
$spaces1 = file_get_contents($root . '/includes/class-sn-spaces-part-1.php');
$spaces2 = file_get_contents($root . '/includes/class-sn-spaces-part-2.php');
$spaces3 = file_get_contents($root . '/includes/class-sn-spaces-part-3.php');
$spaces4 = file_get_contents($root . '/includes/class-sn-spaces-part-4.php');
$spaces5 = file_get_contents($root . '/includes/class-sn-spaces-part-5.php');
$spaces6 = file_get_contents($root . '/includes/class-sn-spaces-part-6.php');
$spaces8 = file_get_contents($root . '/includes/class-sn-spaces-part-8.php');

$must(str_contains($relationships, 'SN_Membership_Assertions::clear_cache()') && str_contains($relationships, 'SN_Policy::access()'), 'R3 canonical pair-lock mutations must refresh File-00 assertions after serialization.');
$must(str_contains($relationshipRuntime, 'SN_Membership_Assertions::clear_cache()') && str_contains($relationshipRuntime, 'SN_Policy::access()'), 'R3 extended relationship locks must refresh File-00 assertions after serialization.');

$must(str_contains($spaces8, 'assert_manage_locked') && str_contains($spaces8, 'role_can_manage'), 'R4 must expose lock-current space-management authorization.');
$must(str_contains($spaces1, '$parent_locked = self::space($parent_id, true)') && str_contains($spaces1, 'assert_manage_locked($parent_id, $actor'), 'R4 child-space creation must revalidate parent governance under lock.');
$must(str_contains($spaces2, 'self::space($id,true)') && str_contains($spaces2, "assert_manage_locked(\$id,\$actor,'settings')"), 'R4 settings mutation must serialize version and manager authorization.');
$must(substr_count($spaces3, "assert_manage_locked(\$space_id,\$actor,'members')") >= 2, 'R4 join decisions and invitations must revalidate current manager role under lock.');
$must(str_contains($spaces4, "assert_manage_locked((int)\$invite->space_id,\$actor,'members')"), 'R4 manager invitation cancellation must revalidate under space lock.');
$must(str_contains($spaces5, "assert_manage_locked(\$space_id,\$actor,'members')") && str_contains($spaces5, "assert_manage_locked(\$space_id,\$actor,'moderation')") && str_contains($spaces5, 'FOR UPDATE'), 'R4 member and moderation changes must bind authority/hierarchy to locked state.');
$must(str_contains($spaces6, "assert_manage_locked(\$id,\$actor,'lifecycle')"), 'R4 lifecycle mutation must use current locked manager authority.');

echo "PASS: File 17 seventh-fresh R3-R4 contracts\n";
