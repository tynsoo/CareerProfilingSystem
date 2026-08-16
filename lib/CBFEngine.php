<?php

/**
 * Content-Based Filtering recommendation engine (thesis Chapter 3):
 *   Final Match Score = 0.70 * RIASEC cosine similarity + 0.30 * worksheet-stated program indicator
 *
 * A program's Holland code (e.g. "RIC") is converted to a 6-dim vector by
 * rank-weighting its letters (primary=3, secondary=2, tertiary=1, absent=0) —
 * this is this project's own interpretive choice for turning a 3-letter code
 * into a vector, not part of the thesis formula itself.
 */
class CBFEngine
{
    private const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    public static function hollandCodeToVector(string $code): array
    {
        $weights = [];
        $letters = str_split(strtoupper($code));
        foreach (array_slice($letters, 0, 3) as $i => $letter) {
            $weights[$letter] = 3 - $i;
        }
        return array_map(fn($d) => $weights[$d] ?? 0, self::DIMENSIONS);
    }

    /** @param array<string,int> $scores keyed by R,I,A,S,E,C */
    public static function scoresToVector(array $scores): array
    {
        return array_map(fn($d) => $scores[$d] ?? 0, self::DIMENSIONS);
    }

    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        foreach (self::DIMENSIONS as $i => $_) {
            $x = $a[$i];
            $y = $b[$i];
            $dot += $x * $y;
            $magA += $x * $x;
            $magB += $y * $y;
        }
        if ($magA <= 0 || $magB <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * @param array<string,int> $studentScores RIASEC scores, keyed R,I,A,S,E,C
     * @param array<int,array{id:int,hollandCode:string}> $programs active programs to score against
     * @param int|null $statedProgramId the program the student picked on the worksheet, if any
     * @return array{all: array, top3: array, statedOutsideTop3: ?array}
     */
    public static function recommend(array $studentScores, array $programs, ?int $statedProgramId): array
    {
        $studentVec = self::scoresToVector($studentScores);

        $scored = array_map(function ($program) use ($studentVec, $statedProgramId) {
            $programVec = self::hollandCodeToVector($program['hollandCode']);
            $cosine = self::cosineSimilarity($studentVec, $programVec);
            $indicator = ($statedProgramId !== null && $program['id'] === $statedProgramId) ? 1.0 : 0.0;
            $final = 0.70 * $cosine + 0.30 * $indicator;
            return $program + [
                'cosine' => round($cosine, 4),
                'indicator' => $indicator,
                'score' => round($final, 4),
            ];
        }, $programs);

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $top3 = array_slice($scored, 0, 3);
        $statedInTop3 = $statedProgramId !== null && in_array($statedProgramId, array_column($top3, 'id'), true);

        $statedEntry = null;
        if ($statedProgramId !== null && !$statedInTop3) {
            foreach ($scored as $s) {
                if ($s['id'] === $statedProgramId) {
                    $statedEntry = $s;
                    break;
                }
            }
        }

        return ['all' => $scored, 'top3' => $top3, 'statedOutsideTop3' => $statedEntry];
    }
}
