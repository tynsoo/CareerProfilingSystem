<?php

/**
 * The Guidance Office's weekly hours, chosen day by day (an opening and a
 * closing time for each open day) instead of typed as free text, so what
 * students read on the Help Center is always consistently formatted.
 *
 * A schedule is a list of ['day' => 'mon'|…|'sun', 'open' => 'HH:MM', 'close' => 'HH:MM']
 * in 24-hour time. text() turns it into the sentence students see, e.g.
 * "Mon–Fri, 8:00 AM–5:00 PM; Sat, 8:00 AM–12:00 PM".
 */
class OfficeHours
{
    public const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    // What a new save may hold: the office never opens on Sunday, and hours stay within 8:00 AM to 5:00 PM.
    // 'sun' stays in DAYS so a schedule saved before this rule still reads (fromJson) instead of falling back.
    public const CLOSED_DAYS = ['sun'];
    public const EARLIEST = '08:00';
    public const LATEST = '17:00';

    public const DEFAULT_SCHEDULE = [
        ['day' => 'mon', 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'tue', 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'wed', 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'thu', 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'fri', 'open' => '08:00', 'close' => '17:00'],
    ];

    /**
     * Checks a posted schedule and returns it cleaned up in Mon→Sun order. $forSave adds the office's limits
     * (no Sunday, 8:00 AM to 5:00 PM); reading a stored schedule leaves them out.
     *
     * @param mixed $input
     * @return array{error:?string,schedule:array<int,array{day:string,open:string,close:string}>}
     */
    public static function validate($input, bool $forSave = true): array
    {
        if (!is_array($input) || $input === []) {
            return ['error' => 'Choose at least one day the Guidance Office is open.', 'schedule' => []];
        }
        $byDay = [];
        foreach ($input as $row) {
            $day = is_array($row) ? (string) ($row['day'] ?? '') : '';
            $open = is_array($row) ? (string) ($row['open'] ?? '') : '';
            $close = is_array($row) ? (string) ($row['close'] ?? '') : '';
            if (!isset(self::DAYS[$day])) {
                return ['error' => 'Unknown day in the schedule.', 'schedule' => []];
            }
            $label = self::DAYS[$day];
            if (isset($byDay[$day])) {
                return ['error' => "$label is listed more than once.", 'schedule' => []];
            }
            if (!self::isTime($open) || !self::isTime($close)) {
                return ['error' => "$label: choose a valid opening and closing time.", 'schedule' => []];
            }
            if ($close <= $open) { // zero-padded HH:MM strings compare correctly
                return ['error' => "$label: closing time must be later than opening time.", 'schedule' => []];
            }
            if ($forSave && in_array($day, self::CLOSED_DAYS, true)) {
                return ['error' => 'The Guidance Office is closed on Sundays.', 'schedule' => []];
            }
            if ($forSave && ($open < self::EARLIEST || $close > self::LATEST)) {
                return ['error' => "$label: choose hours between 8:00 AM and 5:00 PM.", 'schedule' => []];
            }
            $byDay[$day] = ['day' => $day, 'open' => $open, 'close' => $close];
        }
        $ordered = [];
        foreach (array_keys(self::DAYS) as $day) {
            if (isset($byDay[$day])) {
                $ordered[] = $byDay[$day];
            }
        }
        return ['error' => null, 'schedule' => $ordered];
    }

    /** "HH:MM" in 24-hour time, 00:00 to 23:59. */
    public static function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    /** "17:30" -> "5:30 PM" */
    public static function formatTime(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        return sprintf('%d:%02d %s', $h12, $m, $suffix);
    }

    /**
     * The sentence students see. Consecutive days with the same hours are
     * joined into a range; different hours are separated by "; ".
     *
     * @param array<int,array{day:string,open:string,close:string}> $schedule already validated, Mon→Sun
     */
    public static function text(array $schedule): string
    {
        $order = array_keys(self::DAYS);
        $groups = [];
        foreach ($schedule as $row) {
            $idx = array_search($row['day'], $order, true);
            $hours = self::formatTime($row['open']) . '–' . self::formatTime($row['close']);
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['hours'] === $hours && $groups[$last]['endIdx'] === $idx - 1) {
                $groups[$last]['endIdx'] = $idx;
                $groups[$last]['end'] = $row['day'];
            } else {
                $groups[] = ['hours' => $hours, 'start' => $row['day'], 'end' => $row['day'], 'endIdx' => $idx];
            }
        }
        return implode('; ', array_map(function ($g) {
            $days = $g['start'] === $g['end']
                ? self::DAYS[$g['start']]
                : self::DAYS[$g['start']] . '–' . self::DAYS[$g['end']];
            return $days . ', ' . $g['hours'];
        }, $groups));
    }

    /**
     * Reads a stored schedule (JSON); falls back to the default when none was saved.
     *
     * @return array<int,array{day:string,open:string,close:string}>
     */
    public static function fromJson(?string $json): array
    {
        $decoded = $json !== null && $json !== '' ? json_decode($json, true) : null;
        $checked = self::validate($decoded, false);
        return $checked['error'] === null ? $checked['schedule'] : self::DEFAULT_SCHEDULE;
    }
}
