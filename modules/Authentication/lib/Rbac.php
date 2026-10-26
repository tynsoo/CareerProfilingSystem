<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';
require_once __DIR__ . '/Auth.php';

/**
 * Checks a logged-in user's access level for a feature against the security_rbac table (module, role) ->
 * full|limited|none. This is what actually enforces the permission table shown on Security Configuration.
 *
 * The rows are the system's features, grouped under the sidebar page each one lives on (PAGES). A page whose features
 * are all No Access for someone is closed to them: the sidebar leaves it out (js/admin-nav.js) and router.php refuses it.
 */
class Rbac
{
    /**
     * Each feature, in the order of the sidebar, with its default level for the Guidance Counselor and the Guidance
     * Facilitator (the permission table agreed with the team, 2026-10-24). The administrator always has Full. Used when a
     * row is missing from the table, by db/seed_security_defaults.php and by db/migrate_rbac_by_page.php.
     */
    public const DEFAULTS = [
        // Student Information
        'dashboard'         => ['counselor' => 'limited', 'facilitator' => 'limited'],
        'studentInfo'       => ['counselor' => 'full',    'facilitator' => 'limited'],
        'studentAccounts'   => ['counselor' => 'limited', 'facilitator' => 'none'],
        'assessmentResults' => ['counselor' => 'full',    'facilitator' => 'limited'],
        'recommendations'   => ['counselor' => 'full',    'facilitator' => 'limited'],
        'notesCreate'       => ['counselor' => 'full',    'facilitator' => 'full'],
        'exportResults'     => ['counselor' => 'limited', 'facilitator' => 'none'],
        // Analytics & Report
        'analytics'         => ['counselor' => 'limited', 'facilitator' => 'none'],
        'analyticsEmail'    => ['counselor' => 'none',    'facilitator' => 'none'],
        // Assessment
        'programs'          => ['counselor' => 'limited', 'facilitator' => 'limited'],
        'questionBank'      => ['counselor' => 'full',    'facilitator' => 'full'],
        'examinations'      => ['counselor' => 'full',    'facilitator' => 'full'],
        'monitoring'        => ['counselor' => 'full',    'facilitator' => 'limited'],
        // Student Support
        'announcements'     => ['counselor' => 'limited', 'facilitator' => 'limited'],
        'counselingHistory' => ['counselor' => 'full',    'facilitator' => 'limited'],
        'counselingStatus'  => ['counselor' => 'full',    'facilitator' => 'none'],
        'helpCenter'        => ['counselor' => 'full',    'facilitator' => 'limited'],
        // Administration
        'roster'            => ['counselor' => 'limited', 'facilitator' => 'none'],
        'staffAccounts'     => ['counselor' => 'none',    'facilitator' => 'none'],
    ];

    /**
     * The staff pages and the features on each. A page is open to someone when at least one of its features is View Only
     * or Full for them. The first ten (SIDEBAR) are the sidebar's work pages, in its order; the rest are pages reached
     * from them. Pages not listed here or in ADMIN_PAGES (My Access, staff profile, notifications) are open to all staff.
     */
    public const PAGES = [
        'admin-dashboard'         => ['dashboard', 'studentInfo'],
        'analytics'               => ['analytics'],
        'career-dataset'          => ['programs'],
        'question-bank'           => ['questionBank'],
        'assessment-scheduling'   => ['examinations'],
        'monitoring'              => ['monitoring'],
        'announcements'           => ['announcements'],
        // Opened by View counseling history only: Update counseling status alone would open an empty list (resolving and
        // deciding need the requests in view); on its own it still invites from Student Information and Monitoring.
        'counseling-requests'     => ['counselingHistory'],
        'staff-help-center'       => ['helpCenter'],
        'account-management'      => ['roster', 'staffAccounts'],
        'student-profile'         => ['studentInfo'],
        'monitoring-details'      => ['monitoring'],
        'add-career'              => ['programs'],
        'all-recommended-careers' => ['recommendations'],
    ];

    /** The sidebar's work pages, in its order: staff land on the first one open to them. */
    public const SIDEBAR = [
        'admin-dashboard', 'analytics', 'career-dataset', 'question-bank', 'assessment-scheduling', 'monitoring',
        'announcements', 'counseling-requests', 'staff-help-center', 'account-management',
    ];

    /**
     * The Head of Guidance's own tools: only the administrator ever opens them (they are not in the permission table and are
     * not shown to other staff at all). Counselors and facilitators see their own access on My Access instead.
     */
    public const ADMIN_PAGES = ['security-configuration', 'audit-log'];

    /**
     * The administrator's pages a co-admin opens too. The Audit Log is read only for them (masked IP addresses, no
     * retention setting); Security Configuration (permission table, security settings) stays the administrator's.
     */
    public const COADMIN_PAGES = ['audit-log'];

    /** At most this many Guidance Counselors are co-admins at once (Account Management). */
    public const CO_ADMIN_MAX = 3;

    /** Where staff go when every sidebar page is closed to them: their own access, which says why. */
    public const FALLBACK_PAGE = 'my-access';

    /** The roles in the access matrix. Guidance Counselors and Guidance Facilitators share the 'counselor' system role but have a column each. */
    public const ROLES = ['admin', 'counselor', 'facilitator', 'student'];

    /**
     * The role key of a co-admin: a Guidance Counselor the administrator gave the shared admin work (users.is_co_admin).
     * Not a column of the table: a co-admin has Full on every feature, like the administrator, and nobody can change that
     * column. The administrator keeps the permission table, security settings, audit log retention and full IP addresses.
     */
    public const COADMIN = 'coadmin';

    /**
     * Used only by the older migrations (db/migrate_add_facilitator_role.php and the ones before it) that seeded the
     * previous feature list. db/migrate_rbac_by_page.php replaces those rows.
     */
    public const FACILITATOR_DEFAULT_LIMITS = ['sections' => 'limited', 'staffAccounts' => 'none'];

    /** The starting level of a Guidance Facilitator for $module, given the Guidance Counselor's level (older migrations only). */
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

    /** The only feature a Student can ever hold: reading their own recommendations. Everything else is No Access, whatever the table says. */
    public const STUDENT_MODULES = ['recommendations'];

    /**
     * Features a Guidance Facilitator never holds, whatever the table says: Update counseling status (resolving counseling
     * requests, deciding retakes, inviting students to counseling) belongs to the Guidance Counselor and the Administrator
     * (DFD process 4.0). The cell is fixed at No Access on Security Configuration, so the table shows what is enforced.
     */
    public const FACILITATOR_NEVER = ['counselingStatus'];

    /**
     * A feature that only works together with another one (Tyn, 2026-10-26): it counts only while at least one of these
     * is View Only or Full for the same role, otherwise it is No Access (effectiveLevels). Each pair is a place the
     * feature lives: Send analytics report is a button on Analytics; account actions, notes and results are on the
     * student list and profile; Assessment results also drive the dashboard's Course Match pie; Export student results
     * also makes the Analytics Report PDF. The defaults already follow every rule.
     */
    public const NEEDS = [
        'analyticsEmail'    => ['analytics'],
        'studentAccounts'   => ['studentInfo'],
        'notesCreate'       => ['studentInfo'],
        'assessmentResults' => ['studentInfo', 'dashboard'],
        'exportResults'     => ['studentInfo', 'analytics'],
    ];

    /** Features that are only an action (send, write, decide): No Access or Full, no View Only (it gave nothing). */
    public const ACTION_ONLY = ['analyticsEmail', 'notesCreate', 'counselingStatus'];

    /**
     * The levels that really apply, from the saved ones: an action-only feature at View Only is No Access, and a feature
     * whose NEEDS are all No Access is No Access. No database: the same rules for the server, the page and the tests.
     *
     * @param array<string,string> $levels feature => full|limited|none
     * @return array<string,string>
     */
    public static function effectiveLevels(array $levels): array
    {
        foreach (self::ACTION_ONLY as $m) {
            if (($levels[$m] ?? 'none') === 'limited') {
                $levels[$m] = 'none';
            }
        }
        foreach (array_keys(self::unmetNeeds($levels)) as $m) {
            $levels[$m] = 'none';
        }
        return $levels;
    }

    /**
     * The features that are on (View Only or Full) while none of what they need is on.
     *
     * @param array<string,string> $levels feature => full|limited|none
     * @return array<string,array<int,string>> feature => the features it needs (any one of them)
     */
    public static function unmetNeeds(array $levels): array
    {
        $out = [];
        foreach (self::NEEDS as $m => $needs) {
            if (($levels[$m] ?? 'none') === 'none') {
                continue;
            }
            $met = false;
            foreach ($needs as $n) {
                $met = $met || self::atLeast($levels[$n] ?? 'none', 'limited');
            }
            if (!$met) {
                $out[$m] = $needs;
            }
        }
        return $out;
    }

    /** Features about accounts: changing who holds them asks for a confirmation before it is saved. */
    public const SENSITIVE_MODULES = ['staffAccounts', 'studentAccounts'];

    /** Every feature in the table. */
    public static function modules(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** Changing the Guidance Counselor column, or any account feature, is a sensitive change. */
    public static function isSensitiveChange(string $module, string $targetRole): bool
    {
        return in_array($module, self::SENSITIVE_MODULES, true) || $targetRole === 'counselor';
    }

    /**
     * The levels $actorKey may set for a cell of the matrix (module x role), or [] when the cell is fixed for them.
     *  - Only the administrator (Head of Guidance) changes permissions: the Guidance Counselor and Guidance Facilitator
     *    columns. Everyone else only looks at their own access.
     *  - The administrator column and the Student column are never editable (nobody can lock the Head of Guidance out, and a
     *    Student can never be given a staff feature).
     *
     * @param array<string,array<string,string>> $matrix module => role => level (kept for callers; not needed any more)
     * @return array<int,string>
     */
    public static function editableLevels(string $actorKey, string $module, string $targetRole, array $matrix): array
    {
        if (!isset(self::DEFAULTS[$module]) || !in_array($targetRole, ['counselor', 'facilitator'], true) || $actorKey !== 'admin') {
            return [];
        }
        if ($targetRole === 'facilitator' && in_array($module, self::FACILITATOR_NEVER, true)) {
            return [];
        }
        // An action has no "view" part: Full or No Access.
        return in_array($module, self::ACTION_ONLY, true) ? ['full', 'none'] : self::LEVELS;
    }

    /** The matrix column for a signed-in user: 'admin', 'counselor', 'facilitator', 'student', or COADMIN for a co-admin. */
    public static function roleKey(array $user): string
    {
        if (self::isFacilitator($user)) {
            return 'facilitator';
        }
        return self::isCoAdmin($user) ? self::COADMIN : (string) ($user['role'] ?? '');
    }

    /** True for a signed-in Guidance Facilitator. */
    public static function isFacilitator(array $user): bool
    {
        return (self::staffRow($user)['staff_position'] ?? null) === 'facilitator';
    }

    /**
     * True for a co-admin. Read from the database on every request (not the session), so removing a co-admin works at
     * once. A Guidance Facilitator is never one (also a check constraint on users).
     */
    public static function isCoAdmin(array $user): bool
    {
        $row = self::staffRow($user);
        return !empty($row['is_co_admin']) && ($row['staff_position'] ?? null) !== 'facilitator';
    }

    /** The administrator or a co-admin: the shared admin work (staff accounts, assessment reset, reading the Audit Log). */
    public static function isAdminOrCoAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' || self::isCoAdmin($user);
    }

    /** @var array<int,array{staff_position:?string,is_co_admin:bool}> user id => row, read once per request */
    private static array $staffRows = [];

    private static ?bool $hasCoAdminColumn = null;

    /** The database rule that keeps users.is_co_admin on Guidance Counselors only (also in db/migrate_add_co_admin.php). */
    public const CO_ADMIN_RULE = "CHECK (NOT is_co_admin OR (role = 'counselor' AND staff_position IS DISTINCT FROM 'facilitator'))";

    /**
     * Whether users.is_co_admin exists (until then nobody is a co-admin). The first request that finds it missing adds it
     * itself (addCoAdminColumn), so the live site needs no manual migration: Render's free plan has no Shell (Tyn, 2026-10-26).
     */
    public static function hasCoAdminColumn(): bool
    {
        if (self::$hasCoAdminColumn === null) {
            self::$hasCoAdminColumn = self::coAdminColumnExists() || self::addCoAdminColumn();
        }
        return self::$hasCoAdminColumn;
    }

    private static function coAdminColumnExists(): bool
    {
        // information_schema, not a query that fails: a failed query would break an open transaction.
        return (bool) Database::get()->query(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'is_co_admin'"
        )->fetchColumn();
    }

    /**
     * Adds users.is_co_admin and its rule, the same as db/migrate_add_co_admin.php. Safe to run twice and from two requests
     * at once. Never inside an open transaction (it would end it), at most one try per 10 minutes after a failure, and a
     * failure only leaves co-admins switched off (the error goes to the server log). Adding a column with a constant
     * default is instant on PostgreSQL 11 and later; lock_timeout keeps a busy table from holding the request.
     */
    private static function addCoAdminColumn(): bool
    {
        $pdo = Database::get();
        if ($pdo->inTransaction()) {
            return false;
        }
        $marker = sys_get_temp_dir() . '/profilepath-co-admin-column-try';
        if (is_file($marker) && time() - (int) @filemtime($marker) < 600) {
            return false;
        }
        @touch($marker);
        try {
            $pdo->exec("SET lock_timeout = '3s'");
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_co_admin BOOLEAN NOT NULL DEFAULT FALSE');
            if (!$pdo->query("SELECT 1 FROM pg_constraint WHERE conname = 'users_co_admin_counselor_only'")->fetchColumn()) {
                try {
                    $pdo->exec('ALTER TABLE users ADD CONSTRAINT users_co_admin_counselor_only ' . self::CO_ADMIN_RULE);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '42710') { // 42710 = another request added the rule a moment ago
                        throw $e;
                    }
                }
            }
            $pdo->exec('RESET lock_timeout');
            @unlink($marker);
            error_log('[co-admin] users.is_co_admin added automatically');
            return true;
        } catch (Throwable $e) {
            try { $pdo->exec('RESET lock_timeout'); } catch (Throwable $ignored) {}
            error_log('[co-admin] could not add users.is_co_admin: ' . $e->getMessage());
            return false;
        }
    }

    /** A Guidance Counselor's or Facilitator's position and co-admin flag ([] for everyone else). */
    private static function staffRow(array $user): array
    {
        if (($user['role'] ?? '') !== 'counselor') {
            return [];
        }
        $id = (int) $user['id'];
        if (!isset(self::$staffRows[$id])) {
            $stmt = Database::get()->prepare(self::hasCoAdminColumn()
                ? 'SELECT staff_position, is_co_admin FROM users WHERE id = ?'
                : 'SELECT staff_position, FALSE AS is_co_admin FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            self::$staffRows[$id] = [
                'staff_position' => $row['staff_position'] ?? null,
                'is_co_admin' => !empty($row['is_co_admin']),
            ];
        }
        return self::$staffRows[$id];
    }

    /** Forget the co-admin flags read in this request (after the administrator changes one). */
    public static function forgetStaffRows(): void
    {
        self::$staffRows = [];
    }

    /** Uploading or replacing a class roster, adding or deactivating sections: Full on Student roster management. */
    public static function requireRosterEditor(): array
    {
        return self::requireAccess('roster', 'full');
    }

    /** @var array<string,array<string,string>> role => saved rows, read once per request */
    private static array $saved = [];

    /**
     * Every feature's level that really applies to $role: savedLevelsFor() with the needs rules (NEEDS, ACTION_ONLY) for
     * Guidance Counselors and Facilitators. Every access check uses this.
     */
    public static function levelsFor(string $role): array
    {
        $levels = self::savedLevelsFor($role);
        // The administrator, a co-admin (both Full on everything) and a Student (one feature) already follow the rules.
        return in_array($role, ['counselor', 'facilitator'], true) ? self::effectiveLevels($levels) : $levels;
    }

    /**
     * Every feature's level for $role as saved: the saved row, else the default (the administrator always Full, a Student
     * only STUDENT_MODULES, the facilitator's fixed cells). One query per role per request. Security Configuration shows
     * these, so the administrator sees what is saved and which cells do not count yet (unmetNeeds).
     */
    public static function savedLevelsFor(string $role): array
    {
        if ($role === self::COADMIN) {
            // A co-admin has every feature, like the administrator; the table has no co-admin column to read.
            return array_fill_keys(array_keys(self::DEFAULTS), 'full');
        }
        if (!isset(self::$saved[$role])) {
            $stmt = Database::get()->prepare('SELECT module, access_level FROM security_rbac WHERE role = ?');
            $stmt->execute([$role]);
            self::$saved[$role] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $levels = [];
        foreach (self::DEFAULTS as $module => $defaults) {
            if ($role === 'admin') {
                // The administrator always has every feature, whatever the table says, so a missing or changed row can
                // never lock the Head of Guidance out.
                $levels[$module] = 'full';
            } elseif ($role === 'student') {
                // A Student holds only what is in STUDENT_MODULES, even if a row is tampered with. (Students still read the
                // announcements sent to them: api/announcements.php serves that without this permission.)
                $levels[$module] = in_array($module, self::STUDENT_MODULES, true) ? (self::$saved[$role][$module] ?? 'full') : 'none';
            } elseif ($role === 'facilitator' && in_array($module, self::FACILITATOR_NEVER, true)) {
                // Fixed for the facilitator (DFD process 4.0), even if an older saved row says otherwise.
                $levels[$module] = 'none';
            } else {
                $level = self::$saved[$role][$module] ?? ($defaults[$role] ?? 'none');
                $levels[$module] = in_array($level, self::LEVELS, true) ? $level : 'none';
            }
        }
        return $levels;
    }

    public static function accessLevel(string $module, string $role): string
    {
        if (!isset(self::DEFAULTS[$module])) {
            throw new InvalidArgumentException("Unknown RBAC module: $module");
        }
        return self::levelsFor($role)[$module];
    }

    /** Forget the levels read in this request (after the administrator saves the table). */
    public static function forgetSaved(): void
    {
        self::$saved = [];
    }

    /** True when $level is at least $minLevel. */
    public static function atLeast(string $level, string $minLevel): bool
    {
        $rank = ['none' => 0, 'limited' => 1, 'full' => 2];
        return ($rank[$level] ?? 0) >= ($rank[$minLevel] ?? 1);
    }

    /**
     * Whether the staff page $page is open to $role (ADMIN_PAGES: the administrator only, COADMIN_PAGES also a co-admin;
     * pages not in PAGES are open).
     */
    public static function pageOpen(string $page, string $role): bool
    {
        if (in_array($page, self::ADMIN_PAGES, true)) {
            return $role === 'admin' || ($role === self::COADMIN && in_array($page, self::COADMIN_PAGES, true));
        }
        if (!isset(self::PAGES[$page])) {
            return true;
        }
        $levels = self::levelsFor($role);
        foreach (self::PAGES[$page] as $module) {
            if (self::atLeast($levels[$module], 'limited')) {
                return true;
            }
        }
        return false;
    }

    /** The pages closed to $role, for the sidebar. */
    public static function closedPages(string $role): array
    {
        return array_values(array_filter(array_keys(self::PAGES), fn($page) => !self::pageOpen($page, $role)));
    }

    /** Where a staff member lands: the first open sidebar page, else My Access. */
    public static function firstOpenPage(string $role): string
    {
        foreach (self::SIDEBAR as $page) {
            if (self::pageOpen($page, $role)) {
                return $page;
            }
        }
        return self::FALLBACK_PAGE;
    }

    /**
     * Requires the logged-in user's role to have at least $minLevel access
     * to $module. Sends 401/403 and exits otherwise. Returns the current
     * user array on success, for convenience.
     */
    public static function requireAccess(string $module, string $minLevel = 'limited'): array
    {
        $user = Auth::requireLogin();
        if (!self::atLeast(self::accessLevel($module, self::roleKey($user)), $minLevel)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions for this action.']);
            exit;
        }
        return $user;
    }

    /**
     * Staff Accounts (approving staff sign-ups, sending access codes, assigning sections, deactivating staff). The
     * administrator always has it; for everyone else the table decides.
     */
    public static function requireStaffAccounts(string $minLevel = 'full'): array
    {
        return self::requireAccess('staffAccounts', $minLevel);
    }

    /** The administrator or a co-admin (reading the Audit Log, "Check log"). Sends 403 and exits otherwise. */
    public static function requireAdminOrCoAdmin(): array
    {
        $user = Auth::requireLogin();
        if (!self::isAdminOrCoAdmin($user)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        return $user;
    }

    /**
     * Requires the logged-in user's role to be one of $roles — for
     * endpoints gated by a simple "must be this role" rule rather than the
     * module access-level matrix above. Sends 403 and exits otherwise.
     * Returns the current user array on success.
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
