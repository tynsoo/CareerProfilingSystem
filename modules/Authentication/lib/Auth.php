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
            // timeout (up to 240 minutes, enforced in currentUser()). Keep them that long, so students are
            // not signed out at random in the middle of the assessment. (This applies to everyone; with the
            // timeout turned off, idle sessions are now cleaned up after 4 hours instead of about 24 minutes.)
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

    /** @return array{enabled:bool, minutes:int} */
    public static function sessionTimeoutPolicy(): array
    {
        if (self::$policyCache !== null) {
            return self::$policyCache;
        }
        try {
            $rows = Database::get()
                ->query("SELECT key, value FROM security_policies WHERE key IN ('sessionTimeoutEnabled', 'timeoutMinutes')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            // Fail open on a DB hiccup — a broken policy lookup shouldn't
            // mass-log-out every user mid-request.
            $rows = [];
        }
        return self::$policyCache = [
            'enabled' => ($rows['sessionTimeoutEnabled'] ?? 'true') === 'true',
            'minutes' => (int) ($rows['timeoutMinutes'] ?? 30),
        ];
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
