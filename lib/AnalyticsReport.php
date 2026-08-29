<?php

/**
 * Single source of truth for the institution-wide analytics figures shown
 * on the Analytics Dashboard (api/analytics.php) and mirrored, verbatim,
 * into the fixed-format report emailed to the SHS Principal
 * (api/send-principal-report.php). Both callers read the same numbers —
 * there is no separate, hand-maintained set of fields for the report, so
 * the two can never drift out of sync or ask staff to re-enter data that
 * the system already has.
 */
class AnalyticsReport
{
    /** @return array<string,mixed> */
    public static function compute(PDO $pdo, string $strand = '', string $section = ''): array
    {
        $validStrands = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'];
        if (!in_array($strand, $validStrands, true)) {
            $strand = '';
        }
        $hasStrand = $strand !== '';
        $hasSection = $section !== '';

        // Builds the "WHERE ... AND ..." (or "" ) clause plus matching bind params
        // for whichever of the two filters are active, given the students-table
        // alias in use. Every metric below joins to `students s` one way or
        // another, so this alias is always 's'.
        $studentFilterClause = function (string $leadKeyword = 'WHERE') use ($hasStrand, $strand, $hasSection, $section): array {
            $conds = [];
            $params = [];
            if ($hasStrand) {
                $conds[] = 's.strand = ?';
                $params[] = $strand;
            }
            if ($hasSection) {
                $conds[] = 's.section = ?';
                $params[] = $section;
            }
            if (!$conds) {
                return ['', []];
            }
            $joiner = $leadKeyword === 'WHERE' ? ' WHERE ' : ' AND ';
            return [$joiner . implode(' AND ', $conds), $params];
        };

        $totalStudents = (int) (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause();
            // No join needed here — students is the base table, so use its own columns directly.
            $clause = str_replace('s.', '', $clause);
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

        $thresholdRow = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
        $threshold = $thresholdRow !== false ? (float) $thresholdRow : 0.50;

        $latestRecScores = (function () use ($pdo, $hasStrand, $hasSection, $studentFilterClause) {
            $needsJoin = $hasStrand || $hasSection;
            [$clause, $params] = $studentFilterClause();
            $sql = 'SELECT DISTINCT ON (r.student_id) r.top_score FROM recommendations r'
                . ($needsJoin ? ' JOIN students s ON s.user_id = r.student_id' . $clause : '')
                . ' ORDER BY r.student_id, r.computed_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        })();
        $recCount = count($latestRecScores);
        $confidentCount = count(array_filter($latestRecScores, fn($s) => (float) $s >= $threshold));

        // Always the full population breakdown, regardless of the strand/section filter.
        $strandRows = $pdo->query('SELECT strand, COUNT(*) AS cnt FROM students GROUP BY strand ORDER BY cnt DESC')->fetchAll();
        $topStrand = $strandRows[0] ?? null;
        $sectionRows = $pdo->query('SELECT section, COUNT(*) AS cnt FROM students GROUP BY section ORDER BY cnt DESC')->fetchAll();

        $riasecRow = (function () use ($pdo, $studentFilterClause) {
            [$clause, $params] = $studentFilterClause('AND');
            $stmt = $pdo->prepare(
                'SELECT AVG(a.score_r) AS r, AVG(a.score_i) AS i, AVG(a.score_a) AS a, AVG(a.score_s) AS s, AVG(a.score_e) AS e, AVG(a.score_c) AS c
                 FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE a.is_latest = TRUE' . $clause
            );
            $stmt->execute($params);
            return $stmt->fetch();
        })();

        $careerCounts = (function () use ($pdo, $hasStrand, $hasSection, $studentFilterClause) {
            $needsJoin = $hasStrand || $hasSection;
            [$clause, $params] = $studentFilterClause();
            $sql = "SELECT top_program_id, COUNT(*) AS cnt FROM (
                        SELECT DISTINCT ON (r.student_id) r.student_id, r.top_program_id
                        FROM recommendations r"
                . ($needsJoin ? ' JOIN students s ON s.user_id = r.student_id' . $clause : '')
                . " ORDER BY r.student_id, r.computed_at DESC
                    ) latest GROUP BY top_program_id ORDER BY cnt DESC LIMIT 5";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        })();

        // Assessment Statistics: expected (from the admin-uploaded roster for the
        // current Academic Year) vs. actually completed, matching the same
        // strand/section filters.
        $currentAy = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'academicYear.current'")->fetchColumn();
        $expectedCount = (int) (function () use ($pdo, $currentAy, $hasStrand, $strand, $hasSection, $section) {
            $conds = ['academic_year = ?'];
            $params = [$currentAy];
            if ($hasStrand) { $conds[] = 'strand = ?'; $params[] = $strand; }
            if ($hasSection) { $conds[] = 'section = ?'; $params[] = $section; }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_roster WHERE ' . implode(' AND ', $conds));
            $stmt->execute($params);
            return $stmt->fetchColumn();
        })();
        $expectedSectionsStmt = $pdo->prepare('SELECT DISTINCT section FROM assessment_roster WHERE academic_year = ? ORDER BY section');
        $expectedSectionsStmt->execute([$currentAy]);
        $expectedSections = $expectedSectionsStmt->fetchAll(PDO::FETCH_COLUMN);

        $programIds = array_map(fn($r) => (int) $r['top_program_id'], $careerCounts);
        $titles = [];
        if ($programIds) {
            $placeholders = implode(',', array_fill(0, count($programIds), '?'));
            $stmt = $pdo->prepare("SELECT id, title_enc FROM programs WHERE id IN ($placeholders)");
            $stmt->execute($programIds);
            foreach ($stmt->fetchAll() as $r) {
                $titles[(int) $r['id']] = Crypto::dec($r['title_enc']);
            }
        }
        $topCareers = array_map(fn($r) => [
            'title' => $titles[(int) $r['top_program_id']] ?? '—',
            'count' => (int) $r['cnt'],
            'percent' => $recCount > 0 ? round(((int) $r['cnt'] / $recCount) * 100, 1) : 0,
        ], $careerCounts);

        $pct = fn(int $n, int $total): float => $total > 0 ? round(($n / $total) * 100, 1) : 0.0;

        return [
            'strand' => $strand,
            'section' => $section,
            'totalStudents' => $totalStudents,
            // All three rates below are expressed against the same denominator —
            // every registered student — rather than each other's narrower
            // subpopulation (e.g. worksheet completion against only assessed
            // students), so the cards read consistently at a glance.
            'completion' => ['rate' => $pct($assessedCount, $totalStudents), 'count' => $assessedCount, 'total' => $totalStudents],
            'worksheet' => ['rate' => $pct($worksheetCount, $totalStudents), 'count' => $worksheetCount, 'total' => $totalStudents],
            'confidence' => ['rate' => $pct($confidentCount, $totalStudents), 'count' => $confidentCount, 'total' => $totalStudents],
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
                'completedCount' => $assessedCount,
                'expectedSections' => $expectedSections,
            ],
        ];
    }
}
