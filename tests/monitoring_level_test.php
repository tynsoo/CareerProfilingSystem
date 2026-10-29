<?php
/**
 * The level of the low-similarity monitoring flag (modules/Monitoring/lib/MonitoringLevel.php, default 0.793): how a saved
 * value is read and checked, the boundary (below the level is flagged, at or above is not), db/migrate_monitoring_level.php,
 * and that a career mismatch never flags a student while a low similarity does. Everything runs inside one transaction
 * that is rolled back.
 *   DB_NAME=profilepath_test php tests/monitoring_level_test.php
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../modules/Monitoring/lib/MonitoringLevel.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }

$passed = 0; $failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failures;
    $ok ? $passed++ : $failures++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}

echo "=== reading and checking a level (no database) ===\n";
check('the default is 0.793', MonitoringLevel::DEFAULT === 0.793);
check('the lowest possible score is 0.5844', MonitoringLevel::FLOOR === 0.5844);
foreach ([[null, 0.793], [false, 0.793], ['', 0.793], ['abc', 0.793], ['0.50', 0.793], ['0.5844', 0.793], ['1.5', 0.793], ['-1', 0.793],
          ['0.793', 0.793], ['0.800', 0.8], ['0.5845', 0.5845], ['1', 1.0]] as [$in, $want]) {
    check('saved ' . var_export($in, true) . " is read as $want", MonitoringLevel::fromValue($in) === $want, (string) MonitoringLevel::fromValue($in));
}
foreach (['0.793' => 0.793, '0.585' => 0.585, '1.000' => 1.0, '1' => 1.0, ' 0.8 ' => 0.8] as $in => $want) {
    $v = MonitoringLevel::validate($in);
    check("typed '$in' is accepted as $want", $v['error'] === null && $v['level'] === $want, json_encode($v));
}
foreach (['0.584', '0.50', '0', '1.001', '2', '0.7671', 'abc', '', '.767', '-0.7'] as $in) {
    $v = MonitoringLevel::validate($in);
    check("typed '$in' is refused", $v['error'] !== null && $v['level'] === null, json_encode($v));
}
foreach ([null, ['0.8'], true] as $in) {
    check('a ' . gettype($in) . ' is refused', MonitoringLevel::validate($in)['error'] !== null);
}

$pdo = Database::get();
$pdo->beginTransaction();
try {
    $setLevel = function (?string $v) use ($pdo) {
        if ($v === null) {
            $pdo->prepare('DELETE FROM security_policies WHERE key = ?')->execute([MonitoringLevel::KEY]);
        } else {
            $pdo->prepare('INSERT INTO security_policies (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value')
                ->execute([MonitoringLevel::KEY, $v]);
        }
    };
    $stored = function () use ($pdo) {
        $s = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
        $s->execute([MonitoringLevel::KEY]);
        return $s->fetchColumn();
    };

    echo "\n=== the value a new database starts with ===\n";
    check('the test database holds 0.793 (db/seed_security_defaults.php)', $stored() === '0.793', var_export($stored(), true));
    check('MonitoringLevel::get reads 0.793', MonitoringLevel::get($pdo) === 0.793);

    echo "\n=== db/migrate_monitoring_level.php ===\n";
    $migrate = function () { ob_start(); include __DIR__ . '/../db/migrate_monitoring_level.php'; return trim((string) ob_get_clean()); };
    $setLevel('0.50');
    $out = $migrate();
    check('the old 0.50 becomes 0.793', $stored() === '0.793', "$out / " . var_export($stored(), true));
    $out = $migrate();
    check('running it again changes nothing', $stored() === '0.793' && str_contains($out, 'left as it is'), $out);
    $setLevel('0.800');
    $out = $migrate();
    check('a level the Administrator chose (0.800) is kept', $stored() === '0.800', $out);
    $setLevel(null);
    $out = $migrate();
    check('a missing setting is added as 0.793', $stored() === '0.793', $out);

    echo "\n=== the boundary (CBFData::saveRecommendation) ===\n";
    $u = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name) VALUES ('student', ?, 'x', ?, NOW(), 'approved', 'QA') RETURNING id");
    $mkStudent = function (string $name) use ($pdo, $u): int {
        $u->execute([$name, "$name@example.test"]);
        $sid = (int) $u->fetchColumn();
        $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?, ?, ?, ?, 'STEM', '11', 'QA-L')")
            ->execute([$sid, substr(md5($name), 0, 8), Crypto::enc('Qa'), Crypto::enc($name)]);
        return $sid;
    };
    $attempts = [];
    $save = function (int $sid, array $s, ?int $stated = null) use ($pdo, &$attempts): array {
        $attempts[$sid] = ($attempts[$sid] ?? 0) + 1;
        $pdo->prepare('UPDATE assessments SET is_latest = FALSE WHERE student_id = ?')->execute([$sid]);
        $a = $pdo->prepare('INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types) VALUES (?,?,TRUE,?,?,?,?,?,?,\'[]\') RETURNING id');
        $a->execute([$sid, $attempts[$sid], $s['R'], $s['I'], $s['A'], $s['S'], $s['E'], $s['C']]);
        $profile = CBFData::studentProfile($pdo, $sid, []);
        return CBFData::saveRecommendation($pdo, $sid, $profile, $stated, (int) $a->fetchColumn(), null);
    };
    $flags = function (int $sid) use ($pdo): array {
        $q = $pdo->prepare("SELECT id, reason, status FROM monitoring_flags WHERE student_id = ? ORDER BY id");
        $q->execute([$sid]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    $closeFlags = fn(int $sid) => $pdo->prepare("UPDATE monitoring_flags SET status = 'dismissed' WHERE student_id = ?")->execute([$sid]);

    // Score sets whose highest similarity is exactly 0.7920, 0.7930 and 0.7940 against the 32 programs (22 codes of Table 18).
    $at792 = ['R' => 12, 'I' => 38, 'A' => 30, 'S' => 20, 'E' => 12, 'C' => 24];
    $at793 = ['R' => 12, 'I' => 40, 'A' => 42, 'S' => 40, 'E' => 44, 'C' => 20];
    $at794 = ['R' => 12, 'I' => 16, 'A' => 48, 'S' => 18, 'E' => 22, 'C' => 36];
    $worst = ['R' => 34, 'I' => 10, 'A' => 34, 'S' => 42, 'E' => 10, 'C' => 50];
    $strongIrc = ['R' => 40, 'I' => 50, 'A' => 10, 'S' => 10, 'E' => 10, 'C' => 30];
    $setLevel('0.793');
    foreach ([['0.7920', $at792, 1], ['0.7930', $at793, 0], ['0.7940', $at794, 0], ['0.5844', $worst, 1]] as [$want, $s, $n]) {
        $sid = $mkStudent('qa_lvl_' . str_replace('.', '', $want));
        $r = $save($sid, $s);
        check("a score set gives $want", abs($r['topScore'] - (float) $want) < 0.00005, (string) $r['topScore']);
        check("at the level 0.793, $want is " . ($n ? 'flagged (Pending, low similarity)' : 'not flagged'),
            count($flags($sid)) === $n && ($n === 0 || ($flags($sid)[0]['reason'] === 'low_confidence' && $flags($sid)[0]['status'] === 'pending')), json_encode($flags($sid)));
    }

    // The same rule at any level, with the student's own score as the level: equal is not flagged, just above it is.
    $sid = $mkStudent('qa_lvl_edge');
    $top = $save($sid, $strongIrc)['topScore'];
    $edge = number_format($top, 4, '.', '');
    $closeFlags($sid);
    $setLevel($edge);
    $save($sid, $strongIrc);
    check("level equal to the score ($edge): not flagged", array_filter($flags($sid), fn($f) => $f['status'] === 'pending') === []);
    $setLevel(number_format($top + 0.0001, 4, '.', ''));
    $save($sid, $strongIrc);
    check('level 0.0001 above the score: flagged', count(array_filter($flags($sid), fn($f) => $f['status'] === 'pending')) === 1);
    $closeFlags($sid);
    $setLevel('0.50');
    $save($sid, $worst);
    check('a stored 0.50 (never able to flag) is read as 0.793, so the lowest possible score is flagged',
        count(array_filter($flags($sid), fn($f) => $f['status'] === 'pending')) === 1);
    $setLevel('0.793');

    echo "\n=== a career mismatch and a low similarity stay separate ===\n";
    $programs = CBFData::activePrograms($pdo);
    $sid = $mkStudent('qa_lvl_mismatch');
    $first = $save($sid, $strongIrc);
    $best = array_column(array_filter($programs, fn($p) => in_array($p['title'], $first['bestRiasecMatchTitles'], true)), 'id');
    $other = (int) array_values(array_filter($programs, fn($p) => !in_array($p['id'], $best, true)))[0]['id'];
    $closeFlags($sid);
    $r = $save($sid, $strongIrc, $other);
    check('a Preferred Course outside the Best RIASEC Match is a mismatch', $r['status'] === 'mismatch', $r['status']);
    check('...with a high similarity (' . $r['topScore'] . '), it is not flagged', $r['topScore'] >= 0.793 && array_filter($flags($sid), fn($f) => $f['status'] === 'pending') === []);
    $recRow = $pdo->prepare('SELECT match_status, cbf_program_ids FROM recommendations WHERE id = ?');
    $recRow->execute([$r['recommendationId']]);
    $rec = $recRow->fetch();
    check('...and the mismatch is saved on the recommendation', $rec['match_status'] === 'mismatch');
    check('...the Best RIASEC Match is the same as without a Preferred Course', CBFData::parseIntArray($rec['cbf_program_ids']) == $best, $rec['cbf_program_ids']);

    $sid = $mkStudent('qa_lvl_match_low');
    $low = $save($sid, $at792);
    $lowBest = array_values(array_column(array_filter($programs, fn($p) => in_array($p['title'], $low['bestRiasecMatchTitles'], true)), 'id'));
    $closeFlags($sid);
    $r = $save($sid, $at792, (int) $lowBest[0]);
    check('a Preferred Course inside the Best RIASEC Match is a match', $r['status'] === 'match', $r['status']);
    check('...with a low similarity (' . $r['topScore'] . '), it is flagged', count(array_filter($flags($sid), fn($f) => $f['status'] === 'pending')) === 1);
    check('no flag with any reason other than low similarity was raised', (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags mf JOIN users u ON u.id = mf.student_id WHERE u.username LIKE 'qa_lvl_%' AND mf.reason <> 'low_confidence'")->fetchColumn() === 0);
} finally {
    $pdo->rollBack();
}
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'qa_lvl_%'")->fetchColumn();
check('nothing is kept after the rollback', $left === 0);
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
