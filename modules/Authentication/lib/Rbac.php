<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';
require_once __DIR__ . '/Auth.php';

/**
 * Checks a logged-in user's access level for a module against the
 * security_rbac table (module, role) -> full|limited|none. This is what
 * actually enforces the RBAC matrix shown in security-configuration.html —
 * previously nothing in the app consulted it at all.
 */
class Rbac
{
    private const MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes', 'sections', 'staffAccounts'];

    /** The roles in the access matrix. Guidance Counselors and Guidance Facilitators share the 'counselor' system role but have a column each. */
    public const ROLES = ['admin', 'counselor', 'facilitator', 'student'];

    /**
     * Where a Guidance Facilitator starts when the database has no 'facilitator' rows yet (an older database, before the
     * column existed): the Guidance Counselor's level, but no higher than this. Once the rows exist the matrix decides.
     */
    public const FACILITATOR_DEFAULT_LIMITS = ['sections' => 'limited', 'staffAccounts' => 'none', 'career' => 'limited'];

    /** The starting level of a Guidance Facilitator for $module, given the Guidance Counselor's level. */
    public static function facilitatorDefault(string $module, string $counselorLevel): string
    {
        $cap = self::FACILITATOR_DEFAULT_LIMITS[$module] ?? null;
        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        if ($cap === null || ($rank[$counselorLevel] ?? 0) <= $rank[$cap]) {
            return $counselorLevel;
        }
        return $cap;
    }

    /** The three levels, from most to least access. */
    public const LEVELS = ['full', 'limited', 'none'];

    /** The only module a Student can ever hold: reading their own recommendations. Everything else is No Access, whatever the table says. */
    public const STUDENT_MODULES = ['recommendations'];

    /** Modules about accounts: changing who holds them is a sensitive change (it asks for a confirmation before it is saved). */
    public const SENSITIVE_MODULES = ['staffAccounts', 'counselor'];

    /** Changing the Guidance Counselor column, or any account module, is a sensitive change. */
    public static function isSensitiveChange(string $module, string $targetRole): bool
    {
        return in_array($module, self::SENSITIVE_MODULES, true) || $targetRole === 'counselor';
    }

    /**
     * The levels $actorKey may set for a cell of the matrix (module x role), or [] when the cell is fixed for them.
     *  - Only the administrator (Head of Guidance) changes permissions: the Guidance Counselor and Guidance Facilitator
     *    columns, to any level. Everyone else with access to the page only looks at the table.
     *  - The administrator column and the Student column are never editable (nobody can lock the Head of Guidance out, and a
     *    Student can never be given an administrative module).
     *
     * @param array<string,array<string,string>> $matrix module => role => level (kept for callers; not needed any more)
     * @return array<int,string>
     */
    public static function editableLevels(string $actorKey, string $module, string $targetRole, array $matrix): array
    {
        if (!in_array($module, self::MODULES, true) || !in_array($targetRole, ['counselor', 'facilitator'], true)) {
            return [];
        }
        return $actorKey === 'admin' ? self::LEVELS : [];
    }

    /** The matrix column for a signed-in user: 'admin', 'counselor', 'facilitator' or 'student'. */
    public static function roleKey(array $user): string
    {
        return self::isFacilitator($user) ? 'facilitator' : (string) ($user['role'] ?? '');
    }

    /** True for a signed-in Guidance Facilitator. */
    public static function isFacilitator(array $user): bool
    {
        if (($user['role'] ?? '') !== 'counselor') {
            return false;
        }
        $stmt = Database::get()->prepare('SELECT staff_position FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        return $stmt->fetchColumn() === 'facilitator';
    }

    private static function denyViewOnly(): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Guidance Facilitators have view-only access to this.']);
        exit;
    }

    /** Uploading or replacing a class roster: Full on the class roster module, and for a facilitator also Full on Sections (View Only by default). */
    public static function requireRosterEditor(): array
    {
        $user = self::requireAccess('rac', 'full');
        if (self::roleKey($user) === 'facilitator' && self::accessLevel('sections', 'facilitator') !== 'full') {
            self::denyViewOnly();
        }
        return $user;
    }

    public static function accessLevel(string $module, string $role): string
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException("Unknown RBAC module: $module");
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT access_level FROM security_rbac WHERE module = ? AND role = ?');
        $stmt->execute([$module, $role]);
        $level = $stmt->fetchColumn();
        if ($level === false && $role === 'facilitator') {
            $stmt->execute([$module, 'counselor']);
            $counselor = $stmt->fetchColumn();
            $level = $counselor !== false ? self::facilitatorDefault($module, (string) $counselor) : 'none';
        }
        $level = $level !== false ? $level : 'none';
        // A Student holds only what is in STUDENT_MODULES, whatever the table says, even if a row is tampered with. (Students
        // still read the announcements sent to them: api/announcements.php serves that without this permission.)
        if ($role === 'student' && !in_array($module, self::STUDENT_MODULES, true)) {
            return 'none';
        }
        return $level;
    }

    /**
     * Requires the logged-in user's role to have at least $minLevel access
     * to $module. Sends 401/403 and exits otherwise. Returns the current
     * user array on success, for convenience.
     */
    public static function requireAccess(string $module, string $minLevel = 'limited'): array
    {
        $user = Auth::requireLogin();
        $level = self::accessLevel($module, self::roleKey($user));

        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        if (($rank[$level] ?? 0) < ($rank[$minLevel] ?? 1)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions for this action.']);
            exit;
        }
        return $user;
    }

    /**
     * Staff Accounts (approving staff sign-ups, sending access codes, deactivating staff). The administrator always has it, so
     * an unfinished database can never lock them out. Guidance Counselors have it by default and Guidance Facilitators do not;
     * the matrix (Edit Roles) can change either.
     */
    public static function requireStaffAccounts(string $minLevel = 'full'): array
    {
        $user = Auth::requireLogin();
        if ($user['role'] === 'admin') {
            return $user;
        }
        return self::requireAccess('staffAccounts', $minLevel);
    }

    /**
     * Requires the logged-in user's role to be one of $roles — for
     * endpoints gated by a simple "must be this role" rule rather than the
     * module access-level matrix above (e.g. Staff Accounts, Security
     * Configuration). Sends 403 and exits otherwise. Returns the current
     * user array on success.
     */
    public static function requireRole(string ...$roles): array
    {
        $user = Auth::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        return $user;
    }
}
