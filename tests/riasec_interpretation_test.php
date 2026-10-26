<?php

require_once __DIR__ . '/../modules/CareerProfiling/lib/RiasecInterpretation.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

$p = fn(int $r, int $i, int $a, int $s, int $e, int $c) => ['R' => $r, 'I' => $i, 'A' => $a, 'S' => $s, 'E' => $e, 'C' => $c];

echo "=== distinct scores ===\n";
$x = RiasecInterpretation::interpret($p(30, 40, 10, 10, 10, 50));
check('C50 I40 R30 is the code CIR', $x['status'] === 'distinct' && $x['code'] === 'CIR' && $x['possibleCodes'] === ['CIR']);
check('one primary interest, Conventional', $x['uniquePrimary'] && $x['primary'] === ['C']);
check('the lines are highest, second-highest, third-highest', array_column($x['lines'], 'text') === [
    'Highest: Conventional — 50', 'Second-highest: Investigative — 40', 'Third-highest: Realistic — 30']);
check('the ranks are 1-2-3 and nothing is tied in the top three', array_column($x['ranked'], 'rank') === [1, 2, 3, 4, 4, 4]
    && $x['ranked'][0]['tiedWith'] === []);
check('the label for lists is the code', RiasecInterpretation::codeLabel($x) === 'CIR');

echo "\n=== tie for first ===\n";
$x = RiasecInterpretation::interpret($p(30, 50, 10, 10, 10, 50));
check('I = C = 50: no unique primary', $x['status'] === 'tied' && !$x['uniquePrimary'] && $x['primary'] === ['I', 'C']);
check('no single code; ICR or CIR are possible', $x['code'] === null && $x['possibleCodes'] === ['ICR', 'CIR']);
check('the stored display order stays ICR (R, I, A, S, E, C among equals)', $x['displayCode'] === 'ICR');
check('it says there is no unique primary interest', str_contains($x['summary'], 'tied for highest (50 each), so there is no unique primary interest'));
check('both share rank 1', $x['ranked'][0]['rank'] === 1 && $x['ranked'][1]['rank'] === 1 && $x['ranked'][0]['tiedWith'] === ['C']);
check('the list label marks the tie', RiasecInterpretation::codeLabel($x) === 'ICR or CIR (tied)');

echo "\n=== tie for second, inside the top three ===\n";
$x = RiasecInterpretation::interpret($p(30, 50, 10, 10, 10, 30));
check('I, then R = C: one primary, order of second and third unresolved', $x['uniquePrimary'] && $x['uniqueTopSet'] && $x['code'] === null
    && $x['possibleCodes'] === ['IRC', 'ICR'] && str_contains($x['summary'], 'so their order is unresolved'));
check('the line says tied for second', $x['lines'][1]['text'] === 'Tied for second: Realistic and Conventional — 30 each');

echo "\n=== tie across third place ===\n";
$x = RiasecInterpretation::interpret($p(28, 28, 18, 22, 32, 36));
check('C36 E32 R28 I28: no unique top three', $x['status'] === 'tied' && !$x['uniqueTopSet'] && $x['topGroup'] === ['C', 'E', 'R', 'I']);
check('the lines match the example', array_column($x['lines'], 'text') === [
    'Highest: Conventional — 36', 'Second-highest: Enterprising — 32', 'Tied for third: Realistic and Investigative — 28 each']);
check('possible codes CER or CEI', $x['possibleCodes'] === ['CER', 'CEI'] && str_contains($x['summary'], 'Possible Top-3 codes: CER or CEI.'));
check('Investigative is in the top group, not "out of the top 3"', $x['ranked'][3]['letter'] === 'I' && $x['ranked'][3]['inTopGroup'] && $x['ranked'][3]['rank'] === 3);
check('it says there is no unique Top-3 selection', str_contains($x['summary'], 'no unique Top-3 selection'));

echo "\n=== all six equal ===\n";
$x = RiasecInterpretation::interpret($p(30, 30, 30, 30, 30, 30));
check('all 30: status all_equal, no code', $x['status'] === 'all_equal' && $x['code'] === null && !$x['uniquePrimary']);
check('the headline says no distinct primary or unique code', $x['headline'] === 'No distinct primary interest or unique Top-3 code');
check('the summary is the agreed sentence', $x['summary'] === 'All six interest areas scored 30. No single interest area scored higher than the others, so a unique Top-3 Holland Code cannot be identified.');
check('every type has rank 1 and is in the top group', array_unique(array_column($x['ranked'], 'rank')) === [1] && count($x['topGroup']) === 6);
check('120 codes are possible, so none are listed', $x['possibleCodeCount'] === 120 && $x['possibleCodes'] === []);
check('nothing names Realistic as stronger', !str_contains($x['summary'], 'Realistic'));
$x = RiasecInterpretation::interpret($p(50, 50, 50, 50, 50, 50));
check('all 50 is all_equal too', $x['status'] === 'all_equal' && str_contains($x['summary'], 'scored 50'));

echo "\n=== other ties ===\n";
$x = RiasecInterpretation::interpret($p(40, 40, 40, 10, 20, 20));
check('three tied for highest: the set is known, the order is not', $x['uniqueTopSet'] && $x['code'] === null && count($x['possibleCodes']) === 6);
$x = RiasecInterpretation::interpret($p(40, 40, 40, 40, 20, 20));
check('four tied for highest: 24 possible codes, none listed', !$x['uniqueTopSet'] && $x['possibleCodeCount'] === 24 && $x['possibleCodes'] === []);
check('every possible code uses the top three score values', (function () use ($p) {
    $s = $p(28, 28, 18, 22, 32, 36);
    foreach (RiasecInterpretation::possibleCodes($s) as $c) {
        if (array_map(fn($l) => $s[$l], str_split($c)) !== [36, 32, 28]) {
            return false;
        }
    }
    return true;
})());

echo "\n=== incomplete or invalid results ===\n";
check('no result is incomplete, not a tie', RiasecInterpretation::interpret(null)['status'] === 'incomplete');
check('a missing type is incomplete', RiasecInterpretation::interpret(['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30])['status'] === 'incomplete');
check('a score outside 10-50 is incomplete', RiasecInterpretation::interpret($p(0, 30, 30, 30, 30, 30))['status'] === 'incomplete');
check('a non-whole score is incomplete', RiasecInterpretation::interpret(['R' => 30.5] + $p(30, 30, 30, 30, 30, 30))['status'] === 'incomplete');
check('incomplete results have no code and an empty list label', RiasecInterpretation::codeLabel(RiasecInterpretation::interpret(null)) === '');

echo "\n=== zero-length vectors and full-precision ties (CBFEngine) ===\n";
check('cosine with a zero student vector is undefined (null), not 0', CBFEngine::cosineSimilarity([0, 0, 0, 0, 0, 0], [3, 2, 0, 0, 0, 1]) === null);
check('cosine with a zero program vector is undefined (null)', CBFEngine::cosineSimilarity([3, 3, 3, 3, 3, 3], [0, 0, 0, 0, 0, 0]) === null);
check('a stored score of 0 is refused, so no zero student vector reaches the CBF', (function () use ($p) {
    try {
        CBFEngine::studentVector($p(0, 0, 0, 0, 0, 0));
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
})());
$cbf = CBFEngine::compute($p(30, 30, 30, 30, 30, 30), [['id' => 1, 'hollandCode' => 'RIC'], ['id' => 2, 'hollandCode' => 'SEA'], ['id' => 3, 'hollandCode' => 'XYZ']]);
check('an invalid program code is excluded, not given a vector', count($cbf['results']) === 2 && $cbf['excluded'][0]['id'] === 3);
check('every result keeps the full-precision cosine next to the display value',
    abs($cbf['results'][0]['cosineExact'] - 6 / sqrt(6 * 14)) < 1e-12 && $cbf['results'][0]['cosine'] === round(6 / sqrt(6 * 14), 4));
check('equal profiles tie every valid program, with no lower group', $cbf['candidates'] === [1, 2] && $cbf['alternatives'] === []);

echo "\n=== the result email when every program is tied ===\n";
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/ResultEmail.php';
$tiedMail = ResultEmail::build(false, ['BS Nursing', 'BS Biology'], true);
check('it says the programs are equally similar and names no program as best', str_contains($tiedMail['message'], 'every MMCL program was equally similar')
    && !str_contains($tiedMail['message'], 'BS Nursing') && !str_contains($tiedMail['message'], 'Best RIASEC Match ('));
check('without a full tie the email is unchanged (lists the Best RIASEC Match)', str_contains(ResultEmail::build(false, ['BS Nursing'])['message'], 'Your Best RIASEC Match'));

echo "\n=== agrees with the stored code and the J48 input ===\n";
// The stored code (assessment-submit.php arsort) and the J48 input (CBFEngine::topRiasecLetters) take tied types in
// R, I, A, S, E, C order. The display code must be that same code, so no page shows a different one.
$mismatch = 0;
mt_srand(7);
for ($n = 0; $n < 20000; $n++) {
    $s = [];
    foreach (RiasecInterpretation::DIMENSIONS as $d) {
        $s[$d] = 10 + 2 * mt_rand(0, 20);
    }
    $x = RiasecInterpretation::interpret($s);
    $j48 = implode('', CBFEngine::topRiasecLetters($s, 3));
    if ($x['displayCode'] !== $j48 || !in_array($j48, RiasecInterpretation::possibleCodes($s), true)
        || ($x['status'] === 'distinct') !== ($x['possibleCodeCount'] === 1)) {
        $mismatch++;
    }
}
check('20,000 random profiles: display code = J48 tie order, always a possible code', $mismatch === 0);

echo "\nSummary: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
