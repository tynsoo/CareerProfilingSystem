<?php

/**
 * A student's ranked RIASEC interests and ordered Holland code, derived from the six stored
 * scores. The one implementation behind the assessment result, the results page, the API,
 * the dataset export and the Prediction Module's top-three inputs.
 *
 * Ranking: highest score first. Equal scores keep the order R, I, A, S, E, C. That convention
 * keeps results compatible with earlier ones; it does not mean the earlier letter is the stronger
 * interest. Whenever equal scores decide a position in the three-letter code, the code is marked
 * provisional and the tie is reported.
 *
 * Profile checks (descriptive; no suitability thresholds are applied here):
 *   flat         all six scores equal: the profile does not separate any interest
 *   allMinimum   all six scores at the lowest possible value (every answer "Dislike")
 *   orderTie     equal scores decide the order of letters within the top three
 *   thirdPlaceTie the 3rd and 4th scores are equal: which letter is in the top three is decided
 *                by the convention, not by the answers
 */
class RiasecProfile
{
    public const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];
    public const NAMES = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
    /** Stored score range (modules/CareerProfiling/lib/QuestionBank.php scaleScore). */
    public const MIN_SCORE = 10;
    public const MAX_SCORE = 50;

    /**
     * @param array<string,mixed>|null $scores stored scores keyed R..C (10-50, whole numbers)
     * @return array{valid:bool, reason:?string, scores?:array<string,int>, ranking?:array, top1?:string, top2?:string,
     *               top3?:string, code?:string, codeProvisional?:bool, orderTie?:bool, thirdPlaceTie?:bool,
     *               ties?:array, flat?:bool, allMinimum?:bool, notes?:string[]}
     */
    public static function derive(?array $scores): array
    {
        $reason = self::invalidReason($scores);
        if ($reason !== null) {
            return ['valid' => false, 'reason' => $reason];
        }
        $s = [];
        foreach (self::DIMENSIONS as $d) {
            $s[$d] = (int) $scores[$d];
        }
        $order = self::order($s);

        // Groups of letters with the same score (only groups of two or more).
        $groups = [];
        foreach ($order as $pos => $d) {
            $groups[$s[$d]][] = ['letter' => $d, 'position' => $pos + 1];
        }
        $ties = [];
        foreach ($groups as $score => $members) {
            if (count($members) > 1) {
                $ties[] = ['score' => $score, 'letters' => array_column($members, 'letter'), 'positions' => array_column($members, 'position')];
            }
        }
        $ranking = [];
        foreach ($order as $pos => $d) {
            $ranking[] = [
                'letter' => $d, 'name' => self::NAMES[$d], 'score' => $s[$d], 'position' => $pos + 1,
                // Competition rank: equal scores share a rank (1, 2, 2, 4, ...).
                'rank' => 1 + count(array_filter($s, fn($v) => $v > $s[$d])),
                'tied' => count($groups[$s[$d]]) > 1,
            ];
        }
        $orderTie = false;
        foreach ($ties as $t) {
            if (count(array_filter($t['positions'], fn($p) => $p <= 3)) >= 2) {
                $orderTie = true;
            }
        }
        $thirdPlaceTie = $s[$order[2]] === $s[$order[3]];
        $flat = count(array_unique($s)) === 1;
        $allMinimum = $flat && $s['R'] === self::MIN_SCORE;

        $notes = [];
        if ($allMinimum) {
            $notes[] = 'all_minimum';
        } elseif ($flat) {
            $notes[] = 'flat';
        }
        if ($thirdPlaceTie) {
            $notes[] = 'third_place_tie';
        }
        if ($orderTie) {
            $notes[] = 'order_tie';
        }
        return [
            'valid' => true,
            'reason' => null,
            'scores' => $s,
            'ranking' => $ranking,
            'top1' => $order[0],
            'top2' => $order[0] . $order[1],
            'top3' => $order[0] . $order[1] . $order[2],
            'code' => $order[0] . $order[1] . $order[2],
            'codeProvisional' => $orderTie || $thirdPlaceTie,
            'orderTie' => $orderTie,
            'thirdPlaceTie' => $thirdPlaceTie,
            'ties' => $ties,
            'flat' => $flat,
            'allMinimum' => $allMinimum,
            'notes' => $notes,
        ];
    }

    /**
     * The six letters, highest score first; equal scores in R, I, A, S, E, C order.
     *
     * @param array<string,int|float> $scores keyed R..C
     * @return string[]
     */
    public static function order(array $scores): array
    {
        $order = self::DIMENSIONS;
        usort($order, fn($a, $b) => ((float) $scores[$b] <=> (float) $scores[$a])
            ?: (array_search($a, self::DIMENSIONS, true) <=> array_search($b, self::DIMENSIONS, true)));
        return $order;
    }

    /** Why the scores cannot be used, or null if they can. Never substitutes a value. */
    public static function invalidReason(?array $scores): ?string
    {
        if (!$scores) {
            return 'No RIASEC result.';
        }
        foreach (self::DIMENSIONS as $d) {
            $v = $scores[$d] ?? null;
            if ($v === null || !is_numeric($v)) {
                return "The score for $d is missing or not a number.";
            }
            if ((float) $v != floor((float) $v)) {
                return "The score for $d ($v) is not a whole number.";
            }
            if ($v < self::MIN_SCORE || $v > self::MAX_SCORE) {
                return "The score for $d ($v) is outside " . self::MIN_SCORE . '-' . self::MAX_SCORE . '.';
            }
        }
        return null;
    }
}
