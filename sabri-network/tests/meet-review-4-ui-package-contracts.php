<?php
/** Sabri Meet review 4: UI truthfulness, accessibility and package integration. */
$root = dirname(__DIR__);
$meet = file_get_contents($root . '/includes/class-sn-meet.php');
$js = file_get_contents($root . '/assets/js/meet.js');
$css = file_get_contents($root . '/assets/css/meet.css');
$template = file_get_contents($root . '/templates/meet-app.php');
$main = file_get_contents($root . '/sabri-network.php');
$package = file_get_contents($root . '/tools/package.sh');
$readme = file_get_contents($root . '/README.md');
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$check(str_contains($meet, "home_url('/calls/')") && str_contains($meet, "'/calls/' . rawurlencode"), 'Sabri Meet must own dashboard and opaque meeting deep links.');
$check(str_contains($meet, "assets/js/meet.js") && str_contains($meet, "assets/css/meet.css"), 'Meet assets must load only through the File-17 runtime.');
$check(str_contains($package, 'for file in network.js meet.js messages.js message-search.js smail.js file-transfer.js two-plan-ui.js future-superset.js') && str_contains($package, 'node --check "$STAGE/sabri-network/assets/js/$file"'), 'Packaged Meet JavaScript must receive syntax validation through the complete staged-JS loop.');
$check(str_contains($template, 'Sabri Meet') && str_contains($template, 'sn-meet-controls'), 'Dedicated Sabri Meet user experience must exist.');
$check(str_contains($template, 'Camera and microphone access begins only after you choose a control.'), 'Prejoin UI must disclose permission timing truthfully.');
$check(strpos($js, "getUserMedia") > strpos($js, "addEventListener('click', toggleMic)"), 'Camera/microphone access must follow an explicit user action.');
$check(str_contains($js, 'window.SabriMeetProvider.connect'), 'Provider connection must use an explicit adapter boundary.');
$check(str_contains($js, 'providerUnavailable'), 'Unavailable conference transport must be disclosed instead of faked.');
$check(str_contains($js, 'hadTrack ? !tracks.some') && substr_count($js, 'hadTrack ? !tracks.some') === 2, 'First microphone/camera activation must remain enabled after permission.');
$check(str_contains($css, ':focus-visible') && str_contains($css, 'outline: 3px'), 'Keyboard focus must remain visible.');
$check(str_contains($css, 'min-height: 44px'), 'Interactive targets must meet the mobile target baseline.');
$check(str_contains($css, '@media (max-width: 900px)') && str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'Responsive and reduced-motion modes must be present.');
$check(substr_count($css, '{') === substr_count($css, '}'), 'Meet CSS braces must be balanced.');
$check(str_contains($meet, "'owner' => 'file-17'") && !str_contains($main, 'wp_nav_menu_items'), 'Meet must publish a route contract without injecting duplicate global navigation.');
$check(str_contains($readme, 'Sabri Meet'), 'Public package documentation must describe Sabri Meet truthfully.');
$check(str_contains($readme, 'provider-gated') || str_contains($readme, 'provider'), 'Documentation must preserve the external media-provider limitation.');
$check(!str_contains($template, 'fake') && !str_contains($js, 'demo participant'), 'Production Meet UI must not fabricate participants or connectivity.');
$check(str_contains($template, 'id="sn-meet-hand"') && str_contains($js, 'toggleHand'), 'Sabri Meet must provide an accessible raise/lower-hand control.');
$check(str_contains($template, 'id="sn-meet-invite-form"') && str_contains($js, 'inviteMember'), 'Hosts must have a real invitation workflow in the Meet interface.');
$check(str_contains($meet, "'chat_url'") && str_contains($template, 'id="sn-meet-chat"'), 'Meetings linked to a File-17 conversation must expose canonical meeting chat.');
$check(str_contains($js, "participant.media?.hand") && str_contains($js, "Lower hand"), 'Participant roster must surface raised-hand state and host lowering controls.');
$check(str_contains($meet, "'partial' => \$failed > 0") && str_contains($meet, "\$failed > 0 ? 207 : 200"), 'Invitation write failures must be reported as partial failure rather than hidden as policy skips.');
if ($failures) {
    fwrite(STDERR, "Sabri Meet review 4 failures (" . count($failures) . "/$checks):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Sabri Meet review 4 UI/package contracts: PASS ($checks checks)\n";
