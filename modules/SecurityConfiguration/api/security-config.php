<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../../Counseling/lib/OfficeHours.php';
require_once __DIR__ . '/../../Counseling/lib/BookingLink.php';
require_once __DIR__ . '/../../Authentication/lib/PasswordPolicy.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditChange.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditRetention.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();

// 'announcements' was added to modules/Authentication/lib/Rbac.php's MODULES and to
// security-configuration.html's UI but this const was never updated to
// match — meaning loadRbac() silently omitted that row and any RBAC change
// posted for it was silently dropped by the in_array() guard below. Fixed
// here alongside adding 'examinations' the same way.
const RBAC_MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes', 'sections', 'staffAccounts'];
const RBAC_ROLES = ['admin', 'counselor', 'facilitator', 'student'];
const RBAC_LEVELS = ['full', 'limited', 'none'];

function loadRbac(PDO $pdo): array
{
    $rows = $pdo->query('SELECT module, role, access_level FROM security_rbac')->fetchAll();
    $rbac = [];
    $seenFacilitator = [];
    foreach (RBAC_MODULES as $m) {
        $rbac[$m] = array_fill_keys(RBAC_ROLES, 'none');
    }
    foreach ($rows as $r) {
        if (isset($rbac[$r['module']]) && in_array($r['role'], RBAC_ROLES, true)) {
            $rbac[$r['module']][$r['role']] = $r['access_level'];
            if ($r['role'] === 'facilitator') {
                $seenFacilitator[$r['module']] = true;
            }
        }
    }
    // A database from before the Facilitator column: show what Rbac::accessLevel() will actually apply.
    foreach (RBAC_MODULES as $m) {
        if (!isset($seenFacilitator[$m])) {
            $rbac[$m]['facilitator'] = Rbac::facilitatorDefault($m, $rbac[$m]['counselor']);
        }
    }
    // A Student holds only what Rbac::STUDENT_MODULES allows; show what is enforced.
    foreach (RBAC_MODULES as $m) {
        if (!in_array($m, Rbac::STUDENT_MODULES, true)) {
            $rbac[$m]['student'] = 'none';
        }
    }
    return $rbac;
}

/** Which levels the signed-in person may set for each cell, so the page offers only what the server will accept. */
function editableMatrix(array $user, array $rbac): array
{
    $out = [];
    foreach (RBAC_MODULES as $m) {
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
            'lockout' => [
                'enabled' => $b('lockout.enabled', true), 'maxAttempts' => $i('lockout.maxAttempts', 5),
                'lockoutMinutes' => $i('lockout.lockoutMinutes', 15),
            ],
            // Its switch is "Delete older logs automatically" (modules/AuditLog/lib/AuditRetention.php), off unless turned on.
            'retention' => [
                'enabled' => $b(AuditRetention::ON_KEY, false), 'retentionDays' => AuditRetention::days($rows[AuditRetention::DAYS_KEY] ?? null),
            ],
        ],
        'settings' => [
            'twoFactor' => $b('twoFactor', true),
            'sessionTimeoutEnabled' => $b('sessionTimeoutEnabled', true),
            'timeoutMinutes' => $i('timeoutMinutes', 30),
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // The daily cleanup's own markers are not settings changes, so they do not count as updates.
    $lastUpdatedStmt = $pdo->prepare(
        'SELECT GREATEST(
            (SELECT MAX(updated_at) FROM security_rbac),
            (SELECT MAX(updated_at) FROM security_policies WHERE key NOT IN (?, ?))
         )'
    );
    $lastUpdatedStmt->execute([AuditRetention::LAST_RUN_KEY, AuditChain::ANCHOR_KEY]);
    $lastUpdated = $lastUpdatedStmt->fetchColumn();

    // Security Overview card: each number goes only to someone who can open the page it links to
    // (null otherwise, and the page leaves that tile out). Sign-in numbers -> Audit Log (Full on 'rac',
    // as in api/audit-log.php); flags waiting -> Monitoring (any access, everyone on the team sees all flags).
    $roleKey = Rbac::roleKey($user);
    $seesAuditLog = Rbac::accessLevel('rac', $roleKey) === 'full';
    $seesMonitoring = Rbac::accessLevel('monitoring', $roleKey) !== 'none';
    $lockouts7d = $sharedLogins7d = $pendingFlags = null;
    if ($seesAuditLog) {
        // A lockout is logged (api/login.php) the moment an account reaches the maximum failed attempts,
        // so this counts accounts that were actually locked out, not every wrong password.
        $lockouts7d = (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login_lockout' AND created_at >= NOW() - INTERVAL '7 days'"
        )->fetchColumn();
        $sharedLogins7d = (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login_shared_suspected' AND created_at >= NOW() - INTERVAL '7 days'"
        )->fetchColumn();
    }
    if ($seesMonitoring) {
        $pendingFlags = (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags WHERE status = 'pending'")->fetchColumn();
    }

    $rbacNow = loadRbac($pdo);
    // The full permission table is for the administrator, who is the only one who can change it. Other staff are sent only
    // their own column ("Your Access" on the page; the other pages need it to know what they may do), never the other
    // roles' permissions: need to know, as with the full IP addresses in the Audit Log.
    $isAdmin = $user['role'] === 'admin';
    $ownRole = Rbac::roleKey($user);
    $rbacShown = $isAdmin ? $rbacNow : array_map(fn($levels) => [$ownRole => $levels[$ownRole] ?? 'none'], $rbacNow);
    jsonResponse([
        'rbac' => $rbacShown,
        // Non-administrators see "Your Access" (their own column, view only) instead of the whole table.
        'rbacOwnOnly' => $isAdmin ? null : $ownRole,
        // what this person may change in the table (empty for a fixed cell), and which changes ask for a confirmation
        'rbacEditable' => $isAdmin ? editableMatrix($user, $rbacNow) : (object) [],
        'rbacSensitive' => ['modules' => Rbac::SENSITIVE_MODULES, 'roles' => ['counselor']],
        // Uploading class rosters: administrator or Guidance Counselor with Full access to the roster module.
        'canEditRoster' => Rbac::accessLevel('rac', Rbac::roleKey($user)) === 'full'
            && (Rbac::roleKey($user) !== 'facilitator' || Rbac::accessLevel('sections', 'facilitator') === 'full'),
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

    // The Guidance Office hours and the counseling booking link are kept up to date by whoever holds Full on Counseling Notes
    // (set in the permission table). Everything else here, the permission table included, is the administrator's.
    if (in_array($type, ['officeHours', 'bookingLink'], true)) {
        $user = Rbac::requireAccess('counselingNotes', 'full');
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
        $moduleLabels = [
            'career' => 'Career Profile Input', 'rac' => 'RIASEC Assessment', 'recommendations' => 'Recommendations', 'counselor' => 'Guidance Counselor accounts',
            'monitoring' => 'Student Monitoring', 'announcements' => 'Announcements', 'examinations' => 'Assessment Scheduling',
            'counselingNotes' => 'Counseling Notes', 'staffAccounts' => 'Staff Accounts', 'sections' => 'Section Management',
        ];
        $roleLabels = ['admin' => 'Administrator', 'counselor' => 'Guidance Counselor', 'facilitator' => 'Guidance Facilitator', 'student' => 'Student'];
        $levelLabels = ['full' => 'Full Access', 'limited' => 'View Only', 'none' => 'No Access'];

        $actorKey = Rbac::roleKey($user);
        $current = loadRbac($pdo);
        $toApply = [];
        foreach ($changes as $c) {
            $module = is_array($c) ? (string) ($c['module'] ?? '') : '';
            $role = is_array($c) ? (string) ($c['role'] ?? '') : '';
            $level = is_array($c) ? (string) ($c['level'] ?? '') : '';
            if (!in_array($module, RBAC_MODULES, true) || !in_array($role, RBAC_ROLES, true) || !in_array($level, RBAC_LEVELS, true)) {
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
        if (!in_array($key, ['password', 'lockout', 'retention'], true)) {
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
            $set('lockout.enabled', !empty($body['enabled']) ? 'true' : 'false');
            $set('lockout.maxAttempts', (string) max(1, min(20, (int) ($body['maxAttempts'] ?? 5))));
            $set('lockout.lockoutMinutes', (string) max(1, min(1440, (int) ($body['lockoutMinutes'] ?? 15))));
        } else {
            $enabled = !empty($body['enabled']) ? 'true' : 'false';
            $set(AuditRetention::ON_KEY, $enabled);
            $set(AuditRetention::DAYS_KEY, (string) AuditRetention::days($body['retentionDays'] ?? null));
            // Just turned on: nothing is deleted today, which leaves a day to export the log first.
            if (!$before['enabled'] && $enabled === 'true') {
                AuditRetention::startTomorrow($pdo);
            }
        }

        $policyNames = ['password' => 'Password policy', 'lockout' => 'Account lockout', 'monitoring' => 'Login monitoring', 'retention' => 'Audit log retention'];
        $policyLabels = [
            'password' => [
                'enabled' => 'Status', 'minLength' => 'Minimum password length', 'requireUpper' => 'Needs an uppercase letter',
                'requireLower' => 'Needs a lowercase letter', 'requireNumber' => 'Needs a number', 'requireSymbol' => 'Needs a symbol',
            ],
            'lockout' => ['enabled' => 'Status', 'maxAttempts' => 'Failed attempts before lockout', 'lockoutMinutes' => 'Lockout length (minutes)'],
            'monitoring' => ['enabled' => 'Status'],
            'retention' => ['enabled' => 'Delete older logs automatically', 'retentionDays' => 'Keep entries for (days)'],
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
        $beforeSettings = loadPolicies($pdo)['settings'];
        $set('twoFactor', !empty($body['twoFactor']) ? 'true' : 'false');
        $set('sessionTimeoutEnabled', !empty($body['sessionTimeoutEnabled']) ? 'true' : 'false');
        $set('timeoutMinutes', (string) max(5, min(240, (int) ($body['timeoutMinutes'] ?? 30))));

        $fresh = loadPolicies($pdo);
        AuditLogger::log($user['id'], $user['role'], 'update_settings', 'security_policies', 'settings', AuditChange::detail(
            'Sign-in and session settings', $beforeSettings, $fresh['settings'],
            ['twoFactor' => 'Two-step sign-in', 'sessionTimeoutEnabled' => 'Session timeout', 'timeoutMinutes' => 'Timeout length (minutes)']
        ));
        jsonResponse(['success' => true] + $fresh);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
