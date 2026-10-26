<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../../Counseling/lib/OfficeHours.php';
require_once __DIR__ . '/../../Counseling/lib/BookingLink.php';
require_once __DIR__ . '/../../Authentication/lib/PasswordPolicy.php';
require_once __DIR__ . '/../../Authentication/lib/LoginLock.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditChange.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditRetention.php';
require_once __DIR__ . '/../../Monitoring/lib/MonitoringLevel.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();

const RBAC_ROLES = ['admin', 'counselor', 'facilitator', 'student'];
const RBAC_LEVELS = ['full', 'limited', 'none'];
// The row names, for the audit log (the page shows the same names, grouped by sidebar page).
const RBAC_LABELS = [
    'dashboard' => 'Dashboard', 'studentInfo' => 'Student information', 'studentAccounts' => 'Student account management',
    'assessmentResults' => 'Assessment results', 'recommendations' => 'Career recommendations', 'notesCreate' => 'Create notes',
    'exportResults' => 'Export student results', 'analytics' => 'Analytics and reports', 'analyticsEmail' => 'Send analytics report',
    'programs' => 'Program Mapping Crosswalk', 'questionBank' => 'Question Bank', 'examinations' => 'Assessment Scheduling',
    'monitoring' => 'Flagged student monitoring', 'announcements' => 'Announcements', 'counselingHistory' => 'View counseling history',
    'counselingStatus' => 'Update counseling status', 'helpCenter' => 'Help Center', 'roster' => 'Student roster management',
    'staffAccounts' => 'Manage staff accounts',
];

/**
 * The table as saved (Rbac::savedLevelsFor: saved rows, else the defaults; the administrator always Full). What applies on
 * top of it (the needs rules) is Rbac::levelsFor; the page marks the cells that do not count yet.
 */
function loadRbac(PDO $pdo): array
{
    Rbac::forgetSaved();
    $byRole = [];
    foreach (RBAC_ROLES as $r) {
        $byRole[$r] = Rbac::savedLevelsFor($r);
    }
    $rbac = [];
    foreach (Rbac::modules() as $m) {
        foreach (RBAC_ROLES as $r) {
            $rbac[$m][$r] = $byRole[$r][$m];
        }
    }
    return $rbac;
}

/** Which levels the signed-in person may set for each cell, so the page offers only what the server will accept. */
function editableMatrix(array $user, array $rbac): array
{
    $out = [];
    foreach (Rbac::modules() as $m) {
        foreach (RBAC_ROLES as $r) {
            $out[$m][$r] = Rbac::editableLevels(Rbac::roleKey($user), $m, $r, $rbac);
        }
    }
    return $out;
}

function loadPolicies(PDO $pdo): array
{
    $rows = $pdo->query('SELECT key, value FROM security_policies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b = fn($k, $d) => isset($rows[$k]) ? $rows[$k] === 'true' : $d;
    $i = fn($k, $d) => isset($rows[$k]) ? (int) $rows[$k] : $d;
    $s = fn($k, $d) => $rows[$k] ?? $d;
    return [
        'policies' => [
            // Always on (no switch): password rules and sign-in logging cannot be turned off. The old
            // password.enabled and monitoring.enabled switches never changed anything, so they are gone.
            'password' => [
                'enabled' => true, 'minLength' => max(PasswordPolicy::MIN_LENGTH_FLOOR, $i('password.minLength', 8)),
                'requireUpper' => $b('password.requireUpper', true), 'requireLower' => $b('password.requireLower', true),
                'requireNumber' => $b('password.requireNumber', true), 'requireSymbol' => $b('password.requireSymbol', true),
            ],
            // Always on, within the limits the administrator can set (LoginLock::policy, the same rule sign-in uses).
            'lockout' => LoginLock::policy($rows) + [
            ],
            // Its switch is "Delete older logs automatically" (modules/AuditLog/lib/AuditRetention.php), off unless turned on.
            'retention' => [
                'enabled' => $b(AuditRetention::ON_KEY, false), 'retentionDays' => AuditRetention::days($rows[AuditRetention::DAYS_KEY] ?? null),
            ],
            // Always on: the Cosine Similarity level below which a student is flagged on Monitoring (MonitoringLevel).
            'flagging' => [
                'enabled' => true, 'level' => MonitoringLevel::fromValue($rows[MonitoringLevel::KEY] ?? null),
                'floor' => MonitoringLevel::FLOOR, 'default' => MonitoringLevel::DEFAULT,
            ],
        ],
        'settings' => [
            // Email verification for staff password changes and the session timeout are always on (secure by default);
            // only the timeout length is chosen (Auth::TIMEOUT_CHOICES).
            'twoFactor' => true,
            'sessionTimeoutEnabled' => true,
            'timeoutMinutes' => Auth::timeoutMinutes($rows['timeoutMinutes'] ?? null),
            'timeoutChoices' => Auth::TIMEOUT_CHOICES,
        ],
        // Detected from today's date (shared/lib/AcademicYear.php) — read-only here.
        'academicYear' => [
            'current' => AcademicYear::current(),
        ],
        // Chosen day by day; 	ext is the sentence built from it that students read.
        'officeHours' => [
            'text' => $s('officeHours.text', 'Mon–Fri, 8:00 AM–5:00 PM'),
            'schedule' => OfficeHours::fromJson($s('officeHours.schedule', '')),
        ],
        'booking' => ['url' => $s('booking.url', '')],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $user['role'] !== 'admin') {
    // Other staff: Security Configuration is the Head of Guidance's page (Rbac::ADMIN_PAGES). Their pages read only their own
    // column of the permission table, the School Year badge, and (Help Center) the office hours and booking link. The
    // security policies and settings are not sent to them (need to know).
    $ownRole = Rbac::roleKey($user);
    $policies = loadPolicies($pdo);
    // The levels that really apply to this person (Rbac::levelsFor: the needs rules, the facilitator's fixed cell; a
    // co-admin has Full on everything), not the raw saved rows, so their pages offer only what the server allows.
    jsonResponse([
        'rbac' => array_map(fn($level) => [$ownRole => $level], Rbac::levelsFor($ownRole)),
        'rbacOwnOnly' => $ownRole,
        'canEditRoster' => Rbac::accessLevel('roster', $ownRole) === 'full',
        'academicYear' => $policies['academicYear'],
        'officeHours' => $policies['officeHours'],
        'booking' => $policies['booking'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // The administrator: the whole page. The daily cleanup's own markers are not settings changes, so they do not count as updates.
    $lastUpdatedStmt = $pdo->prepare(
        'SELECT GREATEST(
            (SELECT MAX(updated_at) FROM security_rbac),
            (SELECT MAX(updated_at) FROM security_policies WHERE key NOT IN (?, ?))
         )'
    );
    $lastUpdatedStmt->execute([AuditRetention::LAST_RUN_KEY, AuditChain::ANCHOR_KEY]);
    $lastUpdated = $lastUpdatedStmt->fetchColumn();

    // Security Overview card: sign-in numbers (they link to the Audit Log) and flags waiting (they link to Monitoring).
    // A lockout is logged (api/login.php) the moment an account reaches the maximum failed attempts,
    // so this counts accounts that were actually locked out, not every wrong password.
    $roleKey = Rbac::roleKey($user);
    $lockouts7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_lockout' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $sharedLogins7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_shared_suspected' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $pendingFlags = (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags WHERE status = 'pending'")->fetchColumn();

    // The whole permission table: the administrator is the only one who can change it.
    $rbacNow = loadRbac($pdo);
    jsonResponse([
        'rbac' => $rbacNow,
        'rbacOwnOnly' => null,
        // what may be changed in the table (empty for a fixed cell), and which changes ask for a confirmation
        'rbacEditable' => editableMatrix($user, $rbacNow),
        'rbacSensitive' => ['modules' => Rbac::SENSITIVE_MODULES, 'roles' => ['counselor']],
        // The needs rules, so the table can turn on what a feature needs (and off what needs it) before saving.
        'rbacNeeds' => Rbac::NEEDS,
        // Actions (Full or No Access only): an older View Only on them counts as No Access, and the table says so.
        'rbacActionOnly' => Rbac::ACTION_ONLY,
        // Uploading class rosters: Full on Student roster management.
        'canEditRoster' => Rbac::accessLevel('roster', $roleKey) === 'full',
        'lastUpdated' => $lastUpdated,
        'overview' => [
            'lockouts7d' => $lockouts7d,
            'sharedLogins7d' => $sharedLogins7d,
            'pendingFlags' => $pendingFlags,
        ],
    ] + loadPolicies($pdo));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    // The Guidance Office hours and the counseling booking link (edited on the Staff Help Center) are kept up to date by
    // whoever holds Full on Help Center (permission table). Everything else here, the table included, is the administrator's.
    if (in_array($type, ['officeHours', 'bookingLink'], true)) {
        $user = Rbac::requireAccess('helpCenter', 'full');
    } elseif ($type === 'rbac') {
        // Only the Head of Guidance (administrator) changes permissions; Rbac::editableLevels says the same for each cell.
        if ($user['role'] !== 'admin') {
            jsonResponse(['success' => false, 'error' => 'Only the administrator can change permissions.'], 403);
        }
    } elseif ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Only administrators can change security configuration.'], 403);
    }

    if ($type === 'rbac') {
        $changes = $body['changes'] ?? [];
        if (!is_array($changes)) {
            jsonResponse(['success' => false, 'error' => 'Invalid changes payload.'], 400);
        }
        $moduleLabels = RBAC_LABELS;
        $roleLabels = ['admin' => 'Administrator', 'counselor' => 'Guidance Counselor', 'facilitator' => 'Guidance Facilitator', 'student' => 'Student'];
        $levelLabels = ['full' => 'Full Access', 'limited' => 'View Only', 'none' => 'No Access'];

        $actorKey = Rbac::roleKey($user);
        $current = loadRbac($pdo);
        $toApply = [];
        foreach ($changes as $c) {
            $module = is_array($c) ? (string) ($c['module'] ?? '') : '';
            $role = is_array($c) ? (string) ($c['role'] ?? '') : '';
            $level = is_array($c) ? (string) ($c['level'] ?? '') : '';
            if (!in_array($module, Rbac::modules(), true) || !in_array($role, RBAC_ROLES, true) || !in_array($level, RBAC_LEVELS, true)) {
                jsonResponse(['success' => false, 'error' => 'Invalid permission change.'], 400);
            }
            $from = $current[$module][$role];
            if ($level === $from) {
                continue; // nothing to change
            }
            // The server decides, not the page: anything other than the administrator changing the Guidance Counselor or Guidance
            // Facilitator column is refused, and nothing in the request is saved.
            if (!in_array($level, Rbac::editableLevels($actorKey, $module, $role, $current), true)) {
                jsonResponse(['success' => false, 'error' => 'You cannot set ' . $roleLabels[$role] . ' to ' . $levelLabels[$level] . ' for ' . $moduleLabels[$module] . '.'], 403);
            }
            $toApply["$module|$role"] = ['module' => $module, 'role' => $role, 'from' => $from, 'to' => $level];
        }

        if (!$toApply) {
            jsonResponse(['success' => true, 'applied' => 0, 'rbac' => $current, 'rbacEditable' => editableMatrix($user, $current)]);
        }

        // The needs rules (Rbac::NEEDS): a change may not leave a feature on while everything it needs is off. Only the
        // features this save touches (a changed feature, or one whose need was changed) are checked, so an older wrong
        // row elsewhere never blocks a save. Nothing is changed for the administrator: the page turns the pair on or off
        // before saving, and the server only refuses.
        $problems = [];
        foreach (array_unique(array_column($toApply, 'role')) as $role) {
            $final = [];
            foreach (Rbac::modules() as $m) {
                $final[$m] = $toApply["$m|$role"]['to'] ?? $current[$m][$role];
            }
            // An older View Only on an action counts as No Access already, so it never blocks turning its need off.
            foreach (Rbac::ACTION_ONLY as $m) {
                if ($final[$m] === 'limited') {
                    $final[$m] = 'none';
                }
            }
            foreach (Rbac::unmetNeeds($final) as $m => $needs) {
                $touched = isset($toApply["$m|$role"]) || array_filter($needs, fn($n) => isset($toApply["$n|$role"]));
                if ($touched) {
                    $problems[] = $moduleLabels[$m] . ' needs ' . implode(' or ', array_map(fn($n) => $moduleLabels[$n], $needs))
                        . ' (View Only or Full) for the ' . $roleLabels[$role] . '.';
                }
            }
        }
        if ($problems) {
            jsonResponse(['success' => false, 'error' => implode(' ', $problems)], 400);
        }

        // A change to account modules or to the Guidance Counselor column only goes through once it was confirmed.
        $sensitive = array_values(array_filter($toApply, fn($c) => Rbac::isSensitiveChange($c['module'], $c['role'])));
        if ($sensitive && empty($body['confirmed'])) {
            jsonResponse(['success' => false, 'needsConfirmation' => true, 'error' => 'Confirm the changes to account and staff permissions before saving.', 'sensitive' => $sensitive], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO security_rbac (module, role, access_level, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (module, role) DO UPDATE SET access_level = EXCLUDED.access_level, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $pdo->beginTransaction();
        try {
            foreach ($toApply as $c) {
                $stmt->execute([$c['module'], $c['role'], $c['to'], $user['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not save the permissions. Please try again.'], 500);
        }
        // One audit entry per change: who made it (the actor), the previous and the new value, and when (the entry's time).
        foreach ($toApply as $c) {
            AuditLogger::log(
                $user['id'], $user['role'], 'update_rbac', 'security_rbac', $c['module'] . ':' . $c['role'],
                $roleLabels[$c['role']] . ', ' . $moduleLabels[$c['module']] . ': ' . $levelLabels[$c['from']] . ' -> ' . $levelLabels[$c['to']]
                    . (Rbac::isSensitiveChange($c['module'], $c['role']) ? ' (sensitive, confirmed)' : '')
            );
        }
        $fresh = loadRbac($pdo);
        jsonResponse(['success' => true, 'applied' => count($toApply), 'rbac' => $fresh, 'rbacEditable' => editableMatrix($user, $fresh)]);
    }

    if ($type === 'policy') {
        $key = $body['key'] ?? '';
        if (!in_array($key, ['password', 'lockout', 'retention', 'flagging'], true)) {
            jsonResponse(['success' => false, 'error' => 'Unknown policy.'], 400);
        }

        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };
        // The values as they were (defaults included), for the audit log's before -> after.
        $before = loadPolicies($pdo)['policies'][$key];

        if ($key === 'password') {
            // No on/off for the password policy: only its rules change.
            $set('password.minLength', (string) max(PasswordPolicy::MIN_LENGTH_FLOOR, min(32, (int) ($body['minLength'] ?? 8))));
            $set('password.requireUpper', !empty($body['requireUpper']) ? 'true' : 'false');
            $set('password.requireLower', !empty($body['requireLower']) ? 'true' : 'false');
            $set('password.requireNumber', !empty($body['requireNumber']) ? 'true' : 'false');
            $set('password.requireSymbol', !empty($body['requireSymbol']) ? 'true' : 'false');
        } elseif ($key === 'lockout') {
            // Always on (it cannot be turned off); the numbers must be inside the limits, or nothing is saved.
            $attempts = (int) ($body['maxAttempts'] ?? 0);
            $minutes = (int) ($body['lockoutMinutes'] ?? 0);
            if ($attempts < LoginLock::ATTEMPTS_MIN || $attempts > LoginLock::ATTEMPTS_MAX) {
                jsonResponse(['success' => false, 'error' => 'Failed attempts must be from ' . LoginLock::ATTEMPTS_MIN . ' to ' . LoginLock::ATTEMPTS_MAX . '.'], 400);
            }
            if ($minutes < LoginLock::MINUTES_MIN || $minutes > LoginLock::MINUTES_MAX) {
                jsonResponse(['success' => false, 'error' => 'The lockout must last at least ' . LoginLock::MINUTES_MIN . ' minutes (at most ' . LoginLock::MINUTES_MAX . ').'], 400);
            }
            $set('lockout.enabled', 'true');
            $set('lockout.maxAttempts', (string) $attempts);
            $set('lockout.lockoutMinutes', (string) $minutes);
        } elseif ($key === 'flagging') {
            // Always on; a level at or below the lowest possible score would flag nobody, so it is refused and nothing is saved.
            $checked = MonitoringLevel::validate($body['level'] ?? null);
            if ($checked['error'] !== null) {
                jsonResponse(['success' => false, 'error' => $checked['error']], 400);
            }
            $set(MonitoringLevel::KEY, number_format($checked['level'], 3, '.', ''));
        } else {
            $enabled = !empty($body['enabled']) ? 'true' : 'false';
            $set(AuditRetention::ON_KEY, $enabled);
            $set(AuditRetention::DAYS_KEY, (string) AuditRetention::days($body['retentionDays'] ?? null));
            // Just turned on: nothing is deleted today, which leaves a day to export the log first.
            if (!$before['enabled'] && $enabled === 'true') {
                AuditRetention::startTomorrow($pdo);
            }
        }

        $policyNames = ['password' => 'Password policy', 'lockout' => 'Account lockout', 'monitoring' => 'Login monitoring', 'retention' => 'Audit log retention', 'flagging' => 'Low-similarity flag level'];
        $policyLabels = [
            'password' => [
                'enabled' => 'Status', 'minLength' => 'Minimum password length', 'requireUpper' => 'Needs an uppercase letter',
                'requireLower' => 'Needs a lowercase letter', 'requireNumber' => 'Needs a number', 'requireSymbol' => 'Needs a symbol',
            ],
            'lockout' => ['enabled' => 'Status', 'maxAttempts' => 'Failed attempts before lockout', 'lockoutMinutes' => 'Lockout length (minutes)'],
            'monitoring' => ['enabled' => 'Status'],
            'retention' => ['enabled' => 'Delete older logs automatically', 'retentionDays' => 'Keep entries for (days)'],
            'flagging' => ['level' => 'Cosine Similarity level'],
        ];
        $fresh = loadPolicies($pdo);
        AuditLogger::log($user['id'], $user['role'], 'update_policy', 'security_policies', $key, AuditChange::detail(
            $policyNames[$key], $before, $fresh['policies'][$key], $policyLabels[$key],
            ['requireUpper', 'requireLower', 'requireNumber', 'requireSymbol']
        ));
        jsonResponse(['success' => true] + $fresh);
    }

    if ($type === 'officeHours') {
        // Office hours are picked day by day (see modules/Counseling/lib/OfficeHours.php), never typed as text.
        $checked = OfficeHours::validate($body['schedule'] ?? null);
        if ($checked['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $checked['error']], 400);
        }
        $text = OfficeHours::text($checked['schedule']);
        $beforeText = loadPolicies($pdo)['officeHours']['text'];
        $stmt = $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $stmt->execute(['officeHours.schedule', json_encode($checked['schedule']), $user['id']]);
        $stmt->execute(['officeHours.text', $text, $user['id']]); // what api/public-settings.php sends to students

        AuditLogger::log($user['id'], $user['role'], 'update_office_hours', 'security_policies', 'officeHours.text',
            AuditChange::detail('Office hours', ['text' => $beforeText], ['text' => $text], ['text' => 'Office hours']));
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'bookingLink') {
        $checked = BookingLink::validate((string) ($body['url'] ?? ''));
        if ($checked['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $checked['error']], 400);
        }
        $beforeUrl = loadPolicies($pdo)['booking']['url'];
        $stmt = $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $stmt->execute([BookingLink::KEY, $checked['url'], $user['id']]);
        AuditLogger::log($user['id'], $user['role'], 'update_booking_link', 'security_policies', BookingLink::KEY,
            AuditChange::detail('Booking link', ['url' => $beforeUrl], ['url' => $checked['url']], ['url' => 'Booking link']));
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'settings') {
        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };
        // Only the timeout length changes here: email verification and the timeout itself are always on.
        $minutes = (int) ($body['timeoutMinutes'] ?? 0);
        if (!in_array($minutes, Auth::TIMEOUT_CHOICES, true)) {
            jsonResponse(['success' => false, 'error' => 'Choose a timeout of ' . implode(', ', Auth::TIMEOUT_CHOICES) . ' minutes.'], 400);
        }
        $beforeSettings = loadPolicies($pdo)['settings'];
        $set('twoFactor', 'true');
        $set('sessionTimeoutEnabled', 'true');
        $set('timeoutMinutes', (string) $minutes);

        $fresh = loadPolicies($pdo);
        AuditLogger::log($user['id'], $user['role'], 'update_settings', 'security_policies', 'settings', AuditChange::detail(
            'Sign-in and session settings', $beforeSettings, $fresh['settings'],
            ['twoFactor' => 'Email verification for password changes', 'sessionTimeoutEnabled' => 'Session timeout', 'timeoutMinutes' => 'Timeout length (minutes)']
        ));
        jsonResponse(['success' => true] + $fresh);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
