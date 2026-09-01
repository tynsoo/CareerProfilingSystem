<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

/**
 * Checks a logged-in user's access level for a module against the
 * security_rbac table (module, role) -> full|limited|none. This is what
 * actually enforces the RBAC matrix shown in security-configuration.html —
 * previously nothing in the app consulted it at all.
 */
class Rbac
{
    private const MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes'];

    public static function accessLevel(string $module, string $role): string
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException("Unknown RBAC module: $module");
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT access_level FROM security_rbac WHERE module = ? AND role = ?');
        $stmt->execute([$module, $role]);
        $level = $stmt->fetchColumn();
        return $level !== false ? $level : 'none';
    }

    /**
     * Requires the logged-in user's role to have at least $minLevel access
     * to $module. Sends 401/403 and exits otherwise. Returns the current
     * user array on success, for convenience.
     */
    public static function requireAccess(string $module, string $minLevel = 'limited'): array
    {
        $user = Auth::requireLogin();
        $level = self::accessLevel($module, $user['role']);

        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        if (($rank[$level] ?? 0) < ($rank[$minLevel] ?? 1)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions for this action.']);
            exit;
        }
        return $user;
    }
}
