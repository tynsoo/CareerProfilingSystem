<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
// Existing students (registered before this feature existed) have no real
// section on record — 'N/A' makes that visible in the UI rather than an
// empty cell, and keeps the NOT NULL constraint satisfiable immediately.
$pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS section VARCHAR(20) NOT NULL DEFAULT 'N/A'");

echo "students.section column added (or already present).\n";
