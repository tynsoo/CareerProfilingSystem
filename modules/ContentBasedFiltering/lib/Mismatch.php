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
        return self::status($statedProgramId, $scores) === 'mismatch';
    }

    /**
     * 'match' (a Best RIASEC Match), 'close' (an Alternative Course) or 'mismatch'.
     *
     * @param array<int,array{programId:int,cosine:float}> $scores the stored per-program CBF results
     */
    public static function status(?int $statedProgramId, array $scores): string
    {
        if ($statedProgramId === null) {
            return 'mismatch';
        }
        usort($scores, fn($a, $b) => [$b['cosine'], $a['programId']] <=> [$a['cosine'], $b['programId']]);
        $bestRows = CBFEngine::selectCandidates($scores, 'cosine');
        $best = array_map(fn($s) => (int) $s['programId'], $bestRows);
        if (in_array($statedProgramId, $best, true)) {
            return 'match';
        }
        // A Preferred Course among the Alternative Courses (next-highest similarity) is a close match, not a mismatch.
        $rest = array_values(array_filter($scores, fn($s) => !in_array((int) $s['programId'], $best, true)));
        $close = $best ? array_map(fn($s) => (int) $s['programId'], CBFEngine::selectCandidates($rest, 'cosine')) : [];
        return in_array($statedProgramId, $close, true) ? 'close' : 'mismatch';
    }

    /**
     * The student ids (user ids) whose latest recommendation is a mismatch. Uses the status
     * saved with the result (recommendations.match_status) when present.
     *
     * @return array<int,true> keyed by user id
     */
    public static function studentIds(PDO $pdo): array
    {
        return array_map(fn() => true, array_filter(self::statusByStudent($pdo), fn($st) => $st === 'mismatch'));
    }

    /**
     * Every student with a recommendation for their latest assessment, with its status: 'match', 'close' or
     * 'mismatch' (the status saved with the result when present, else worked out from the stored scores).
     *
     * @return array<int,string> keyed by user id
     */
    public static function statusByStudent(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT DISTINCT ON (student_id) student_id, stated_program_id, scores, match_status
             FROM recommendations WHERE source_assessment_id IN (SELECT id FROM assessments WHERE is_latest = TRUE)
             ORDER BY student_id, computed_at DESC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if ($r['match_status'] !== null) {
                $out[(int) $r['student_id']] = (string) $r['match_status'];
            } else {
                $scores = json_decode((string) $r['scores'], true) ?: [];
                $stated = $r['stated_program_id'] !== null ? (int) $r['stated_program_id'] : null;
                $out[(int) $r['student_id']] = self::status($stated, $scores);
            }
        }
        return $out;
    }
}
