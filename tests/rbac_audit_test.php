<?php

require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';
require_once __DIR__ . '/../modules/AuditLog/lib/AuditLogger.php';
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';

// Needs the local database with db/migrate_rbac_by_page.php applied and the permission table at its defaults (a level
// changed in Security Configuration shows here as a FAIL). Every change below is made inside a transaction that is rolled
// back, so the real permission table and audit trail are left exactly as they were.

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

/** Runs $test against the table changed by $sql, then undoes the change. Rbac reads the table once per request, so it forgets it around the change. */
function withTable(string $sql, callable $test)
{
    $pdo = Database::get();
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        Rbac::forgetSaved();
        return $test();
    } finally {
        $pdo->rollBack();
        Rbac::forgetSaved();
    }
}

echo "=== Rbac::accessLevel against the seeded security_rbac table (the agreed defaults) ===\n";
foreach (Rbac::DEFAULTS as $module => $defaults) {
    check("$module/admin == full", Rbac::accessLevel($module, 'admin') === 'full');
    check("$module/counselor == {$defaults['counselor']}", Rbac::accessLevel($module, 'counselor') === $defaults['counselor']);
    check("$module/facilitator == {$defaults['facilitator']}", Rbac::accessLevel($module, 'facilitator') === $defaults['facilitator']);
    $studentLevel = in_array($module, Rbac::STUDENT_MODULES, true) ? 'full' : 'none';
    check("$module/student == $studentLevel", Rbac::accessLevel($module, 'student') === $studentLevel);
}
check('the older features and the administrator\'s pages are not rows of the table', (int) Database::get()->query(
    "SELECT COUNT(*) FROM security_rbac WHERE module IN ('career', 'rac', 'counselor', 'counselingNotes', 'sections', 'settings', 'auditLog')"
)->fetchColumn() === 0);

echo "\n=== the administrator always has every feature ===\n";
check('even if the table is tampered to No Access, the administrator keeps Manage staff accounts',
    withTable("UPDATE security_rbac SET access_level = 'none' WHERE module = 'staffAccounts' AND role = 'admin'",
        fn() => Rbac::accessLevel('staffAccounts', 'admin') === 'full'));

echo "\n=== a Student can only ever hold their own recommendations ===\n";
check('even if the table is tampered to Full, a student has no access to announcements, staff accounts or monitoring',
    withTable("UPDATE security_rbac SET access_level = 'full' WHERE module IN ('announcements', 'staffAccounts', 'monitoring') AND role = 'student'",
        fn() => Rbac::accessLevel('announcements', 'student') === 'none' && Rbac::accessLevel('staffAccounts', 'student') === 'none'
            && Rbac::accessLevel('monitoring', 'student') === 'none'));
check('a student keeps Career recommendations', Rbac::accessLevel('recommendations', 'student') === 'full');

echo "\n=== a Guidance Facilitator never updates counseling status (DFD process 4.0) ===\n";
check('even if the table is tampered to Full, the facilitator has No Access to Update counseling status',
    withTable("UPDATE security_rbac SET access_level = 'full' WHERE module = 'counselingStatus' AND role = 'facilitator'",
        fn() => Rbac::accessLevel('counselingStatus', 'facilitator') === 'none'));
check('the facilitator cell of Update counseling status cannot be changed, the counselor cell can',
    // An action (resolve, decide, invite): Full or No Access, no View Only (Rbac::ACTION_ONLY).
    Rbac::editableLevels('admin', 'counselingStatus', 'facilitator', []) === [] && Rbac::editableLevels('admin', 'counselingStatus', 'counselor', []) === ['full', 'none']);

echo "\n=== a missing row falls back to the default ===\n";
check('with no counselor row for Analytics, the counselor gets the default (View Only)',
    withTable("DELETE FROM security_rbac WHERE module = 'analytics' AND role = 'counselor'",
        fn() => Rbac::accessLevel('analytics', 'counselor') === Rbac::DEFAULTS['analytics']['counselor']));
check('the Facilitator column changes independently of the counselor',
    withTable("UPDATE security_rbac SET access_level = 'full' WHERE module = 'roster' AND role = 'facilitator'",
        fn() => Rbac::accessLevel('roster', 'facilitator') === 'full' && Rbac::accessLevel('roster', 'counselor') === Rbac::DEFAULTS['roster']['counselor']));
check('an unknown role gets No Access', Rbac::accessLevel('analytics', 'nonexistent_role') === 'none');
try {
    Rbac::accessLevel('not_a_real_module', 'admin');
    check('an unknown feature throws', false);
} catch (InvalidArgumentException $e) {
    check('an unknown feature throws', true);
}
check('the matrix has four columns', Rbac::ROLES === ['admin', 'counselor', 'facilitator', 'student']);
check('a student is never a facilitator', Rbac::isFacilitator(['id' => 0, 'role' => 'student']) === false);
check('the administrator is never a facilitator', Rbac::isFacilitator(['id' => 1, 'role' => 'admin']) === false);

echo "\n=== pages open and close with their features ===\n";
check('Audit Log and Security Configuration are the administrator\'s only, whatever the table says',
    !Rbac::pageOpen('audit-log', 'counselor') && !Rbac::pageOpen('security-configuration', 'counselor')
    && !Rbac::pageOpen('audit-log', 'facilitator') && !Rbac::pageOpen('security-configuration', 'facilitator')
    && Rbac::pageOpen('audit-log', 'admin') && Rbac::pageOpen('security-configuration', 'admin')
    && withTable("UPDATE security_rbac SET access_level = 'full' WHERE role = 'counselor'", fn() => !Rbac::pageOpen('audit-log', 'counselor')));
check('My Access is open to all staff', Rbac::pageOpen('my-access', 'counselor') && Rbac::pageOpen('my-access', 'facilitator'));
check('Analytics is open to the counselor (View Only) and closed to the facilitator',
    Rbac::pageOpen('analytics', 'counselor') && !Rbac::pageOpen('analytics', 'facilitator'));
check('Account Management is open to the counselor (roster View Only) and closed to the facilitator',
    Rbac::pageOpen('account-management', 'counselor') && !Rbac::pageOpen('account-management', 'facilitator'));
check('every page is open to the administrator', count(Rbac::closedPages('admin')) === 0);
check('a page that is not in the table (staff profile) is always open', Rbac::pageOpen('staff-profile', 'facilitator'));
check('staff land on Student Information while Dashboard or Student information is open', Rbac::firstOpenPage('counselor') === 'admin-dashboard');
check('with Dashboard and Student information both No Access, the counselor lands on the next open page (Analytics)',
    withTable("UPDATE security_rbac SET access_level = 'none' WHERE module IN ('dashboard', 'studentInfo') AND role = 'counselor'",
        fn() => !Rbac::pageOpen('admin-dashboard', 'counselor') && !Rbac::pageOpen('student-profile', 'counselor')
            && Rbac::firstOpenPage('counselor') === 'analytics'));
check('with every feature No Access, staff land on My Access',
    withTable("UPDATE security_rbac SET access_level = 'none' WHERE role = 'facilitator'",
        fn() => Rbac::firstOpenPage('facilitator') === Rbac::FALLBACK_PAGE && count(Rbac::closedPages('facilitator')) === count(Rbac::PAGES)));

echo "\n=== who may change which permission ===\n";
check('the administrator can set the counselor and facilitator columns to any level',
    Rbac::editableLevels('admin', 'analytics', 'counselor', []) === ['full', 'limited', 'none'] && Rbac::editableLevels('admin', 'roster', 'facilitator', []) === ['full', 'limited', 'none']);
check('the administrator\'s own pages are not rows that can be set', Rbac::editableLevels('admin', 'settings', 'counselor', []) === [] && Rbac::editableLevels('admin', 'auditLog', 'facilitator', []) === []);
check('nobody can change the administrator column', Rbac::editableLevels('admin', 'analytics', 'admin', []) === [] && Rbac::editableLevels('counselor', 'analytics', 'admin', []) === []);
check('nobody can change the student column', Rbac::editableLevels('admin', 'recommendations', 'student', []) === [] && Rbac::editableLevels('counselor', 'staffAccounts', 'student', []) === []);
check('only the administrator changes permissions', Rbac::editableLevels('counselor', 'announcements', 'facilitator', []) === []
    && Rbac::editableLevels('counselor', 'announcements', 'counselor', []) === [] && Rbac::editableLevels('facilitator', 'analytics', 'facilitator', []) === []);
check('an unknown feature is not editable', Rbac::editableLevels('admin', 'nope', 'counselor', []) === []);

echo "\n=== sensitive changes ask for a confirmation ===\n";
check('account features are sensitive', Rbac::isSensitiveChange('staffAccounts', 'facilitator') && Rbac::isSensitiveChange('studentAccounts', 'facilitator'));
check('anything in the Guidance Counselor column is sensitive', Rbac::isSensitiveChange('programs', 'counselor'));
check('an everyday feature for the facilitator column is not', !Rbac::isSensitiveChange('announcements', 'facilitator'));

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
