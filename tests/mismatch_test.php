<?php
/**
 * Mismatch (modules/ContentBasedFiltering/lib/Mismatch.php): the Preferred Course compared with the Best RIASEC Match.
 *
 *   php tests/mismatch_test.php
 *
 * DEVELOPMENT TEST DATA ONLY.
 */
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/Mismatch.php';

$passed = 0;
$failures = 0;
function check(string $label, bool $cond): void
{
    global $passed, $failures;
    if ($cond) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

// Stored per-program CBF results (cosine similarity); programs 2 and 3 are tied at the top.
$scores = [
    ['programId' => 1, 'cosine' => 0.7741],
    ['programId' => 2, 'cosine' => 0.8868],
    ['programId' => 3, 'cosine' => 0.8868],
    ['programId' => 4, 'cosine' => 0.8310],
    ['programId' => 5, 'cosine' => 0.4509],
];

echo "=== match or mismatch ===\n";
check('Preferred Course is a Best RIASEC Match -> match', Mismatch::isMismatch(2, $scores) === false);
check('Preferred Course tied at the top -> match', Mismatch::isMismatch(3, $scores) === false);
check('Preferred Course is the next level (an alternative) -> close match, not a mismatch', Mismatch::isMismatch(4, $scores) === false);
check('Preferred Course two levels down -> mismatch', Mismatch::isMismatch(1, $scores) === true);
check('Preferred Course far below -> mismatch', Mismatch::isMismatch(5, $scores) === true);
check('the typed career could not be linked to a program -> mismatch', Mismatch::isMismatch(null, $scores) === true);
check('the order the scores are stored in does not matter', Mismatch::isMismatch(3, array_reverse($scores)) === false);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
