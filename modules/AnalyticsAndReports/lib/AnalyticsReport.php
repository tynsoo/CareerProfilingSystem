<?php


/**
 * Single source of truth for the institution-wide analytics figures shown
 * on the Analytics Dashboard (api/analytics.php) and in its Excel export
 * (modules/AnalyticsAndReports/lib/AnalyticsXlsx.php). Both read the same numbers — there is no
 * separate, hand-maintained set of fields for the export, so the two can
 * never drift out of sync or ask staff to re-enter data that the system
 * already has.
 */
require_once __DIR__ . '/../../StudentManagement/lib/Sections.php';
require_once __DIR__ . '/../../Monitoring/lib/MonitoringLevel.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';

class AnalyticsReport
{
    /** @return array<string,mixed> */
    public static function compute(PDO $pdo, string $strand = '', string $section = '', string $academicYear = ''): array
    {
        $validStrands = ['STEM', 'ABM', 'ICT', 'HUMSS'];
        if (!in_array($strand, $validStrands, true)) {
            $strand = '';
        }
        $hasStrand = $strand !== '';
        $hasSection = $section !== '';
        // The school year being looked at (the current one unless another was picked). Every student figure is narrowed to
        // the students of that year, the current year included: see AcademicYear::studentCondition().
        $ay = AcademicYear::resolve($pdo, $academicYear);

        // Builds the "WHERE ... AND ..." clause plus matching bind params for the
        // Strand, Section and School Year filters, given the students-table alias
        // in use. Every metric below joins to `students s` one way or another, so
        // the alias is 's' (or '' where students is the base table).
        $studentFilterClause = function (string $leadKeyword = 'WHERE', string $alias = 's') use ($hasStrand, $strand, $hasSection, $section, $ay): array {
            $c = $alias === '' ? '' : $alias . '.';
            $conds = [];
            $params = [];
            if ($hasStrand) {
                $conds[] = $c . 'strand = ?';
                $params[] = $strand;
            }
            if ($hasSection) {
                $conds[] = $c . 'section = ?';
                $params[] = $section;
            }
            [$ayCond, $ayParams] = AcademicYear::studentCondition($alias, $ay);
            $conds[] = $ayCond;
            array_push($params, ...$ayParams);
            $joiner = $leadKeyword === 'WHERE' ? ' WHERE ' : ' AND ';
            return [$joiner . implode(' AND ', $conds), $params];
        };

        $totalStudents = (int) (function () use ($pdo, $studentFilterClause) {
            // No join needed here — students is the base table, so use its own columns directly.
            [$clause, $params] = $studentFilterClause('WHERE', '');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM students' . $clause);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        })();

        $assessedCount = (int) (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause('AND');
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE a.is_latest = TRUE' . $clause
            );
            $stmt->execute($params);
            return $stmt->fetchColumn();
        })();

        $worksheetCount = (int) (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause('AND');
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT w.student_id) FROM worksheets w
                 JOIN students s ON s.user_id = w.student_id
                 WHERE w.attempt_number = (SELECT MAX(attempt_number) FROM assessments a WHERE a.student_id = w.student_id)' . $clause
            );
            $stmt->execute($params);
            return $stmt->fetchColumn();
        })();

        $threshold = MonitoringLevel::get($pdo);

        $latestRecScores = (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause();
            $sql = 'SELECT DISTINCT ON (r.student_id) r.top_score FROM recommendations r JOIN assessments la ON la.id = r.source_assessment_id AND la.is_latest = TRUE'
                . ' JOIN students s ON s.user_id = r.student_id' . $clause
                . ' ORDER BY r.student_id, r.computed_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        })();
        $recCount = count($latestRecScores);
        $confidentCount = count(array_filter($latestRecScores, fn($s) => (float) $s >= $threshold));

        // The strand and section breakdowns follow the same Strand/Section
        // filter as every other figure, so picking STEM never shows ABM bars.
        [$distClause, $distParams] = $studentFilterClause('WHERE', '');
        $strandStmt = $pdo->prepare('SELECT strand, COUNT(*) AS cnt FROM students' . $distClause . ' GROUP BY strand ORDER BY cnt DESC');
        $strandStmt->execute($distParams);
        $strandRows = $strandStmt->fetchAll();
        $topStrand = $strandRows[0] ?? null;
        $sectionStmt = $pdo->prepare('SELECT section, COUNT(*) AS cnt FROM students' . $distClause . ' GROUP BY section ORDER BY cnt DESC');
        $sectionStmt->execute($distParams);
        $sectionRows = $sectionStmt->fetchAll();

        $riasecRow = (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause('AND');
            $stmt = $pdo->prepare(
                'SELECT AVG(a.score_r) AS r, AVG(a.score_i) AS i, AVG(a.score_a) AS a, AVG(a.score_s) AS s, AVG(a.score_e) AS e, AVG(a.score_c) AS c
                 FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE a.is_latest = TRUE' . $clause
            );
            $stmt->execute($params);
            return $stmt->fetch();
        })();

        $careerCounts = (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause();
            // Students whose CBF candidates (courses most similar to their RIASEC profile)
            // include each program (latest result per student; older results: top CBF program).
            $sql = "SELECT unnest(COALESCE(cbf_program_ids, ARRAY[top_program_id])) AS top_program_id, COUNT(*) AS cnt FROM (
                        SELECT DISTINCT ON (r.student_id) r.student_id, r.top_program_id, r.cbf_program_ids
                        FROM recommendations r JOIN assessments la ON la.id = r.source_assessment_id AND la.is_latest = TRUE"
                . ' JOIN students s ON s.user_id = r.student_id' . $clause
                . " ORDER BY r.student_id, r.computed_at DESC
                    ) latest GROUP BY 1 ORDER BY cnt DESC, 1 LIMIT 5";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        })();

        // Assessment Statistics / the "Y" in every "X of Y total students"
        // figure: how many students are EXPECTED for the current Academic
        // Year — the union of who's actually registered and who's on the
        // uploaded roster but hasn't registered yet, deduplicated by
        // school_id (a UNION, not UNION ALL, does the dedup). A roster
        // upload used to REPLACE this count outright rather than add to it,
        // so a small or stale roster could show something like "2 of 1" once
        // more students registered than the roster had ever listed.
        $currentAy = $ay; // the school year being looked at
        // A student with no year recorded counts for the current year only (same rule as AcademicYear::studentCondition).
        $studentAyCond = AcademicYear::isPast($ay) ? 'academic_year = ?' : '(academic_year = ? OR academic_year IS NULL)';
        $expectedCount = (int) (function () use ($pdo, $currentAy, $studentAyCond, $hasStrand, $strand, $hasSection, $section) {
            $studentConds = [$studentAyCond];
            $studentParams = [$currentAy];
            $rosterConds = ['academic_year = ?'];
            $rosterParams = [$currentAy];
            if ($hasStrand) {
                $studentConds[] = 'strand = ?'; $studentParams[] = $strand;
                $rosterConds[] = 'strand = ?'; $rosterParams[] = $strand;
            }
            if ($hasSection) {
                $studentConds[] = 'section = ?'; $studentParams[] = $section;
                $rosterConds[] = 'section = ?'; $rosterParams[] = $section;
            }
            $sql = 'SELECT COUNT(*) FROM (
                        SELECT school_id FROM students WHERE ' . implode(' AND ', $studentConds) . '
                        UNION
                        SELECT school_id FROM assessment_roster WHERE ' . implode(' AND ', $rosterConds) . '
                    ) combined';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($studentParams, $rosterParams));
            return $stmt->fetchColumn();
        })();
        $expectedSectionsStmt = $pdo->prepare('SELECT DISTINCT section FROM assessment_roster WHERE academic_year = ? ORDER BY section');
        $expectedSectionsStmt->execute([$currentAy]);
        $expectedSections = $expectedSectionsStmt->fetchAll(PDO::FETCH_COLUMN);

        $expectedTotal = $expectedCount;

        // Expected vs completed per section, for the Assessment Statistics bar
        // graph. Both sides use exactly the populations behind the two big
        // numbers above it, so the bars add up to them: "expected" is the same
        // registered-plus-roster union (a student on both counts once, under the
        // section of their real account), and "completed" is the same set of
        // students with a latest assessment, just grouped by section.
        $assessmentBySection = (function () use ($pdo, $currentAy, $studentAyCond, $hasStrand, $strand, $hasSection, $section, $studentFilterClause) {
            $conds = [$studentAyCond]; // roster rows always have a year, so the NULL branch only matches students
            $params = [$currentAy];
            if ($hasStrand) { $conds[] = 'strand = ?'; $params[] = $strand; }
            if ($hasSection) { $conds[] = 'section = ?'; $params[] = $section; }
            $where = implode(' AND ', $conds);

            $expectedBySection = [];
            $registeredBySection = []; // expected students who have an account (the rest are only on the roster)
            $seen = [];
            foreach (['students', 'assessment_roster'] as $table) { // account first: its section wins over a roster row's
                $stmt = $pdo->prepare("SELECT school_id, section FROM $table WHERE $where");
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $r) {
                    if (isset($seen[$r['school_id']])) {
                        continue;
                    }
                    $seen[$r['school_id']] = true;
                    $expectedBySection[$r['section']] = ($expectedBySection[$r['section']] ?? 0) + 1;
                    if ($table === 'students') {
                        $registeredBySection[$r['section']] = ($registeredBySection[$r['section']] ?? 0) + 1;
                    }
                }
            }

            [$clause, $filterParams] = $studentFilterClause('AND');
            $stmt = $pdo->prepare(
                'SELECT s.section, COUNT(*) AS cnt FROM assessments a JOIN students s ON s.user_id = a.student_id
                 WHERE a.is_latest = TRUE' . $clause . ' GROUP BY s.section'
            );
            $stmt->execute($filterParams);
            $completedBySection = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $sections = array_unique(array_merge(array_keys($expectedBySection), array_keys($completedBySection)));
            natcasesort($sections);
            return array_values(array_map(fn($sec) => [
                'section' => (string) $sec,
                'expected' => $expectedBySection[$sec] ?? 0,
                'registered' => $registeredBySection[$sec] ?? 0,
                'completed' => (int) ($completedBySection[$sec] ?? 0),
            ], $sections));
        })();

        $programIds = array_map(fn($r) => (int) $r['top_program_id'], $careerCounts);
        $titles = [];
        $collegeCodes = [];
        if ($programIds) {
            $placeholders = implode(',', array_fill(0, count($programIds), '?'));
            $stmt = $pdo->prepare("SELECT p.id, p.title_enc, c.code AS college_code FROM programs p LEFT JOIN colleges c ON c.id = p.college_id WHERE p.id IN ($placeholders)");
            $stmt->execute($programIds);
            foreach ($stmt->fetchAll() as $r) {
                $titles[(int) $r['id']] = Crypto::dec($r['title_enc']);
                $collegeCodes[(int) $r['id']] = (string) ($r['college_code'] ?? '');
            }
        }
        $topCareers = array_map(fn($r) => [
            'title' => $titles[(int) $r['top_program_id']] ?? 'Removed program',
            'collegeCode' => $collegeCodes[(int) $r['top_program_id']] ?? '',   // for the chart's college colors
            'count' => (int) $r['cnt'],
            'percent' => $recCount > 0 ? round(((int) $r['cnt'] / $recCount) * 100, 1) : 0,
        ], $careerCounts);

        // Capped at 100 — a student who registered before roster gating existed
        // may not be on the roster, and must not push a rate past 100%.
        $pct = fn(int $n, int $total): float => $total > 0 ? min(100.0, round(($n / $total) * 100, 1)) : 0.0;

        return [
            'strand' => $strand,
            'section' => $section,
            'academicYear' => $ay,
            'academicYears' => AcademicYear::available($pdo),
            'totalStudents' => $totalStudents,
            'expectedTotal' => $expectedTotal,
            // Not active-only — a filter dropdown should still offer a
            // since-deactivated section so past terms' data stays filterable.
            'sectionsByStrand' => Sections::byStrand($pdo, false),
            // All three rates below are expressed against the same denominator —
            // the expected student total (see above) — rather than each other's
            // narrower subpopulation (e.g. worksheet completion against only
            // assessed students), so the cards read consistently at a glance.
            'completion' => ['rate' => $pct($assessedCount, $expectedTotal), 'count' => $assessedCount, 'total' => $expectedTotal],
            'worksheet' => ['rate' => $pct($worksheetCount, $expectedTotal), 'count' => $worksheetCount, 'total' => $expectedTotal],
            // Share of students whose highest cosine similarity meets the confidence
            // threshold (security_policies monitoring.lowConfidenceThreshold) —
            // a rate, not an average. "threshold" is exposed so the UI can say
            // exactly what the cut-off is.
            'confidence' => ['rate' => $pct($confidentCount, $expectedTotal), 'count' => $confidentCount, 'total' => $expectedTotal, 'threshold' => $threshold],
            'topStrand' => $topStrand ? [
                'strand' => $topStrand['strand'],
                'count' => (int) $topStrand['cnt'],
                'percent' => $pct((int) $topStrand['cnt'], $totalStudents),
            ] : null,
            'strandDistribution' => array_map(fn($r) => ['strand' => $r['strand'], 'count' => (int) $r['cnt']], $strandRows),
            'sectionDistribution' => array_map(fn($r) => ['section' => $r['section'], 'count' => (int) $r['cnt']], $sectionRows),
            'riasecAverages' => $riasecRow ? [
                'R' => round((float) $riasecRow['r'], 1), 'I' => round((float) $riasecRow['i'], 1), 'A' => round((float) $riasecRow['a'], 1),
                'S' => round((float) $riasecRow['s'], 1), 'E' => round((float) $riasecRow['e'], 1), 'C' => round((float) $riasecRow['c'], 1),
            ] : ['R' => 0, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0],
            'topCareers' => $topCareers,
            'assessmentStats' => [
                'academicYear' => $currentAy,
                'expectedCount' => $expectedCount,
                // Of the expected students, how many have registered an account (by section in bySection).
                'registeredCount' => array_sum(array_column($assessmentBySection, 'registered')),
                'completedCount' => $assessedCount,
                'expectedSections' => $expectedSections,
                'bySection' => $assessmentBySection,
            ],
            'completionByYear' => self::completionByYear($pdo, $ay),
        ];
    }

    /**
     * Assessment completion rate per Academic Year, oldest first — always
     * whole-population, like strandDistribution/sectionDistribution above,
     * regardless of the strand/section filter passed to compute(). Each year is
     * counted as the cards are: the students expected that year (registered that
     * year plus its class roster, a student once) against those with a finished
     * assessment. $selected marks the year being looked at. Every selectable year is
     * listed (AcademicYear::available), the past ones too, even with no students yet.
     *
     * @return array<int,array{academicYear:string,total:int,completed:int,rate:?float,selected:bool}>
     */
    public static function completionByYear(PDO $pdo, string $selected = ''): array
    {
        $totalStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM (SELECT school_id FROM students WHERE academic_year = ?
                                   UNION SELECT school_id FROM assessment_roster WHERE academic_year = ?) expected'
        );
        $currentTotalStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM (SELECT school_id FROM students WHERE academic_year = ? OR academic_year IS NULL
                                   UNION SELECT school_id FROM assessment_roster WHERE academic_year = ?) expected'
        );
        $rows = [];
        foreach (array_reverse(AcademicYear::available($pdo)) as $ay) {
            $stmt = AcademicYear::isPast($ay) ? $totalStmt : $currentTotalStmt;
            $stmt->execute([$ay, $ay]);
            $total = (int) $stmt->fetchColumn();
            [$cond, $params] = AcademicYear::studentCondition('s', $ay);
            $completedStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE a.is_latest = TRUE AND ' . $cond
            );
            $completedStmt->execute($params);
            $completed = (int) $completedStmt->fetchColumn();
            $rows[] = [
                'academicYear' => $ay,
                'total' => $total,
                'completed' => $completed,
                // A year with no students has no rate (null: a gap in the line), not a misleading 0%.
                'rate' => $total > 0 ? min(100.0, round($completed / $total * 100, 1)) : null,
                'selected' => $ay === $selected,
            ];
        }
        return $rows;
    }
}
