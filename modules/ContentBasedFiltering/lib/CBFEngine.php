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
 *   2. Course vectors  [R, I, A, S, E, C] = 1 if the letter is in the program's code,
 *                      0 if not (e.g. AES -> [0, 0, 1, 1, 1, 0]). The counselors' order of the
 *                      letters (1st, 2nd, 3rd) carries no numeric weight: letter-position
 *                      weights (e.g. 3-2-1) are not approved. It is used only in step 4.
 *                      A program without a valid code is flagged and skipped.
 *   3. Cosine          computed between the student vector and EVERY valid course vector,
 *                      with the dot product and both magnitudes kept for auditing.
 *   4. Results         all courses sorted by cosine similarity (highest first). Courses with
 *                      the same similarity are listed by the counselors' 1-2-3 letter ranking:
 *                      first those whose 1st letter matches the student's 1st-ranked interest,
 *                      then the 2nd, then the 3rd (letterOrderMatch()), then the program-list
 *                      order. This only orders a tie; it changes no cosine value and no
 *                      membership, and uses no numeric weights.
 *   5. Best RIASEC Match  every course tied at the highest cosine similarity (ties are
 *                      kept, all listed: courses with the same three letters have the
 *                      same vector and therefore the same similarity).
 *   6. Alternatives    every course at the next-highest cosine similarity.
 *
 * Nothing else enters the calculation: no worksheet answers, no strand, no electives,
 * no prediction model, and no weights (no 70/30, no 1.00/0.67/0.33). The worksheet and
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

    /** Equal cosine values (within this tolerance) are treated as ties. */
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
            if ($v === null || !is_numeric($v)) {
                throw new InvalidArgumentException("The RIASEC score for $d is missing or not a number.");
            }
            if ($v < $min || $v > $max) {
                throw new InvalidArgumentException("The RIASEC score for $d ($v) is outside the valid range $min-$max.");
            }
            $vector[] = round($v / $items, 4);
        }
        return $vector;
    }

    /**
     * A program's Holland code -> binary course vector in [R, I, A, S, E, C] order.
     * Returns null for a missing or invalid code (anything other than three different
     * RIASEC letters), so the program is flagged instead of given a made-up vector.
     */
    public static function courseVector(?string $hollandCode): ?array
    {
        $code = strtoupper(trim((string) $hollandCode));
        if (!preg_match('/^[RIASEC]{3}$/', $code) || count(array_unique(str_split($code))) !== 3) {
            return null;
        }
        return array_map(fn($d) => str_contains($code, $d) ? 1 : 0, self::DIMENSIONS);
    }

    public static function dotProduct(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $x) {
            $sum += $x * $b[$i];
        }
        return $sum;
    }

    public static function magnitude(array $v): float
    {
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
     *         courseVector, dotProduct, courseMagnitude and cosine.
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
            $course = self::courseVector($p['hollandCode'] ?? null);
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
                'hollandCode' => strtoupper($p['hollandCode']),
                'courseVector' => $course,
                'dotProduct' => round(self::dotProduct($student, $course), 4),
                'courseMagnitude' => round(self::magnitude($course), 4),
                'cosine' => $cosine,
                'letterOrder' => self::letterOrderMatch($riasecScores, $p['hollandCode']),
            ];
        }

        // Highest similarity first; equal values by the 1-2-3 letter ranking, then program-list order (lower id).
        usort($results, fn($a, $b) => abs($a['cosine'] - $b['cosine']) < self::TIE_EPSILON
            ? [$b['letterOrder'], $a['id']] <=> [$a['letterOrder'], $b['id']]
            : $b['cosine'] <=> $a['cosine']);

        $candidates = self::selectCandidates($results, 'cosine');
        $alternatives = self::selectAlternatives($results, 'cosine');
        foreach ($results as &$r) {
            $r['cosine'] = round($r['cosine'], 4);
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
     * course tied at the highest cosine similarity. Also used by modules/ContentBasedFiltering/api/recommendations.php on saved results.
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
     * modules/ContentBasedFiltering/api/recommendations.php on saved results.
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
     * The counselors' 1-2-3 ranking of a program's code against the student's ranked interests:
     * for the program's 1st, 2nd and 3rd letter, true when that letter has the student's 1st,
     * 2nd or 3rd highest score respectively. Equal scores share a rank, so a tie between the
     * student's scores never favours one letter over another (e.g. S = I = 45: S and I both match
     * 1st and 2nd). Used only to order courses that already have the same cosine similarity.
     *
     * @return bool[] three values, for the program's 1st, 2nd and 3rd letter
     */
    public static function letterOrderMatch(array $scores, string $hollandCode): array
    {
        $ranked = array_map(fn($d) => (float) $scores[$d], self::DIMENSIONS);
        rsort($ranked);
        $match = [];
        foreach (array_slice(str_split(strtoupper($hollandCode)), 0, 3) as $i => $letter) {
            $match[] = isset($scores[$letter]) && abs((float) $scores[$letter] - $ranked[$i]) < self::TIE_EPSILON;
        }
        return $match;
    }

    /**
     * Program ids from one tie group (same cosine similarity) listed by the 1-2-3 letter ranking,
     * then by id. $codes maps program id => approved Holland code. Used for saved results.
     */
    public static function orderByLetterRank(array $ids, array $codes, array $scores): array
    {
        $key = fn($id) => isset($codes[$id]) ? self::letterOrderMatch($scores, $codes[$id]) : [false, false, false];
        usort($ids, fn($a, $b) => [$key($b), $a] <=> [$key($a), $b]);
        return $ids;
    }

    /**
     * Student's top N RIASEC letters (highest score first; ties keep R,I,A,S,E,C order).
     * Not used by the CBF calculation; kept for the dataset export and the prediction
     * model code (modules/ContentBasedFiltering/lib/PredictionModel.php), which are outside the CBF.
     */
    public static function topRiasecLetters(array $scores, int $n = 3): array
    {
        require_once __DIR__ . '/../../CareerProfiling/lib/RiasecProfile.php';
        return array_slice(RiasecProfile::order($scores), 0, $n);
    }
}
