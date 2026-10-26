<?php

/**
 * What a student's six RIASEC scores do and do not establish, in one place, so the student pages, the staff
 * profile, the student list and the downloads all say the same thing.
 *
 * The scores are the stored scaled scores (10-50). Ties are read from them exactly: they are whole numbers, and with
 * the same number of questions per type (QuestionBank::balance) and 10 or fewer questions per type, two different raw
 * totals never give the same scaled score, so a stored tie is a tie in the answers. (Raw totals are not stored.)
 *
 * The stored code (assessments.top_types, CBFEngine::topRiasecLetters) lists equal scores in the order
 * R, I, A, S, E, C. That order is kept for a consistent display only: it never means one tied type is stronger.
 * Rules:
 *   - distinct scores in the top three: one ordered code;
 *   - a tie for highest: joint highest types, no unique primary;
 *   - a tie inside the top three: the tied positions are marked and their order is unresolved;
 *   - a tie across third place: every type tied there is shown, and there is no unique top three;
 *   - all six equal: no distinct primary interest and no unique top-three code.
 */
class RiasecInterpretation
{
    public const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];
    public const NAMES = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
    /** The stored score range (QuestionBank: 10 x mean answer, answers 1-5). */
    public const MIN_SCORE = 10;
    public const MAX_SCORE = 50;
    /** More possible codes than this are counted, not listed. */
    public const MAX_LISTED_CODES = 6;

    private const POSITION_WORDS = [1 => 'highest', 2 => 'second', 3 => 'third'];

    /**
     * @param array<string,int|float|null>|null $scores R..C stored scores (10-50); null or a missing/invalid value = incomplete
     * @return array{status:string, headline:string, summary:string, scores:?array, ranked:array, lines:array,
     *               primary:string[], uniquePrimary:bool, topGroup:string[], uniqueTopSet:bool, code:?string,
     *               displayCode:?string, possibleCodes:string[], possibleCodeCount:int, scoreRange:array}
     */
    public static function interpret(?array $scores): array
    {
        $range = ['min' => self::MIN_SCORE, 'max' => self::MAX_SCORE];
        $clean = self::validScores($scores);
        if ($clean === null) {
            return ['status' => 'incomplete', 'headline' => 'No complete assessment result',
                'summary' => 'There is no complete RIASEC Assessment result, so no interest profile can be shown.',
                'scores' => null, 'ranked' => [], 'lines' => [], 'primary' => [], 'uniquePrimary' => false, 'topGroup' => [],
                'uniqueTopSet' => false, 'code' => null, 'displayCode' => null, 'possibleCodes' => [], 'possibleCodeCount' => 0,
                'scoreRange' => $range];
        }

        // Highest first; equal scores keep the R, I, A, S, E, C order (display only).
        $order = self::DIMENSIONS;
        usort($order, fn($a, $b) => ($clean[$b] <=> $clean[$a]) ?: (array_search($a, self::DIMENSIONS) <=> array_search($b, self::DIMENSIONS)));
        // Competition rank: 1 + the number of types scored higher, so equal scores share a rank.
        $rank = [];
        foreach (self::DIMENSIONS as $d) {
            $rank[$d] = 1 + count(array_filter(self::DIMENSIONS, fn($o) => $clean[$o] > $clean[$d]));
        }
        $tiedWith = fn(string $d) => array_values(array_filter(self::DIMENSIONS, fn($o) => $o !== $d && $clean[$o] === $clean[$d]));
        $topGroup = array_values(array_filter($order, fn($d) => $rank[$d] <= 3));   // every type that can be in a top three
        $ranked = array_map(fn($d) => ['letter' => $d, 'name' => self::NAMES[$d], 'score' => $clean[$d], 'rank' => $rank[$d],
            'tiedWith' => $tiedWith($d), 'inTopGroup' => $rank[$d] <= 3], $order);

        $primary = array_values(array_filter($order, fn($d) => $rank[$d] === 1));
        $uniqueTopSet = count($topGroup) === 3;
        $possible = self::possibleCodes($clean, $order);
        $code = count($possible) === 1 ? $possible[0] : null;
        $allEqual = count($primary) === 6;
        $status = $allEqual ? 'all_equal' : ($code !== null ? 'distinct' : 'tied');

        // One line per score level that reaches the top three ("Tied for third: Realistic and Investigative, 28 each").
        $lines = [];
        $levels = [];
        foreach ($topGroup as $d) {
            $levels[$rank[$d]][] = $d;
        }
        foreach ($levels as $r => $letters) {
            $label = count($letters) > 1
                ? 'Tied for ' . self::POSITION_WORDS[$r]
                : ($r === 1 ? 'Highest' : ($r === 2 ? 'Second-highest' : 'Third-highest'));
            $lines[] = ['label' => $label, 'letters' => $letters, 'score' => $clean[$letters[0]],
                'text' => $label . ': ' . self::names($letters) . ' — ' . $clean[$letters[0]] . (count($letters) > 1 ? ' each' : '')];
        }

        if ($allEqual) {
            $headline = 'No distinct primary interest or unique Top-3 code';
            $summary = 'All six interest areas scored ' . $clean['R'] . '. No single interest area scored higher than the others, '
                . 'so a unique Top-3 Holland Code cannot be identified.';
        } elseif ($code !== null) {
            $headline = 'Holland Code ' . $code;
            $summary = 'The three highest scores are ' . self::names(str_split($code)) . ', in that order (' .
                implode(', ', array_map(fn($d) => $clean[$d], str_split($code))) . '), so the Holland Code is ' . $code . '.';
        } else {
            $parts = [];
            if (count($primary) > 1) {
                $parts[] = self::names($primary) . ' are tied for highest (' . $clean[$primary[0]] . ' each), so there is no unique primary interest.';
            }
            foreach ($levels as $r => $letters) {
                if ($r === 1 || count($letters) < 2) {
                    continue;
                }
                $parts[] = self::names($letters) . ' are tied for ' . self::POSITION_WORDS[$r] . ' (' . $clean[$letters[0]] . ' each)'
                    . ($r + count($letters) - 1 > 3 ? '.' : ', so their order is unresolved.');
            }
            if (!$uniqueTopSet) {
                $parts[] = 'More than three interest areas reach the top three, so there is no unique Top-3 selection.';
            }
            $parts[] = count($possible) <= self::MAX_LISTED_CODES
                ? 'Possible Top-3 codes: ' . self::orList($possible) . '.'
                : count($possible) . ' Top-3 codes are possible.';
            $headline = !$uniqueTopSet ? 'No unique Top-3 code' : (count($primary) > 1 ? 'No unique primary interest' : 'Top-3 order partly tied');
            $summary = implode(' ', array_unique($parts));
        }

        return ['status' => $status, 'headline' => $headline, 'summary' => $summary, 'scores' => $clean, 'ranked' => $ranked,
            'lines' => $lines, 'primary' => $primary, 'uniquePrimary' => count($primary) === 1, 'topGroup' => $topGroup,
            'uniqueTopSet' => $uniqueTopSet, 'code' => $code, 'displayCode' => implode('', array_slice($order, 0, 3)),
            'possibleCodes' => count($possible) <= self::MAX_LISTED_CODES ? $possible : [], 'possibleCodeCount' => count($possible),
            'scoreRange' => $range];
    }

    /**
     * Every three-letter code the scores allow: three different types whose scores do not increase, and no type left
     * out scores higher than the third one chosen. Listed in the display order.
     *
     * @return string[]
     */
    public static function possibleCodes(array $scores, ?array $order = null): array
    {
        $order ??= self::DIMENSIONS;
        $sorted = array_map(fn($d) => $scores[$d], $order);
        rsort($sorted);
        $codes = [];
        foreach ($order as $a) {
            foreach ($order as $b) {
                foreach ($order as $c) {
                    if ($a === $b || $b === $c || $a === $c) {
                        continue;
                    }
                    // Same three score values as the true top three, in non-increasing order.
                    if ([$scores[$a], $scores[$b], $scores[$c]] === array_slice($sorted, 0, 3)) {
                        $codes[] = $a . $b . $c;
                    }
                }
            }
        }
        return $codes;
    }

    /** A short label for lists and downloads: "CIR", "CER or CEI (tied)", "No unique code (all six equal)". */
    public static function codeLabel(array $interpretation): string
    {
        return match ($interpretation['status']) {
            'incomplete' => '',
            'distinct' => (string) $interpretation['code'],
            'all_equal' => 'No unique code (all six equal)',
            default => ($interpretation['possibleCodes'] ? self::orList($interpretation['possibleCodes'])
                : $interpretation['possibleCodeCount'] . ' possible codes') . ' (tied)',
        };
    }

    /** A label that fits a narrow column (the student list PDF): "CIR", "CER/CEI tie", "Tie (24 codes)", "All equal". */
    public static function shortLabel(array $interpretation): string
    {
        return match ($interpretation['status']) {
            'incomplete' => '',
            'distinct' => (string) $interpretation['code'],
            'all_equal' => 'All equal',
            default => count($interpretation['possibleCodes']) === 2 ? implode('/', $interpretation['possibleCodes']) . ' tie'
                : 'Tie (' . $interpretation['possibleCodeCount'] . ' codes)',
        };
    }

    /** The tie column of the downloads: "Unique", "Tied: CER or CEI", "All six equal", or "" without a result. */
    public static function tieLabel(array $interpretation): string
    {
        return match ($interpretation['status']) {
            'incomplete' => '',
            'distinct' => 'Unique',
            'all_equal' => 'All six equal',
            default => 'Tied: ' . ($interpretation['possibleCodes'] ? self::orList($interpretation['possibleCodes'])
                : $interpretation['possibleCodeCount'] . ' possible codes'),
        };
    }

    /** @return array<string,int>|null */
    private static function validScores(?array $scores): ?array
    {
        if (!$scores) {
            return null;
        }
        $out = [];
        foreach (self::DIMENSIONS as $d) {
            $v = $scores[$d] ?? null;
            if ($v === null || !is_numeric($v) || !is_finite((float) $v) || (float) $v != (int) $v
                || (int) $v < self::MIN_SCORE || (int) $v > self::MAX_SCORE) {
                return null;
            }
            $out[$d] = (int) $v;
        }
        return $out;
    }

    private static function names(array $letters): string
    {
        $n = array_map(fn($d) => self::NAMES[$d], $letters);
        return count($n) < 2 ? implode('', $n) : implode(', ', array_slice($n, 0, -1)) . ' and ' . end($n);
    }

    private static function orList(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)) . ' or ' . end($items);
    }
}
