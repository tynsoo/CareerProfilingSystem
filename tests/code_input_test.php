<?php
/**
 * Codes pasted with extra characters (shared/lib/CodeInput.php, panel revision S3): a copied access code or emailed
 * 6-digit code must still be accepted when it brings spaces, line breaks, non-breaking or zero-width spaces, or dashes.
 *   php tests/code_input_test.php
 */
require_once __DIR__ . '/../shared/lib/CodeInput.php';

$failures = 0;
$passed = 0;
function check(string $label, bool $condition): void
{
    global $failures, $passed;
    $condition ? $passed++ : $failures++;
    echo ($condition ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}

echo "=== assessment access code ===\n";
check('leading and trailing spaces are dropped', CodeInput::clean('  3FA9C1 ') === '3FA9C1');
check('a line break and tab copied from an email are dropped', CodeInput::clean("\t3fa9c1\r\n") === '3FA9C1');
check('a non-breaking space (which trim() keeps) is dropped', CodeInput::clean("\u{00A0}3FA9C1\u{00A0}") === '3FA9C1');
check('zero-width characters are dropped', CodeInput::clean("3FA\u{200B}9C1\u{FEFF}") === '3FA9C1');
check('a space or dash inside the code is dropped', CodeInput::clean('3FA 9C1') === '3FA9C1' && CodeInput::clean('3FA-9C1') === '3FA9C1');
check('lower case becomes upper case', CodeInput::clean('abcd2345') === 'ABCD2345');
check('an empty or all-space entry stays empty', CodeInput::clean("  \u{00A0} ") === '');
check('invalid UTF-8 still comes out as letters and digits only', CodeInput::clean("AB\xC3(12") === 'AB12');

echo "\n=== access code pasted with the words around it (CodeInput::pick) ===\n";
check('a clean code is unchanged', CodeInput::pick('7c3e91', 6) === '7C3E91');
check('spaces around and inside still work', CodeInput::pick(" 7C3 E91\n", 6) === '7C3E91');
check('"Access code: 7C3E91" gives the code, not "ACCESS"', CodeInput::pick('Access code: 7C3E91', 6) === '7C3E91');
check('a whole confirmation sentence gives the code', CodeInput::pick('Schedule created. Access code: 7C3E91', 6) === '7C3E91');
check('a non-breaking space before the code is handled', CodeInput::pick("Code:\u{00A0}7C3E91\u{200B}", 6) === '7C3E91');
check('an all-letter code is still found', CodeInput::pick('Code: ABCDEF', 6) === 'ABCDEF');
check('a wrong length with no 6-character word is left as typed', CodeInput::pick('7C3E9', 6) === '7C3E9');
check('8-character staff code out of an email line', CodeInput::pick('Your access code is ABCD2345.', 8) === 'ABCD2345');

echo "\n=== emailed 6-digit codes ===\n";
check('" 123456" keeps all six digits', CodeInput::digits(' 123456') === '123456');
check('"123 456" (grouped) keeps all six digits', CodeInput::digits('123 456') === '123456');
check('a pasted sentence keeps only its digits', CodeInput::digits('Your code: 123456.') === '123456');

echo "\n$passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
