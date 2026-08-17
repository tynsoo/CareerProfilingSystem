<?php

/**
 * Dev-only CLI helper: dumps a table with its *_enc columns decrypted inline,
 * so you can inspect real data without a Postgres extension that can't touch
 * app-level AES ciphertext. Usage:
 *   php db/view_decrypted.php <table> [limit]
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

$table = $argv[1] ?? null;
$limit = (int) ($argv[2] ?? 20);

if ($table === null) {
    echo "Usage: php db/view_decrypted.php <table> [limit]\n";
    echo "Tables with encrypted columns: students, programs, assessment_questions, help_requests, audit_log\n";
    exit(1);
}

$pdo = Database::get();

// Only allow real table names from the catalog — this takes CLI input, never
// request input, but this still keeps the interpolation below honest.
$tableCheck = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
$tableCheck->execute([$table]);
if (!$tableCheck->fetch()) {
    echo "No such table: $table\n";
    exit(1);
}

$columnsStmt = $pdo->prepare(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position"
);
$columnsStmt->execute([$table]);
$columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);

$rows = $pdo->query("SELECT * FROM \"$table\" LIMIT $limit")->fetchAll();

if (!$rows) {
    echo "(no rows)\n";
    exit;
}

foreach ($rows as $i => $row) {
    echo "--- row " . ($i + 1) . " ---\n";
    foreach ($columns as $col) {
        $value = $row[$col];
        if (str_ends_with($col, '_enc')) {
            $label = substr($col, 0, -4) . ' (decrypted)';
            try {
                $value = $value === null ? null : Crypto::dec($value);
            } catch (Throwable $e) {
                $value = '<decrypt failed: ' . $e->getMessage() . '>';
            }
            echo "  $label: $value\n";
        } else {
            echo "  $col: $value\n";
        }
    }
}
