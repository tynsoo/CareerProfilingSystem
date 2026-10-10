<?php

require_once __DIR__ . '/../lib/QuestionBank.php';

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

echo "=== the O*NET Mini Interest Profiler set ===\n";
$set = QuestionBank::ONET_MINI_IP;
check('it has the six types in RIASEC order', array_keys($set) === ['R', 'I', 'A', 'S', 'E', 'C']);
check('it has 5 questions for every type', count(array_unique(array_map('count', $set))) === 1 && count($set['R']) === 5);
check('it has 30 different questions', count(array_unique(array_merge(...array_values($set)))) === 30);
check('every question fits the length limit', max(array_map('strlen', array_merge(...array_values($set)))) <= QuestionBank::MAX_TEXT_LENGTH);
check('the first two are the ones O*NET lists first', $set['R'][0] === 'Build kitchen cabinets' && $set['I'][0] === 'Develop a new medicine');

echo "\n=== when students can take the assessment ===\n";
$even = ['R' => 5, 'I' => 5, 'A' => 5, 'S' => 5, 'E' => 5, 'C' => 5];
$b = QuestionBank::balance($even);
check('5 of every type is ready: 30 questions', $b['ok'] && $b['total'] === 30 && $b['size'] === 5);
check('10 of every type (the old set) is ready too', QuestionBank::balance(array_fill_keys(QuestionBank::DIMENSIONS, 10))['ok']);
check('3 of every type is the smallest allowed', QuestionBank::balance(array_fill_keys(QuestionBank::DIMENSIONS, 3))['ok']);
check('2 of every type is too few', !QuestionBank::balance(array_fill_keys(QuestionBank::DIMENSIONS, 2))['ok']);
$uneven = $even; $uneven['A'] = 6;
$b = QuestionBank::balance($uneven);
check('one type with an extra question is not ready', !$b['ok'] && strpos($b['message'], 'same number') !== false);
$short = $even; unset($short['C']);
check('a type with no questions is not ready', !QuestionBank::balance($short)['ok']);
check('an empty bank is not ready', !QuestionBank::balance([])['ok']);

echo "\n=== scores stay on the 10-50 scale ===\n";
check('all 5s on 10 questions is 50 (as before)', QuestionBank::scaleScore(50, 10) === 50);
check('all 1s on 10 questions is 10 (as before)', QuestionBank::scaleScore(10, 10) === 10);
check('all 5s on 5 questions is 50', QuestionBank::scaleScore(25, 5) === 50);
check('all 1s on 5 questions is 10', QuestionBank::scaleScore(5, 5) === 10);
check('the same answers give the same score whatever the number of questions', QuestionBank::scaleScore(18, 5) === QuestionBank::scaleScore(36, 10));
check('a type with no questions scores 0, not an error', QuestionBank::scaleScore(0, 0) === 0);

echo "\n=== the ranked Holland code used in the downloads ===\n";
check('the top three types become letters in rank order', QuestionBank::hollandCode(['Investigative', 'Conventional', 'Social']) === 'ICS');
check('a different order gives a different code', QuestionBank::hollandCode(['Social', 'Investigative', 'Conventional']) === 'SIC');
check('only the top three count', QuestionBank::hollandCode(['Realistic', 'Artistic', 'Enterprising', 'Social']) === 'RAE');
check('nothing gives an empty code', QuestionBank::hollandCode([]) === '');
check('results stored as letters work too', QuestionBank::hollandCode(['E', 'A', 'R']) === 'EAR');
check('every type name is understood', QuestionBank::hollandCode(array_values(QuestionBank::NAMES)) === 'RIA');

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
