<?php

require_once __DIR__ . '/../lib/BookingLink.php';

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

echo "=== the counseling booking link ===\n";
$ok = BookingLink::validate('  https://booking.example.edu.ph/cgc?x=1  ');
check('a full https address is accepted and trimmed', $ok['error'] === null && $ok['url'] === 'https://booking.example.edu.ph/cgc?x=1');
$empty = BookingLink::validate('   ');
check('an empty value clears the link', $empty['error'] === null && $empty['url'] === '');
check('http is refused (https only)', BookingLink::validate('http://booking.example.edu.ph')['error'] !== null);
check('text that is not an address is refused', BookingLink::validate('book me please')['error'] !== null);
check('an address without a scheme is refused', BookingLink::validate('booking.example.edu.ph')['error'] !== null);
check('javascript: and other schemes are refused', BookingLink::validate('javascript:alert(1)')['error'] !== null && BookingLink::validate('ftp://x.example.org')['error'] !== null);
check('a user name and password in the link are refused', BookingLink::validate('https://user:pw@booking.example.edu.ph')['error'] !== null);
check('a very long link is refused', BookingLink::validate('https://example.org/' . str_repeat('a', 400))['error'] !== null);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);