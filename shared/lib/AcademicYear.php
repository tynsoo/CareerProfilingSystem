<?php

/**
 * The current Academic Year, worked out from today's date (school time,
 * Asia/Manila) instead of being typed in by an admin. An academic year runs
 * from July to June, so July 2026 through June 2027 is "2026-2027".
 */
class AcademicYear
{
    /** Month the academic year starts (1-12). */
    public const START_MONTH = 7;

    public static function current(?DateTimeImmutable $now = null): string
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $year = (int) $now->format('Y');
        $startYear = (int) $now->format('n') >= self::START_MONTH ? $year : $year - 1;
        return $startYear . '-' . ($startYear + 1);
    }

    /** 2026 for "2026-2027"; null when the text is not a school year. */
    public static function startYear(string $ay): ?int
    {
        return preg_match('/^(\d{4})-(\d{4})$/', $ay, $m) && (int) $m[2] === (int) $m[1] + 1 ? (int) $m[1] : null;
    }

    /** True for a school year that already ended (before the current one). */
    public static function isPast(string $ay, ?DateTimeImmutable $now = null): bool
    {
        $start = self::startYear($ay);
        return $start !== null && $start < (int) self::startYear(self::current($now));
    }

    /**
     * The school years staff can look back at, newest first: every year that has students, a class roster or a schedule,
     * plus the current one and the one before it (shown empty until it has data, so the filter can be seen working in a
     * system's first year). A year after the current one is never offered.
     *
     * @return array<int,string>
     */
    public static function available(PDO $pdo, ?DateTimeImmutable $now = null): array
    {
        $current = self::current($now);
        $currentStart = (int) self::startYear($current);
        $years = $pdo->query(
            'SELECT academic_year FROM students WHERE academic_year IS NOT NULL
             UNION SELECT academic_year FROM assessment_roster
             UNION SELECT academic_year FROM exam_schedules'
        )->fetchAll(PDO::FETCH_COLUMN);
        $years[] = $current;
        $years[] = ($currentStart - 1) . '-' . $currentStart;
        $years = array_values(array_unique(array_filter($years, function ($y) use ($currentStart) {
            $start = self::startYear((string) $y);
            return $start !== null && $start <= $currentStart;
        })));
        rsort($years);
        return $years;
    }

    /**
     * SQL for "this student belongs to school year $ay": registered in that year, or on that year's class roster (a
     * student who registered earlier and is still enrolled), or, for the current year only, registered before the year
     * was recorded. $alias is the students table alias ('' for none).
     *
     * @return array{0:string,1:array<int,string>} the condition and its two bind values
     */
    public static function studentCondition(string $alias, string $ay, ?DateTimeImmutable $now = null): array
    {
        $c = $alias === '' ? '' : $alias . '.';
        $sql = "({$c}academic_year = ? OR {$c}school_id IN (SELECT school_id FROM assessment_roster WHERE academic_year = ?)"
            . (self::isPast($ay, $now) ? '' : " OR {$c}academic_year IS NULL") . ')';
        return [$sql, [$ay, $ay]];
    }

    /** The school_ids on a year's class roster, as a lookup (id => true), for the PHP side of studentCondition(). */
    public static function rosterIds(PDO $pdo, string $ay): array
    {
        $stmt = $pdo->prepare('SELECT school_id FROM assessment_roster WHERE academic_year = ?');
        $stmt->execute([$ay]);
        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** The same rule as studentCondition(), for one student already loaded. */
    public static function studentBelongs(?string $studentYear, string $schoolId, string $ay, array $rosterIds, ?DateTimeImmutable $now = null): bool
    {
        return $studentYear === $ay || isset($rosterIds[$schoolId]) || ($studentYear === null && !self::isPast($ay, $now));
    }

    /** The year asked for when it is one of the available years, otherwise the current year. */
    public static function resolve(PDO $pdo, string $requested, ?DateTimeImmutable $now = null): string
    {
        $requested = trim($requested);
        return $requested !== '' && in_array($requested, self::available($pdo, $now), true) ? $requested : self::current($now);
    }
}
