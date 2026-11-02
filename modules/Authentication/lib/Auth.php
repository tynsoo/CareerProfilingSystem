<?php

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/DbSessionHandler.php';
require_once __DIR__ . '/../../../shared/lib/Database.php';

class Auth
{
    private static bool $started = false;

    /** Cached per-request so a page that checks auth more than once doesn't re-query the DB each time. */
    private static ?array $policyCache = null;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Sessions live in Postgres (see DbSessionHandler) rather than local
            // disk, since Render's free web service tier wipes local disk every
            // time the container spins down after idling and restarts.
            session_set_save_handler(new DbSessionHandler(), true);

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); // Render terminates TLS upstream
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ]);
            // PHP's default cleanup deletes sessions idle for 24 minutes, sooner than the app's own idle
            // timeout (up to 60 minutes, enforced in currentUser()). Keep them longer than that, so students are
            // not signed out at random in the middle of the assessment.
            ini_set('session.gc_maxlifetime', '14400');
            session_start();
        }
        self::$started = true;
    }

    /** @return array{id:int, role:string, username:string, schoolId?:string, firstName?:string, lastName?:string}|null */
    public static function currentUser(): ?array
    {
        self::start();
        if (!isset($_SESSION['user'])) {
            return null;
        }

        if (self::isIdleTimedOut()) {
            self::logTimeout();
            self::logout();
            return null;
        }

        // Guidance staff are checked against their account on every request, not only at sign-in (panel revision S2):
        // an account that is not approved, not activated (no password created with the emailed code yet) or turned off
        // gets no page and no data, even if it somehow holds a session.
        if (($_SESSION['user']['role'] ?? '') === 'counselor' && !self::staffAccountUsable((int) $_SESSION['user']['id'])) {
            self::logout();
            return null;
        }

        // Sliding idle timeout — every authenticated request that reaches
        // this point resets the clock, same as most session-timeout
        // implementations (bank sites, admin panels, etc.): it's time
        // since the *last* action, not a fixed expiry from login.
        $_SESSION['lastActivity'] = time();

        return $_SESSION['user'];
    }

    /** Per request: staff account id => whether it may be used (approved, activated and active). */
    private static array $staffUsable = [];

    /**
     * Whether a Guidance Counselor or Facilitator account may use the system: approved by the administrator, activated
     * (the person created a password with the emailed access code, which sets email_verified_at) and active. A pending,
     * rejected, not yet activated or deactivated account may not. A database hiccup fails open, like the timeout lookup,
     * so it does not sign everyone out.
     */
    public static function staffAccountUsable(int $userId): bool
    {
        if (!array_key_exists($userId, self::$staffUsable)) {
            try {
                $stmt = Database::get()->prepare('SELECT approval_status, is_active, email_verified_at FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                self::$staffUsable[$userId] = $row !== false && self::staffRowUsable($row);
            } catch (\Throwable $e) {
                error_log('[auth] staff account check: ' . $e->getMessage());
                return true;
            }
        }
        return self::$staffUsable[$userId];
    }

    /** The rule itself, on a users row (approval_status, is_active, email_verified_at). */
    public static function staffRowUsable(array $row): bool
    {
        return ($row['approval_status'] ?? null) === 'approved'
            && !empty($row['is_active'])
            && ($row['email_verified_at'] ?? null) !== null;
    }

    /** @return array{enabled:bool, minutes:int} */
    public static function sessionTimeoutPolicy(): array
    {
        if (self::$policyCache !== null) {
            return self::$policyCache;
        }
        try {
            $rows = Database::get()
                ->query("SELECT key, value FROM security_policies WHERE key = 'timeoutMinutes'")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            // Fail open on a DB hiccup — a broken policy lookup shouldn't
            // mass-log-out every user mid-request.
            $rows = [];
        }
        // Always on (secure by default: it cannot be switched off); only the length is chosen, from TIMEOUT_CHOICES.
        return self::$policyCache = [
            'enabled' => true,
            'minutes' => self::timeoutMinutes($rows['timeoutMinutes'] ?? null),
        ];
    }

    /** The idle timeout lengths the administrator can choose (minutes). */
    public const TIMEOUT_CHOICES = [15, 30, 60];

    /** A saved timeout length as one of TIMEOUT_CHOICES: the next allowed length at or above it, 60 at most (30 if unset). */
    public static function timeoutMinutes(mixed $stored): int
    {
        if ($stored === null || $stored === '' || !is_numeric($stored)) {
            return 30;
        }
        foreach (self::TIMEOUT_CHOICES as $choice) {
            if ((int) $stored <= $choice) {
                return $choice;
            }
        }
        return max(self::TIMEOUT_CHOICES);
    }

    private static function isIdleTimedOut(): bool
    {
        $policy = self::sessionTimeoutPolicy();
        if (!$policy['enabled']) {
            return false;
        }
        $last = $_SESSION['lastActivity'] ?? null;
        if ($last === null) {
            return false; // no prior activity recorded yet (e.g. first request right after login) — nothing to time out against
        }
        return (time() - $last) > $policy['minutes'] * 60;
    }

    /**
     * Audit Log row for a session the idle timeout ended, written before logout() empties the session. A page loads
     * its data with several requests at once, so all of them can find the same expired session: only the request that
     * removes the session row writes the row. A row SessionEnd ended meanwhile (other device, account turned off,
     * password changed) is left alone, so the sign-in page still says why. A failure here is logged and never keeps
     * the session alive.
     */
    private static function logTimeout(): void
    {
        try {
            $claim = Database::get()->prepare(DbSessionHandler::hasUserColumns()
                ? 'DELETE FROM sessions WHERE id = ? AND ended_reason IS NULL RETURNING id'
                : 'DELETE FROM sessions WHERE id = ? RETURNING id');
            $claim->execute([session_id()]);
            if ($claim->fetchColumn() === false) {
                return;
            }
            require_once __DIR__ . '/../../AuditLog/lib/AuditLogger.php';
            $user = $_SESSION['user'];
            $minutes = self::sessionTimeoutPolicy()['minutes'];
            $last = date('M j, Y g:i A', (int) $_SESSION['lastActivity']);   // Asia/Manila (config/env.php)
            AuditLogger::log((int) $user['id'], $user['role'], 'session_timeout', 'user', (string) ($user['username'] ?? ''),
                "Signed out after $minutes minutes of inactivity. Last activity: $last.");
        } catch (\Throwable $e) {
            error_log('[auth] session timeout audit row: ' . $e->getMessage());
        }
    }

    public static function login(array $userData): void
    {
        self::start();
        session_regenerate_id(true); // prevent session fixation across the privilege change
        $_SESSION['user'] = $userData;
        $_SESSION['lastActivity'] = time();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /** Sends 401 and exits if no one is logged in. */
    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }
        return $user;
    }
}
