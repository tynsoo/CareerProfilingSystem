<?php
// Adds programs.description_enc — nullable, so existing programs are
// unaffected until an admin writes a description for them via
// add-career.html / career-dataset.html. Encrypted like every other
// free-text content column (title_enc, holland_code_enc), never used in
// WHERE/ORDER BY.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec('ALTER TABLE programs ADD COLUMN IF NOT EXISTS description_enc TEXT');

echo "programs.description_enc column added (or already present).\n";
