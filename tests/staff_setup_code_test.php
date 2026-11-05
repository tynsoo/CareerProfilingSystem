<?php

require_once __DIR__ . '/../modules/Authentication/lib/StaffSetupCode.php';

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

echo "=== temporary access code ===\n";
$code = StaffSetupCode::generate();
check('a code is 8 characters', strlen($code) === 8);
check('it uses only easy-to-read letters and digits (no 0, O, 1, I, L)', (bool) preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code));
$many = [];
for ($i = 0; $i < 200; $i++) {
    $many[StaffSetupCode::generate()] = true;
}
check('200 codes are all different', count($many) === 200);

echo "\n=== typing the code ===\n";
check('lower case, dashes and spaces are ignored', StaffSetupCode::normalize('abcd-2345') === 'ABCD2345' && StaffSetupCode::normalize(' ab cd 23 45 ') === 'ABCD2345');
check('the same code gives the same hash however it is typed', StaffSetupCode::hash(5, 'abcd-2345') === StaffSetupCode::hash(5, 'ABCD2345'));
check('the code copied out of the email with spaces between the letters works', StaffSetupCode::normalize("A B C D 2 3 4 5\n") === 'ABCD2345');
check('the code is picked out of a pasted email line', StaffSetupCode::normalize('Temporary access code: ABCD2345') === 'ABCD2345');
check('the hash is tied to the account (another user\'s code does not work)', StaffSetupCode::hash(5, 'ABCD2345') !== StaffSetupCode::hash(6, 'ABCD2345'));
check('the hash is not the plain code', strpos(StaffSetupCode::hash(5, 'ABCD2345'), 'ABCD2345') === false);
check('it cannot double as an email-verification link token', StaffSetupCode::hash(5, 'ABCD2345') !== hash('sha256', 'ABCD2345'));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
