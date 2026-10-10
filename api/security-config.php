<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AcademicYear.php';
require_once __DIR__ . '/../lib/OfficeHours.php';
require_once __DIR__ . '/../lib/BookingLink.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();

// 'announcements' was added to lib/Rbac.php's MODULES and to
// security-configuration.html's UI but this const was never updated to
// match — meaning loadRbac() silently omitted that row and any RBAC change
// posted for it was silently dropped by the in_array() guard below. Fixed
// here alongside adding 'examinations' the same way.
const RBAC_MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes', 'sections'];
const RBAC_ROLES = ['admin', 'counselor', 'student'];
const RBAC_LEVELS = ['full', 'limited', 'none'];

function loadRbac(PDO $pdo, bool $viewerIsFacilitator = false): array
{
    $rows = $pdo->query('SELECT module, role, access_level FROM security_rbac')->fetchAll();
    $rbac = [];
    foreach (RBAC_MODULES as $m) {
        $rbac[$m] = ['admin' => 'none', 'counselor' => 'none', 'student' => 'none'];
    }
    foreach ($rows as $r) {
        if (isset($rbac[$r['module']])) {
            $level = $r['access_level'];
            // A Guidance Facilitator is view-only for these (lib/Rbac.php): show them that, so the pages hide the write controls.
            if ($viewerIsFacilitator && $r['role'] === 'counselor' && $level === 'full' && in_array($r['module'], Rbac::FACILITATOR_VIEW_ONLY, true)) {
                $level = 'limited';
            }
            if ($viewerIsFacilitator && $r['role'] === 'counselor' && in_array($r['module'], Rbac::FACILITATOR_NO_ACCESS, true)) {
                $level = 'none';
            }
            $rbac[$r['module']][$r['role']] = $level;
        }
    }
    return $rbac;
}

function loadPolicies(PDO $pdo): array
{
    $rows = $pdo->query('SELECT key, value FROM security_policies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b = fn($k, $d) => isset($rows[$k]) ? $rows[$k] === 'true' : $d;
    $i = fn($k, $d) => isset($rows[$k]) ? (int) $rows[$k] : $d;
    $s = fn($k, $d) => $rows[$k] ?? $d;
    return [
        'policies' => [
            'password' => [
                'enabled' => $b('password.enabled', true), 'minLength' => $i('password.minLength', 8),
                'requireUpper' => $b('password.requireUpper', true), 'requireLower' => $b('password.requireLower', true),
                'requireNumber' => $b('password.requireNumber', true), 'requireSymbol' => $b('password.requireSymbol', true),
            ],
            'lockout' => [
                'enabled' => $b('lockout.enabled', true), 'maxAttempts' => $i('lockout.maxAttempts', 5),
                'lockoutMinutes' => $i('lockout.lockoutMinutes', 15),
            ],
            'monitoring' => [
                'enabled' => $b('monitoring.enabled', true), 'retentionDays' => $i('monitoring.retentionDays', 90),
            ],
        ],
        'settings' => [
            'twoFactor' => $b('twoFactor', true),
            'sessionTimeoutEnabled' => $b('sessionTimeoutEnabled', true),
            'timeoutMinutes' => $i('timeoutMinutes', 30),
        ],
        // Detected from today's date (lib/AcademicYear.php) — read-only here.
        'academicYear' => [
            'current' => AcademicYear::current(),
        ],
        // Chosen day by day; 	ext is the sentence built from it that students read.
        'officeHours' => [
            'text' => $s('officeHours.text', 'Mon–Fri, 8:00 AM–5:00 PM'),
            'schedule' => OfficeHours::fromJson($s('officeHours.schedule', '')),
        ],
        'booking' => ['url' => $s('booking.url', '')],
        'principal' => [
            'name' => $s('principal.name', ''),
            'email' => $s('principal.email', ''),
            'lastSentAt' => $s('principal.lastSentAt', ''),
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lastUpdated = $pdo->query(
        'SELECT GREATEST(
            (SELECT MAX(updated_at) FROM security_rbac),
            (SELECT MAX(updated_at) FROM security_policies)
         )'
    )->fetchColumn();

    // A lockout is logged (api/login.php) the moment an account reaches the maximum failed attempts,
    // so this counts accounts that were actually locked out, not every wrong password.
    $lockouts7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_lockout' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $sharedLogins7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_shared_suspected' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $activeUsersToday = (int) $pdo->query(
        "SELECT COUNT(DISTINCT actor_user_id) FROM audit_log WHERE created_at::date = CURRENT_DATE"
    )->fetchColumn();
    $pendingFlags = (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags WHERE status = 'pending'")->fetchColumn();

    jsonResponse([
        'rbac' => loadRbac($pdo, Rbac::isFacilitator($user)),
        // Uploading class rosters: administrator or Guidance Counselor with Full access to the roster module.
        'canEditRoster' => Rbac::accessLevel('rac', $user['role']) === 'full' && !Rbac::isFacilitator($user),
        'lastUpdated' => $lastUpdated,
        'overview' => [
            'lockouts7d' => $lockouts7d,
            'sharedLogins7d' => $sharedLogins7d,
            'activeUsersToday' => $activeUsersToday,
            'pendingFlags' => $pendingFlags,
        ],
    ] + loadPolicies($pdo));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Only administrators can change security configuration.'], 403);
    }

    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'rbac') {
        $changes = $body['changes'] ?? [];
        if (!is_array($changes)) {
            jsonResponse(['success' => false, 'error' => 'Invalid changes payload.'], 400);
        }

        $skippedAdmin = false;
        $stmt = $pdo->prepare(
            'UPDATE security_rbac SET access_level = ?, updated_at = NOW(), updated_by = ? WHERE module = ? AND role = ?'
        );
        foreach ($changes as $c) {
            $module = $c['module'] ?? '';
            $role = $c['role'] ?? '';
            $level = $c['level'] ?? '';
            if (!in_array($module, RBAC_MODULES, true) || !in_array($role, RBAC_ROLES, true) || !in_array($level, RBAC_LEVELS, true)) {
                continue;
            }
            // Self-lockout guard: the admin role's access is never editable through this UI —
            // there is no recovery path if an admin accidentally revokes their own access.
            // The student role is fixed too: students only ever need the assessment, their
            // results and recommendations, counseling and announcements, so it isn't configurable.
            if ($role === 'admin' || $role === 'student') {
                $skippedAdmin = $skippedAdmin || $role === 'admin';
                continue;
            }
            $stmt->execute([$level, $user['id'], $module, $role]);
        }

        AuditLogger::log($user['id'], $user['role'], 'update_rbac', 'security_rbac', null, json_encode($changes));
        jsonResponse(['success' => true, 'skippedAdminChanges' => $skippedAdmin, 'rbac' => loadRbac($pdo)]);
    }

    if ($type === 'policy') {
        $key = $body['key'] ?? '';
        if (!in_array($key, ['password', 'lockout', 'monitoring'], true)) {
            jsonResponse(['success' => false, 'error' => 'Unknown policy.'], 400);
        }

        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };

        $enabled = !empty($body['enabled']) ? 'true' : 'false';
        $set("$key.enabled", $enabled);

        if ($key === 'password') {
            $set('password.minLength', (string) max(6, min(32, (int) ($body['minLength'] ?? 8))));
            $set('password.requireUpper', !empty($body['requireUpper']) ? 'true' : 'false');
            $set('password.requireLower', !empty($body['requireLower']) ? 'true' : 'false');
            $set('password.requireNumber', !empty($body['requireNumber']) ? 'true' : 'false');
            $set('password.requireSymbol', !empty($body['requireSymbol']) ? 'true' : 'false');
        } elseif ($key === 'lockout') {
            $set('lockout.maxAttempts', (string) max(1, min(20, (int) ($body['maxAttempts'] ?? 5))));
            $set('lockout.lockoutMinutes', (string) max(1, min(1440, (int) ($body['lockoutMinutes'] ?? 15))));
        } else {
            $set('monitoring.retentionDays', (string) max(7, min(365, (int) ($body['retentionDays'] ?? 90))));
        }

        AuditLogger::log($user['id'], $user['role'], 'update_policy', 'security_policies', $key, json_encode($body));
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'officeHours') {
        // Office hours are picked day by day (see lib/OfficeHours.php), never typed as text.
        $checked = OfficeHours::validate($body['schedule'] ?? null);
        if ($checked['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $checked['error']], 400);
        }
        $text = OfficeHours::text($checked['schedule']);
        $stmt = $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $stmt->execute(['officeHours.schedule', json_encode($checked['schedule']), $user['id']]);
        $stmt->execute(['officeHours.text', $text, $user['id']]); // what api/public-settings.php sends to students

        AuditLogger::log($user['id'], $user['role'], 'update_office_hours', 'security_policies', 'officeHours.text', "Set to: $text");
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'bookingLink') {
        $checked = BookingLink::validate((string) ($body['url'] ?? ''));
        if ($checked['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $checked['error']], 400);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
        );
        $stmt->execute([BookingLink::KEY, $checked['url'], $user['id']]);
        AuditLogger::log($user['id'], $user['role'], 'update_booking_link', 'security_policies', BookingLink::KEY, $checked['url'] === '' ? 'Cleared' : 'Set to: ' . $checked['url']);
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    if ($type === 'principalContact') {
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            jsonResponse(['success' => false, 'error' => 'Principal name must be 1-100 characters.'], 400);
        }
        if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
        }
        $set = function (string $k, string $v) use ($pdo, $user) {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
            );
            $stmt->execute([$k, $v, $user['id']]);
        };
        $set('principal.name', $name);
        $set('principal.email', $email);

        AuditLogger::log($user['id'], $user['role'], 'update_principal_contact', 'security_policies', 'principal', "Set to: $name <$email>");
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
        $set('twoFactor', !empty($body['twoFactor']) ? 'true' : 'false');
        $set('sessionTimeoutEnabled', !empty($body['sessionTimeoutEnabled']) ? 'true' : 'false');
        $set('timeoutMinutes', (string) max(5, min(240, (int) ($body['timeoutMinutes'] ?? 30))));

        AuditLogger::log($user['id'], $user['role'], 'update_settings', 'security_policies', 'settings', json_encode($body));
        jsonResponse(['success' => true] + loadPolicies($pdo));
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
