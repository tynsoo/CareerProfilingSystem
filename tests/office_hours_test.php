<?php

require_once __DIR__ . '/../modules/Counseling/lib/OfficeHours.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

function days(array $list, string $open = '08:00', string $close = '17:00'): array
{
    return array_map(fn($d) => ['day' => $d, 'open' => $open, 'close' => $close], $list);
}

echo "=== text: what students read ===\n";
check('Mon to Fri, 8 to 5 -> "Mon–Fri, 8:00 AM–5:00 PM"', OfficeHours::text(OfficeHours::DEFAULT_SCHEDULE) === 'Mon–Fri, 8:00 AM–5:00 PM');
check('a single day is not shown as a range', OfficeHours::text(days(['sat'], '09:00', '12:30')) === 'Sat, 9:00 AM–12:30 PM');
$mixed = array_merge(days(['mon', 'tue', 'wed', 'thu', 'fri']), days(['sat'], '08:00', '12:00'));
check('different hours are separated by "; "', OfficeHours::text($mixed) === 'Mon–Fri, 8:00 AM–5:00 PM; Sat, 8:00 AM–12:00 PM');
check('days that are not next to each other are listed apart', OfficeHours::text(days(['mon', 'wed'])) === 'Mon, 8:00 AM–5:00 PM; Wed, 8:00 AM–5:00 PM');
check('midnight and noon read correctly', OfficeHours::formatTime('00:00') === '12:00 AM' && OfficeHours::formatTime('12:00') === '12:00 PM' && OfficeHours::formatTime('13:05') === '1:05 PM');

echo "\n=== validate: prevents invalid schedules ===\n";
$v = OfficeHours::validate(days(['fri', 'mon']));
check('a valid schedule is accepted and put in Mon→Sun order', $v['error'] === null && array_column($v['schedule'], 'day') === ['mon', 'fri']);
$v = OfficeHours::validate(days(['mon'], '17:00', '08:00'));
check('closing before opening is rejected', $v['error'] !== null && strpos($v['error'], 'later than') !== false);
$v = OfficeHours::validate(days(['mon'], '09:00', '09:00'));
check('closing equal to opening is rejected', $v['error'] !== null);
$v = OfficeHours::validate([['day' => 'mon', 'open' => '8am', 'close' => '5pm']]);
check('a time that is not HH:MM is rejected', $v['error'] !== null && strpos($v['error'], 'valid') !== false);
$v = OfficeHours::validate([['day' => 'mon', 'open' => '25:00', 'close' => '26:00']]);
check('an impossible time is rejected', $v['error'] !== null);
$v = OfficeHours::validate([['day' => 'funday', 'open' => '08:00', 'close' => '17:00']]);
check('an unknown day is rejected', $v['error'] !== null);
$v = OfficeHours::validate(array_merge(days(['mon']), days(['mon'])));
check('the same day twice is rejected', $v['error'] !== null);
$v = OfficeHours::validate(days(['sun']));
check('Sunday is refused on save', $v['error'] === 'The Guidance Office is closed on Sundays.');
$v = OfficeHours::validate(days(['mon'], '07:30', '17:00'));
check('opening before 8:00 AM is refused on save', $v['error'] !== null && str_contains($v['error'], '8:00 AM and 5:00 PM'));
$v = OfficeHours::validate(days(['sat'], '08:00', '17:30'));
check('closing after 5:00 PM is refused on save', $v['error'] !== null);
$v = OfficeHours::validate(days(['mon', 'sat'], '08:00', '17:00'));
check('8:00 AM to 5:00 PM, Mon and Sat is accepted', $v['error'] === null && count($v['schedule']) === 2);
check('a schedule saved before the limits still reads (Sunday, 7 AM to 9 PM)', OfficeHours::fromJson(json_encode(days(['sun'], '07:00', '21:00'))) === days(['sun'], '07:00', '21:00'));
check('no open days is rejected', OfficeHours::validate([])['error'] !== null && OfficeHours::validate('nonsense')['error'] !== null);

echo "\n=== stored schedule ===\n";
check('nothing saved yet -> the default schedule', OfficeHours::fromJson(null) === OfficeHours::DEFAULT_SCHEDULE);
check('garbage stored -> the default schedule', OfficeHours::fromJson('not json') === OfficeHours::DEFAULT_SCHEDULE);
check('a saved schedule round-trips', OfficeHours::fromJson(json_encode(days(['tue'], '10:00', '15:00'))) === days(['tue'], '10:00', '15:00'));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
