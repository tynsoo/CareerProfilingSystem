<?php
/**
 * The School Year filter on Analytics, the Excel/PDF report rows and the roster CSV: every figure follows the year
 * picked, the current year included (AcademicYear::studentCondition). Test students live in their own section (QA-Y), so
 * the numbers are exact whatever else is in the database. Everything runs inside one transaction that is rolled back.
 *   DB_NAME=profilepath_test php tests/analytics_year_filter_test.php
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/AnalyticsAndReports/lib/AnalyticsXlsx.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }

$passed = 0; $failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failures;
    $ok ? $passed++ : $failures++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}

$pdo = Database::get();
$cur = AcademicYear::current();
$curStart = (int) AcademicYear::startYear($cur);
$y1 = ($curStart - 2) . '-' . ($curStart - 1);   // two years back
$y2 = ($curStart - 1) . '-' . $curStart;         // last year

$byYear = fn(array $list) => array_column($list, null, 'academicYear');
$pdo->beginTransaction();
try {
    echo "=== The year before the current one is selectable and empty ===\n";
    $empty = AnalyticsReport::compute($pdo, '', 'QA-Y', $y2);
    check("$y2 is offered and resolved with no data", in_array($y2, $empty['academicYears'], true) && $empty['academicYear'] === $y2);
    check('an empty year shows zeros, not the current year', $empty['totalStudents'] === 0 && $empty['expectedTotal'] === 0 && $empty['completion']['rate'] === 0.0 && $empty['topCareers'] === []);
    check('an empty year has no report rows', AnalyticsXlsx::studentRows($pdo, null, '', 'QA-Y', $y2) === []);
    echo "\n";

    $before = $byYear(AnalyticsReport::completionByYear($pdo, $cur));

    $n = 0;
    $add = function (?string $ay, bool $assessed) use ($pdo, &$n): string {
        $n++;
        $sid = '9910' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $u = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name) VALUES ('student', ?, 'x', ?, NOW(), 'approved', 'QA') RETURNING id");
        $u->execute(['qa_year_' . $n, 'qa_year_' . $n . '@example.test']);
        $uid = (int) $u->fetchColumn();
        $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, ?, ?, ?, 'STEM', '11', 'QA-Y', ?)")
            ->execute([$uid, $sid, Crypto::enc('Qa' . $n), Crypto::enc('Year'), $ay]);
        if ($assessed) {
            $pdo->prepare("INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types) VALUES (?, 1, TRUE, 40, 30, 20, 10, 5, 0, '[\"R\",\"I\",\"A\"]')")
                ->execute([$uid]);
        }
        return $sid;
    };
    $roster = fn(string $ay, string $sid) => $pdo->prepare("INSERT INTO assessment_roster (academic_year, school_id, name_enc, strand, section) VALUES (?, ?, ?, 'STEM', 'QA-Y')")
        ->execute([$ay, $sid, Crypto::enc('Roster Only')]);

    $add($y1, true);                 // A  two years back, assessed
    $add($y1, false);                // B  two years back, not assessed
    $gId = $add($y1, true);          // G  registered two years back, still enrolled last year (on that roster)
    $roster($y2, $gId);
    $add($y2, true);                 // C  last year, assessed
    $add($cur, true);                // D  this year, assessed
    $add($cur, false);               // E  this year, not assessed
    $add(null, true);                // F  no year recorded: counts for the current year only
    $roster($cur, '99109999');       // H  on this year's roster, not registered

    echo "=== Analytics follows the school year picked ===\n";
    $expect = [
        $y1 => ['total' => 3, 'assessed' => 2, 'expected' => 3],   // A, B, G
        $y2 => ['total' => 2, 'assessed' => 2, 'expected' => 2],   // C, G (G is on this year's roster)
        $cur => ['total' => 3, 'assessed' => 2, 'expected' => 4],  // D, E, F + H on the roster
    ];
    foreach ($expect as $ay => $e) {
        $r = AnalyticsReport::compute($pdo, '', 'QA-Y', $ay);
        check("$ay: academic year resolved", $r['academicYear'] === $ay);
        check("$ay: registered students = {$e['total']}", $r['totalStudents'] === $e['total'], (string) $r['totalStudents']);
        check("$ay: finished assessments = {$e['assessed']}", $r['completion']['count'] === $e['assessed'], (string) $r['completion']['count']);
        check("$ay: students expected = {$e['expected']}", $r['expectedTotal'] === $e['expected'], (string) $r['expectedTotal']);
        check("$ay: section bars add up to the expected number", array_sum(array_column($r['assessmentStats']['bySection'], 'expected')) === $e['expected']);
        check("$ay: completed bars add up to the finished assessments", array_sum(array_column($r['assessmentStats']['bySection'], 'completed')) === $e['assessed']);
        check("$ay: RIASEC averages come from that year's assessments", $r['riasecAverages']['R'] === 40.0);
        check("$ay: strand chart counts only that year", array_sum(array_column($r['strandDistribution'], 'count')) === $e['total']);
    }
    $blank = AnalyticsReport::compute($pdo, '', 'QA-Y', '');
    check('no year picked = the current year', $blank['academicYear'] === $cur && $blank['totalStudents'] === 3);
    $other = AnalyticsReport::compute($pdo, '', 'QA-Y', $y1);
    check('a past year never shows the current year\'s students', $other['totalStudents'] === 3 && $other['assessmentStats']['expectedCount'] === 3);

    echo "\n=== Completion by school year chart ===\n";
    $after = $byYear(AnalyticsReport::completionByYear($pdo, $y2));
    $delta = fn(string $ay, string $k) => ($after[$ay][$k] ?? 0) - ($before[$ay][$k] ?? 0);
    check("$y1: 3 expected, 2 completed added", $delta($y1, 'total') === 3 && $delta($y1, 'completed') === 2);
    check("$y2: 2 expected, 2 completed added", $delta($y2, 'total') === 2 && $delta($y2, 'completed') === 2);
    check("$cur: 4 expected, 2 completed added", $delta($cur, 'total') === 4 && $delta($cur, 'completed') === 2);
    check('the picked year is marked, the others are not', ($after[$y2]['selected'] ?? false) === true && ($after[$y1]['selected'] ?? true) === false);
    check('years are listed oldest first', array_keys($after) === (function ($k) { sort($k); return $k; })(array_keys($after)));
    check('no rate is above 100', max(array_column($after, 'rate')) <= 100.0);

    echo "\n=== Excel / PDF student rows and roster CSV use the same students ===\n";
    foreach ($expect as $ay => $e) {
        $rows = AnalyticsXlsx::studentRows($pdo, null, '', 'QA-Y', $ay);
        check("$ay: report rows = {$e['expected']}", count($rows) === $e['expected'], (string) count($rows));
        [$cond, $params] = AcademicYear::studentCondition('s', $ay);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.section = 'QA-Y' AND " . $cond);
        $stmt->execute($params);
        check("$ay: roster CSV students = {$e['total']}", (int) $stmt->fetchColumn() === $e['total']);
    }
} finally {
    $pdo->rollBack();
}

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
