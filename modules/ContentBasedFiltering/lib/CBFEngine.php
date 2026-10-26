<?php

/**
 * Content-Based Filtering (CBF) using Cosine Similarity.
 *
 *   User          = the student
 *   User profile  = the student's RIASEC profile from the RIASEC Assessment (scores on a 10-50 scale)
 *   Items         = the MCL degree programs
 *   Item profile  = each program's guidance-approved Holland (RIASEC) code
 *   Similarity    = cosine similarity, cos(S, C) = (S · C) / (||S|| × ||C||)
 *
 * Process (compute()):
 *   1. Student vector  [R, I, A, S, E, C] = the student's six RIASEC results, each as the
 *                      mean item score of that type (raw total ÷ 10 items, so 1.0-5.0).
 *                      Dividing every value by the same number does not change cosine
 *                      values; it only puts the vector on the answer scale (1-5).
 *   2. Course vectors  [R, I, A, S, E, C] = the matching strength of each letter of the
 *                      program's code, 0 if the letter is not in it. The guidance counselors
 *                      rank the letters 1-2-3: 1 = Primary, 2 = Secondary, 3 = Tertiary. Each
 *                      rank is converted to a strength, strength = 4 - rank, so the Primary
 *                      letter gets 3, the Secondary 2 and the Tertiary 1 (config/cbf.php
 *                      holland_rank_weights). Example: AES -> [0, 0, 3, 1, 2, 0] (A Primary 3,
 *                      E Secondary 2, S Tertiary 1). The ranks are the Holland code; the
 *                      strengths are an algorithmic choice. Every code has the same three
 *                      strengths, so every course vector has the same magnitude (sqrt(14)).
 *                      A program without a valid code is flagged and skipped.
 *   3. Cosine          computed between the student vector and EVERY valid course vector,
 *                      with the dot product and both magnitudes kept for auditing.
 *   4. Results         all courses sorted by cosine similarity (highest first; equal
 *                      values keep the program-list order).
 *   5. Best RIASEC Match  every course tied at the highest cosine similarity (ties are
 *                      kept, never broken: courses with the same three letters in the same
 *                      order have the same vector and therefore the same similarity).
 *   6. Alternatives    every course at the next-highest cosine similarity.
 *
 * Nothing else enters the calculation: no worksheet answers, no strand, no electives, no
 * prediction model, and no blend with other sources (no 70/30). The only weighting is the
 * rank-to-strength conversion of the counselors' 1-2-3 letter ranks inside a program's own
 * code. The worksheet and
 * the prediction model are separate recommendation sources (modules/ContentBasedFiltering/lib/RecommendationPipeline.php).
 *
 * Cosine similarity is a similarity score between 0 and 1. It is not an accuracy and not
 * a percentage, and must never be shown as one.
 */
class CBFEngine
{
    /** Feature order used everywhere (student and course vectors). Never change it. */
    public const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    public const RIASEC_LABELS = [
        'R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic',
        'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional',
    ];

    /**
     * Equal cosine values (within this tolerance) are treated as ties. Compared on the full-precision values
     * (cosineExact), never on the 4-decimal display values; two codes that are mathematically equally close differ
     * only by floating-point error (far below 1e-9), and different similarities among the 32 codes differ by much more.
     */
    public const TIE_EPSILON = 1e-9;

    private static ?array $config = null;

    /** The settings in config/cbf.php (loaded once per request). */
    public static function config(): array
    {
        return self::$config ??= require __DIR__ . '/../../../config/cbf.php';
    }

    // ------------------------------------------------------------------
    // Vectors
    // ------------------------------------------------------------------

    /**
     * Student RIASEC totals (R..C, each = sum of the type's items) -> student vector of
     * mean item scores in [R, I, A, S, E, C] order.
     *
     * @param array<string,int|float>|null $scores
     * @throws InvalidArgumentException when a score is missing, not numeric or out of range
     *         (no value is ever substituted)
     */
    public static function studentVector(?array $scores, ?array $config = null): array
    {
        $config ??= self::config();
        $items = (int) $config['riasec_items_per_type'];
        [$min, $max] = [$items * $config['riasec_answer_min'], $items * $config['riasec_answer_max']];
        if (!$scores) {
            throw new InvalidArgumentException('The student has no RIASEC Assessment result.');
        }
        $vector = [];
        foreach (self::DIMENSIONS as $d) {
            $v = $scores[$d] ?? null;
            // is_numeric() accepts NAN and INF, which pass no range check; refuse them too.
            if ($v === null || !is_numeric($v) || !is_finite((float) $v)) {
                throw new InvalidArgumentException("The RIASEC score for $d is missing or not a number.");
            }
            if ($v < $min || $v > $max) {
                throw new InvalidArgumentException("The RIASEC score for $d ($v) is outside the valid range $min-$max.");
            }
            $vector[] = (float) $v / $items;   // full precision; rounded only for display
        }
        return $vector;
    }

    /**
     * Refuses anything other than a list of six finite numbers in [R, I, A, S, E, C] order, so a
     * short, keyed or non-numeric vector can never give a silently wrong similarity.
     *
     * @throws InvalidArgumentException
     */
    public static function assertVector(array $v): void
    {
        if (!array_is_list($v) || count($v) !== count(self::DIMENSIONS)) {
            throw new InvalidArgumentException('A vector must list exactly ' . count(self::DIMENSIONS) . ' values in R, I, A, S, E, C order.');
        }
        foreach ($v as $x) {
            if ((!is_int($x) && !is_float($x)) || !is_finite((float) $x)) {
                throw new InvalidArgumentException('Every vector value must be a finite number.');
            }
        }
    }

    /**
     * A program's Holland code -> course vector in [R, I, A, S, E, C] order. The guidance
     * counselors rank the letters 1-2-3 (1 = Primary, 2 = Secondary, 3 = Tertiary); the letter
     * at rank r gets the r-th matching strength (default strength = 4 - r: 3, 2, 1) and the
     * other types get 0.
     * Example: RIC -> [3, 2, 0, 0, 0, 1]; IRC -> [2, 3, 0, 0, 0, 1].
     * Returns null for a missing or invalid code (anything other than three different
     * RIASEC letters), so the program is flagged instead of given a made-up vector.
     *
     * @param float[]|null $weights matching strengths for rank 1, 2 and 3; null = config/cbf.php
     */
    public static function courseVector(?string $hollandCode, ?array $weights = null): ?array
    {
        $code = strtoupper(trim((string) $hollandCode));
        if (!preg_match('/^[RIASEC]{3}$/', $code) || count(array_unique(str_split($code))) !== 3) {
            return null;
        }
        $weights ??= self::config()['holland_rank_weights'];
        return array_map(function ($d) use ($code, $weights) {
            $position = strpos($code, $d);
            return $position === false ? 0 : $weights[$position];
        }, self::DIMENSIONS);
    }

    public static function dotProduct(array $a, array $b): float
    {
        self::assertVector($a);
        self::assertVector($b);
        $sum = 0.0;
        foreach ($a as $i => $x) {
            $sum += $x * $b[$i];
        }
        return $sum;
    }

    public static function magnitude(array $v): float
    {
        self::assertVector($v);
        return sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
    }

    /**
     * cos(A, B) = (A · B) / (||A|| × ||B||). null when either magnitude is 0: the
     * similarity is undefined, and returning 0 would be a misleading value.
     */
    public static function cosineSimilarity(array $a, array $b): ?float
    {
        $den = self::magnitude($a) * self::magnitude($b);
        return $den > 0 ? self::dotProduct($a, $b) / $den : null;
    }

    // ------------------------------------------------------------------
    // CBF over all courses
    // ------------------------------------------------------------------

    /**
     * Runs the CBF for one student against every program.
     *
     * @param array<string,int|float>|null $riasecScores student's RIASEC totals keyed R..C
     * @param array<int,array{id:int,title?:string,hollandCode:?string}> $programs all active programs
     * @return array{studentScores:array, studentVector:float[], studentMagnitude:float,
     *               results:array, excluded:array, candidates:array}
     *         results: every valid course, highest cosine first, each with
     *         courseVector, dotProduct, courseMagnitude, cosine (4 decimals, for display) and cosineExact.
     * @throws InvalidArgumentException for an invalid RIASEC profile
     */
    public static function compute(?array $riasecScores, array $programs, ?array $config = null): array
    {
        $config ??= self::config();
        $student = self::studentVector($riasecScores, $config);
        $studentMagnitude = self::magnitude($student);

        $results = [];
        $excluded = [];
        foreach ($programs as $p) {
            // The code as courseVector() reads it, so the stored code uses the same letters as the vector.
            $code = strtoupper(trim((string) ($p['hollandCode'] ?? '')));
            $course = self::courseVector($code, $config['holland_rank_weights'] ?? null);
            if ($course === null) {
                $excluded[] = ['id' => $p['id'], 'title' => $p['title'] ?? null, 'hollandCode' => $p['hollandCode'] ?? null,
                    'reason' => 'Missing or invalid RIASEC mapping (needs three different letters from R, I, A, S, E, C).'];
                continue;
            }
            $cosine = self::cosineSimilarity($student, $course);
            if ($cosine === null) {
                $excluded[] = ['id' => $p['id'], 'title' => $p['title'] ?? null, 'hollandCode' => $p['hollandCode'],
                    'reason' => 'Zero-magnitude vector: cosine similarity is undefined.'];
                continue;
            }
            $results[] = [
                'id' => (int) $p['id'],
                'title' => $p['title'] ?? null,
                'hollandCode' => $code,
                'courseVector' => $course,
                'dotProduct' => round(self::dotProduct($student, $course), 4),
                'courseMagnitude' => round(self::magnitude($course), 4),
                'cosine' => $cosine,
            ];
        }

        // Highest similarity first; equal values keep the program-list order (lower id).
        usort($results, fn($a, $b) => abs($a['cosine'] - $b['cosine']) < self::TIE_EPSILON
            ? $a['id'] <=> $b['id']
            : $b['cosine'] <=> $a['cosine']);

        $candidates = self::selectCandidates($results, 'cosine');
        $alternatives = self::selectAlternatives($results, 'cosine');
        foreach ($results as &$r) {
            $r['cosineExact'] = $r['cosine'];          // full precision: ties and groups are decided on this
            $r['cosine'] = round($r['cosine'], 4);     // display value
        }
        unset($r);

        return [
            'studentScores' => array_combine(self::DIMENSIONS, array_map(fn($d) => $riasecScores[$d], self::DIMENSIONS)),
            'studentVector' => $student,
            'studentMagnitude' => round($studentMagnitude, 4),
            'results' => $results,
            'excluded' => $excluded,
            'candidates' => array_map(fn($c) => (int) $c['id'], $candidates),
            'alternatives' => array_map(fn($c) => (int) $c['id'], $alternatives),
        ];
    }

    /**
     * Best RIASEC Match (CBF candidates) from results sorted by $key (highest first): every
     * course tied at the highest cosine similarity. Also used by api/recommendations.php on saved results.
     */
    public static function selectCandidates(array $sorted, string $key = 'cosine'): array
    {
        if (!$sorted) {
            return [];
        }
        $best = (float) $sorted[0][$key];
        if ($best <= 0) {
            return [];
        }
        return array_values(array_filter($sorted, fn($e) => abs((float) $e[$key] - $best) < self::TIE_EPSILON));
    }

    /**
     * Alternative courses from results sorted by $key (highest first): every course at the
     * next-highest cosine similarity after the Best RIASEC Match level. Also used by
     * api/recommendations.php on saved results.
     */
    public static function selectAlternatives(array $sorted, string $key = 'cosine'): array
    {
        $top = self::selectCandidates($sorted, $key);
        $rest = array_slice($sorted, count($top));
        if (!$top || !$rest) {
            return [];
        }
        return self::selectCandidates(array_values($rest), $key);
    }

    /**
     * Student's top N RIASEC letters (highest score first; ties keep R,I,A,S,E,C order).
     * Not used by the CBF calculation; kept for the dataset export and the prediction
     * model code (modules/ContentBasedFiltering/lib/PredictionModel.php), which are outside the CBF.
     */
    public static function topRiasecLetters(array $scores, int $n = 3): array
    {
        $ranked = array_intersect_key(array_merge(array_flip(self::DIMENSIONS), $scores), array_flip(self::DIMENSIONS));
        arsort($ranked);
        return array_slice(array_keys($ranked), 0, $n);
    }
}
