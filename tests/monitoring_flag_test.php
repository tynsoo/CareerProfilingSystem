<?php
/**
 * The low-similarity monitoring flag (CBFData::saveRecommendation, Figure 14, Table 21 last row): a flag is raised as
 * Pending when the highest similarity is below the level in security_policies (0.793 by default; the level itself is
 * tested in monitoring_level_test.php), never twice while one is open, and an
 * Escalated flag follows the newest result. Everything runs inside one transaction that is rolled back.
 *   DB_NAME=profilepath_test php tests/monitoring_flag_test.php
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }

$passed = 0; $failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failures;
    $ok ? $passed++ : $failures++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}

$pdo = Database::get();
$pdo->beginTransaction();
try {
    $u = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name) VALUES ('student', ?, 'x', ?, NOW(), 'approved', 'QA') RETURNING id");
    $u->execute(['qa_flag_student', 'qa_flag_student@example.test']);
    $sid = (int) $u->fetchColumn();
    $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?, '99009999', ?, ?, 'STEM', '11', 'QA-F')")
        ->execute([$sid, Crypto::enc('Qa'), Crypto::enc('Flag')]);
    $attempt = 0;
    $save = function (array $s) use ($pdo, $sid, &$attempt): array {
        $attempt++;
        $pdo->prepare('UPDATE assessments SET is_latest = FALSE WHERE student_id = ?')->execute([$sid]);
        $a = $pdo->prepare('INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types) VALUES (?,?,TRUE,?,?,?,?,?,?,\'[]\') RETURNING id');
        $a->execute([$sid, $attempt, $s['R'], $s['I'], $s['A'], $s['S'], $s['E'], $s['C']]);
        $profile = CBFData::studentProfile($pdo, $sid, []);
        return CBFData::saveRecommendation($pdo, $sid, $profile, null, (int) $a->fetchColumn(), null);
    };
    $setLevel = fn(string $v) => $pdo->prepare("UPDATE security_policies SET value = ? WHERE key = 'monitoring.lowConfidenceThreshold'")->execute([$v]);
    $flags = function () use ($pdo, $sid): array {
        $q = $pdo->prepare("SELECT id, status, recommendation_id FROM monitoring_flags WHERE student_id = ? AND reason = 'low_confidence' ORDER BY id");
        $q->execute([$sid]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    // Lowest possible highest similarity (Section 4.2.3): raw R17 I5 A17 S21 E5 C25 = scaled 34, 10, 34, 42, 10, 50.
    $worst = ['R' => 34, 'I' => 10, 'A' => 34, 'S' => 42, 'E' => 10, 'C' => 50];
    $flat = ['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30];
    $strongIrc = ['R' => 40, 'I' => 50, 'A' => 10, 'S' => 10, 'E' => 10, 'C' => 30];

    echo "=== level 0.793 (the value the system ships with, MonitoringLevel::DEFAULT) ===\n";
    $current = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
    check('the stored level is 0.793', (float) $current === 0.793, (string) $current);
    $r = $save($worst);
    check('the lowest possible highest similarity is 0.5844', abs($r['topScore'] - 0.5844) < 0.00005, (string) $r['topScore']);
    $f = $flags();
    check('below the level: one flag is raised', count($f) === 1, json_encode($f));
    check('the new flag is Pending and points at this recommendation', ($f[0]['status'] ?? '') === 'pending' && (int) ($f[0]['recommendation_id'] ?? 0) === $r['recommendationId']);
    $r2 = $save($flat);
    check('a flat profile gives 0.6547 for the top program', abs($r2['topScore'] - 0.6547) < 0.00005, (string) $r2['topScore']);
    check('with a Pending flag already open, no second flag is raised', count($flags()) === 1);
    $pdo->prepare('UPDATE monitoring_flags SET status = ? WHERE id = ?')->execute(['escalated', $f[0]['id']]);
    $r3 = $save($worst);
    $f = $flags();
    check('an Escalated flag stays one flag', count($f) === 1);
    check('...and now shows the newest result', (int) $f[0]['recommendation_id'] === $r3['recommendationId']);
    $pdo->prepare('UPDATE monitoring_flags SET status = ? WHERE id = ?')->execute(['dismissed', $f[0]['id']]);
    $r4 = $save($strongIrc);
    check('a student whose interests fit a program code is above the level (' . round($r4['topScore'], 4) . ')', $r4['topScore'] >= 0.793);
    check('...and is not flagged', count($flags()) === 1);
    $save($worst);
    $f = $flags();
    check('after a flag is closed (Dismissed), a new low result raises a new Pending flag', count($f) === 2 && $f[1]['status'] === 'pending');
} finally {
    $pdo->rollBack();
}
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'qa_flag_student'")->fetchColumn();
check('nothing is kept after the rollback', $left === 0);
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
