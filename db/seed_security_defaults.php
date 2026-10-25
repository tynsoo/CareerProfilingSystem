<?php
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$pdo = Database::get();

// Matches security-configuration.html's current DEFAULT_STATE.rbac exactly.
$rbac = [
    // Programs and their RIASEC codes (Career Dataset): Guidance Counselors edit them; Facilitators view only.
    'career'          => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'rac'             => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'recommendations' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'full'],
    'counselor'       => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'monitoring'      => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'announcements'   => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    'examinations'    => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'counselingNotes' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    // limited = add sections; full = add and deactivate/reactivate them.
    'sections'        => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    // Approve staff sign-ups and send access codes: the administrator and the Guidance Counselors (never Facilitators).
    'staffAccounts'   => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
];

$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
$rbacCount = 0;
foreach ($rbac as $module => $roles) {
    // A Guidance Facilitator starts where the Guidance Counselor is, except Sections (View Only) and Staff Accounts
    // (No Access); the administrator can change any of it in Security Configuration.
    $roles['facilitator'] = Rbac::facilitatorDefault($module, $roles['counselor']);
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
    'monitoring.retentionDays'           => '365',
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
