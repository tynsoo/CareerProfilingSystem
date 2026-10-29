<?php
/**
 * Changing the encryption key (KeyRotation::rotate, used by db/rotate_aes_key.php): every encrypted value
 * reads back the same with the new key, the audit log checks out with the new key, and a rotation with an
 * unreadable value, a tampered audit log, or an encrypted value outside a *_enc column changes nothing.
 * Runs on the test database only, inside transactions that are rolled back.
 *
 *   DB_NAME=profilepath_test php tests/key_rotation_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. The new key is made up for each run and never saved.
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/KeyRotation.php';

if (!str_ends_with((string) envValue('DB_NAME'), '_test')) {
    echo "Runs on a *_test database only (DB_NAME=profilepath_test).\n";
    exit(1);
}

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $info = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label" . ($info !== '' ? " ($info)" : '') . "\n";
    }
}

/** Every encrypted value, decrypted with the current key, keyed "table.column#id". */
function plainValues(PDO $pdo): array
{
    $out = [];
    foreach (KeyRotation::encryptedColumns($pdo) as $c) {
        $rows = $pdo->query("SELECT \"{$c['key']}\", \"{$c['column']}\" FROM \"{$c['table']}\" WHERE \"{$c['column']}\" IS NOT NULL AND \"{$c['column']}\" <> ''")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $id => $blob) {
            $out["{$c['table']}.{$c['column']}#$id"] = Crypto::dec((string) $blob);
        }
    }
    return $out;
}

/** Runs $fn, returning the exception message ('' when it did not throw). */
function thrown(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * The HTTP tests remove their own users' audit entries when they clean up, which leaves gaps in the test database's
 * chain (a real database never has them). Inside the caller's transaction, which is rolled back, start such a broken
 * log over with a few sealed entries, so each rotation is tested on a chain that checks out.
 */
function cleanChain(PDO $pdo): void
{
    if (!AuditChain::enabled($pdo) || AuditChain::verify($pdo)['intact']) {
        return;
    }
    $pdo->exec('DELETE FROM audit_log');
    $pdo->prepare('DELETE FROM security_policies WHERE key = ?')->execute([AuditChain::ANCHOR_KEY]);
    for ($i = 0; $i < 6; $i++) {
        AuditChain::append($pdo, [null, null, 'qa_key_rotation_probe', null, null, null, null]);
    }
}

$pdo = Database::get();
$oldKey = (string) envValue('APP_AES_KEY');
$newKey = base64_encode(random_bytes(32));
$before = plainValues($pdo);
$chained = AuditChain::enabled($pdo);
$chainAtStart = $chained ? AuditChain::verify($pdo) : null;

echo "=== refused before anything changes ===\n";
check('outside a transaction', str_contains(thrown(fn() => KeyRotation::rotate($pdo, $oldKey, $newKey)), 'inside a transaction'));
$pdo->beginTransaction();
check('the new key is the same as the current one', str_contains(thrown(fn() => KeyRotation::rotate($pdo, $oldKey, $oldKey)), 'same as the current'));
check('a key that is not 32 bytes', str_contains(thrown(fn() => KeyRotation::rotate($pdo, $oldKey, base64_encode('short'))), '32 bytes'));
check('a current key the data was not saved with (or a rotation run twice)',
    str_contains(thrown(fn() => KeyRotation::rotate($pdo, base64_encode(random_bytes(32)), $newKey)), 'cannot read this database'));
$pdo->rollBack();
KeyRotation::useKey($oldKey);
check('keys are shown only as a fingerprint', KeyRotation::fingerprint($newKey) !== $newKey && strlen(KeyRotation::fingerprint($newKey)) === 12);

echo "\n=== a rotation ===\n";
$pdo->beginTransaction();
cleanChain($pdo);
$inRotation = plainValues($pdo); // without the entries cleanChain() removed
$error = thrown(function () use ($pdo, $oldKey, $newKey, &$result) {
    $result = KeyRotation::rotate($pdo, $oldKey, $newKey);
});
check('the rotation finishes', $error === '', $error);
if ($error === '') {
    $after = plainValues($pdo);
    check('every value reads back the same with the new key (' . count($inRotation) . ' values)',
        array_diff_key($inRotation, $after) === [] && array_intersect_key($after, $inRotation) == $inRotation);
    check('the count of re-encrypted values matches', $result['values'] === count($inRotation), $result['values'] . ' vs ' . count($inRotation));
    check('every *_enc column is listed', count($result['columns']) === count(KeyRotation::encryptedColumns($pdo)));
    if ($chained) {
        check('the audit log checks out with the new key', AuditChain::verify($pdo)['intact']);
        check('the rotation is recorded in the audit log',
            (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'rotate_encryption_key' AND actor_user_id IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn());
    }
    KeyRotation::useKey($oldKey);
    $sample = $pdo->query('SELECT first_name_enc FROM students WHERE first_name_enc IS NOT NULL LIMIT 1')->fetchColumn();
    check('the old key can no longer read the values', $sample === false || thrown(fn() => Crypto::dec((string) $sample)) !== '');
    if ($chained) {
        check('the audit log no longer checks out with the old key', !AuditChain::verify($pdo)['intact']);
    }
}
$pdo->rollBack();
KeyRotation::useKey($oldKey);
check('after rolling back, every value reads the same with the old key again', plainValues($pdo) == $before);
if ($chained) {
    $pdo->beginTransaction();
    check('after rolling back, the audit log is as it was', AuditChain::verify($pdo) == $chainAtStart);
    $pdo->rollBack();
}

if ($chained) {
    echo "\n=== a rotation after the daily cleanup removed old entries (the chain starts at an anchor) ===\n";
    $pdo->beginTransaction();
    cleanChain($pdo);
    $cut = (int) $pdo->query('SELECT id FROM audit_log ORDER BY id OFFSET 4 LIMIT 1')->fetchColumn();
    AuditChain::removeUpTo($pdo, $cut);
    check('before: the shortened log checks out with the old key', AuditChain::verify($pdo)['intact']);
    $error = thrown(fn() => KeyRotation::rotate($pdo, $oldKey, $newKey));
    check('the rotation finishes', $error === '', $error);
    check('after: the shortened log checks out with the new key', $error === '' && AuditChain::verify($pdo)['intact']);
    $pdo->rollBack();
    KeyRotation::useKey($oldKey);
}

echo "\n=== nothing changes when something is wrong ===\n";
$fingerprint = fn() => md5(json_encode(plainValues($pdo)));
$untouched = $fingerprint();

$pdo->beginTransaction();
cleanChain($pdo);
KeyRotation::useKey(base64_encode(random_bytes(32)));
$foreign = Crypto::enc('written with some other key');
KeyRotation::useKey($oldKey);
$id = $pdo->query('SELECT id FROM help_requests WHERE subject_enc IS NOT NULL LIMIT 1')->fetchColumn();
if ($id !== false) {
    $pdo->prepare('UPDATE help_requests SET subject_enc = ? WHERE id = ?')->execute([$foreign, $id]);
    $msg = thrown(fn() => KeyRotation::rotate($pdo, $oldKey, $newKey));
    check('a value the current key cannot read stops the rotation, naming it', str_contains($msg, "help_requests.subject_enc #$id"), $msg);
}
$pdo->rollBack();
KeyRotation::useKey($oldKey);

if ($chained) {
    $pdo->beginTransaction();
    cleanChain($pdo);
    // A fresh test database has an empty audit log; seal one entry of our own (rolled back below) so there is something to tamper with.
    AuditChain::append($pdo, [null, null, 'qa_key_rotation_probe', null, null, null, null]);
    $pdo->exec("UPDATE audit_log SET action = action || '_edited' WHERE id = (SELECT MIN(id) FROM audit_log)");
    $msg = thrown(fn() => KeyRotation::rotate($pdo, $oldKey, $newKey));
    check('a tampered audit log stops the rotation (re-sealing would hide it)', str_contains($msg, 'does not check out'), $msg);
    $pdo->rollBack();
    KeyRotation::useKey($oldKey);
}

$pdo->beginTransaction();
cleanChain($pdo);
$pdo->prepare("INSERT INTO security_policies (key, value) VALUES ('test.keyRotationStray', ?)")->execute([Crypto::enc('stray')]);
$msg = thrown(fn() => KeyRotation::rotate($pdo, $oldKey, $newKey));
check('an encrypted value outside a *_enc column stops the rotation', str_contains($msg, 'security_policies.value'), $msg);
$pdo->rollBack();
KeyRotation::useKey($oldKey);

check('after the refused rotations, every value is unchanged', $fingerprint() === $untouched);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
