<?php

/**
 * When an exam schedule counts as over. An exam is over once its end time on
 * its exam date has passed (school time, Asia/Manila — the same clock the rest
 * of the app uses, see config/env.php), not merely when the date has passed.
 *
 * Over exams are "archived": they leave the active list and their access code
 * stops working (api/verify-access-code.php). The row itself is never deleted,
 * since audit logs and historical reports still refer to it.
 */
class ExamSchedule
{
    /** The Guidance Office room: make-up sessions there may overlap each other (walk-ins), see mayShareRoom(). */
    public const CGC_OFFICE = 'CGC Office';

    private static ?bool $makeupAvailable = null;

    /** True once db/migrate_add_makeup_sessions.php has run (the list of a make-up session's students exists). */
    public static function makeupAvailable(PDO $pdo): bool
    {
        if (self::$makeupAvailable === null) {
            self::$makeupAvailable = $pdo->query("SELECT to_regclass('exam_schedule_students') IS NOT NULL")->fetchColumn() === true;
        }
        return self::$makeupAvailable;
    }

    /**
     * SQL condition (no placeholders): exam session $es is for student $s (a joined students row). A group session
     * matches on school year, grade level, strand and section (an empty column means everyone). A make-up session
     * (schedule_type 'makeup', group columns empty) matches ONLY the students listed for it in exam_schedule_students,
     * never the group rule, so it can never open for everyone. Before the migration, make-ups match nobody.
     * Every query that matches sessions to a student goes through here.
     */
    public static function matchSql(PDO $pdo, string $es = 'es', string $s = 's'): string
    {
        if (!preg_match('/^[a-z_]+$/', $es) || !preg_match('/^[a-z_]+$/', $s)) {
            throw new InvalidArgumentException('Bad table alias');
        }
        $group = "($es.schedule_type <> 'makeup' AND $es.academic_year = $s.academic_year"
            . " AND ($es.grade_level IS NULL OR $es.grade_level = $s.grade_level)"
            . " AND ($es.strand IS NULL OR $es.strand = $s.strand)"
            . " AND ($es.section IS NULL OR $es.section = $s.section))";
        if (!self::makeupAvailable($pdo)) {
            return $group;
        }
        return "($group OR ($es.schedule_type = 'makeup' AND $es.academic_year = $s.academic_year AND EXISTS ("
            . "SELECT 1 FROM exam_schedule_students ess WHERE ess.schedule_id = $es.id AND ess.student_id = $s.user_id)))";
    }

    /** The CGC Office, however it is typed ("cgc office", " CGC  Office "). */
    public static function isCgcOffice(string $room): bool
    {
        return self::roomKey($room) === self::roomKey(self::CGC_OFFICE);
    }

    /**
     * Two sessions booked in the same room at overlapping times are allowed only when both are make-up sessions in the
     * CGC Office (the office takes walk-ins). A make-up there still cannot overlap a regular group session.
     */
    public static function mayShareRoom(string $typeA, string $typeB, string $room): bool
    {
        return $typeA === 'makeup' && $typeB === 'makeup' && self::isCgcOffice($room);
    }

    /**
     * SQL condition (no placeholders) true for schedules whose exam has ended.
     * Assumes the exam_schedules columns are unqualified; pass an alias to qualify them.
     */
    public static function endedSql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "(({$p}exam_date + {$p}end_time) < (NOW() AT TIME ZONE 'Asia/Manila'))";
    }

    /** Rooms are compared ignoring case and surrounding spaces ("Room 301" = " room 301 "). */
    public static function roomKey(string $room): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $room)));
    }

    /** Two time ranges on the same day overlap when each starts before the other ends (touching ends don't count). */
    public static function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $a1 = substr($startA, 0, 5); $a2 = substr($endA, 0, 5);
        $b1 = substr($startB, 0, 5); $b2 = substr($endB, 0, 5);
        return $a1 < $b2 && $b1 < $a2;
    }

    /**
     * Pairs of sessions booked into the same room at overlapping times on the same date (make-ups sharing the CGC
     * Office are not a conflict, see mayShareRoom()).
     *
     * @param array<int,array{id:int,examDate:string,startTime:string,endTime:string,room:string,scheduleType?:string}> $sessions
     * @return array<int,array{room:string,examDate:string,a:array,b:array}>
     */
    public static function findConflicts(array $sessions): array
    {
        $conflicts = [];
        $n = count($sessions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $sessions[$i];
                $b = $sessions[$j];
                if ($a['examDate'] === $b['examDate']
                    && self::roomKey($a['room']) === self::roomKey($b['room'])
                    && self::timesOverlap($a['startTime'], $a['endTime'], $b['startTime'], $b['endTime'])
                    && !self::mayShareRoom($a['scheduleType'] ?? '', $b['scheduleType'] ?? '', $a['room'])) {
                    $conflicts[] = ['room' => $a['room'], 'examDate' => $a['examDate'], 'a' => $a, 'b' => $b];
                }
            }
        }
        return $conflicts;
    }

    /** "13:30" -> "1:30 PM", "12:00" -> "12:00 NN" (noon), "00:15" -> "12:15 AM". Never 24-hour time. */
    public static function formatTime(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        if ($h === 12 && $m === 0) {
            return '12:00 NN';
        }
        return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m, $h >= 12 ? 'PM' : 'AM');
    }

    /** @param string $examDate Y-m-d  @param string $endTime H:i or H:i:s */
    public static function hasEnded(string $examDate, string $endTime, ?DateTimeImmutable $now = null): bool
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $end = new DateTimeImmutable($examDate . ' ' . substr($endTime, 0, 8), $tz);
        return $end < $now;
    }

    /** True from a session's start time until its end time on its date (school time, Asia/Manila). */
    public static function isOpen(string $examDate, string $startTime, string $endTime, ?DateTimeImmutable $now = null): bool
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $start = new DateTimeImmutable($examDate . ' ' . substr($startTime, 0, 8), $tz);
        $end = new DateTimeImmutable($examDate . ' ' . substr($endTime, 0, 8), $tz);
        return $start <= $now && $now < $end;
    }

    /**
     * Where a student's group stands: the assessment is "activated" only while one of its
     * scheduled sessions is open. 'open' (now), 'upcoming' (next one not started),
     * 'ended' (all over) or 'none' (nothing scheduled).
     *
     * @param array<int,array{examDate:string,startTime:string,endTime:string,room?:string}> $sessions
     * @return array{state:string,examDate?:string,startTime?:string,endTime?:string,room?:string}
     */
    public static function windowFor(array $sessions, ?DateTimeImmutable $now = null): array
    {
        if (!$sessions) {
            return ['state' => 'none'];
        }
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $upcoming = null;
        $upcomingStart = null;
        foreach ($sessions as $s) {
            if (self::isOpen($s['examDate'], $s['startTime'], $s['endTime'], $now)) {
                return ['state' => 'open'] + self::pick($s);
            }
            $start = new DateTimeImmutable($s['examDate'] . ' ' . substr($s['startTime'], 0, 8), $tz);
            if ($start > $now && ($upcomingStart === null || $start < $upcomingStart)) {
                $upcoming = $s;
                $upcomingStart = $start;
            }
        }
        return $upcoming !== null ? ['state' => 'upcoming'] + self::pick($upcoming) : ['state' => 'ended'];
    }

    /** @return array{examDate:string,startTime:string,endTime:string,room:string} */
    private static function pick(array $s): array
    {
        return [
            'examDate' => $s['examDate'],
            'startTime' => substr($s['startTime'], 0, 5),
            'endTime' => substr($s['endTime'], 0, 5),
            'room' => (string) ($s['room'] ?? ''),
        ];
    }

    /**
     * The sessions scheduled for this student: their group's sessions and their own make-up sessions (same matching
     * rule as the access-code check, matchSql()).
     *
     * @return array<int,array{examDate:string,startTime:string,endTime:string,room:string}>
     */
    public static function sessionsForStudent(PDO $pdo, int $studentUserId): array
    {
        $stmt = $pdo->prepare(
            'SELECT es.exam_date::text AS exam_date, es.start_time::text AS start_time, es.end_time::text AS end_time, es.room
             FROM exam_schedules es JOIN students s ON s.user_id = ?
             WHERE ' . self::matchSql($pdo)
        );
        $stmt->execute([$studentUserId]);
        return array_map(fn($r) => [
            'examDate' => $r['exam_date'], 'startTime' => $r['start_time'], 'endTime' => $r['end_time'], 'room' => $r['room'],
        ], $stmt->fetchAll());
    }
}