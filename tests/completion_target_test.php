<?php

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CompletionTarget.php';

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

echo "=== what counts as a valid target ===\n";
check('whole numbers from 1 to 100 are valid', CompletionTarget::isValid(1) && CompletionTarget::isValid(80) && CompletionTarget::isValid(100));
check('0 is valid: it means no target', CompletionTarget::isValid(0) && !CompletionTarget::isOn(0) && CompletionTarget::isOn(1));
check('101 and negatives are not', !CompletionTarget::isValid(101) && !CompletionTarget::isValid(-5));
check('decimals, text and nothing are not', !CompletionTarget::isValid(79.5) && !CompletionTarget::isValid('80') && !CompletionTarget::isValid(null));

echo "\n=== saving and reading it back ===\n";
$pdo = Database::get();
$original = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
$original->execute([CompletionTarget::KEY]);
$before = $original->fetchColumn();

$pdo->prepare('DELETE FROM security_policies WHERE key = ?')->execute([CompletionTarget::KEY]);
check('with nothing saved there is no target (the CGC has none)', CompletionTarget::get($pdo) === CompletionTarget::OFF);
CompletionTarget::set($pdo, 65, null);
check('a saved target is read back', CompletionTarget::get($pdo) === 65);
CompletionTarget::set($pdo, 90, null);
check('saving again replaces it', CompletionTarget::get($pdo) === 90);
CompletionTarget::set($pdo, 0, null);
check('saving 0 turns the target off', CompletionTarget::get($pdo) === CompletionTarget::OFF);
$pdo->prepare('UPDATE security_policies SET value = ? WHERE key = ?')->execute(['abc', CompletionTarget::KEY]);
check('a damaged stored value means no target', CompletionTarget::get($pdo) === CompletionTarget::OFF);
$pdo->prepare('UPDATE security_policies SET value = ? WHERE key = ?')->execute(['250', CompletionTarget::KEY]);
check('an out-of-range stored value means no target', CompletionTarget::get($pdo) === CompletionTarget::OFF);

// leave the table as it was
if ($before === false) {
    $pdo->prepare('DELETE FROM security_policies WHERE key = ?')->execute([CompletionTarget::KEY]);
} else {
    CompletionTarget::set($pdo, (int) $before, null);
}

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
