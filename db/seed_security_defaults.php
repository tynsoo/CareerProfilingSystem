<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

// Matches security-configuration.html's current DEFAULT_STATE.rbac exactly.
$rbac = [
    'career'          => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    'rac'             => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'recommendations' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'full'],
    'counselor'       => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'monitoring'      => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
];

$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
$rbacCount = 0;
foreach ($rbac as $module => $roles) {
    foreach ($roles as $role => $level) {
        $rbacStmt->execute([$module, $role, $level]);
        $rbacCount++;
    }
}

// Matches DEFAULT_STATE.policies/settings, flattened to dot-notation keys,
// plus the new monitoring.lowConfidenceThreshold used by the CBF monitoring flag.
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
    'monitoring.retentionDays'           => '90',
    'monitoring.lowConfidenceThreshold'  => '0.50',
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
