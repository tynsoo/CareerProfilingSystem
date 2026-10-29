<?php

require_once __DIR__ . '/../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../shared/lib/Database.php';

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

$tz = new DateTimeZone('Asia/Manila');
$at = fn(string $date) => new DateTimeImmutable($date . ' 12:00:00', $tz);

echo "=== AcademicYear::current: July to June ===\n";
check('1 July starts the new year', AcademicYear::current($at('2026-07-01')) === '2026-2027');
check('30 June is still the old year', AcademicYear::current($at('2026-06-30')) === '2025-2026');
check('August 2026 -> 2026-2027', AcademicYear::current($at('2026-08-15')) === '2026-2027');
check('October 2026 -> 2026-2027', AcademicYear::current($at('2026-10-05')) === '2026-2027');
check('January 2027 -> still 2026-2027', AcademicYear::current($at('2027-01-15')) === '2026-2027');
check('June 2027 -> still 2026-2027', AcademicYear::current($at('2027-06-30')) === '2026-2027');
check('1 July 2027 -> 2027-2028', AcademicYear::current($at('2027-07-01')) === '2027-2028');
check('31 December -> same year start', AcademicYear::current($at('2026-12-31')) === '2026-2027');

echo "\n=== uses school time (Asia/Manila), not the server's clock ===\n";
// 2026-06-30 17:00 UTC is 2026-07-01 01:00 in Manila -> the new academic year already.
$utcLate = new DateTimeImmutable('2026-06-30 17:00:00', new DateTimeZone('UTC'));
check('late 30 June UTC is already 1 July in Manila', AcademicYear::current($utcLate->setTimezone($tz)) === '2026-2027');
echo "\n=== looking back at past school years ===\n";
check('the start year is read from the text', AcademicYear::startYear('2025-2026') === 2025);
check('text that is not a school year has no start year', AcademicYear::startYear('2025') === null && AcademicYear::startYear('2025-2027') === null && AcademicYear::startYear('abcd-efgh') === null);
$now = $at('2026-10-05');
check('last year is past', AcademicYear::isPast('2025-2026', $now));
check('the current year is not past', !AcademicYear::isPast('2026-2027', $now));
check('next year is not past', !AcademicYear::isPast('2027-2028', $now));

$pdoAy = Database::get();
$pdoAy->beginTransaction();
$ins = $pdoAy->prepare("INSERT INTO assessment_roster (academic_year, school_id, name_enc, strand, section) VALUES (?, ?, 'x', 'STEM', 'S1114')");
foreach (['2024-2025', '2099-2100', 'nonsense'] as $i => $y) {
    $ins->execute([$y, 'AYTEST' . $i]);
}
$years = AcademicYear::available($pdoAy, $now);
check('a past year that has data is offered', in_array('2024-2025', $years, true));
check('the current year is always offered', in_array('2026-2027', $years, true));
check('the year before the current one is offered even with no data', in_array('2025-2026', $years, true));
check('only the current and the previous year are added without data', !in_array('2023-2024', $years, true));
check('a future year is never offered', !in_array('2099-2100', $years, true));
check('text that is not a year is never offered', !in_array('nonsense', $years, true));
check('years are listed newest first', $years === array_values(array_unique(array_merge(array_filter($years, fn($y) => true)))) && $years === (function ($a) { rsort($a); return $a; })($years));
check('asking for an available past year gives that year', AcademicYear::resolve($pdoAy, '2024-2025', $now) === '2024-2025');
check('asking for a future year falls back to the current year', AcademicYear::resolve($pdoAy, '2099-2100', $now) === '2026-2027');
check('asking for nothing gives the current year', AcademicYear::resolve($pdoAy, '', $now) === '2026-2027');
$pdoAy->rollBack();

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
