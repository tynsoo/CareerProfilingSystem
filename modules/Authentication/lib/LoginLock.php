<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';

/**
 * Soft, growing sign-in lock. Wrong passwords count only for FAIL_WINDOW_MINUTES; reaching the
 * limit (Security Configuration "Failed attempts before lockout") locks for 1 minute the first time
 * in a day, 5 the second time, then the admin's lockout length (also the ceiling for every step).
 * A successful sign-in, an Unlock by staff or a password reset starts over.
 *
 * The same rules apply to a real account ('u:<id>') and to a name with no account ('n:<hash>'), so
 * the answers never show which accounts exist. Kept in rate_limit_hits (rows older than a day are
 * removed), so no table change is needed. A real account's lock is also written to users.locked_until,
 * which sign-in and the Unlock buttons read.
 */
class LoginLock
{
    /** Wrong passwords older than this no longer count. */
    public const FAIL_WINDOW_MINUTES = 15;

    /** Minutes for the first and second lock in a day; later ones use the admin's lockout length. */
    private const STEPS = [1, 5];

    /** Lock length for the $nth lock (1-based) within a day, never above the admin's $ceiling. */
    public static function minutesFor(int $nth, int $ceiling): int
    {
        $ceiling = max(1, $ceiling);
        return max(1, min(self::STEPS[$nth - 1] ?? $ceiling, $ceiling));
    }

    public static function forAccount(int $userId): string
    {
        return 'u:' . $userId;
    }

    /** A name with no account, hashed so a password typed into the username box is never stored. */
    public static function forName(string $typed): string
    {
        return 'n:' . hash('sha256', mb_strtolower($typed));
    }

    /**
     * Makes sign-in attempts for one subject wait in line, so a burst of attempts sent at the same
     * moment cannot all pass the lock check before the lock is written. A session-level advisory lock:
     * PostgreSQL releases it when the request ends and its connection closes.
     */
    public static function oneAtATime(PDO $pdo, string $subject): void
    {
        $pdo->prepare('SELECT pg_advisory_lock(hashtextextended(?, 0))')->execute(['login:' . $subject]);
    }

    /**
     * Counts one wrong password. Returns the lock length in minutes when this one reaches the limit,
     * otherwise null.
     */
    public static function recordFailure(PDO $pdo, string $subject, int $maxAttempts, int $ceiling): ?int
    {
        $pdo->exec("DELETE FROM rate_limit_hits WHERE created_at < NOW() - INTERVAL '1 day'");
        $pdo->prepare('INSERT INTO rate_limit_hits (rate_key) VALUES (?)')->execute(['login-fail:' . $subject]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_hits WHERE rate_key = ? AND created_at > NOW() - make_interval(mins => ?)");
        $count->execute(['login-fail:' . $subject, self::FAIL_WINDOW_MINUTES]);
        if ((int) $count->fetchColumn() < max(1, $maxAttempts)) {
            return null;
        }
        // Locked: the wrong tries start over, and the lock is remembered for a day (it sets the next length).
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = ?')->execute(['login-fail:' . $subject]);
        $pdo->prepare('INSERT INTO rate_limit_hits (rate_key) VALUES (?)')->execute(['login-lock:' . $subject]);
        return self::minutesFor(self::locksToday($pdo, $subject), $ceiling);
    }

    /** When the subject's lock ends (Unix time), or null when it is not locked. */
    public static function lockedUntil(PDO $pdo, string $subject, int $ceiling): ?int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n, EXTRACT(EPOCH FROM MAX(created_at)) AS last
                               FROM rate_limit_hits WHERE rate_key = ? AND created_at > NOW() - INTERVAL '1 day'");
        $stmt->execute(['login-lock:' . $subject]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['n'] === 0) {
            return null;
        }
        $until = (int) floor((float) $row['last']) + self::minutesFor((int) $row['n'], $ceiling) * 60;
        return $until > time() ? $until : null;
    }

    /** Starts over (successful sign-in, Unlock, password reset). */
    public static function clear(PDO $pdo, string $subject): void
    {
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key IN (?, ?)')->execute(['login-fail:' . $subject, 'login-lock:' . $subject]);
    }

    /** "Try again in 3 minutes." for a lock ending at $until. */
    public static function waitText(int $until): string
    {
        $minutes = max(1, (int) ceil(($until - time()) / 60));
        return 'Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ', or ask the Guidance Office to unlock it.';
    }

    private static function locksToday(PDO $pdo, string $subject): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_hits WHERE rate_key = ? AND created_at > NOW() - INTERVAL '1 day'");
        $stmt->execute(['login-lock:' . $subject]);
        return (int) $stmt->fetchColumn();
    }
}
