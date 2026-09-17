<?php
/**
 * One-off fix: programs and assessment_questions were originally seeded
 * while .env's APP_AES_KEY didn't match this database's actual production
 * key, so their _enc columns are undecryptable ("Invalid PKCS#7 padding").
 * Clears both tables and re-runs the normal seed scripts so everything is
 * (re-)encrypted with whatever APP_AES_KEY is active when this runs.
 */
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec('DELETE FROM programs');
$pdo->exec('DELETE FROM assessment_questions');
echo "Cleared programs and assessment_questions.\n";

echo "--- seed_programs.php ---\n";
require __DIR__ . '/seed_programs.php';

echo "--- seed_questions.php ---\n";
require __DIR__ . '/seed_questions.php';

echo "--- backfill_program_descriptions.php ---\n";
require __DIR__ . '/backfill_program_descriptions.php';
