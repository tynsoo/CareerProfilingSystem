<?php

require_once __DIR__ . '/XlsxWriter.php';
require_once __DIR__ . '/AnalyticsReport.php';
require_once __DIR__ . '/AcademicYear.php';
require_once __DIR__ . '/StaffScope.php';
require_once __DIR__ . '/CompletionTarget.php';
require_once __DIR__ . '/QuestionBank.php';

/**
 * The Excel version of the analytics report: a Summary sheet (key numbers, completion by section and a
 * chart against the completion target), a RIASEC sheet (averages and a chart) and a Students sheet.
 * Every sheet is protected with the password passed in, so the file can't be edited by accident;
 * api/analytics-export-xlsx.php emails that password to whoever downloaded it.
 */
class AnalyticsXlsx
{
    private const RIASEC_NAMES = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];

    /**
     * Everyone expected this year as the dashboard counts them: every registered student, plus roster students
     * who have not registered. Limited to the sections the staff member handles and to the strand/section filter.
     *
     * @param array<int,string>|null $scope see StaffScope
     * @return array<int,array{name:string,strand:string,section:string,registered:bool,assessed:bool,date:?string,riasec:string}>
     */
    public static function studentRows(PDO $pdo, ?array $scope, string $strand = '', string $section = ''): array
    {
        $rows = [];
        $registeredIds = [];
        $stmt = $pdo->query(
            'SELECT s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.section, a.top_types, a.completed_at
             FROM students s
             JOIN users u ON u.id = s.user_id
             LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE'
        );
        foreach ($stmt->fetchAll() as $r) {
            $registeredIds[$r['school_id']] = true;
            $done = $r['completed_at'] !== null;
            $types = $done ? (json_decode((string) $r['top_types'], true) ?: []) : [];
            $rows[] = [
                'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
                'strand' => $r['strand'], 'section' => $r['section'],
                'registered' => true, 'assessed' => $done,
                'date' => $done ? date('Y-m-d', strtotime((string) $r['completed_at'])) : null,
                'riasec' => QuestionBank::hollandCode($types),
            ];
        }
        $ay = AcademicYear::current();
        if ($ay !== '') {
            $rosterStmt = $pdo->prepare('SELECT school_id, name_enc, strand, section FROM assessment_roster WHERE academic_year = ?');
            $rosterStmt->execute([$ay]);
            foreach ($rosterStmt->fetchAll() as $r) {
                if (isset($registeredIds[$r['school_id']])) {
                    continue;
                }
                $rows[] = [
                    'name' => (string) Crypto::dec($r['name_enc']), 'strand' => $r['strand'], 'section' => $r['section'],
                    'registered' => false, 'assessed' => false, 'date' => null, 'riasec' => '',
                ];
            }
        }
        $rows = array_values(array_filter($rows, function ($r) use ($scope, $strand, $section) {
            return StaffScope::allows($scope, $r['strand'], $r['section'])
                && ($strand === '' || $r['strand'] === $strand)
                && ($section === '' || strcasecmp($r['section'], $section) === 0);
        }));
        usort($rows, fn($a, $b) => [$a['strand'], $a['section'], $a['name']] <=> [$b['strand'], $b['section'], $b['name']]);
        return $rows;
    }

    /**
     * @param array<int,string>|null $scope
     * @return string the .xlsx file's bytes
     */
    public static function build(PDO $pdo, ?array $scope, string $strand, string $section, string $password, string $generatedBy, string $emailedTo): string
    {
        $students = self::studentRows($pdo, $scope, $strand, $section);
        $target = CompletionTarget::get($pdo);
        $ay = AcademicYear::current();

        // Per section, counted the way the dashboard counts them.
        $bySection = [];
        foreach ($students as $s) {
            $k = $s['strand'] . ' ' . $s['section'];
            $bySection[$k] ??= ['expected' => 0, 'registered' => 0, 'assessed' => 0];
            $bySection[$k]['expected']++;
            $bySection[$k]['registered'] += $s['registered'] ? 1 : 0;
            $bySection[$k]['assessed'] += $s['assessed'] ? 1 : 0;
        }
        ksort($bySection);
        $expected = count($students);
        $registered = count(array_filter($students, fn($s) => $s['registered']));
        $assessed = count(array_filter($students, fn($s) => $s['assessed']));
        $rate = fn(int $n, int $whole): float => $whole > 0 ? round($n / $whole * 100, 1) : 0.0;

        $x = new XlsxWriter();
        $S = fn($v, int $style) => ['v' => $v, 's' => $style];

        // ---- Summary
        $filterText = $strand === '' && $section === '' ? 'All strands and sections' : trim($strand . ' ' . $section);
        $rows = [
            [$S('Career Profiling System: Assessment Report', XlsxWriter::S_TITLE)],
            [$S("Academic Year $ay", XlsxWriter::S_BOLD)],
            [$S('Prepared ' . date('Y-m-d H:i') . ' by ' . $generatedBy . '. ' . $filterText . '.', XlsxWriter::S_NOTE)],
            [$S('This report is protected against editing. The password to edit it was emailed to ' . $emailedTo . '.', XlsxWriter::S_NOTE)],
            [],
            [$S('Key numbers', XlsxWriter::S_BOLD)],
            [$S('Students expected', XlsxWriter::S_LABEL), $S($expected, XlsxWriter::S_INT)],
            [$S('Registered', XlsxWriter::S_LABEL), $S($registered, XlsxWriter::S_INT)],
            [$S('Not registered', XlsxWriter::S_LABEL), $S($expected - $registered, XlsxWriter::S_INT)],
            [$S('Assessed', XlsxWriter::S_LABEL), $S($assessed, XlsxWriter::S_INT)],
            [$S('Not yet assessed', XlsxWriter::S_LABEL), $S($expected - $assessed, XlsxWriter::S_INT)],
            [$S('Completion (%)', XlsxWriter::S_LABEL), $S($rate($assessed, $expected), XlsxWriter::S_NUM1)],
            [$S('Completion target (%)', XlsxWriter::S_LABEL), CompletionTarget::isOn($target) ? $S($target, XlsxWriter::S_INT) : $S('Not set', XlsxWriter::S_TEXT)],
            [],
        ];
        $headerRow = count($rows); // 0-based row index of the table header
        $hasTarget = CompletionTarget::isOn($target);
        $rows[] = array_map(fn($h) => $S($h, XlsxWriter::S_HEADER), $hasTarget
            ? ['Section', 'Expected', 'Registered', 'Assessed', 'Completion (%)', 'Target (%)', 'Status']
            : ['Section', 'Expected', 'Registered', 'Assessed', 'Completion (%)']);
        $cats = $vals = $targets = $colors = [];
        foreach ($bySection as $name => $b) {
            $c = $rate($b['assessed'], $b['expected']);
            $met = $c >= $target;
            $row = [
                $S($name, XlsxWriter::S_LABEL), $S($b['expected'], XlsxWriter::S_INT), $S($b['registered'], XlsxWriter::S_INT), $S($b['assessed'], XlsxWriter::S_INT),
                $S($c, XlsxWriter::S_NUM1),
            ];
            if ($hasTarget) {
                $row[] = $S($target, XlsxWriter::S_INT);
                $row[] = $S($met ? 'On target' : 'Below target', $met ? XlsxWriter::S_GOOD : XlsxWriter::S_BAD);
            }
            $rows[] = $row;
            $cats[] = $name; $vals[] = $c; $targets[] = $target; $colors[] = $hasTarget ? ($met ? '059669' : 'DC2626') : '0F2A6B';
        }
        $rows[] = [];
        $rows[] = [$S('Completion = assessed students out of everyone expected in the section (registered, or on the class roster).', XlsxWriter::S_NOTE)];
        $summary = $x->addSheet('Summary', $rows, [24, 12, 12, 12, 16, 12, 16], false, [[0, 0, 0, 6]]);
        if ($bySection) {
            $first = $headerRow + 2;           // 1-based first data row
            $last = $headerRow + 1 + count($bySection);
            $x->addChart([
                'title' => 'Assessment completion by section', 'sheet' => $summary, 'anchor' => [8, 1, 18, 21],
                'catRef' => "Summary!\$A\$$first:\$A\$$last", 'cats' => $cats,
                'valRef' => "Summary!\$E\$$first:\$E\$$last", 'vals' => $vals, 'seriesName' => 'Completion (%)', 'colors' => $colors,
                'max' => 100, 'axisTitle' => 'Percent assessed',
            ] + ($hasTarget ? ['targetRef' => "Summary!\$F\$$first:\$F\$$last", 'targetVals' => $targets, 'targetName' => "Target ($target%)"] : []));
        }

        // ---- RIASEC
        $avg = AnalyticsReport::compute($pdo, $strand, $section)['riasecAverages'];
        $riasecRows = [array_map(fn($h) => $S($h, XlsxWriter::S_HEADER), ['Type', 'Letter', 'Average score'])];
        $rCats = $rVals = [];
        foreach (self::RIASEC_NAMES as $letter => $label) {
            $riasecRows[] = [$S($label, XlsxWriter::S_LABEL), $S($letter, XlsxWriter::S_TEXT), $S((float) ($avg[$letter] ?? 0), XlsxWriter::S_NUM1)];
            $rCats[] = $label; $rVals[] = (float) ($avg[$letter] ?? 0);
        }
        $riasecRows[] = [];
        $riasecRows[] = [$S('Average RIASEC scores of the students who finished the assessment, for ' . strtolower($filterText) . '.', XlsxWriter::S_NOTE)];
        $riasec = $x->addSheet('RIASEC', $riasecRows, [18, 10, 16]);
        $x->addChart([
            'title' => 'Average RIASEC scores', 'sheet' => $riasec, 'anchor' => [4, 0, 14, 18],
            'catRef' => 'RIASEC!$A$2:$A$7', 'cats' => $rCats, 'valRef' => 'RIASEC!$C$2:$C$7', 'vals' => $rVals,
            'seriesName' => 'Average score', 'colors' => ['2563EB', '059669', 'D97706', '9333EA', 'DC2626', '0891B2'], 'axisTitle' => 'Average score',
        ]);

        // ---- Students
        $list = [array_map(fn($h) => $S($h, XlsxWriter::S_HEADER), ['Name', 'Strand', 'Section', 'Registered', 'Assessed', 'Assessment date', 'Holland code (top 3, ranked)'])];
        foreach ($students as $s) {
            $list[] = [
                $S($s['name'], XlsxWriter::S_TEXT), $S($s['strand'], XlsxWriter::S_TEXT), $S($s['section'], XlsxWriter::S_TEXT),
                $S($s['registered'] ? 'Yes' : 'No', XlsxWriter::S_TEXT), $S($s['assessed'] ? 'Yes' : 'No', XlsxWriter::S_TEXT),
                $S($s['date'] ?? '', XlsxWriter::S_TEXT), $S($s['riasec'], XlsxWriter::S_TEXT),
            ];
        }
        $x->addSheet('Students', $list, [34, 10, 12, 12, 12, 16, 28], true);

        $x->protect($password);
        return $x->build();
    }

    /** A readable edit password: no 0/O/1/I/l, so it can be typed from an email. */
    public static function generatePassword(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $pw = '';
        for ($i = 0; $i < $length; $i++) {
            $pw .= $alphabet[random_int(0, $max)];
        }
        return $pw;
    }
}
