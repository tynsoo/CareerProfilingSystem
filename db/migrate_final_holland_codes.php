<?php
// Applies the validator's final Holland codes (August 7, 2026) and keeps the
// original codes for audit, using db/program_codes.php as the source.
//
// 1. Adds programs.original_holland_code_enc (encrypted like holland_code_enc).
// 2. For each of the 32 listed programs (matched by decrypted title):
//      - original_holland_code_enc <- the crosswalk code, if not recorded yet
//      - holland_code_enc          <- the final code, if different
//    Only BS Tourism Management (EAS -> SEA) and BS Computer Engineering
//    (RIC -> ICR) change on a database seeded from the crosswalk.
// Programs not in the list (e.g. added later by an admin) are left untouched.
// Safe to run more than once, but it resets these programs to the codes below: after staff have
// changed a code on the Career Dataset page, running it again would undo that change.

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';

$pdo = Database::get();
$pdo->exec('ALTER TABLE programs ADD COLUMN IF NOT EXISTS original_holland_code_enc TEXT');

$codes = [];
foreach (require __DIR__ . '/program_codes.php' as [, $title, $original, $final]) {
    $codes[$title] = [$original, $final];
}

$rows = $pdo->query('SELECT id, title_enc, holland_code_enc, original_holland_code_enc FROM programs')->fetchAll();
$setOriginal = $pdo->prepare('UPDATE programs SET original_holland_code_enc = ? WHERE id = ?');
$setFinal = $pdo->prepare('UPDATE programs SET holland_code_enc = ?, updated_at = NOW() WHERE id = ?');

$found = [];
$pdo->beginTransaction();
foreach ($rows as $r) {
    $title = Crypto::dec($r['title_enc']);
    if (!isset($codes[$title])) {
        echo "  skipped (not in the 32-program list): $title\n";
        continue;
    }
    $found[$title] = true;
    [$original, $final] = $codes[$title];

    if ($r['original_holland_code_enc'] === null) {
        $setOriginal->execute([Crypto::enc($original), $r['id']]);
    }
    $current = Crypto::dec($r['holland_code_enc']);
    if ($current !== $final) {
        $setFinal->execute([Crypto::enc($final), $r['id']]);
        echo "  $title: $current -> $final\n";
    }
}
$pdo->commit();

foreach (array_diff_key($codes, $found) as $title => $_) {
    echo "  WARNING: listed program not found in the database: $title\n";
}
echo "Final Holland codes applied; original codes recorded.\n";
