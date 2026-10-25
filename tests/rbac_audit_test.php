<?php

require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';
require_once __DIR__ . '/../modules/AuditLog/lib/AuditLogger.php';
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';

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
    // Guidance Counselors add and edit programs and RIASEC codes (db/migrate_counselor_career_access.php).
    'career'          => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'rac'             => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'recommendations' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'full'],
    'counselor'       => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'monitoring'      => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    // Seeded defaults (db/seed_security_defaults.php): only the Administrator posts announcements; counselors view them and sections.
    'announcements'   => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    'examinations'    => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'counselingNotes' => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
    'sections'        => ['admin' => 'full', 'counselor' => 'limited', 'student' => 'none'],
    'staffAccounts'   => ['admin' => 'full', 'counselor' => 'full',    'student' => 'none'],
];

foreach ($expected as $module => $roles) {
    foreach ($roles as $role => $level) {
        check("$module/$role == $level", Rbac::accessLevel($module, $role) === $level);
    }
}

echo "\n=== a student has no access to sending announcements ===\n";
$pdoCap = Database::get();
$pdoCap->beginTransaction();
$pdoCap->prepare("UPDATE security_rbac SET access_level = 'full' WHERE module = 'announcements' AND role = 'student'")->execute();
check('even if the table is tampered to Full, a student has no access to announcements', Rbac::accessLevel('announcements', 'student') === 'none');
check('the cap is only for students: the counselor row is untouched', Rbac::accessLevel('announcements', 'counselor') === 'limited');
$pdoCap->rollBack();
check('the real student row is No Access', Rbac::accessLevel('announcements', 'student') === 'none');
check('a student is still not cut off from other modules (Recommendations stays Full)', Rbac::accessLevel('recommendations', 'student') === 'full');

echo "\n=== Guidance Facilitators have a column of their own ===\n";
$facilitator = [
    'career' => 'limited', 'rac' => 'full', 'recommendations' => 'full', 'counselor' => 'full', 'monitoring' => 'full',
    // counselingNotes is left out: the administrator sets it in the table (Full by default for a new database).
    'announcements' => 'limited', 'examinations' => 'full', 'sections' => 'limited', 'staffAccounts' => 'none',
];
foreach ($facilitator as $module => $level) {
    check("$module/facilitator == $level", Rbac::accessLevel($module, 'facilitator') === $level);
}
check('the matrix has four columns', Rbac::ROLES === ['admin', 'counselor', 'facilitator', 'student']);
check('facilitator defaults follow the counselor, capped on sections and staff accounts (counseling notes: same as the counselor)',
    Rbac::facilitatorDefault('sections', 'full') === 'limited' && Rbac::facilitatorDefault('sections', 'none') === 'none'
    && Rbac::facilitatorDefault('counselingNotes', 'full') === 'full' && Rbac::facilitatorDefault('staffAccounts', 'limited') === 'none'
    && Rbac::facilitatorDefault('announcements', 'full') === 'full' && Rbac::facilitatorDefault('career', 'limited') === 'limited'
    && Rbac::facilitatorDefault('career', 'full') === 'limited');
$pdoFac = Database::get();
$pdoFac->beginTransaction();
$pdoFac->exec("DELETE FROM security_rbac WHERE role = 'facilitator'");
check('with no facilitator rows (an older database) the level is derived from the counselor: sections View Only', Rbac::accessLevel('sections', 'facilitator') === 'limited');
check('...and counseling notes as the counselor has them', Rbac::accessLevel('counselingNotes', 'facilitator') === Rbac::accessLevel('counselingNotes', 'counselor'));
check('...and announcements as the counselor has them', Rbac::accessLevel('announcements', 'facilitator') === Rbac::accessLevel('announcements', 'counselor'));
$pdoFac->rollBack();
check('the Facilitator column can be changed independently of the counselor', (function () use ($pdoFac) {
    $pdoFac->beginTransaction();
    $pdoFac->exec("UPDATE security_rbac SET access_level = 'full' WHERE module = 'sections' AND role = 'facilitator'");
    $ok = Rbac::accessLevel('sections', 'facilitator') === 'full' && Rbac::accessLevel('sections', 'counselor') === Rbac::accessLevel('sections', 'counselor');
    $pdoFac->rollBack();
    return $ok;
})());check('a student is never a facilitator', Rbac::isFacilitator(['id' => 0, 'role' => 'student']) === false);
check('the administrator is never a facilitator', Rbac::isFacilitator(['id' => 1, 'role' => 'admin']) === false);

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


echo "\n=== who may change which permission ===\n";
$matrix = [];
foreach (['career', 'rac', 'recommendations', 'counselor', 'monitoring', 'announcements', 'examinations', 'counselingNotes', 'staffAccounts', 'sections'] as $m) {
    $matrix[$m] = ['admin' => 'full', 'counselor' => 'full', 'facilitator' => 'limited', 'student' => 'none'];
}
$matrix['career']['counselor'] = 'limited';
check('the administrator can set the counselor and facilitator columns to any level',
    Rbac::editableLevels('admin', 'career', 'counselor', $matrix) === ['full', 'limited', 'none'] && Rbac::editableLevels('admin', 'sections', 'facilitator', $matrix) === ['full', 'limited', 'none']);
check('nobody can change the administrator column', Rbac::editableLevels('admin', 'career', 'admin', $matrix) === [] && Rbac::editableLevels('counselor', 'career', 'admin', $matrix) === []);
check('nobody can change the student column', Rbac::editableLevels('admin', 'career', 'student', $matrix) === [] && Rbac::editableLevels('counselor', 'staffAccounts', 'student', $matrix) === []);
check('only the administrator changes permissions: a counselor cannot set the facilitator column', Rbac::editableLevels('counselor', 'announcements', 'facilitator', $matrix) === [] && Rbac::editableLevels('counselor', 'career', 'facilitator', $matrix) === []);
check('a counselor cannot change their own column', Rbac::editableLevels('counselor', 'announcements', 'counselor', $matrix) === []);
check('a counselor cannot change the account modules for anyone', Rbac::editableLevels('counselor', 'staffAccounts', 'facilitator', $matrix) === [] && Rbac::editableLevels('counselor', 'counselor', 'facilitator', $matrix) === []);
check('a facilitator and a student change nothing', Rbac::editableLevels('facilitator', 'career', 'facilitator', $matrix) === [] && Rbac::editableLevels('student', 'career', 'counselor', $matrix) === []);
check('an unknown module is not editable', Rbac::editableLevels('admin', 'nope', 'counselor', $matrix) === []);

echo "\n=== sensitive changes ask for a confirmation ===\n";
check('account modules are sensitive', Rbac::isSensitiveChange('staffAccounts', 'facilitator') && Rbac::isSensitiveChange('counselor', 'facilitator'));
check('anything in the Guidance Counselor column is sensitive', Rbac::isSensitiveChange('career', 'counselor'));
check('an everyday module for the facilitator column is not', !Rbac::isSensitiveChange('announcements', 'facilitator'));

echo "\n=== a Student can only ever hold their own recommendations ===\n";
$pdoStu = Database::get();
$pdoStu->beginTransaction();
$pdoStu->exec("INSERT INTO security_rbac (module, role, access_level) VALUES ('staffAccounts', 'student', 'full') ON CONFLICT (module, role) DO UPDATE SET access_level = 'full'");
$pdoStu->exec("UPDATE security_rbac SET access_level = 'full' WHERE module IN ('counselor', 'sections', 'monitoring') AND role = 'student'");
check('even if the table is tampered with, a student has no access to staff accounts, sections or monitoring',
    Rbac::accessLevel('staffAccounts', 'student') === 'none' && Rbac::accessLevel('sections', 'student') === 'none' && Rbac::accessLevel('monitoring', 'student') === 'none' && Rbac::accessLevel('counselor', 'student') === 'none');
check('a student keeps Recommendations', Rbac::accessLevel('recommendations', 'student') === 'full');
$pdoStu->rollBack();
echo "\n=== AuditLogger round-trip ===\n";

$pdo = Database::get();
// Inside a transaction that is rolled back, so the real audit trail (and its tamper-evident chain,
// modules/AuditLog/lib/AuditChain.php) is left exactly as it was. Deleting the test row instead would leave a gap.
$pdo->beginTransaction();
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
}
$pdo->rollBack();

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
