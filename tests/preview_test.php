<?php

// The student preview (dev/) must keep working with no database: it only uses plain-logic classes from lib/.
require_once __DIR__ . '/../dev/PreviewApi.php';

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

echo "=== the preview needs no database ===\n";
check('loading it does not load the Database or Crypto classes', !class_exists('Database', false) && !class_exists('Crypto', false));

echo "\n=== sample data ===\n";
$programs = PreviewApi::programs();
check('it has the 30 MMCL programs', count($programs) === 30);
check('every program has a Holland code and careers', count(array_filter($programs, fn($p) => $p['hollandCode'] !== '' && $p['careers'] !== [])) === 30);

echo "\n=== the three students ===\n";
$new = PreviewApi::fresh('new');
check('a new student has taken nothing', $new['assessment'] === null && $new['worksheet'] === null && $new['saved'] === []);
$done = PreviewApi::fresh('done');
check('"assessment done" has scores and top three types', $done['assessment'] !== null && count($done['assessment']['topTypes']) === 3 && $done['worksheet'] === null);
check('the sample scores use the 10-50 scale', max($done['assessment']['scores']) <= 50 && min($done['assessment']['scores']) >= 10);
$complete = PreviewApi::fresh('complete');
check('"assessment and worksheet done" has a worksheet linked to a program', $complete['worksheet'] !== null && $complete['worksheet']['programId'] !== null);
check('it starts with two saved programs', count($complete['saved']) === 2);

echo "\n=== the real code runs on the sample ===\n";
$match = CareerMatcher::resolve('Software Engineer', $programs, $done['assessment']['scores']);
check('"Software Engineer" matches a program', $match !== null);
$rec = CBFEngine::recommend($done['assessment']['scores'], array_map(fn($p) => ['id' => (int) $p['id'], 'hollandCode' => $p['hollandCode']], $programs), $match);
check('the recommendation has a top three, best first', count($rec['top3']) === 3 && $rec['top3'][0]['score'] >= $rec['top3'][1]['score']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
