<?php

require_once __DIR__ . '/../lib/Rbac.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

echo "=== Rbac::accessLevel against the real seeded security_rbac table ===\n";

$expected = [
    'career'          => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    'rac'             => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'recommendations' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'full'],
    'counselor'       => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'monitoring'      => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
];

foreach ($expected as $module => $roles) {
    foreach ($roles as $role => $level) {
        check("$module/$role == $level", Rbac::accessLevel($module, $role) === $level);
    }
}

check(
    'Unknown role for a valid module falls back to none',
    Rbac::accessLevel('career', 'nonexistent_role') === 'none'
);

try {
    Rbac::accessLevel('not_a_real_module', 'admin');
    check('Unknown module throws', false);
} catch (InvalidArgumentException $e) {
    check('Unknown module throws', true);
}

echo "\n=== AuditLogger round-trip ===\n";

$pdo = Database::get();
$marker = 'test-marker-' . bin2hex(random_bytes(4));
AuditLogger::log(1, 'admin', 'test_action', 'test_target', 'target-123', $marker);

$stmt = $pdo->prepare("SELECT * FROM audit_log WHERE action = 'test_action' AND target_id = 'target-123' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$row = $stmt->fetch();

check('Audit row was written', $row !== false);
if ($row) {
    check('actor_user_id stored correctly', (int) $row['actor_user_id'] === 1);
    check('actor_role stored correctly', $row['actor_role'] === 'admin');
    check('target_type stored correctly', $row['target_type'] === 'test_target');
    check('detail_enc decrypts to the original marker', Crypto::dec($row['detail_enc']) === $marker);
    check('detail_enc is not the plaintext marker (actually encrypted)', $row['detail_enc'] !== $marker);
    // clean up the test row so it doesn't pollute the real audit trail
    $pdo->prepare('DELETE FROM audit_log WHERE id = ?')->execute([$row['id']]);
}

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
