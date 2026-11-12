<?php

require_once __DIR__ . '/CBFEngine.php';
require_once __DIR__ . '/CBFData.php';

/**
 * A student's result "matches" when their Preferred Course (the program the career on their
 * worksheet links to) is also one of their Best RIASEC Match programs: the programs with the
 * highest cosine similarity to their RIASEC profile (modules/ContentBasedFiltering/lib/CBFEngine.php). It is a "close"
 * match when the Preferred Course is one of their Alternative Courses (the next-highest similarity). Otherwise it is a
 * mismatch and they are asked to see the Guidance Office. A career that could not be linked
 * to any program is a mismatch too. A student who has not done the worksheet yet has no
 * recommendation, so there is nothing to compare.
 *
 * The Preferred Course never changes the CBF result; this only compares the two.
 */
class Mismatch
{
    /**
     * @param array<int,array{programId:int,cosine:float}> $scores the stored per-program CBF results
     */
    public static function isMismatch(?int $statedProgramId, array $scores): bool
    {
        if ($statedProgramId === null) {
            return true;
        }
        usort($scores, fn($a, $b) => [$b['cosine'], $a['programId']] <=> [$a['cosine'], $b['programId']]);
        $bestRows = CBFEngine::selectCandidates($scores, 'cosine');
        $best = array_map(fn($s) => (int) $s['programId'], $bestRows);
        // A Preferred Course among the Alternative Courses (next-highest similarity) is a close match, not a mismatch.
        $rest = array_values(array_filter($scores, fn($s) => !in_array((int) $s['programId'], $best, true)));
        $close = $best ? array_map(fn($s) => (int) $s['programId'], CBFEngine::selectCandidates($rest, 'cosine')) : [];
        return !in_array($statedProgramId, $best, true) && !in_array($statedProgramId, $close, true);
    }

    /**
     * The student ids (user ids) whose latest recommendation is a mismatch. Uses the status
     * saved with the result (recommendations.match_status) when present.
     *
     * @return array<int,true> keyed by user id
     */
    public static function studentIds(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT DISTINCT ON (student_id) student_id, stated_program_id, scores, match_status
             FROM recommendations WHERE source_assessment_id IN (SELECT id FROM assessments WHERE is_latest = TRUE)
             ORDER BY student_id, computed_at DESC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if ($r['match_status'] !== null) {
                $mismatch = $r['match_status'] === 'mismatch';
            } else {
                $scores = json_decode((string) $r['scores'], true) ?: [];
                $stated = $r['stated_program_id'] !== null ? (int) $r['stated_program_id'] : null;
                $mismatch = self::isMismatch($stated, $scores);
            }
            if ($mismatch) {
                $out[(int) $r['student_id']] = true;
            }
        }
        return $out;
    }
}
