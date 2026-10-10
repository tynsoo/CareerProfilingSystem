<?php

/**
 * The optional completion target for the dashboard's per-section graph: when one is set, a section whose assessment
 * completion is at or above it shows green and below it shows red. The CGC has no fixed target (taking the assessment
 * is not required), so it starts OFF; the Head of Guidance (admin) can set one, from 1 to 100 percent, or turn it off
 * again with 0. Stored in security_policies under 'analytics.completionTarget', so it needs no table of its own.
 */
class CompletionTarget
{
    public const KEY = 'analytics.completionTarget';
    /** The value that means "no target". */
    public const OFF = 0;

    public static function isValid(mixed $value): bool
    {
        return is_int($value) && $value >= self::OFF && $value <= 100;
    }

    public static function isOn(int $target): bool
    {
        return $target > self::OFF;
    }

    public static function get(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
        $stmt->execute([self::KEY]);
        $value = $stmt->fetchColumn();
        return $value !== false && ctype_digit((string) $value) && self::isValid((int) $value) ? (int) $value : self::OFF;
    }

    public static function set(PDO $pdo, int $percent, ?int $userId): void
    {
        $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        )->execute([self::KEY, (string) $percent, $userId]);
    }
}