<?php

require_once __DIR__ . '/../modules/CareerProfiling/lib/ExamSchedule.php';

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

echo "=== timesOverlap ===\n";
check('partial overlap', ExamSchedule::timesOverlap('08:00', '10:00', '09:00', '11:00'));
check('one inside the other', ExamSchedule::timesOverlap('08:00', '12:00', '09:00', '10:00'));
check('identical ranges', ExamSchedule::timesOverlap('08:00', '09:00', '08:00', '09:00'));
check('back-to-back sessions do NOT overlap', !ExamSchedule::timesOverlap('08:00', '09:00', '09:00', '10:00'));
check('separate sessions do not overlap', !ExamSchedule::timesOverlap('08:00', '09:00', '13:00', '14:00'));
check('H:i:s values work too', ExamSchedule::timesOverlap('08:00:00', '10:00:00', '09:30:00', '11:00:00'));

echo "\n=== roomKey ===\n";
check('case and spaces are ignored', ExamSchedule::roomKey('  Room   301 ') === ExamSchedule::roomKey('room 301'));
check('different rooms differ', ExamSchedule::roomKey('Room 301') !== ExamSchedule::roomKey('Room 302'));

echo "\n=== findConflicts ===\n";
$s = fn($id, $date, $start, $end, $room) => ['id' => $id, 'examDate' => $date, 'startTime' => $start, 'endTime' => $end, 'room' => $room];
$none = ExamSchedule::findConflicts([
    $s(1, '2026-10-20', '08:00', '09:00', 'R310'),
    $s(2, '2026-10-20', '09:00', '10:00', 'R310'),   // back to back, fine
    $s(3, '2026-10-20', '08:00', '09:00', 'R311'),   // other room, fine
    $s(4, '2026-10-21', '08:00', '09:00', 'R310'),   // other day, fine
]);
check('no conflicts among compatible sessions', $none === []);

$clash = ExamSchedule::findConflicts([
    $s(1, '2026-10-20', '08:00', '10:00', 'R310'),
    $s(2, '2026-10-20', '09:30', '11:00', ' r310 '),
    $s(3, '2026-10-20', '13:00', '14:00', 'R310'),
]);
check('one clash found (same room, same day, overlapping, spelled differently)', count($clash) === 1);
check('it names both sessions', $clash[0]['a']['id'] === 1 && $clash[0]['b']['id'] === 2);

echo "\n=== make-up sessions in the CGC Office ===\n";
check('CGC Office is recognized however it is typed', ExamSchedule::isCgcOffice(' cgc   OFFICE ') && ExamSchedule::isCgcOffice(ExamSchedule::CGC_OFFICE));
check('another room is not the CGC Office', !ExamSchedule::isCgcOffice('Room 301') && !ExamSchedule::isCgcOffice('CGC'));
check('two make-ups may share the CGC Office', ExamSchedule::mayShareRoom('makeup', 'makeup', 'CGC Office'));
check('a make-up may not share the CGC Office with a group session', !ExamSchedule::mayShareRoom('makeup', 'initial', 'CGC Office') && !ExamSchedule::mayShareRoom('initial', 'makeup', 'CGC Office'));
check('two group sessions may not share the CGC Office', !ExamSchedule::mayShareRoom('initial', 'initial', 'CGC Office'));
check('two make-ups may not share any other room', !ExamSchedule::mayShareRoom('makeup', 'makeup', 'Room 301'));
$m = fn($id, $start, $end, $room, $type) => $s($id, '2026-10-20', $start, $end, $room) + ['scheduleType' => $type];
check('overlapping make-ups in the CGC Office are not a conflict', ExamSchedule::findConflicts([
    $m(1, '09:00', '10:00', 'CGC Office', 'makeup'),
    $m(2, '09:30', '10:30', 'cgc office', 'makeup'),
]) === []);
$mixed = ExamSchedule::findConflicts([
    $m(1, '09:00', '10:00', 'CGC Office', 'makeup'),
    $m(2, '09:30', '10:30', 'CGC Office', 'initial'),
]);
check('a make-up overlapping a group session in the CGC Office is a conflict', count($mixed) === 1);
check('overlapping make-ups in another room are a conflict', count(ExamSchedule::findConflicts([
    $m(1, '09:00', '10:00', 'Room 301', 'makeup'),
    $m(2, '09:30', '10:30', 'Room 301', 'makeup'),
])) === 1);
check('sessions without a type are treated as group sessions (still a conflict)', count(ExamSchedule::findConflicts([
    $s(1, '2026-10-20', '09:00', '10:00', 'CGC Office'),
    $s(2, '2026-10-20', '09:30', '10:30', 'CGC Office'),
])) === 1);

echo "\n=== activation window: when the assessment is open for a group ===\n";
$tzM = new DateTimeZone('Asia/Manila');
$at = fn(string $s) => new DateTimeImmutable($s, $tzM);
$session = ['examDate' => '2026-10-12', 'startTime' => '08:00:00', 'endTime' => '10:00:00', 'room' => '301'];
check('before the start time it is not open', ExamSchedule::isOpen('2026-10-12', '08:00', '10:00', $at('2026-10-12 07:59:00')) === false);
check('at the start time it opens', ExamSchedule::isOpen('2026-10-12', '08:00', '10:00', $at('2026-10-12 08:00:00')) === true);
check('in the middle it is open', ExamSchedule::isOpen('2026-10-12', '08:00', '10:00', $at('2026-10-12 09:15:00')) === true);
check('at the end time it is closed', ExamSchedule::isOpen('2026-10-12', '08:00', '10:00', $at('2026-10-12 10:00:00')) === false);
check('the next day it is closed', ExamSchedule::isOpen('2026-10-12', '08:00', '10:00', $at('2026-10-13 08:30:00')) === false);
check('nothing scheduled -> none', ExamSchedule::windowFor([], $at('2026-10-12 09:00:00'))['state'] === 'none');
check('inside a session -> open, with its room', (function () use ($session, $at) { $w = ExamSchedule::windowFor([$session], $at('2026-10-12 09:00:00')); return $w['state'] === 'open' && $w['room'] === '301' && $w['endTime'] === '10:00'; })());
check('a session later today -> upcoming, with its start', (function () use ($session, $at) { $w = ExamSchedule::windowFor([$session], $at('2026-10-12 06:00:00')); return $w['state'] === 'upcoming' && $w['startTime'] === '08:00' && $w['examDate'] === '2026-10-12'; })());
check('only past sessions -> ended', ExamSchedule::windowFor([$session], $at('2026-10-12 11:00:00'))['state'] === 'ended');
check('several sessions: the soonest upcoming one is reported', (function () use ($session, $at) { $later = ['examDate' => '2026-10-14', 'startTime' => '13:00', 'endTime' => '15:00']; $w = ExamSchedule::windowFor([$later, $session], $at('2026-10-11 09:00:00')); return $w['state'] === 'upcoming' && $w['examDate'] === '2026-10-12'; })());
check('a past session plus a future one -> upcoming, not ended', ExamSchedule::windowFor([$session, ['examDate' => '2026-10-20', 'startTime' => '08:00', 'endTime' => '10:00']], $at('2026-10-13 09:00:00'))['state'] === 'upcoming');
echo "\n=== times are written 12-hour, never 24-hour ===\n";
check('morning: 08:30 -> 8:30 AM', ExamSchedule::formatTime('08:30') === '8:30 AM');
check('afternoon: 13:45 -> 1:45 PM', ExamSchedule::formatTime('13:45') === '1:45 PM');
check('noon: 12:00 -> 12:00 NN', ExamSchedule::formatTime('12:00') === '12:00 NN');
check('just after noon is PM, not NN: 12:05 -> 12:05 PM', ExamSchedule::formatTime('12:05') === '12:05 PM');
check('midnight: 00:00 -> 12:00 AM and 00:15 -> 12:15 AM', ExamSchedule::formatTime('00:00') === '12:00 AM' && ExamSchedule::formatTime('00:15') === '12:15 AM');
check('late evening: 23:59 -> 11:59 PM', ExamSchedule::formatTime('23:59') === '11:59 PM');
check('seconds from the database are ignored: 09:00:00 -> 9:00 AM', ExamSchedule::formatTime('09:00:00') === '9:00 AM');
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
