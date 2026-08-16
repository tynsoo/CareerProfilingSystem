<?php

require_once __DIR__ . '/../lib/CBFEngine.php';

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

function approx(float $a, float $b, float $eps = 0.0001): bool
{
    return abs($a - $b) < $eps;
}

echo "=== hollandCodeToVector: rank-weighted letters, order R,I,A,S,E,C ===\n";
check('RIC -> [3,2,0,0,0,1]', CBFEngine::hollandCodeToVector('RIC') === [3, 2, 0, 0, 0, 1]);
check('SEC -> [0,0,0,3,2,1]', CBFEngine::hollandCodeToVector('SEC') === [0, 0, 0, 3, 2, 1]);
check('AES -> [0,0,3,1,2,0]', CBFEngine::hollandCodeToVector('AES') === [0, 0, 3, 1, 2, 0]);

echo "\n=== cosineSimilarity: hand-computed known vectors ===\n";
// Student scored 50 on Realistic and 0 elsewhere. Program A = "RIC" -> [3,2,0,0,0,1].
// dot = 50*3 = 150; |studentVec| = 50; |programVec| = sqrt(9+4+1) = sqrt(14).
// cosine = 150 / (50 * sqrt(14)) = 3 / sqrt(14) = 0.801783725...
$studentVec = CBFEngine::scoresToVector(['R' => 50, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0]);
$programVecRIC = CBFEngine::hollandCodeToVector('RIC');
$cosineRIC = CBFEngine::cosineSimilarity($studentVec, $programVecRIC);
check('pure-Realistic student vs RIC program ~= 0.8018', approx($cosineRIC, 3 / sqrt(14)));

// Program B = "SEC" -> [0,0,0,3,2,1]. Shares no dimension with the student's R=50, so dot = 0.
$programVecSEC = CBFEngine::hollandCodeToVector('SEC');
check('pure-Realistic student vs SEC program == 0', CBFEngine::cosineSimilarity($studentVec, $programVecSEC) === 0.0);

// Identical vectors -> cosine 1.
check('identical vectors cosine == 1', approx(CBFEngine::cosineSimilarity($programVecRIC, $programVecRIC), 1.0));

// Zero vector -> defined as 0, not NaN/division-by-zero.
check('zero vector cosine == 0 (no div-by-zero)', CBFEngine::cosineSimilarity([0, 0, 0, 0, 0, 0], $programVecRIC) === 0.0);

echo "\n=== recommend(): Final Match Score = 0.70*cosine + 0.30*stated-indicator ===\n";
// Programs 1, 3, and 4 all have R as the PRIMARY letter, so — for a purely-Realistic
// student — they all land on the same cosine (only the primary-letter weight matters
// when the student's vector is zero everywhere else). Program 2 shares no letters with
// the student at all (cosine 0), so it only wins a top-3 spot via the +0.30 indicator.
$programs = [
    ['id' => 1, 'hollandCode' => 'RIC'],
    ['id' => 2, 'hollandCode' => 'SEC'], // zero RIASEC overlap
    ['id' => 3, 'hollandCode' => 'RCI'],
    ['id' => 4, 'hollandCode' => 'RSA'],
];
$scores = ['R' => 50, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0];
$rPrimaryCosine = 3 / sqrt(14); // shared by programs 1, 3, and 4

// Case 1: student stated program 1 (a strong match) on the worksheet.
$result = CBFEngine::recommend($scores, $programs, 1);
$expectedFinal = round(0.70 * $rPrimaryCosine + 0.30 * 1.0, 4);
check('stated program (id=1) final score == 0.70*cosine + 0.30', $result['top3'][0]['id'] === 1 && approx($result['top3'][0]['score'], $expectedFinal));
check('non-stated program never gets the +0.30 indicator', $result['all'][1]['indicator'] === 0.0 && $result['all'][2]['indicator'] === 0.0);
check('top3 has exactly 3 entries', count($result['top3']) === 3);
check('statedOutsideTop3 is null when the stated program IS in the top 3', $result['statedOutsideTop3'] === null);

// Case 2: no worksheet yet (statedProgramId null) -> every indicator term is 0, ranking is cosine-only.
$resultNoWorksheet = CBFEngine::recommend($scores, $programs, null);
check('no stated program -> top pick is still an R-primary program', in_array($resultNoWorksheet['top3'][0]['id'], [1, 3, 4], true));
check('no stated program -> top score caps at 0.70*cosine (no +0.30 possible)', approx($resultNoWorksheet['top3'][0]['score'], round(0.70 * $rPrimaryCosine, 4)));

// Case 3: student stated the zero-overlap program (id=2). Even with +0.30, its score
// (0 + 0.30 = 0.30) loses to all three R-primary programs' ~0.56 pure-cosine scores,
// so it should be correctly excluded from the top 3 and surfaced separately instead.
$resultBadStated = CBFEngine::recommend($scores, $programs, 2);
$statedIds = array_column($resultBadStated['top3'], 'id');
check('stated-but-poor-fit program falls outside the top 3', !in_array(2, $statedIds, true));
check('statedOutsideTop3 correctly surfaces program id=2 separately', $resultBadStated['statedOutsideTop3'] !== null && $resultBadStated['statedOutsideTop3']['id'] === 2);
check('statedOutsideTop3 score == 0.30 (cosine 0 + full indicator)', approx($resultBadStated['statedOutsideTop3']['score'], 0.30));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
