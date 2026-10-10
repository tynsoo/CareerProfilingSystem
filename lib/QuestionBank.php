<?php

/**
 * Rules for the RIASEC question bank, kept in one place so the question bank page, the assessment and the scoring
 * agree. Staff can add, edit and remove questions, so the number of questions is not fixed: the assessment shows
 * whatever is active, a student's score for each type is scaled to the same 10-50 range every earlier result used,
 * and the assessment only opens while every type has the same number of active questions.
 */
class QuestionBank
{
    public const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];
    /** The full name of each type, as stored with a student's result. */
    public const NAMES = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
    /** The fewest active questions each type may have. */
    public const MIN_PER_TYPE = 3;
    /** Longest question text accepted. */
    public const MAX_TEXT_LENGTH = 300;
    /** Every answer is 1 (dislike) to 5 (enjoy). */
    public const MAX_ANSWER = 5;
    /** The scale stored scores use: 50 is "answered 5 to every question of that type". */
    public const SCORE_SCALE = 50;

    /**
     * The 30 questions of the O*NET Mini Interest Profiler (Mini-IP, 5 per type), as printed in Appendix A of
     * Rounds, Wee, Cao, Song & Lewis, "Development of an O*NET Mini Interest Profiler (Mini-IP) for Mobile Devices"
     * (National Center for O*NET Development, U.S. Department of Labor). O*NET content is published under CC BY 4.0.
     */
    public const ONET_MINI_IP = [
        'R' => [
            'Build kitchen cabinets',
            'Repair household appliances',
            'Assemble electronic parts',
            'Drive a truck to deliver packages to offices and homes',
            'Test the quality of parts before shipment',
        ],
        'I' => [
            'Develop a new medicine',
            'Study ways to reduce water pollution',
            'Conduct chemical experiments',
            'Examine blood samples using a microscope',
            'Develop a way to better predict the weather',
        ],
        'A' => [
            'Write books or plays',
            'Compose or arrange music',
            'Create special effects for movies',
            'Paint sets for plays',
            'Write scripts for movies or television shows',
        ],
        'S' => [
            'Help people with personal or emotional problems',
            'Give career guidance to people',
            'Perform rehabilitation therapy',
            'Do volunteer work at a non-profit organization',
            'Teach a high-school class',
        ],
        'E' => [
            'Manage a department within a large company',
            'Start your own business',
            'Negotiate business contracts',
            'Market a new line of clothing',
            'Sell merchandise at a department store',
        ],
        'C' => [
            'Install software across computers on a large network',
            'Operate a calculator',
            'Keep shipping and receiving records',
            'Inventory supplies using a hand-held computer',
            'Stamp, sort, and distribute mail for an organization',
        ],
    ];

    /**
     * Whether students can take the assessment with these counts of ACTIVE questions.
     *
     * @param array<string,int> $counts active questions per type (missing types count as 0)
     * @return array{ok:bool,perType:array<string,int>,total:int,size:int,message:string}
     */
    public static function balance(array $counts): array
    {
        $perType = [];
        foreach (self::DIMENSIONS as $d) {
            $perType[$d] = (int) ($counts[$d] ?? 0);
        }
        $total = array_sum($perType);
        $size = min($perType);
        $equal = count(array_unique($perType)) === 1;

        if ($equal && $size >= self::MIN_PER_TYPE) {
            return ['ok' => true, 'perType' => $perType, 'total' => $total, 'size' => $size, 'message' => ''];
        }
        $message = $equal
            ? 'Each type needs at least ' . self::MIN_PER_TYPE . ' active questions.'
            : 'Every type needs the same number of active questions.';
        return ['ok' => false, 'perType' => $perType, 'total' => $total, 'size' => $size, 'message' => $message];
    }

    /**
     * The ranked three-letter Holland code for a student's top types (best first), for example
     * ['Investigative', 'Conventional', 'Social'] gives "ICS". Used in the downloads.
     *
     * @param array<int,string> $topTypes type names (or letters) in rank order
     */
    public static function hollandCode(array $topTypes): string
    {
        $code = '';
        foreach (array_slice($topTypes, 0, 3) as $name) {
            // A result stores the full name; some older or seeded rows store the letter itself.
            $letter = in_array($name, self::DIMENSIONS, true) ? $name : array_search($name, self::NAMES, true);
            $code .= $letter !== false ? $letter : '';
        }
        return $code;
    }

    /** A student's raw total for one type, put on the 10-50 scale every stored result uses. */
    public static function scaleScore(int $rawTotal, int $questionsInType): int
    {
        if ($questionsInType <= 0) {
            return 0;
        }
        return (int) round($rawTotal / ($questionsInType * self::MAX_ANSWER) * self::SCORE_SCALE);
    }
}
