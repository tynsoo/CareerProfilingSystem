<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
    jsonResponse(['error' => 'Forbidden'], 403);
}
$pdo = Database::get();

const RBAC_MODULES = ['career', 'rac', 'recommendations', 'counselor', 'monitoring'];
const RBAC_ROLES = ['admin', 'counselor', 'student'];
const RBAC_LEVELS = ['full', 'limited', 'none'];

function loadRbac(PDO $pdo): array
{
    $rows = $pdo->query('SELECT module, role, access_level FROM security_rbac')->fetchAll();
    $rbac = [];
    foreach (RBAC_MODULES as $m) {
        $rbac[$m] = ['admin' => 'none', 'counselor' => 'none', 'student' => 'none'];
    }
    foreach ($rows as $r) {
        if (isset($rbac[$r['module']])) {
            $rbac[$r['module']][$r['role']] = $r['access_level'];
        }
    }
    return $rbac;
}

function loadPolicies(PDO $pdo): array
{
    $rows = $pdo->query('SELECT key, value FROM security_policies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $b = fn($k, $d) => isset($rows[$k]) ? $rows[$k] === 'true' : $d;
    $i = fn($k, $d) => isset($rows[$k]) ? (int) $rows[$k] : $d;
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
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lastUpdated = $pdo->query(
        'SELECT GREATEST(
            (SELECT MAX(updated_at) FROM security_rbac),
            (SELECT MAX(updated_at) FROM security_policies)
         )'
    )->fetchColumn();

    $failedLogins7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $activeUsersToday = (int) $pdo->query(
        "SELECT COUNT(DISTINCT actor_user_id) FROM audit_log WHERE created_at::date = CURRENT_DATE"
    )->fetchColumn();
    $pendingFlags = (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags WHERE status = 'pending'")->fetchColumn();
    $encryptedRecords = (int) $pdo->query(
        'SELECT (SELECT COUNT(*) FROM students) + (SELECT COUNT(*) FROM programs) + (SELECT COUNT(*) FROM assessment_questions)'
    )->fetchColumn();

    jsonResponse([
        'rbac' => loadRbac($pdo),
        'lastUpdated' => $lastUpdated,
        'overview' => [
            'failedLogins7d' => $failedLogins7d,
            'activeUsersToday' => $activeUsersToday,
            'pendingFlags' => $pendingFlags,
            'encryptedRecords' => $encryptedRecords,
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
            if ($role === 'admin') {
                $skippedAdmin = true;
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
