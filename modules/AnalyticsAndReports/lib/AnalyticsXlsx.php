<?php

require_once __DIR__ . '/../../../shared/lib/XlsxWriter.php';
require_once __DIR__ . '/AnalyticsReport.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../../CareerProfiling/lib/QuestionBank.php';
require_once __DIR__ . '/../../CareerProfiling/lib/RiasecInterpretation.php';

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
     * @return array<int,array{name:string,strand:string,section:string,registered:bool,assessed:bool,date:?string,riasec:string,ties:string}>
     */
    public static function studentRows(PDO $pdo, ?array $scope, string $strand = '', string $section = '', string $academicYear = ''): array
    {
        $ay = AcademicYear::resolve($pdo, $academicYear);
        $pastAy = AcademicYear::isPast($ay);
        $rows = [];
        $registeredIds = [];
        $stmt = $pdo->query(
            'SELECT s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.section, s.academic_year, a.top_types, a.completed_at,
                    a.score_r, a.score_i, a.score_a, a.score_s, a.score_e, a.score_c
             FROM students s
             JOIN users u ON u.id = s.user_id
             LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE'
        );
        foreach ($stmt->fetchAll() as $r) {
            $registeredIds[$r['school_id']] = true;
            if ($pastAy && $r['academic_year'] !== $ay) {
                continue;
            }
            $done = $r['completed_at'] !== null;
            $types = $done ? (json_decode((string) $r['top_types'], true) ?: []) : [];
            $rows[] = [
                'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
                'strand' => $r['strand'], 'section' => $r['section'],
                'registered' => true, 'assessed' => $done,
                'date' => $done ? date('Y-m-d', strtotime((string) $r['completed_at'])) : null,
                'riasec' => QuestionBank::hollandCode($types),
                // "Unique", "Tied: CER or CEI" or "All six equal" (modules/CareerProfiling/lib/RiasecInterpretation.php).
                'ties' => $done ? RiasecInterpretation::tieLabel(RiasecInterpretation::interpret([
                    'R' => $r['score_r'], 'I' => $r['score_i'], 'A' => $r['score_a'], 'S' => $r['score_s'], 'E' => $r['score_e'], 'C' => $r['score_c'],
                ])) : '',
            ];
        }
        if ($ay !== '') {
            $rosterStmt = $pdo->prepare('SELECT school_id, name_enc, strand, section FROM assessment_roster WHERE academic_year = ?');
            $rosterStmt->execute([$ay]);
            foreach ($rosterStmt->fetchAll() as $r) {
                if (isset($registeredIds[$r['school_id']])) {
                    continue;
                }
                $rows[] = [
                    'name' => (string) Crypto::dec($r['name_enc']), 'strand' => $r['strand'], 'section' => $r['section'],
                    'registered' => false, 'assessed' => false, 'date' => null, 'riasec' => '', 'ties' => '',
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
    public static function build(PDO $pdo, ?array $scope, string $strand, string $section, string $password, string $generatedBy, string $emailedTo, string $academicYear = ''): string
    {
        $ay = AcademicYear::resolve($pdo, $academicYear);
        $students = self::studentRows($pdo, $scope, $strand, $section, $ay);

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
            [],
        ];
        $headerRow = count($rows); // 0-based row index of the table header
        $rows[] = array_map(fn($h) => $S($h, XlsxWriter::S_HEADER), ['Section', 'Expected', 'Registered', 'Assessed', 'Completion (%)', 'Pending']);
        $cats = $vals = $colors = [];
        foreach ($bySection as $name => $b) {
            $c = $rate($b['assessed'], $b['expected']);
            $rows[] = [
                $S($name, XlsxWriter::S_LABEL), $S($b['expected'], XlsxWriter::S_INT), $S($b['registered'], XlsxWriter::S_INT), $S($b['assessed'], XlsxWriter::S_INT),
                $S($c, XlsxWriter::S_NUM1), $S($b['expected'] - $b['assessed'], XlsxWriter::S_INT),
            ];
            $cats[] = $name; $vals[] = $c; $colors[] = '0F2A6B';
        }
        $rows[] = [];
        $rows[] = [$S('Completion = assessed students out of everyone expected in the section (registered, or on the class roster). Pending = not assessed yet.', XlsxWriter::S_NOTE)];
        $summary = $x->addSheet('Summary', $rows, [24, 12, 12, 12, 16, 12, 16], false, [[0, 0, 0, 6]]);
        if ($bySection) {
            $first = $headerRow + 2;           // 1-based first data row
            $last = $headerRow + 1 + count($bySection);
            $x->addChart([
                'title' => 'Assessment completion by section', 'sheet' => $summary, 'anchor' => [8, 1, 18, 21],
                'catRef' => "Summary!\$A\$$first:\$A\$$last", 'cats' => $cats,
                'valRef' => "Summary!\$E\$$first:\$E\$$last", 'vals' => $vals, 'seriesName' => 'Completion (%)', 'colors' => $colors,
                'max' => 100, 'axisTitle' => 'Percent assessed',
            ]);
        }

        // ---- RIASEC
        $avg = AnalyticsReport::compute($pdo, $strand, $section, $ay)['riasecAverages'];
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
        $list = [array_map(fn($h) => $S($h, XlsxWriter::S_HEADER), ['Name', 'Strand', 'Section', 'Registered', 'Assessed', 'Assessment date', 'Holland code (top 3, ranked)', 'Top-3 ties'])];
        foreach ($students as $s) {
            $list[] = [
                $S($s['name'], XlsxWriter::S_TEXT), $S($s['strand'], XlsxWriter::S_TEXT), $S($s['section'], XlsxWriter::S_TEXT),
                $S($s['registered'] ? 'Yes' : 'No', XlsxWriter::S_TEXT), $S($s['assessed'] ? 'Yes' : 'No', XlsxWriter::S_TEXT),
                $S($s['date'] ?? '', XlsxWriter::S_TEXT), $S($s['riasec'], XlsxWriter::S_TEXT), $S($s['ties'] ?? '', XlsxWriter::S_TEXT),
            ];
        }
        $x->addSheet('Students', $list, [34, 10, 12, 12, 12, 16, 28, 26], true);

        $x->protect($password);
        return $x->build();
    }

    public const SUMMARY_TITLE = 'ProfilePath Career Profiling System: Analytics Summary Report';
    public const SUMMARY_SCHOOL = 'Mapúa Malayan Colleges Laguna, Center for Guidance and Counseling';

    /**
     * The Analytics Summary Report that is emailed to the principal or another school official (api/analytics-report.php):
     * the same numbers the Analytics & Report page shows, from AnalyticsReport::compute(), as tables. Totals and shares
     * only: no student names, Student Numbers, answers or other personal details. The page's preview and the PDF
     * (modules/AnalyticsAndReports/lib/AnalyticsReportPdf.php) are both built from this, so what the staff member previews is what gets sent.
     *
     * Each table: title, columns, rows (cells are int = count, float = percent or score, string = text) and an optional note.
     *
     * @param array<string,mixed> $data the result of AnalyticsReport::compute()
     * @return array{title:string,generatedAt:string,scope:string,sections:array<int,array{title:string,columns:array<int,string>,rows:array<int,array<int,int|float|string>>,note?:string}>}
     */
    public static function summaryReport(array $data, string $generatedAt): array
    {
        $pct = fn(int $n, int $whole): float => $whole > 0 ? min(100.0, round($n / $whole * 100, 1)) : 0.0;
        $scope = trim(($data['strand'] ?: '') . ' ' . ($data['section'] ?: ''));
        $scope = $scope === '' ? 'All strands and sections' : $scope;
        $ay = (string) $data['academicYear'];

        $expected = (int) $data['completion']['total'];
        $assessed = (int) $data['completion']['count'];
        $notAssessed = max(0, $expected - $assessed);
        $registered = min($expected, (int) ($data['assessmentStats']['registeredCount'] ?? 0));

        $sections = [];
        $sections[] = [
            'title' => 'Report information',
            'columns' => ['Item', 'Details'],
            'rows' => [
                ['Report', self::SUMMARY_TITLE],
                ['School', self::SUMMARY_SCHOOL],
                ['Academic year', $ay],
                ['Students covered', $scope],
                ['Generated', $generatedAt],
            ],
        ];
        $sections[] = [
            'title' => 'Assessment participation',
            'columns' => ['Measure', 'Students', 'Percent (%)'],
            'rows' => [
                ['Students expected this academic year', $expected, 100.0],
                ['Registered a ProfilePath account', $registered, $pct($registered, $expected)],
                ['Not registered yet (on the class roster only)', $expected - $registered, $pct($expected - $registered, $expected)],
                ['Completed the RIASEC assessment', $assessed, (float) $data['completion']['rate']],
                ['Not yet completed the assessment', $notAssessed, $pct($notAssessed, $expected)],
                ['Completed the Career Electives Worksheet', (int) $data['worksheet']['count'], (float) $data['worksheet']['rate']],
                ['Program match at or above the similarity level (cosine similarity ' . number_format((float) $data['confidence']['threshold'], 2) . ' or higher)', (int) $data['confidence']['count'], (float) $data['confidence']['rate']],
            ],
            'note' => 'Students expected = registered students plus students on the uploaded class roster who have not registered yet. Every percent in this table is out of that number.',
        ];

        $bySection = [];
        foreach ($data['assessmentStats']['bySection'] as $s) {
            $bySection[] = [(string) $s['section'], (int) $s['expected'], (int) ($s['registered'] ?? 0), (int) $s['completed'], max(0, (int) $s['expected'] - (int) $s['completed']), $pct((int) $s['completed'], (int) $s['expected'])];
        }
        $sections[] = [
            'title' => 'Assessment completion by section',
            'columns' => ['Section', 'Expected', 'Registered', 'Completed', 'Not yet completed', 'Completion (%)'],
            'rows' => $bySection,
            'note' => $bySection ? '' : 'No students are expected for this academic year yet.',
        ];

        $riasec = [];
        foreach (self::RIASEC_NAMES as $letter => $label) {
            $riasec[] = [$label, $letter, (float) ($data['riasecAverages'][$letter] ?? 0)];
        }
        $sections[] = [
            'title' => 'Career interests: average RIASEC scores',
            'columns' => ['Interest area', 'Letter', 'Average score (10 to 50)'],
            'rows' => $riasec,
            'note' => $assessed > 0 ? 'Averages of the students who completed the assessment.' : 'No completed assessments yet, so every average is 0.',
        ];

        $programs = [];
        foreach ($data['topCareers'] as $i => $c) {
            $programs[] = [$i + 1, (string) $c['title'], (int) $c['count'], (float) $c['percent']];
        }
        $sections[] = [
            'title' => 'Top 5 programs most similar to students\' RIASEC profiles',
            'columns' => ['Rank', 'Program', 'Students', 'Percent of students with a recommendation (%)'],
            'rows' => $programs,
            'note' => $programs ? 'From Content-Based Filtering (cosine similarity). A student can count toward more than one program.' : 'No recommendations computed yet.',
        ];

        $strandTotal = array_sum(array_map(fn($r) => (int) $r['count'], $data['strandDistribution']));
        $sections[] = [
            'title' => 'Registered students by strand',
            'columns' => ['Strand', 'Students', 'Percent (%)'],
            'rows' => array_map(fn($r) => [(string) $r['strand'], (int) $r['count'], $pct((int) $r['count'], $strandTotal)], $data['strandDistribution']),
            'note' => $strandTotal ? 'Registered student accounts in ProfilePath.' : 'No registered students yet.',
        ];

        $sections[] = [
            'title' => 'Assessment completion by academic year',
            'columns' => ['Academic year', 'Registered students', 'Completed', 'Completion (%)'],
            'rows' => array_map(fn($y) => [(string) $y['academicYear'], (int) $y['total'], (int) $y['completed'], (float) $y['rate']], $data['completionByYear']),
            'note' => $data['completionByYear'] ? 'All strands and sections.' : 'No academic year data yet.',
        ];

        return ['title' => self::SUMMARY_TITLE, 'generatedAt' => $generatedAt, 'scope' => $scope . ', Academic Year ' . $ay, 'sections' => $sections];
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
