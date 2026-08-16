<?php

require_once __DIR__ . '/../config/env.php';

class Auth
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); // Render terminates TLS upstream
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ]);
            session_start();
        }
        self::$started = true;
    }

    /** @return array{id:int, role:string, username:string, schoolId?:string, firstName?:string, lastName?:string}|null */
    public static function currentUser(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function login(array $userData): void
    {
        self::start();
        session_regenerate_id(true); // prevent session fixation across the privilege change
        $_SESSION['user'] = $userData;
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
