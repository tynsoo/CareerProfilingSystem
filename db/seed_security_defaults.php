<?php
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$pdo = Database::get();

// The permission table's defaults (Rbac::DEFAULTS: one row per feature, grouped by sidebar page). The administrator always
// has Full; a Student holds only Rbac::STUDENT_MODULES. The administrator can change the staff columns in Security Configuration.
$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
$rbacCount = 0;
foreach (Rbac::DEFAULTS as $module => $defaults) {
    $roles = [
        'admin' => 'full',
        'counselor' => $defaults['counselor'],
        'facilitator' => $defaults['facilitator'],
        'student' => in_array($module, Rbac::STUDENT_MODULES, true) ? 'full' : 'none',
    ];
    foreach ($roles as $role => $level) {
        $rbacStmt->execute([$module, $role, $level]);
        $rbacCount++;
    }
}

// Matches DEFAULT_STATE.policies/settings, flattened to dot-notation keys,
// plus monitoring.lowConfidenceThreshold, the level of the low-similarity monitoring flag (MonitoringLevel::DEFAULT;
// db/migrate_monitoring_level.php moves an older database off the old 0.50).
require_once __DIR__ . '/../modules/Monitoring/lib/MonitoringLevel.php';
$policies = [
    'password.enabled'                   => 'true',
    'password.minLength'                 => '8',
    'password.requireUpper'              => 'true',
    'password.requireLower'              => 'true',
    'password.requireNumber'             => 'true',
    'password.requireSymbol'             => 'true',
    'lockout.enabled'                    => 'true',
    'lockout.maxAttempts'                => '5',
    'lockout.lockoutMinutes'             => '15',
    'monitoring.enabled'                 => 'true',
    'monitoring.retentionDays'           => '365',
    MonitoringLevel::KEY                 => (string) MonitoringLevel::DEFAULT,
    'twoFactor'                          => 'true',
    'sessionTimeoutEnabled'              => 'true',
    'timeoutMinutes'                     => '30',
];

$policyStmt = $pdo->prepare(
    'INSERT INTO security_policies (key, value) VALUES (?, ?)
     ON CONFLICT (key) DO NOTHING'
);
foreach ($policies as $key => $value) {
    $policyStmt->execute([$key, $value]);
}

echo "RBAC rows seeded: $rbacCount\n";
echo "Policy rows seeded: " . count($policies) . "\n";
