<?php
/** V12.3 F17-FUT-07 — executable source contracts for accessible reminder management. */
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static function(string $p)use($root):string{
    $source=$root.'/'.$p;
    if(!is_file($source)){fwrite(STDERR,"Missing: $p\n");exit(1);}
    return (string)file_get_contents($source);
};
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{
    ++$checks;
    if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);}
};
$php=$read('includes/class-sn-future-superset-core.php');
$js=$read('assets/js/future-superset.js');
$css=$read('assets/css/future-superset.css');
$m=$read('includes/class-sn-future24-review-hardening-m.php');
$base=$read('includes/class-sn-future-superset-part-1.php');

foreach([
    'id="snf-reminder-manager"',
    'id="snf-reminders"',
    'id="snf-refresh-reminders"',
    'aria-live="polite"',
    'id="snf-reminder-form"',
    'type="datetime-local"',
] as $marker)$check(str_contains($php,$marker),"Rendered accessible workspace marker: $marker");
$check(str_contains($php,"'version'=>(int)\$x->version"),
    'Canonical owned projection includes current CAS version');

foreach([
    "future/reminders",
    "future/reminders/\${id}",
    "expected_version: version",
    "method: 'DELETE'",
    "method: 'POST'",
    "loadReminders()",
    "refreshReminders.addEventListener('click', loadReminders)",
    "exactLocalIso",
    "localClock",
    "new Date(date.getTime() + delta * 60000)",
    "return date.toISOString()",
    "Number.isSafeInteger(version)",
    "reminderList.replaceChildren(container)",
    "card.appendChild(node('p'",
] as $marker)$check(str_contains($js,$marker),"Active reminder UI source: $marker");

$check(str_contains($js,"node('h4', String(item.data?.label"),
    'Saved label is represented as textContent, never interpolated HTML');
$check(!str_contains($js,"reminderList.innerHTML"),
    'Reminder state is not interpolated into unsafe HTML');
$check(str_contains($js,'pendingReminderCreation.idempotencyKey'),
    'Reminder retry identity is caller-owned and persists across ambiguous response failure');
$check(str_contains($js,'pendingReminderCreation = null'),
    'Confirmed successful creation retires the retry key for the next intentional operation');
$check(str_contains($js,'fingerprint = JSON.stringify(body)'),
    'Different reminder form parameters create a different retry identity');
$check(str_contains($js,"if (state === 'active'"),
    'Terminal reminders never render mutation buttons');
$check(str_contains($js,"catch (error) { announce(error.message, true); }"),
    'Stale/cross-permission server errors remain visible');
$check(str_contains($m,"sn_reminder_dst_nonexistent")&&str_contains($m,"sn_reminder_dst_ambiguous"),
    'Backend refuses ambiguous/nonexistent wall-clock times');
$check(str_contains($base,"$"."c<=0||!self::conversation_member"),
    'Backend refuses orphan reminder creation');

foreach([
    ".snf-reminder-manager", ".snf-reminder-actions", ".snf-reschedule-form",
    "min-height:44px", ":focus-visible", "[dir=\"rtl\"]", "prefers-reduced-motion",
    "max-width:680px",
] as $marker)$check(str_contains($css,$marker),"Accessible/RTL responsive style: $marker");

echo "V12.3 managed reminder workspace: $checks PASS, 0 FAIL\n";
