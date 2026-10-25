<?php

require_once __DIR__ . '/../../Encryption/lib/Crypto.php';
require_once __DIR__ . '/CBFEngine.php';
require_once __DIR__ . '/RecommendationPipeline.php';

/**
 * Loads the inputs of the CBF engine from the existing tables, so that
 * modules/CareerProfiling/api/worksheet-submit.php and db/cbf_debug.php both build
 * the student profile and the program dataset the same way.
 *
 *   Student profile  <- assessments (latest attempt: score_r .. score_c) -> used by the CBF
 *                     + worksheets (latest: Preferred Course, electives) -> worksheet source only
 *                     + students.strand (stored; not used by the CBF)
 *   Program dataset  <- programs (Active): title, Holland code (+ college code)
 */
class CBFData
{
    /**
     * Postgres text[] as returned by PDO (e.g. {STEM,"Biology 1-2"}) -> PHP array.
     * Postgres only quotes elements that need it, so both quoted and bare
     * elements must be read.
     */
    public static function parseTextArray(?string $raw): array
    {
        if ($raw === null || $raw === '{}' || $raw === '') {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}"]+)/', $raw, $m, PREG_SET_ORDER);
        $values = [];
        foreach ($m as $match) {
            if (isset($match[2]) && $match[2] !== '') {
                if (strtoupper($match[2]) !== 'NULL') {
                    $values[] = $match[2];
                }
            } else {
                $values[] = str_replace(['\\"', '\\\\'], ['"', '\\'], $match[1]);
            }
        }
        return $values;
    }

    /** PHP array -> Postgres text[] literal. */
    public static function textArrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(fn($v) => '"' . addcslashes($v, '\\"') . '"', $values)) . '}';
    }

    /** @return array<string,int>|null R..C scores of the student's latest assessment, or null if none. */
    public static function latestRiasecScores(PDO $pdo, int $studentId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT score_r, score_i, score_a, score_s, score_e, score_c
             FROM assessments WHERE student_id = ? AND is_latest = TRUE'
        );
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'R' => (int) $row['score_r'], 'I' => (int) $row['score_i'], 'A' => (int) $row['score_a'],
            'S' => (int) $row['score_s'], 'E' => (int) $row['score_e'], 'C' => (int) $row['score_c'],
        ];
    }

    /**
     * The full student profile the engine compares against programs.
     * $electives overrides the stored worksheet (used while a worksheet is being submitted).
     *
     * @return array{riasec: ?array, strand: ?string, electives: array, statedProgramId: ?int}|null null if no such student
     */
    public static function studentProfile(PDO $pdo, int $studentId, ?array $electives = null): ?array
    {
        $stmt = $pdo->prepare('SELECT strand FROM students WHERE user_id = ?');
        $stmt->execute([$studentId]);
        $strand = $stmt->fetchColumn();
        if ($strand === false) {
            return null;
        }

        $statedProgramId = null;
        if ($electives === null) {
            $ws = $pdo->prepare(
                'SELECT electives, stated_program_id FROM worksheets WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 1'
            );
            $ws->execute([$studentId]);
            $wsRow = $ws->fetch();
            $electives = $wsRow ? self::parseTextArray($wsRow['electives']) : [];
            $statedProgramId = $wsRow ? (int) $wsRow['stated_program_id'] : null;
        }

        return [
            'riasec' => self::latestRiasecScores($pdo, $studentId),
            'strand' => $strand,
            'electives' => $electives,
            'statedProgramId' => $statedProgramId,
        ];
    }

    /**
     * Recompute every student who has a Career Worksheet with the current program codes and
     * settings: their latest RIASEC assessment and latest worksheet, saved as a new
     * `recommendations` row each (older rows stay as history). Used after a program's Holland code
     * changes (modules/CareerDataset/api/programs.php) and by db/recompute_recommendations.php.
     * Each student is saved in a transaction of its own; one failure does not stop the others.
     * Sends nothing to students.
     *
     * @param callable|null $report called per student: fn(string $schoolId, ?array $saved, ?string $error)
     * @return array{recomputed: int, failed: int}
     */
    public static function recomputeAll(PDO $pdo, ?callable $report = null): array
    {
        $students = $pdo->query(
            "SELECT s.user_id, s.school_id, a.id AS assessment_id,
                    (SELECT w.id FROM worksheets w WHERE w.student_id = s.user_id ORDER BY w.submitted_at DESC LIMIT 1) AS worksheet_id
             FROM students s JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
             ORDER BY s.school_id"
        )->fetchAll();
        $done = 0;
        $failed = 0;
        foreach ($students as $st) {
            if ($st['worksheet_id'] === null) {
                continue; // no worksheet yet -> no recommendation to refresh
            }
            $pdo->beginTransaction();
            try {
                $profile = self::studentProfile($pdo, (int) $st['user_id']);
                $saved = self::saveRecommendation(
                    $pdo, (int) $st['user_id'], $profile, $profile['statedProgramId'], (int) $st['assessment_id'], (int) $st['worksheet_id']
                );
                $pdo->commit();
                $done++;
                if ($report) {
                    $report($st['school_id'], $saved, null);
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $failed++;
                error_log('[recompute] ' . $st['school_id'] . ': ' . $e->getMessage());
                if ($report) {
                    $report($st['school_id'], null, $e->getMessage());
                }
            }
        }
        return ['recomputed' => $done, 'failed' => $failed];
    }

    /**
     * Run the recommendation sources (modules/ContentBasedFiltering/lib/RecommendationPipeline.php: CBF, worksheet,
     * WEKA prediction model) for one student and save the result as a new
     * `recommendations` row (earlier rows are kept as history). Also raises the
     * low-confidence monitoring flag when the highest cosine similarity is below the
     * configured threshold. The caller manages the transaction.
     *
     * @return array{recommendationId: int, topProgramId: int, topScore: float, status: string, reason: ?string,
     *               bestRiasecMatchTitles: string[], alternativeTitles: string[]}|null null if there are no active programs
     * @throws InvalidArgumentException when the student's RIASEC profile is missing or invalid
     */
    public static function saveRecommendation(PDO $pdo, int $studentId, array $profile, ?int $statedProgramId, int $assessmentId, ?int $worksheetId): ?array
    {
        $programs = self::activePrograms($pdo);
        if (!$programs) {
            return null;
        }
        $result = RecommendationPipeline::run($profile, $programs, $statedProgramId !== null ? [$statedProgramId] : []);
        $cbf = $result['cbf'];
        if ($cbf['status'] !== 'available') {
            throw new InvalidArgumentException($cbf['reason']);
        }
        if (!$cbf['results']) {
            throw new InvalidArgumentException('No program has a valid RIASEC mapping.');
        }
        // Internal only (monitoring threshold / history); never shown as a percentage.
        $topProgramId = (int) $cbf['results'][0]['id'];
        $topScore = (float) $cbf['results'][0]['cosine'];

        // Every course's CBF calculation, so the result can be audited later.
        $scoresForStorage = array_map(fn($r) => [
            'programId' => $r['id'], 'hollandCode' => $r['hollandCode'], 'courseVector' => $r['courseVector'],
            'dotProduct' => $r['dotProduct'], 'courseMagnitude' => $r['courseMagnitude'], 'cosine' => $r['cosine'],
            'letterOrder' => $r['letterOrder'],   // 1st/2nd/3rd letter matches (orders ties only)
            'studentVector' => $cbf['studentVector'], 'studentMagnitude' => $cbf['studentMagnitude'],
        ], $cbf['results']);

        $recInsert = $pdo->prepare(
            'INSERT INTO recommendations (student_id, stated_program_id, scores, top_program_id, top_score, source_assessment_id, source_worksheet_id,
                                          match_status, mismatch_reason, cbf_program_ids, prediction_program_ids, final_program_ids, model_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?) RETURNING id'
        );
        $recInsert->execute([
            $studentId, $statedProgramId, json_encode($scoresForStorage), $topProgramId, $topScore, $assessmentId, $worksheetId,
            $result['status'], $result['reason'],
            self::intArrayLiteral($cbf['candidateIds']), // Best RIASEC Match (CBF); final Best Match stays NULL
            // Prediction model (WEKA): its course, or NULL when the model did not run.
            $result['prediction']['status'] === 'unavailable' ? null : self::intArrayLiteral($result['prediction']['courseIds']),
            $result['prediction']['modelVersion'] ?? null,
        ]);
        $recommendationId = (int) $recInsert->fetchColumn();

        $threshold = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
        $threshold = $threshold !== false ? (float) $threshold : 0.50;
        if ($topScore < $threshold) {
            $pendingCheck = $pdo->prepare(
                "SELECT id, status FROM monitoring_flags WHERE student_id = ? AND reason = 'low_confidence' AND status IN ('pending', 'escalated')"
            );
            $pendingCheck->execute([$studentId]);
            $openFlags = $pendingCheck->fetchAll();
            // A flag already with the counselors stays one flag, but now shows the newest result (a retake that is
            // still low), so nobody approves a result the student has replaced.
            foreach ($openFlags as $open) {
                if ($open['status'] === 'escalated') {
                    $pdo->prepare('UPDATE monitoring_flags SET recommendation_id = ? WHERE id = ?')->execute([$recommendationId, $open['id']]);
                }
            }
            if (!$openFlags) {
                $pdo->prepare(
                    "INSERT INTO monitoring_flags (student_id, recommendation_id, reason, status) VALUES (?, ?, 'low_confidence', 'pending')"
                )->execute([$studentId, $recommendationId]);
            }
        }

        $titles = array_column($programs, 'title', 'id');
        $named = fn(array $ids) => array_values(array_map(fn($id) => $titles[$id], $ids));
        return ['recommendationId' => $recommendationId, 'topProgramId' => $topProgramId, 'topScore' => $topScore,
            'status' => $result['status'], 'reason' => $result['reason'],
            'bestRiasecMatchTitles' => $named($result['bestRiasecMatchIds']), 'alternativeTitles' => $named($result['alternativeIds'])];
    }

    /** PostgreSQL INT[] literal, e.g. {3,4,6}. */
    public static function intArrayLiteral(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }

    /** Parses a PostgreSQL INT[] value ("{3,4,6}") into ints; null stays null. */
    public static function parseIntArray(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $inner = trim($value, '{}');
        return $inner === '' ? [] : array_map('intval', explode(',', $inner));
    }

    /** Every Active program, decrypted, in the shape CBFEngine::compute() expects. */
    public static function activePrograms(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT p.id, p.title_enc, p.holland_code_enc, p.related_strands, c.code AS college_code
             FROM programs p JOIN colleges c ON c.id = p.college_id
             WHERE p.status = 'Active' ORDER BY p.id"
        )->fetchAll();

        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'title' => Crypto::dec($r['title_enc']),
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'relatedStrands' => self::parseTextArray($r['related_strands']),
            'collegeCode' => $r['college_code'],
        ], $rows);
    }
}
