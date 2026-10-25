<?php
/**
 * CBF (modules/ContentBasedFiltering/lib/CBFEngine.php): controlled development tests of the cosine-similarity math.
 *
 *   php tests/cbf_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. Every student profile below is a made-up input used to
 * check the software. None of it is research data, and nothing here measures accuracy.
 */
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';

$passed = 0;
$failures = 0;
function check(string $label, bool $condition): void
{
    global $passed, $failures;
    $condition ? $passed++ : $failures++;
    echo ($condition ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}
function near(?float $a, float $b, float $eps = 0.0005): bool
{
    return $a !== null && abs($a - $b) < $eps;
}
function throws(callable $f): bool
{
    try { $f(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}

echo "=== Feature space and configuration ===\n";
check('dimension order is [R, I, A, S, E, C]', CBFEngine::DIMENSIONS === ['R', 'I', 'A', 'S', 'E', 'C']);
$cfg = CBFEngine::config();
foreach (['weights', 'final_score', 'holland_rank_weights', 'student_vector', 'riasec_subtract_floor', 'match_rule'] as $removed) {
    check("no '$removed' setting (no weights/percentages in the CBF)", !array_key_exists($removed, $cfg));
}
check('assessment structure: 10 items per type, answers 1-5', $cfg['riasec_items_per_type'] === 10 && $cfg['riasec_answer_min'] === 1 && $cfg['riasec_answer_max'] === 5);

echo "\n=== Course vector (binary, guidance-approved code) ===\n";
check('AES -> [0, 0, 1, 1, 1, 0]', CBFEngine::courseVector('AES') === [0, 0, 1, 1, 1, 0]);
check('IRC -> [1, 1, 0, 0, 0, 1]', CBFEngine::courseVector('IRC') === [1, 1, 0, 0, 0, 1]);
check('letter order carries no weight: IRC, RIC and ICR give the same vector',
    CBFEngine::courseVector('IRC') === CBFEngine::courseVector('RIC') && CBFEngine::courseVector('RIC') === CBFEngine::courseVector('ICR'));
check('lower-case code accepted', CBFEngine::courseVector('aes') === [0, 0, 1, 1, 1, 0]);
check('missing code -> null (flagged, not invented)', CBFEngine::courseVector(null) === null && CBFEngine::courseVector('') === null);
check('invalid letter -> null', CBFEngine::courseVector('AEX') === null);
check('repeated letter -> null', CBFEngine::courseVector('AAS') === null);
check('wrong length -> null', CBFEngine::courseVector('AE') === null && CBFEngine::courseVector('AESI') === null);
foreach (require __DIR__ . '/../db/program_codes.php' as [, $title, , $final]) {
    if (CBFEngine::courseVector($final) === null) {
        check("program list: $title has a valid code", false);
    }
}
check('every program in db/program_codes.php has a valid final code', true);

echo "\n=== Student vector (the student's actual RIASEC result) ===\n";
check('totals R45 I45 A45 S45 E35 C25 -> mean item scores [4.5, 4.5, 4.5, 4.5, 3.5, 2.5]',
    CBFEngine::studentVector(['R' => 45, 'I' => 45, 'A' => 45, 'S' => 45, 'E' => 35, 'C' => 25]) === [4.5, 4.5, 4.5, 4.5, 3.5, 2.5]);
check('keys in any order are placed in R,I,A,S,E,C order',
    CBFEngine::studentVector(['C' => 10, 'E' => 20, 'S' => 30, 'A' => 40, 'I' => 50, 'R' => 10]) === [1.0, 5.0, 4.0, 3.0, 2.0, 1.0]);
check('no assessment (null) -> rejected, no value substituted', throws(fn() => CBFEngine::studentVector(null)));
check('a missing score -> rejected', throws(fn() => CBFEngine::studentVector(['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30])));
check('a null score -> rejected', throws(fn() => CBFEngine::studentVector(['R' => 30, 'I' => null, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30])));
check('a non-numeric score -> rejected', throws(fn() => CBFEngine::studentVector(['R' => 'x', 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30])));
check('a score below 10 or above 50 -> rejected',
    throws(fn() => CBFEngine::studentVector(['R' => 9, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30]))
    && throws(fn() => CBFEngine::studentVector(['R' => 51, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30])));

echo "\n=== Cosine similarity: hand-computed values ===\n";
// Controlled example from the methodology (mathematical check only):
$S = [4.5, 4.5, 4.5, 4.5, 3.5, 2.5];
$C = CBFEngine::courseVector('AES');
check('example: S·C = 4.5 + 4.5 + 3.5 = 12.5', near(CBFEngine::dotProduct($S, $C), 12.5));
check('example: ||S|| = sqrt(99.5) = 9.9750', near(CBFEngine::magnitude($S), 9.9750));
check('example: ||C|| = sqrt(3) = 1.7321', near(CBFEngine::magnitude($C), 1.7321));
check('example: cos = 12.5 / (9.9750 x 1.7321) = 0.724 (BA Communication, AES)', near(CBFEngine::cosineSimilarity($S, $C), 0.7235));
check('identical direction -> 1', near(CBFEngine::cosineSimilarity([1, 1, 0, 0, 0, 1], [2, 2, 0, 0, 0, 2]), 1.0));
check('no shared dimension -> 0', near(CBFEngine::cosineSimilarity([1, 1, 1, 0, 0, 0], [0, 0, 0, 1, 1, 1]), 0.0));
check('scaling the student vector does not change cosine (totals vs mean item scores)',
    near(CBFEngine::cosineSimilarity([45, 45, 45, 45, 35, 25], $C), CBFEngine::cosineSimilarity($S, $C), 1e-9));
check('zero-magnitude vector -> null (undefined), not a misleading 0', CBFEngine::cosineSimilarity([0, 0, 0, 0, 0, 0], $C) === null);

echo "\n=== CBF over all courses ===\n";
$programs = [
    ['id' => 1, 'title' => 'BA Communication', 'hollandCode' => 'AES'],
    ['id' => 2, 'title' => 'BS Computer Science', 'hollandCode' => 'IRC'],
    ['id' => 3, 'title' => 'BS Information Technology', 'hollandCode' => 'IRC'],
    ['id' => 4, 'title' => 'BS Psychology', 'hollandCode' => 'SIA'],
    ['id' => 5, 'title' => 'BS Accountancy', 'hollandCode' => 'CEI'],
    ['id' => 6, 'title' => 'Unmapped Program', 'hollandCode' => null],
    ['id' => 7, 'title' => 'Bad Code Program', 'hollandCode' => 'XYZ'],
];
$ircStudent = ['R' => 38, 'I' => 45, 'A' => 18, 'S' => 20, 'E' => 22, 'C' => 35]; // mean [3.8,4.5,1.8,2.0,2.2,3.5]
$r = CBFEngine::compute($ircStudent, $programs);
check('every valid course is compared (5 of 5)', count($r['results']) === 5);
check('missing and invalid mappings are flagged, not given a similarity',
    array_column($r['excluded'], 'id') === [6, 7] && !in_array(6, array_column($r['results'], 'id'), true));
$cos = array_column($r['results'], 'cosine');
$sortedDesc = $cos;
rsort($sortedDesc);
check('results sorted by cosine similarity, highest first', $cos === $sortedDesc);
$byId = array_column($r['results'], null, 'id');
check('hand check, IRC course: S·C = 3.8 + 4.5 + 3.5 = 11.8', near($byId[2]['dotProduct'], 11.8));
check('hand check, ||S|| = sqrt(59.02) = 7.6824', near($r['studentMagnitude'], 7.6824));
check('hand check, cosine = 11.8 / (7.6824 x 1.7321) = 0.8868', near($byId[2]['cosine'], 0.8868));
check('tied similarity: both IRC courses have the same cosine', $byId[2]['cosine'] === $byId[3]['cosine']);
check('tied courses with the same code keep the program-list order', array_column($r['results'], 'id')[0] === 2 && array_column($r['results'], 'id')[1] === 3);
check('multiple CBF candidates: every course tied at the highest similarity', $r['candidates'] === [2, 3]);
check('candidates come only from the RIASEC profile (no worksheet, no prediction input exists)',
    (new ReflectionMethod('CBFEngine', 'compute'))->getNumberOfParameters() === 3);

// Ties between scores are handled by the math, not by a letter-order rule:
$tie = CBFEngine::compute(['R' => 10, 'I' => 40, 'A' => 10, 'S' => 10, 'E' => 40, 'C' => 40], [
    ['id' => 1, 'title' => 'CEI course', 'hollandCode' => 'CEI'],
    ['id' => 2, 'title' => 'EIC course', 'hollandCode' => 'EIC'],
    ['id' => 3, 'title' => 'ECS course', 'hollandCode' => 'ECS'],
]);
check('same three letters in a different order -> same similarity, both candidates', $tie['candidates'] === [1, 2]);

$single = CBFEngine::compute(['R' => 12, 'I' => 18, 'A' => 48, 'S' => 44, 'E' => 30, 'C' => 14], $programs);
check('single best course: A-S-E student -> BA Communication is the only candidate', $single['candidates'] === [1]);
check('alternatives = every course at the next-highest similarity', $r['alternatives'] === array_values(array_map(fn($x) => $x['id'],
    array_filter($r['results'], fn($x) => $x['cosine'] === $r['results'][2]['cosine']))));
check('alternatives never repeat a Best RIASEC Match course', !array_intersect($r['alternatives'], $r['candidates']));
check('selectAlternatives on saved results', array_column(CBFEngine::selectAlternatives([['id' => 1, 'cosine' => 0.9], ['id' => 2, 'cosine' => 0.9], ['id' => 3, 'cosine' => 0.8], ['id' => 4, 'cosine' => 0.8], ['id' => 5, 'cosine' => 0.7]]), 'id') === [3, 4]);
check('no alternatives when every course is tied at the top', CBFEngine::selectAlternatives([['id' => 1, 'cosine' => 0.9], ['id' => 2, 'cosine' => 0.9]]) === []);
check('invalid RIASEC profile -> compute() refuses instead of guessing', throws(fn() => CBFEngine::compute(null, $programs)));
check('no valid course at all -> no candidates',
    CBFEngine::compute($ircStudent, [['id' => 9, 'title' => 'x', 'hollandCode' => '']])['candidates'] === []);
check('selectCandidates on saved results uses the same rule',
    array_column(CBFEngine::selectCandidates([['id' => 1, 'cosine' => 0.8868], ['id' => 2, 'cosine' => 0.8868], ['id' => 3, 'cosine' => 0.7741]]), 'id') === [1, 2]);

echo "\n=== 1-2-3 letter ranking inside a tie (orders ties only, no weights) ===\n";
$studentA = ['R' => 30, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25];   // ranked S, I, R -> code SIR
$health = [
    ['id' => 1, 'title' => 'BS Biology', 'hollandCode' => 'IRS'],
    ['id' => 2, 'title' => 'BS Physical Therapy', 'hollandCode' => 'SIR'],
    ['id' => 3, 'title' => 'BS Nursing', 'hollandCode' => 'SIR'],
    ['id' => 4, 'title' => 'BS Pharmacy', 'hollandCode' => 'ISC'],
];
$h = CBFEngine::compute($studentA, $health);
$hById = array_column($h['results'], null, 'id');
check('the three S-I-R programs tie: cosine 11.5 / (7.5993 x 1.7321) = 0.8737 for each',
    near($hById[1]['cosine'], 0.8737) && $hById[1]['cosine'] === $hById[2]['cosine'] && $hById[2]['cosine'] === $hById[3]['cosine']);
check('all three stay in Best RIASEC Match (membership decided by cosine only)', $h['candidates'] === [2, 3, 1]);
check('inside the tie, SIR (same 1st letter as the student) comes before IRS', array_column($h['results'], 'id') === [2, 3, 1, 4]);
check('Alternatives unchanged: BS Pharmacy (ISC) at the next similarity', $h['alternatives'] === [4]);
check('letter matches: SIR = [1st, 2nd, 3rd], IRS = none', $hById[2]['letterOrder'] === [true, true, true] && $hById[1]['letterOrder'] === [false, false, false]);
check('1st before 2nd before 3rd: SRI > RIS > ISR > IRS',
    CBFEngine::letterOrderMatch($studentA, 'SRI') === [true, false, false] && CBFEngine::letterOrderMatch($studentA, 'RIS') === [false, true, false]
    && CBFEngine::letterOrderMatch($studentA, 'ISR') === [false, false, true]
    && CBFEngine::orderByLetterRank([1, 2, 3, 4], [1 => 'IRS', 2 => 'ISR', 3 => 'RIS', 4 => 'SRI'], $studentA) === [4, 3, 2, 1]);
check('saved results are ordered the same way (orderByLetterRank)', CBFEngine::orderByLetterRank([1, 2, 3], [1 => 'IRS', 2 => 'SIR', 3 => 'SIR'], $studentA) === [2, 3, 1]);
$cosines = fn(array $res) => array_map(fn($x) => $x['cosine'], $res);
$swapped = CBFEngine::compute($studentA, array_reverse($health));
check('the 1-2-3 order changes no cosine value and no membership (program list reversed)',
    $cosines($swapped['results']) === $cosines($h['results']) && $swapped['candidates'] === [2, 3, 1] && $swapped['alternatives'] === [4]);
$tiedTop = ['R' => 30, 'I' => 45, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 25];   // S = I: neither is "1st"
check('tied student scores favour neither letter: SIR and ISR both match all three, program order kept',
    CBFEngine::letterOrderMatch($tiedTop, 'SIR') === [true, true, true] && CBFEngine::letterOrderMatch($tiedTop, 'ISR') === [true, true, true]
    && CBFEngine::orderByLetterRank([5, 4], [4 => 'SIR', 5 => 'ISR'], $tiedTop) === [4, 5]);
$thirdTie = ['R' => 30, 'I' => 40, 'A' => 20, 'S' => 45, 'E' => 15, 'C' => 30];  // R = C for 3rd place
check('a tie for 3rd place: SIR and SIC both match the 3rd letter',
    CBFEngine::letterOrderMatch($thirdTie, 'SIR') === [true, true, true] && CBFEngine::letterOrderMatch($thirdTie, 'SIC') === [true, true, true]);
$flat = ['R' => 30, 'I' => 30, 'A' => 30, 'S' => 30, 'E' => 30, 'C' => 30];
check('flat profile: every code matches, so the program-list order is kept',
    array_column(CBFEngine::compute($flat, $health)['results'], 'id') === [1, 2, 3, 4]);

echo "\n=== Postgres text[] parsing ===\n";
check('bare and quoted elements are both read', CBFData::parseTextArray('{Animation,"Biology 1-2",Entrepreneurship}') === ['Animation', 'Biology 1-2', 'Entrepreneurship']);
check('escaped quotes/backslashes are unescaped', CBFData::parseTextArray('{"a \\"b\\"","c\\\\d"}') === ['a "b"', 'c\\d']);
check('empty array -> []', CBFData::parseTextArray('{}') === [] && CBFData::parseTextArray(null) === []);
check('literal round-trips through the parser', CBFData::parseTextArray(CBFData::textArrayLiteral(['Physics 1-2', 'Animation'])) === ['Physics 1-2', 'Animation']);
check('INT[] literal round-trips', CBFData::parseIntArray(CBFData::intArrayLiteral([3, 4, 6])) === [3, 4, 6] && CBFData::parseIntArray('{}') === [] && CBFData::parseIntArray(null) === null);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures ? 1 : 0);
